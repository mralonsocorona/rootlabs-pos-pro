<?php

namespace MXPOSPro\Sales;

defined('ABSPATH') || exit;

use WP_Error;

class SaleRepository
{
    private string $salesTable;
    private string $logsTable;

    public function __construct()
    {
        global $wpdb;

        $this->salesTable = $wpdb->prefix . 'mx_pos_sales';
        $this->logsTable  = $wpdb->prefix . 'mx_pos_sale_logs';
    }

    public function create(array $data): array|WP_Error
    {
        global $wpdb;

        $insertData = [
            'wc_order_id'      => (int) $data['wc_order_id'],
            'session_id'       => (int) $data['session_id'],
            'branch_id'        => isset($data['branch_id']) && (int) $data['branch_id'] > 0 ? (int) $data['branch_id'] : null,
            'pos_register_id'  => isset($data['pos_register_id']) && (int) $data['pos_register_id'] > 0 ? (int) $data['pos_register_id'] : null,
            'pos_employee_id'  => isset($data['pos_employee_id']) && (int) $data['pos_employee_id'] > 0 ? (int) $data['pos_employee_id'] : null,
            'cashier_id'       => (int) $data['cashier_id'],
            'total'            => $data['total'],
            'payment_summary'  => isset($data['payment_summary']) ? $data['payment_summary'] : null,
            'status'           => $data['status'],
            'client_request_id' => isset($data['client_request_id']) ? $data['client_request_id'] : null,
            'created_at'       => current_time('mysql'),
        ];

        $formats = ['%d', '%d', '%d', '%d', '%d', '%d', '%f', '%s', '%s', '%s', '%s'];

        $result = $wpdb->insert($this->salesTable, $insertData, $formats);

        if ($result === false) {
            if (
                isset($data['client_request_id']) &&
                $data['client_request_id'] !== '' &&
                $data['client_request_id'] !== null &&
                strpos($wpdb->last_error, 'Duplicate entry') !== false
            ) {
                $existing = $this->find_by_client_request_id($data['client_request_id']);

                if ($existing !== null) {
                    return $existing;
                }
            }

            error_log(
                sprintf(
                    '[MX POS Pro] Failed to insert sale record: %s',
                    $wpdb->last_error !== '' ? $wpdb->last_error : 'unknown database error'
                )
            );

            return new WP_Error(
                'mx_pos_sale_insert_failed',
                __('Failed to insert sale record.', 'mx-pos-pro'),
                [
                    'status'   => 500,
                    'db_error' => $wpdb->last_error,
                ]
            );
        }

        return $this->get_by_id((int) $wpdb->insert_id);
    }

