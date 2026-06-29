<?php

namespace MXPOSPro\Cash;

defined('ABSPATH') || exit;

use MXPOSPro\Audit\AuditLogger;
use WP_Error;

class CashMovementService
{
    private CashMovementRepository $movementRepo;
    private CashSessionService $sessionService;

    public function __construct(
        CashMovementRepository $movementRepo,
        CashSessionService $sessionService
    ) {
        $this->movementRepo   = $movementRepo;
        $this->sessionService = $sessionService;
    }

    public function get_current_session_movements(int $user_id): array
    {
        $result = $this->sessionService->get_current_session($user_id);

        if (! $result['has_open_session'] || $result['session'] === null) {
            return [
                'has_open_session' => false,
                'session_id'       => null,
                'opening_amount'   => null,
                'items'            => [],
                'totals'           => [
                    'cash_in'      => '0.0000',
                    'cash_out'     => '0.0000',
                    'net'          => '0.0000',
                    'current_cash' => '0.0000',
                ],
            ];
        }

        $sessionId = (int) $result['session']['id'];
        $rows      = $this->movementRepo->list_by_session($sessionId);
        $totals    = $this->totals_for_session($result['session']);

        return [
            'has_open_session' => true,
            'session_id'       => $sessionId,
            'opening_amount'   => $this->formatDecimal((float) $result['session']['opening_amount']),
            'items'            => $this->map_movements($rows),
            'totals'           => $totals,
        ];
    }

