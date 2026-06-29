<?php

namespace MXPOSPro\Cash;

defined('ABSPATH') || exit;

use MXPOSPro\Sales\SaleRepository;
use MXPOSPro\Sales\RefundRepository;
use MXPOSPro\Sales\TicketService;
use MXPOSPro\Entities\EmployeeRepository;
use WP_Error;

class CashCutService
{
    private CashCutRepository $cutRepo;
    private CashSessionService $sessionService;
    private CashMovementRepository $movementRepo;
    private SaleRepository $saleRepo;
    private RefundRepository $refundRepo;
    private TicketService $ticketService;

    public function __construct(
        CashCutRepository $cutRepo,
        CashSessionService $sessionService,
        CashMovementRepository $movementRepo,
        SaleRepository $saleRepo,
        RefundRepository $refundRepo,
        TicketService $ticketService
    ) {
        $this->cutRepo        = $cutRepo;
        $this->sessionService = $sessionService;
        $this->movementRepo   = $movementRepo;
        $this->saleRepo       = $saleRepo;
        $this->refundRepo     = $refundRepo;
        $this->ticketService  = $ticketService;
    }

    public function generate_x(int $sessionId, int $userId): array|WP_Error
    {
        $session = $this->sessionService->get_session_by_id($sessionId);

        if ($session === null) {
            return new WP_Error(
                'mx_pos_cut_session_not_found',
                __('Session not found.', 'mx-pos-pro'),
                ['status' => 404]
            );
        }

        if ($session['status'] !== 'open') {
            return new WP_Error(
                'mx_pos_cut_session_not_open',
                __('El pre-corte requiere una sesión abierta.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $summary = $this->build_summary($session, $sessionId, $userId, 'X');
        $summary['ticket_html'] = $this->ticketService->generate_cut_ticket_html($summary, 'X');

        return [
            'cut'         => $summary,
            'ticket_html' => $summary['ticket_html'],
        ];
    }

    public function generate_z(int $sessionId, int $userId): array|WP_Error
    {
        $session = $this->sessionService->get_session_by_id($sessionId);

        if ($session === null) {
            return new WP_Error(
                'mx_pos_cut_session_not_found',
                __('Session not found.', 'mx-pos-pro'),
                ['status' => 404]
            );
        }

        if ($session['status'] !== 'closed') {
            return new WP_Error(
                'mx_pos_cut_session_not_closed',
                __('El cierre requiere una sesión cerrada.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $existingFinal = $this->cutRepo->find_final_by_session($sessionId);

        if ($existingFinal !== null) {
            return $this->response_from_existing_final($existingFinal);
        }

        $summary = $this->build_summary($session, $sessionId, $userId, 'Z');

        $closingExpected  = isset($session['expected_amount']) ? (float) $session['expected_amount'] : null;
        $closingCounted   = isset($session['counted_amount']) ? (float) $session['counted_amount'] : null;
        $closingDifference = isset($session['difference']) ? (float) $session['difference'] : null;

        $summary['closing'] = [
            'expected_amount' => $closingExpected !== null ? $this->formatDecimal($closingExpected) : null,
            'counted_amount'  => $closingCounted !== null ? $this->formatDecimal($closingCounted) : null,
            'difference'      => $closingDifference !== null ? $this->formatDecimal($closingDifference) : null,
            'closed_at'       => $session['closed_at'] ?? null,
            'closed_by'       => $this->get_user_name($session['closed_by'] ?? null),
            'close_note'      => $session['close_note'] ?? null,
        ];

        $summaryJson = wp_json_encode($summary);

        if (! is_string($summaryJson)) {
            return new WP_Error(
                'mx_pos_cut_json_error',
                __('Failed to encode cut summary.', 'mx-pos-pro'),
                ['status' => 500]
            );
        }

        $cutData = $this->cutRepo->create([
            'session_id'      => $sessionId,
            'branch_id'       => isset($session['branch_id']) ? (int) $session['branch_id'] : null,
            'pos_register_id' => isset($session['pos_register_id']) ? (int) $session['pos_register_id'] : null,
            'pos_employee_id' => isset($session['pos_employee_id']) ? (int) $session['pos_employee_id'] : null,
            'cut_type'     => 'Z',
            'sequence'     => 1,
            'summary_json' => $summaryJson,
            'generated_by' => $userId,
            'generated_at' => current_time('mysql'),
            'is_final'     => 1,
        ]);

        if (is_wp_error($cutData)) {
            $existingFinal = $this->cutRepo->find_final_by_session($sessionId);

            if ($existingFinal !== null) {
                return $this->response_from_existing_final($existingFinal);
            }

            return $cutData;
        }

        $cutId = (int) $cutData['id'];

        $this->log_audit($userId, $cutId, 'cut_z_generated');

        $ticketHtml = $this->ticketService->generate_cut_ticket_html($summary, 'Z');

        $summary['cut_id']     = $cutId;
        $summary['ticket_html'] = $ticketHtml;

        do_action('mx_pos_cut_z_generated', [
            'cut_id'     => $cutId,
            'session_id' => $sessionId,
            'summary'    => $summary,
        ]);

        return [
            'cut'         => $summary,
            'ticket_html' => $ticketHtml,
        ];
    }

    public function get_cut_by_id(int $cutId): ?array
    {
        $cut = $this->cutRepo->get_by_id($cutId);

        if ($cut === null) {
            return null;
        }

        $summary = json_decode($cut['summary_json'], true);

        if (! is_array($summary)) {
            return null;
        }

        $summary['cut_id']      = (int) $cut['id'];
        $summary['cut_type']    = $cut['cut_type'];
        $summary['generated_at'] = $cut['generated_at'];
        $summary['generated_by'] = $this->get_user_name($cut['generated_by']);

        return $summary;
    }

    public function get_cut_ticket_html(int $cutId): string|WP_Error
    {
        $cut = $this->cutRepo->get_by_id($cutId);

        if ($cut === null) {
            return new WP_Error(
                'mx_pos_cut_not_found',
                __('Cut not found.', 'mx-pos-pro'),
                ['status' => 404]
            );
        }

        $summary = json_decode($cut['summary_json'], true);

        if (! is_array($summary)) {
            return new WP_Error(
                'mx_pos_cut_corrupt',
                __('Cut data is corrupt.', 'mx-pos-pro'),
                ['status' => 500]
            );
        }

        $cutType = $cut['cut_type'];

        return $this->ticketService->generate_cut_ticket_html($summary, $cutType);
    }

    private function build_summary(array $session, int $sessionId, int $userId, string $cutType): array
    {
        $movementTotals = $this->movementRepo->classified_totals_by_session($sessionId);
        $saleTotals     = $this->saleRepo->get_totals_by_session($sessionId);
        $refundTotals   = $this->refundRepo->get_totals_by_session($sessionId);
        $operator       = $this->resolve_session_operator($session);

        $openingAmount = (float) $session['opening_amount'];
        $cashInTotal   = (float) $movementTotals['cash_in_total'];
        $cashOutTotal  = (float) $movementTotals['cash_out_total'];
        $expectedCash  = $openingAmount + $cashInTotal - $cashOutTotal;

        $collectedTotal = (float) $saleTotals['gross_sales'];
        $refundTotal    = (float) $refundTotals['refund_total'];
        $netAfterRefunds = $collectedTotal - $refundTotal;

        $summary = [
            'cut_type'   => $cutType,
            'session'    => [
                'id'           => (int) $session['id'],
                'status'       => $session['status'],
                'opened_at'    => $session['opened_at'],
                'opened_by'    => $operator['id'],
                'opened_by_type' => $operator['type'],
                'cashier_name' => $operator['name'],
            ],
            'opening' => [
                'amount' => $this->formatDecimal($openingAmount),
            ],
            'cash_flow' => [
                'cash_in_total'  => $this->formatDecimal($cashInTotal),
                'cash_out_total' => $this->formatDecimal($cashOutTotal),
                'net_cash'       => $this->formatDecimal($cashInTotal - $cashOutTotal),
                'manual_cash_in_total'   => $movementTotals['manual_cash_in_total'],
                'manual_cash_in_count'   => $movementTotals['manual_cash_in_count'],
                'manual_cash_out_total'  => $movementTotals['manual_cash_out_total'],
                'manual_cash_out_count'  => $movementTotals['manual_cash_out_count'],
                'manual_net_cash'        => $movementTotals['manual_net_cash'],
                'sales_cash_in_total'    => $movementTotals['sales_cash_in_total'],
                'sales_cash_in_count'    => $movementTotals['sales_cash_in_count'],
                'sales_change_out_total' => $movementTotals['sales_change_out_total'],
                'sales_change_out_count' => $movementTotals['sales_change_out_count'],
            ],
            'expected_cash' => $this->formatDecimal($expectedCash),
            'sales' => [
                'collected_total' => $this->formatDecimal($collectedTotal),
                'count_orders'    => $saleTotals['count_orders'],
                'cash_collected_total' => $movementTotals['sales_cash_in_total'],
                'cash_change_total'    => $movementTotals['sales_change_out_total'],
                'card_collected_total' => $saleTotals['card_sales'] ?? '0.0000',
                'card_sales_count'     => $saleTotals['card_sales_count'] ?? 0,
            ],
            'discounts' => [
                'total' => $saleTotals['discount_total'],
            ],
            'coupons' => [
                'total'       => $saleTotals['coupon_total'] ?? '0.0000',
                'count'       => $saleTotals['coupon_count'] ?? 0,
                'by_code'     => $saleTotals['coupon_by_code'] ?? [],
            ],
            'by_method'       => $saleTotals['by_method'] ?? [],
            'mixed_breakdown' => $saleTotals['mixed_breakdown'] ?? [],
            'card_fees'       => [
                'total' => $saleTotals['card_fees_total'] ?? '0.0000',
                'count' => $saleTotals['card_fees_count'] ?? 0,
            ],
            'refunds' => [
                'total'              => $this->formatDecimal($refundTotal),
                'count_refunds'      => $refundTotals['count_refunds'],
                'count_cancellations' => $refundTotals['count_cancellations'],
                'cash_refunds'       => $refundTotals['cash_refunds'],
                'card_refunds'       => $refundTotals['card_refunds'],
            ],
            'net_after_refunds' => $this->formatDecimal(max(0, $netAfterRefunds)),
            'generated_at'      => current_time('mysql'),
            'generated_by'      => $this->get_user_name($userId),
        ];

        return $summary;
    }

    private function resolve_session_operator(array $session): array
    {
        $employeeId = isset($session['pos_employee_id']) ? (int) $session['pos_employee_id'] : 0;

        if ($employeeId > 0) {
            $name = $this->get_employee_name($employeeId);

            if ($name !== '') {
                return [
                    'id'   => $employeeId,
                    'type' => 'pos_employee',
                    'name' => $name,
                ];
            }
        }

        if (! empty($session['employee_name']) && is_string($session['employee_name'])) {
            return [
                'id'   => $employeeId,
                'type' => 'pos_employee',
                'name' => $session['employee_name'],
            ];
        }

        $cashierId = isset($session['cashier_id']) ? (int) $session['cashier_id'] : (isset($session['opened_by']) ? (int) $session['opened_by'] : 0);
        $cashierName = $this->get_user_name($cashierId);

        return [
            'id'   => $cashierId,
            'type' => 'wp_user',
            'name' => $cashierName !== '' ? $cashierName : __('Sin operador registrado', 'mx-pos-pro'),
        ];
    }

    private function get_employee_name(int $employeeId): string
    {
        if ($employeeId <= 0) {
            return '';
        }

        $employee = (new EmployeeRepository())->get_by_id($employeeId);

        if (! is_array($employee)) {
            return '';
        }

        return trim((string) ($employee['display_name'] ?? ''));
    }

    private function response_from_existing_final(array $existingFinal): array|WP_Error
    {
        $summary = json_decode($existingFinal['summary_json'], true);

        if (! is_array($summary)) {
            return new WP_Error(
                'mx_pos_cut_corrupt',
                __('Cut data is corrupt.', 'mx-pos-pro'),
                ['status' => 500]
            );
        }

        $ticketHtml = $this->ticketService->generate_cut_ticket_html($summary, 'Z');

        $summary['cut_id']       = (int) $existingFinal['id'];
        $summary['cut_type']     = $existingFinal['cut_type'];
        $summary['generated_at'] = $existingFinal['generated_at'];
        $summary['generated_by'] = $this->get_user_name($existingFinal['generated_by']);
        $summary['ticket_html']  = $ticketHtml;

        return [
            'cut'         => $summary,
            'ticket_html' => $ticketHtml,
        ];
    }

    private function get_user_name(mixed $userId): string
    {
        $id = (int) $userId;

        if ($id <= 0) {
            return '';
        }

        $user = get_userdata($id);

        if (! $user instanceof \WP_User) {
            return '';
        }

        return $user->display_name;
    }

    private function log_audit(int $userId, int $cutId, string $action): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'mx_pos_audit_logs';
        $ipAddress = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        $wpdb->insert(
            $table,
            [
                'actor_id'     => $userId,
                'action'       => $action,
                'entity_type'  => 'cash_cut',
                'entity_id'    => $cutId,
                'ip_address'   => $ipAddress,
                'context_data' => wp_json_encode(['cut_id' => $cutId]),
                'created_at'   => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );
    }

    private function formatDecimal(float $value): string
    {
        return number_format($value, 4, '.', '');
    }
}