    public function validate_checkout_schema(): bool|WP_Error
    {
        global $wpdb;

        $table_exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $this->salesTable)
        );

        if ($table_exists !== $this->salesTable) {
            return new WP_Error(
                'mx_pos_sales_table_missing',
                __('POS sales table is missing. Run database migrations before checkout.', 'mx-pos-pro'),
                ['status' => 500]
            );
        }

        $requiredColumns = [
            'wc_order_id',
            'session_id',
            'cashier_id',
            'total',
            'payment_summary',
            'status',
            'client_request_id',
            'created_at',
        ];

        $placeholders = implode(',', array_fill(0, count($requiredColumns), '%s'));
        $columns = $wpdb->get_col(
            $wpdb->prepare(
                "SHOW COLUMNS FROM `{$this->salesTable}` WHERE Field IN ({$placeholders})",
                ...$requiredColumns
            )
        );

        $missing = array_values(array_diff($requiredColumns, is_array($columns) ? $columns : []));

        if (count($missing) > 0) {
            error_log(
                sprintf(
                    '[MX POS Pro] POS sales table schema incomplete. Missing columns: %s',
                    implode(', ', $missing)
                )
            );

            return new WP_Error(
                'mx_pos_sales_schema_incomplete',
                __('POS sales table schema is incomplete. Run database migrations before checkout.', 'mx-pos-pro'),
                [
                    'status'          => 500,
                    'missing_columns' => $missing,
                ]
            );
        }

        return true;
    }

    public function get_by_id(int $sale_id): ?array
    {
        global $wpdb;

        if ($sale_id <= 0) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->salesTable} WHERE id = %d LIMIT 1",
                $sale_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function get_by_order_id(int $wc_order_id): ?array
    {
        global $wpdb;

        if ($wc_order_id <= 0) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->salesTable} WHERE wc_order_id = %d LIMIT 1",
                $wc_order_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function find_by_client_request_id(string $clientRequestId): ?array
    {
        global $wpdb;

        if ($clientRequestId === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->salesTable} WHERE client_request_id = %s LIMIT 1",
                $clientRequestId
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function update_payment_summary(int $sale_id, string $payment_summary): bool|WP_Error
    {
        global $wpdb;

        if ($sale_id <= 0) {
            return new WP_Error(
                'mx_pos_invalid_sale_id',
                __('Invalid sale ID.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $result = $wpdb->update(
            $this->salesTable,
            ['payment_summary' => $payment_summary],
            ['id' => $sale_id],
            ['%s'],
            ['%d']
        );

        if ($result === false) {
            return new WP_Error(
                'mx_pos_sale_update_failed',
                __('Failed to update sale payment summary.', 'mx-pos-pro'),
                ['status' => 500]
            );
        }

        return true;
    }

    public function update_status(int $sale_id, string $status): bool|WP_Error
    {
        global $wpdb;

        if ($sale_id <= 0) {
            return new WP_Error(
                'mx_pos_invalid_sale_id',
                __('Invalid sale ID.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $result = $wpdb->update(
            $this->salesTable,
            ['status' => $status],
            ['id' => $sale_id],
            ['%s'],
            ['%d']
        );

        if ($result === false) {
            return new WP_Error(
                'mx_pos_sale_update_failed',
                __('Failed to update sale status.', 'mx-pos-pro'),
                ['status' => 500]
            );
        }

        return true;
    }

    public function update_refunded_total(int $sale_id, string $refunded_total): bool|WP_Error
    {
        global $wpdb;

        if ($sale_id <= 0) {
            return new WP_Error(
                'mx_pos_invalid_sale_id',
                __('Invalid sale ID.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $result = $wpdb->update(
            $this->salesTable,
            ['refunded_total' => $refunded_total],
            ['id' => $sale_id],
            ['%f'],
            ['%d']
        );

        if ($result === false) {
            return new WP_Error(
                'mx_pos_sale_update_failed',
                __('Failed to update sale refunded total.', 'mx-pos-pro'),
                ['status' => 500]
            );
        }

        return true;
    }

    public function get_totals_by_session(int $session_id): array
    {
        global $wpdb;

        $totals = [
            'gross_sales'      => '0.0000',
            'count_orders'     => 0,
            'cash_sales'       => '0.0000',
            'cash_sales_count' => 0,
            'card_sales'       => '0.0000',
            'card_sales_count' => 0,
            'discount_total'   => '0.0000',
            'coupon_total'     => '0.0000',
            'coupon_count'     => 0,
            'coupon_by_code'   => [],
            'by_method'        => [],
            'mixed_breakdown'  => [],
            'card_fees_total'  => '0.0000',
            'card_fees_count'  => 0,
        ];

        if ($session_id <= 0) {
            return $totals;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, total, payment_summary
                 FROM {$this->salesTable}
                 WHERE session_id = %d
                   AND status IN ('completed', 'processing')",
                $session_id
            ),
            ARRAY_A
        );

        if (! is_array($rows) || count($rows) === 0) {
            return $totals;
        }

        $gross     = 0.0;
        $cashSum   = 0.0;
        $cashCount = 0;
        $cardSum   = 0.0;
        $cardCount = 0;
        $discSum   = 0.0;
        $coupSum   = 0.0;
        $coupCount = 0;
        $coupByCode = [];
        $byMethod  = [];
        $mixedCash = 0.0;
        $mixedCashCount = 0;
        $mixedCard = 0.0;
        $mixedCardCount = 0;
        $feeSum    = 0.0;
        $feeCount  = 0;
        $count     = 0;

        foreach ($rows as $row) {
            $count++;
            $total = (float) $row['total'];
            $gross += $total;

            $summary = $this->decode_payment_summary($row['payment_summary'] ?? null);
            $payment = $summary['payment'] ?? [];
            $method  = $payment['method'] ?? '';
            $methodName = $payment['method_name'] ?? $method;

            if (! isset($byMethod[$method]) && $method !== '') {
                $byMethod[$method] = ['slug' => $method, 'name' => $methodName, 'total' => 0.0, 'count' => 0];
            }

            $hasLines = ! empty($payment['payment_lines']) && is_array($payment['payment_lines']);

            if ($hasLines) {
                $validLineCount = 0;

                foreach ($payment['payment_lines'] as $line) {
                    $lineMethod = $line['method'] ?? '';
                    $lineAmount = isset($line['amount']) ? (float) $line['amount'] : 0;

                    if ($lineMethod === '' || $lineAmount <= 0) {
                        continue;
                    }

                    if (! isset($byMethod[$lineMethod])) {
                        $lineName = $line['method_name'] ?? $lineMethod;
                        $byMethod[$lineMethod] = ['slug' => $lineMethod, 'name' => $lineName, 'total' => 0.0, 'count' => 0];
                    }

                    $byMethod[$lineMethod]['total'] += $lineAmount;
                    $byMethod[$lineMethod]['count']++;
                    $validLineCount++;

                    if ($lineMethod === 'cash') {
                        $cashSum += $lineAmount;
                        $cashCount++;

                        if ($method === 'mixed') {
                            $mixedCash += $lineAmount;
                            $mixedCashCount++;
                        }
                    } elseif ($lineMethod === 'card') {
                        $cardSum += $lineAmount;
                        $cardCount++;

                        if ($method === 'mixed') {
                            $mixedCard += $lineAmount;
                            $mixedCardCount++;
                        }
                    }
                }

                if ($validLineCount === 0 && $method !== '') {
                    if (! isset($byMethod[$method])) {
                        $byMethod[$method] = ['slug' => $method, 'name' => $methodName, 'total' => 0.0, 'count' => 0];
                    }

                    $byMethod[$method]['total'] += $total;
                    $byMethod[$method]['count']++;

                    if ($method === 'cash') {
                        $cashSum += $total;
                        $cashCount++;
                    } elseif ($method === 'card') {
                        $cardSum += $total;
                        $cardCount++;
                    }
                }
            } elseif ($method === 'cash') {
                $cashSum += $total;
                $cashCount++;
                if (isset($byMethod['cash'])) {
                    $byMethod['cash']['total'] += $total;
                    $byMethod['cash']['count']++;
                }
            } elseif ($method === 'card') {
                $cardSum += $total;
                $cardCount++;
                if (isset($byMethod['card'])) {
                    $byMethod['card']['total'] += $total;
                    $byMethod['card']['count']++;
                }
            } elseif ($method === 'mixed' && ! empty($payment['payment_lines'])) {
                if (isset($byMethod['mixed'])) {
                    $byMethod['mixed']['total'] += $total;
                    $byMethod['mixed']['count']++;
                }
                foreach ($payment['payment_lines'] as $line) {
                    $lineMethod = $line['method'] ?? '';
                    $lineAmount = isset($line['amount']) ? (float) $line['amount'] : 0;
                    if ($lineMethod === 'cash') {
                        $mixedCash += $lineAmount;
                        $mixedCashCount++;
                    } elseif ($lineMethod === 'card') {
                        $mixedCard += $lineAmount;
                        $mixedCardCount++;
                    }
                }
            } elseif ($method !== '' && $method !== 'mixed') {
                if (isset($byMethod[$method])) {
                    $byMethod[$method]['total'] += $total;
                    $byMethod[$method]['count']++;
                }
            }

            if (isset($summary['discount_total']) && is_numeric($summary['discount_total'])) {
                $discSum += (float) $summary['discount_total'];
            }

            if (isset($summary['coupon_total']) && is_numeric($summary['coupon_total'])) {
                $coupSum += (float) $summary['coupon_total'];
            }

            if (! empty($summary['coupon']['code'])) {
                $coupCount++;
                $code = $summary['coupon']['code'];
                $couponTotal = isset($summary['coupon_total']) && is_numeric($summary['coupon_total'])
                    ? (float) $summary['coupon_total']
                    : 0.0;
                if (! isset($coupByCode[$code])) {
                    $coupByCode[$code] = ['total' => 0.0, 'count' => 0];
                }
                $coupByCode[$code]['total'] += $couponTotal;
                $coupByCode[$code]['count']++;
            }

            $cardFeeTotal = isset($payment['card_fee_total']) && is_numeric($payment['card_fee_total'])
                ? (float) $payment['card_fee_total']
                : 0;
            if ($cardFeeTotal > 0) {
                $feeSum += $cardFeeTotal;
                $feeCount++;
            }
        }

        $totals['gross_sales']      = number_format($gross, 4, '.', '');
        $totals['count_orders']     = $count;
        $totals['cash_sales']       = number_format($cashSum, 4, '.', '');
        $totals['cash_sales_count'] = $cashCount;
        $totals['card_sales']       = number_format($cardSum, 4, '.', '');
        $totals['card_sales_count'] = $cardCount;
        $totals['discount_total']   = number_format($discSum, 4, '.', '');
        $totals['coupon_total']     = number_format($coupSum, 4, '.', '');
        $totals['coupon_count']     = $coupCount;
        $totals['coupon_by_code']   = $coupByCode;
        $totals['by_method']        = $byMethod;
        $totals['mixed_breakdown']  = [
            'cash' => ['total' => number_format($mixedCash, 4, '.', ''), 'count' => $mixedCashCount],
            'card' => ['total' => number_format($mixedCard, 4, '.', ''), 'count' => $mixedCardCount],
        ];
        $totals['card_fees_total']  = number_format($feeSum, 4, '.', '');
        $totals['card_fees_count']  = $feeCount;

        return $totals;
    }

    public function create_log(array $data): array|bool
    {
        global $wpdb;

        $insertData = [
            'sale_id'    => (int) $data['sale_id'],
            'event_type' => $data['event_type'],
            'message'    => isset($data['message']) ? $data['message'] : null,
            'created_by' => isset($data['created_by']) ? (int) $data['created_by'] : null,
            'created_at' => current_time('mysql'),
        ];

        $formats = ['%d', '%s', '%s', '%d', '%s'];

        $result = $wpdb->insert($this->logsTable, $insertData, $formats);

        if ($result === false) {
            return false;
        }

        $insertId = (int) $wpdb->insert_id;

        return [
            'id'         => $insertId,
            'sale_id'    => $insertData['sale_id'],
            'event_type' => $insertData['event_type'],
            'message'    => $insertData['message'],
            'created_by' => $insertData['created_by'],
            'created_at' => $insertData['created_at'],
        ];
    }

    private function decode_payment_summary(mixed $paymentSummary): array
    {
        if (! is_string($paymentSummary) || trim($paymentSummary) === '') {
            return [];
        }

        $decoded = json_decode($paymentSummary, true);

        return is_array($decoded) ? $decoded : [];
    }
}