    public function create_movement(
        int $user_id,
        string $movement_type,
        string $amount,
        ?string $reason = null,
        ?string $client_request_id = null
    ): array|WP_Error {
        if ($user_id <= 0) {
            return new WP_Error(
                'mx_pos_invalid_user',
                __('Invalid user.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if (! in_array($movement_type, ['cash_in', 'cash_out'], true)) {
            return new WP_Error(
                'mx_pos_invalid_type',
                __('Movement type must be cash_in or cash_out.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if (! is_numeric($amount) || (float) $amount <= 0) {
            return new WP_Error(
                'mx_pos_invalid_amount',
                __('Amount must be a positive number.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if ($reason !== null) {
            $reason = trim($reason);

            if (mb_strlen($reason) > 255) {
                return new WP_Error(
                    'mx_pos_reason_too_long',
                    __('Reason must not exceed 255 characters.', 'mx-pos-pro'),
                    ['status' => 400]
                );
            }
        }

        $sessionResult = $this->sessionService->get_current_session($user_id);

        if (! $sessionResult['has_open_session'] || $sessionResult['session'] === null) {
            return new WP_Error(
                'mx_pos_no_open_session',
                __('No open session found. Open a session before recording cash movements.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $sessionId = (int) $sessionResult['session']['id'];
        $amountValue = (float) $amount;

        $branchId      = isset($sessionResult['session']['branch_id']) ? (int) $sessionResult['session']['branch_id'] : null;
        $posRegisterId = isset($sessionResult['session']['pos_register_id']) ? (int) $sessionResult['session']['pos_register_id'] : null;
        $posEmployeeId = isset($sessionResult['session']['pos_employee_id']) ? (int) $sessionResult['session']['pos_employee_id'] : null;

        if ($client_request_id !== null && trim($client_request_id) !== '') {
            $client_request_id = trim($client_request_id);

            if (mb_strlen($client_request_id) > 100) {
                return new WP_Error(
                    'mx_pos_invalid_client_request_id',
                    __('client_request_id must not exceed 100 characters.', 'mx-pos-pro'),
                    ['status' => 400]
                );
            }

            $existing = $this->movementRepo->find_by_client_request_id($client_request_id);

            if ($existing !== null && (int) $existing['session_id'] === $sessionId) {
                $totals = $this->totals_for_session($sessionResult['session']);

                return [
                    'movement' => $this->map_movement($existing),
                    'totals'   => $totals,
                ];
            }
        }

        if ($movement_type === 'cash_out') {
            $cashCheck = $this->ensure_sufficient_cash($sessionResult['session'], $amountValue);

            if (is_wp_error($cashCheck)) {
                return $cashCheck;
            }
        }

        $data = [
            'session_id'        => $sessionId,
            'branch_id'         => $branchId,
            'pos_register_id'   => $posRegisterId,
            'pos_employee_id'   => $posEmployeeId,
            'movement_type'     => $movement_type,
            'amount'            => $this->formatDecimal($amountValue),
            'reason'            => $reason !== null && $reason !== '' ? $reason : null,
            'created_by'        => $user_id,
            'client_request_id' => $client_request_id !== null && trim($client_request_id) !== '' ? trim($client_request_id) : null,
        ];

        $movement = $this->movementRepo->create($data);
        $totals   = $this->totals_for_session($sessionResult['session']);

        AuditLogger::log('cash_movement_created', [
            'entity_type'      => 'cash_movement',
            'entity_id'        => (int) $movement['id'],
            'cash_session_id'  => $sessionId,
            'severity'         => 'info',
            'message'          => sprintf(
                /* translators: 1: movement type, 2: amount */
                __('Movimiento de caja registrado: %1$s de %2$s.', 'mx-pos-pro'),
                $movement_type === 'cash_in' ? __('entrada', 'mx-pos-pro') : __('salida', 'mx-pos-pro'),
                $this->formatDecimal($amountValue)
            ),
            'metadata'         => [
                'movement_id'    => (int) $movement['id'],
                'movement_type'  => $movement_type,
                'amount'         => $this->formatDecimal($amountValue),
                'reason'         => $reason !== null && $reason !== '' ? $reason : null,
                'session_id'     => $sessionId,
            ],
        ]);

        return [
            'movement' => $this->map_movement($movement),
            'totals'   => $totals,
        ];
    }

    public function reverse_movement(
        int $user_id,
        int $movement_id,
        ?string $reason = null
    ): array|WP_Error {
        if ($user_id <= 0) {
            return new WP_Error(
                'mx_pos_invalid_user',
                __('Invalid user.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if ($movement_id <= 0) {
            return new WP_Error(
                'mx_pos_invalid_movement',
                __('Invalid cash movement.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $reason = $reason !== null ? trim($reason) : '';

        if (mb_strlen($reason) > 255) {
            return new WP_Error(
                'mx_pos_reason_too_long',
                __('Reason must not exceed 255 characters.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $sessionResult = $this->sessionService->get_current_session($user_id);

        if (! $sessionResult['has_open_session'] || $sessionResult['session'] === null) {
            return new WP_Error(
                'mx_pos_no_open_session',
                __('No open session found. Open a session before correcting cash movements.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $sessionId = (int) $sessionResult['session']['id'];
        $original  = $this->movementRepo->get_by_id($movement_id);

        if ($original === null || (int) $original['session_id'] !== $sessionId) {
            return new WP_Error(
                'mx_pos_movement_not_found',
                __('Cash movement not found for the current open session.', 'mx-pos-pro'),
                ['status' => 404]
            );
        }

        if ($this->movementRepo->has_reversal_for_movement($sessionId, $movement_id)) {
            return new WP_Error(
                'mx_pos_movement_already_reversed',
                __('This cash movement has already been corrected.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $reverseType = $original['movement_type'] === 'cash_in' ? 'cash_out' : 'cash_in';
        $amountValue = (float) $original['amount'];

        if ($reverseType === 'cash_out') {
            $cashCheck = $this->ensure_sufficient_cash($sessionResult['session'], $amountValue);

            if (is_wp_error($cashCheck)) {
                return $cashCheck;
            }
        }

        $generatedReason = sprintf(
            'Corrección del movimiento #%d: %s',
            $movement_id,
            $reason !== '' ? $reason : 'Captura incorrecta'
        );

        if (mb_strlen($generatedReason) > 255) {
            return new WP_Error(
                'mx_pos_reason_too_long',
                __('Correction reason must not exceed 255 characters.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $movement = $this->movementRepo->create([
            'session_id'    => $sessionId,
            'branch_id'     => isset($sessionResult['session']['branch_id']) ? (int) $sessionResult['session']['branch_id'] : null,
            'pos_register_id' => isset($sessionResult['session']['pos_register_id']) ? (int) $sessionResult['session']['pos_register_id'] : null,
            'pos_employee_id' => isset($sessionResult['session']['pos_employee_id']) ? (int) $sessionResult['session']['pos_employee_id'] : null,
            'movement_type' => $reverseType,
            'amount'        => $this->formatDecimal($amountValue),
            'reason'        => $generatedReason,
            'created_by'    => $user_id,
        ]);

        AuditLogger::log('cash_movement_reversed', [
            'entity_type'      => 'cash_movement',
            'entity_id'        => (int) $movement['id'],
            'cash_session_id'  => $sessionId,
            'severity'         => 'warn',
            'message'          => sprintf(
                __('Movimiento #%d corregido.', 'mx-pos-pro'),
                $movement_id
            ),
            'metadata'         => [
                'reversal_id'         => (int) $movement['id'],
                'original_movement_id' => $movement_id,
                'original_type'       => $original['movement_type'],
                'original_amount'     => $this->formatDecimal($amountValue),
                'reversal_type'       => $reverseType,
                'reason'              => $reason !== '' ? $reason : null,
                'session_id'          => $sessionId,
            ],
        ]);

        return [
            'movement' => $this->map_movement($movement),
            'totals'   => $this->totals_for_session($sessionResult['session']),
        ];
    }

    private function totals_for_session(array $session): array
    {
        $rawTotals = $this->movementRepo->totals_by_session((int) $session['id']);
        $classifiedTotals = $this->movementRepo->classified_totals_by_session((int) $session['id']);

        $totals = array_merge($classifiedTotals, [
            'cash_in'  => $rawTotals['cash_in'],
            'cash_out' => $rawTotals['cash_out'],
            'net'      => $rawTotals['net'],
        ]);

        $totals['current_cash'] = $this->formatDecimal(
            (float) $session['opening_amount'] + (float) $totals['net']
        );

        $saleRepo = new \MXPOSPro\Sales\SaleRepository();
        $salesTotals = $saleRepo->get_totals_by_session((int) $session['id']);
        $totals['gross_sales'] = $salesTotals['gross_sales'];
        $totals['card_sales'] = $salesTotals['card_sales'];

        $refundRepo = new \MXPOSPro\Sales\RefundRepository();
        $refundTotals = $refundRepo->get_totals_by_session((int) $session['id']);
        $totals['refund_total'] = $refundTotals['refund_total'];

        return $totals;
    }

    public function can_withdraw(int $sessionId, float $amount): bool|WP_Error
    {
        if ($sessionId <= 0) {
            return new WP_Error(
                'mx_pos_invalid_session',
                __('Invalid session.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if ($amount <= 0) {
            return new WP_Error(
                'mx_pos_invalid_amount',
                __('Amount must be a positive number.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $session = $this->sessionService->get_session_by_id($sessionId);

        if ($session === null) {
            return new WP_Error(
                'mx_pos_session_not_found',
                __('Session not found.', 'mx-pos-pro'),
                ['status' => 404]
            );
        }

        $totals = $this->movementRepo->totals_by_session($sessionId);
        $currentCash = (float) $session['opening_amount'] + (float) $totals['net'];

        if ($amount > $currentCash + 0.00001) {
            return new WP_Error(
                'mx_pos_insufficient_cash',
                __('Cash out amount exceeds the current cash balance.', 'mx-pos-pro'),
                [
                    'status'       => 409,
                    'current_cash' => $this->formatDecimal($currentCash),
                ]
            );
        }

        return true;
    }

    private function ensure_sufficient_cash(array $session, float $amount): bool|WP_Error
    {
        $totals = $this->totals_for_session($session);
        $currentCash = (float) $totals['current_cash'];

        if ($amount > $currentCash + 0.00001) {
            return new WP_Error(
                'mx_pos_insufficient_cash',
                __('Cash out amount exceeds the current cash balance.', 'mx-pos-pro'),
                [
                    'status'       => 409,
                    'current_cash' => $totals['current_cash'],
                ]
            );
        }

        return true;
    }

    private function map_movements(array $rows): array
    {
        return array_map([$this, 'map_movement'], $rows);
    }

    private function map_movement(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'session_id'    => (int) $row['session_id'],
            'movement_type' => $row['movement_type'],
            'amount'        => $this->formatDecimal((float) $row['amount']),
            'reason'        => $row['reason'] ?? null,
            'created_at'    => $row['created_at'],
            'created_by'    => (int) $row['created_by'],
        ];
    }

    private function formatDecimal(float $value): string
    {
        return number_format($value, 4, '.', '');
    }
}
