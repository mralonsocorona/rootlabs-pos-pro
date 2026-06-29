<?php

namespace MXPOSPro\Cash;

defined('ABSPATH') || exit;

class CashMovementRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;

        $this->table = $wpdb->prefix . 'mx_pos_cash_movements';
    }

    public function table_name(): string
    {
        return $this->table;
    }

    public function create(array $data): array
    {
        global $wpdb;

        $insert_data = [
            'session_id'         => (int) $data['session_id'],
            'branch_id'          => isset($data['branch_id']) && (int) $data['branch_id'] > 0 ? (int) $data['branch_id'] : null,
            'pos_register_id'    => isset($data['pos_register_id']) && (int) $data['pos_register_id'] > 0 ? (int) $data['pos_register_id'] : null,
            'pos_employee_id'    => isset($data['pos_employee_id']) && (int) $data['pos_employee_id'] > 0 ? (int) $data['pos_employee_id'] : null,
            'movement_type'      => (string) $data['movement_type'],
            'amount'             => $data['amount'],
            'reason'             => $data['reason'] ?? null,
            'created_by'         => (int) $data['created_by'],
            'client_request_id'  => $data['client_request_id'] ?? null,
            'created_at'         => current_time('mysql'),
        ];

        $formats = ['%d', '%d', '%d', '%d', '%s', '%f', '%s', '%d', '%s', '%s'];

        $wpdb->insert($this->table, $insert_data, $formats);

        return $this->get_by_id((int) $wpdb->insert_id);
    }

    public function list_by_session(int $session_id): array
    {
        global $wpdb;

        if ($session_id <= 0) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE session_id = %d
                 ORDER BY created_at DESC, id DESC",
                $session_id
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public function totals_by_session(int $session_id): array
    {
        global $wpdb;

        $totals = [
            'cash_in'  => '0.0000',
            'cash_out' => '0.0000',
            'net'      => '0.0000',
        ];

        if ($session_id <= 0) {
            return $totals;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT movement_type, SUM(amount) as total
                 FROM {$this->table}
                 WHERE session_id = %d
                 GROUP BY movement_type",
                $session_id
            ),
            OBJECT_K
        );

        if (! is_array($rows)) {
            return $totals;
        }

        $cashIn  = isset($rows['cash_in']) ? (float) $rows['cash_in']->total : 0.0;
        $cashOut = isset($rows['cash_out']) ? (float) $rows['cash_out']->total : 0.0;
        $net     = $cashIn - $cashOut;

        $totals['cash_in']  = number_format($cashIn, 4, '.', '');
        $totals['cash_out'] = number_format($cashOut, 4, '.', '');
        $totals['net']      = number_format($net, 4, '.', '');

        return $totals;
    }

    public function classified_totals_by_session(int $session_id): array
    {
        $totals = [
            'cash_in_total'           => '0.0000',
            'cash_out_total'          => '0.0000',
            'net_cash'                => '0.0000',
            'manual_cash_in_total'    => '0.0000',
            'manual_cash_in_count'    => 0,
            'manual_cash_out_total'   => '0.0000',
            'manual_cash_out_count'   => 0,
            'manual_net_cash'         => '0.0000',
            'sales_cash_in_total'     => '0.0000',
            'sales_cash_in_count'     => 0,
            'sales_change_out_total'  => '0.0000',
            'sales_change_out_count'  => 0,
        ];

        if ($session_id <= 0) {
            return $totals;
        }

        $rows = $this->list_by_session($session_id);

        $cashIn = 0.0;
        $cashOut = 0.0;
        $manualCashIn = 0.0;
        $manualCashOut = 0.0;
        $salesCashIn = 0.0;
        $salesChangeOut = 0.0;
        $manualCashInCount = 0;
        $manualCashOutCount = 0;
        $salesCashInCount = 0;
        $salesChangeOutCount = 0;

        foreach ($rows as $row) {
            $type = (string) ($row['movement_type'] ?? '');
            $amount = (float) ($row['amount'] ?? 0);

            if ($type === 'cash_in') {
                $cashIn += $amount;

                if ($this->is_automatic_sale_cash_in($row)) {
                    $salesCashIn += $amount;
                    $salesCashInCount++;
                } else {
                    $manualCashIn += $amount;
                    $manualCashInCount++;
                }
                continue;
            }

            if ($type === 'cash_out') {
                $cashOut += $amount;

                if ($this->is_automatic_sale_change_out($row)) {
                    $salesChangeOut += $amount;
                    $salesChangeOutCount++;
                } else {
                    $manualCashOut += $amount;
                    $manualCashOutCount++;
                }
            }
        }

        $totals['cash_in_total']          = number_format($cashIn, 4, '.', '');
        $totals['cash_out_total']         = number_format($cashOut, 4, '.', '');
        $totals['net_cash']               = number_format($cashIn - $cashOut, 4, '.', '');
        $totals['manual_cash_in_total']   = number_format($manualCashIn, 4, '.', '');
        $totals['manual_cash_in_count']   = $manualCashInCount;
        $totals['manual_cash_out_total']  = number_format($manualCashOut, 4, '.', '');
        $totals['manual_cash_out_count']  = $manualCashOutCount;
        $totals['manual_net_cash']        = number_format($manualCashIn - $manualCashOut, 4, '.', '');
        $totals['sales_cash_in_total']    = number_format($salesCashIn, 4, '.', '');
        $totals['sales_cash_in_count']    = $salesCashInCount;
        $totals['sales_change_out_total'] = number_format($salesChangeOut, 4, '.', '');
        $totals['sales_change_out_count'] = $salesChangeOutCount;

        return $totals;
    }

    private function is_automatic_sale_cash_in(array $row): bool
    {
        $clientRequestId = (string) ($row['client_request_id'] ?? '');
        $reason = (string) ($row['reason'] ?? '');

        return strpos($clientRequestId, ':cash-movement:') !== false
            || strpos($reason, 'Venta POS') === 0;
    }

    private function is_automatic_sale_change_out(array $row): bool
    {
        $clientRequestId = (string) ($row['client_request_id'] ?? '');
        $reason = (string) ($row['reason'] ?? '');

        return strpos($clientRequestId, ':cash-out:') !== false
            || strpos($reason, 'Cambio POS') === 0;
    }

    public function get_by_id(int $movement_id): ?array
    {
        global $wpdb;

        if ($movement_id <= 0) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
                $movement_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function has_reversal_for_movement(int $session_id, int $movement_id): bool
    {
        global $wpdb;

        if ($session_id <= 0 || $movement_id <= 0) {
            return false;
        }

        $prefix = sprintf('Corrección del movimiento #%d:', $movement_id);
        $like   = $wpdb->esc_like($prefix) . '%';

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$this->table}
                 WHERE session_id = %d
                   AND reason LIKE %s",
                $session_id,
                $like
            )
        );

        return $count > 0;
    }

    public function find_by_client_request_id(string $client_request_id): ?array
    {
        global $wpdb;

        if ($client_request_id === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE client_request_id = %s LIMIT 1",
                $client_request_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }
}
