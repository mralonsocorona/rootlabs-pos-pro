<?php

namespace MXPOSPro\Sales;

defined('ABSPATH') || exit;

use WP_Error;

class RefundRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;

        $this->table = $wpdb->prefix . 'mx_pos_refunds';
    }

    public function create(array $data): array|WP_Error
    {
        global $wpdb;

        $insertData = [
            'sale_id'           => (int) $data['sale_id'],
            'wc_refund_id'      => (int) $data['wc_refund_id'],
            'session_id'        => (int) $data['session_id'],
            'branch_id'         => isset($data['branch_id']) && (int) $data['branch_id'] > 0 ? (int) $data['branch_id'] : null,
            'pos_register_id'   => isset($data['pos_register_id']) && (int) $data['pos_register_id'] > 0 ? (int) $data['pos_register_id'] : null,
            'pos_employee_id'   => isset($data['pos_employee_id']) && (int) $data['pos_employee_id'] > 0 ? (int) $data['pos_employee_id'] : null,
            'cashier_id'        => (int) $data['cashier_id'],
            'refund_type'       => $data['refund_type'],
            'refund_amount'     => $data['refund_amount'],
            'refund_method'     => isset($data['refund_method']) ? $data['refund_method'] : null,
            'items_data'        => isset($data['items_data']) ? $data['items_data'] : null,
            'reason'            => isset($data['reason']) ? $data['reason'] : null,
            'client_request_id' => isset($data['client_request_id']) ? $data['client_request_id'] : null,
            'created_at'        => current_time('mysql'),
        ];

        $formats = ['%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s'];

        $result = $wpdb->insert($this->table, $insertData, $formats);

        if ($result === false) {
            return new WP_Error(
                'mx_pos_refund_insert_failed',
                __('Failed to insert refund record.', 'mx-pos-pro'),
                [
                    'status' => 500,
                    'db_error' => $wpdb->last_error,
                ]
            );
        }

        return $this->get_by_id((int) $wpdb->insert_id);
    }

    public function validate_schema(): bool|WP_Error
    {
        global $wpdb;

        $tableExists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $this->table)
        );

        if ($tableExists !== $this->table) {
            return new WP_Error(
                'mx_pos_refunds_table_missing',
                __('The POS refunds table is missing. Run the plugin database migration before processing refunds.', 'mx-pos-pro'),
                ['status' => 500]
            );
        }

        $columns = $wpdb->get_col("DESC `{$this->table}`", 0);

        if (! is_array($columns)) {
            return new WP_Error(
                'mx_pos_refunds_schema_unreadable',
                __('The POS refunds table could not be inspected.', 'mx-pos-pro'),
                [
                    'status' => 500,
                    'db_error' => $wpdb->last_error,
                ]
            );
        }

        $requiredColumns = [
            'id',
            'sale_id',
            'wc_refund_id',
            'session_id',
            'cashier_id',
            'refund_type',
            'refund_amount',
            'refund_method',
            'items_data',
            'reason',
            'client_request_id',
            'created_at',
        ];

        $missingColumns = array_values(array_diff($requiredColumns, $columns));

        if (! empty($missingColumns)) {
            return new WP_Error(
                'mx_pos_refunds_schema_incomplete',
                sprintf(
                    /* translators: %s: comma-separated column names */
                    __('The POS refunds table is missing required columns: %s.', 'mx-pos-pro'),
                    implode(', ', $missingColumns)
                ),
                ['status' => 500]
            );
        }

        return true;
    }

    public function get_by_id(int $refund_id): ?array
    {
        global $wpdb;

        if ($refund_id <= 0) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$this->table}` WHERE id = %d LIMIT 1",
                $refund_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function get_by_sale_id(int $sale_id): array
    {
        global $wpdb;

        if ($sale_id <= 0) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$this->table}` WHERE sale_id = %d ORDER BY created_at DESC, id DESC",
                $sale_id
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public function find_by_client_request_id(string $client_request_id): ?array
    {
        global $wpdb;

        $client_request_id = trim($client_request_id);

        if ($client_request_id === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$this->table}` WHERE client_request_id = %s LIMIT 1",
                $client_request_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function get_totals_by_session(int $session_id): array
    {
        global $wpdb;

        $totals = [
            'refund_total'      => '0.0000',
            'count_refunds'     => 0,
            'count_cancellations' => 0,
            'cash_refunds'       => '0.0000',
            'card_refunds'       => '0.0000',
        ];

        if ($session_id <= 0) {
            return $totals;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT refund_type, refund_amount, refund_method
                 FROM `{$this->table}`
                 WHERE session_id = %d",
                $session_id
            ),
            ARRAY_A
        );

        if (! is_array($rows) || count($rows) === 0) {
            return $totals;
        }

        $refundSum    = 0.0;
        $refundCount  = 0;
        $cancelCount  = 0;
        $cashRefund   = 0.0;
        $cardRefund   = 0.0;

        foreach ($rows as $row) {
            $amount = (float) $row['refund_amount'];
            $type   = $row['refund_type'];
            $method = $row['refund_method'] ?? '';

            if ($type === 'total' && $amount <= 0.00001) {
                $cancelCount++;
                continue;
            }

            $refundSum += $amount;
            $refundCount++;

            if ($method === 'cash') {
                $cashRefund += $amount;
            } elseif ($method === 'card') {
                $cardRefund += $amount;
            }
        }

        $totals['refund_total']       = number_format($refundSum, 4, '.', '');
        $totals['count_refunds']      = $refundCount;
        $totals['count_cancellations'] = $cancelCount;
        $totals['cash_refunds']       = number_format($cashRefund, 4, '.', '');
        $totals['card_refunds']       = number_format($cardRefund, 4, '.', '');

        return $totals;
    }
}
