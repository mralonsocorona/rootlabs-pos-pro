<?php

namespace MXPOSPro\API;

defined('ABSPATH') || exit;

use MXPOSPro\Sales\SaleHistoryRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

class SaleHistoryController
{
    private SaleHistoryRepository $repository;

    public function __construct()
    {
        $this->repository = new SaleHistoryRepository();
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route('mx-pos/v1', '/sales/history/cashiers', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'cashiers'],
            'permission_callback' => [$this, 'permission_check'],
        ]);

        register_rest_route('mx-pos/v1', '/sales/history', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'history'],
            'permission_callback' => [$this, 'permission_check'],
            'args'                => $this->get_history_args(),
        ]);

        register_rest_route('mx-pos/v1', '/sales/lookup', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'lookup'],
            'permission_callback' => [$this, 'permission_check'],
            'args'                => [
                'query' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ($value) {
                        return is_string($value) && trim($value) !== '';
                    },
                ],
            ],
        ]);

        register_rest_route('mx-pos/v1', '/sales/(?P<id>\d+)/payment-method', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'update_payment_method'],
            'permission_callback' => [$this, 'permission_check'],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function ($value) {
                        return absint($value) > 0;
                    },
                ],
                'payment_method' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ($value) {
                        return in_array((string) $value, ['cash', 'card'], true);
                    },
                ],
            ],
        ]);



        register_rest_route('mx-pos/v1', '/sales/(?P<id>\d+)/detail', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'detail'],
            'permission_callback' => [$this, 'permission_check'],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && (int) $value > 0;
                    },
                ],
            ],
        ]);
    }

    private function get_history_args(): array
    {
        return [
            'page' => [
                'required'          => false,
                'type'              => 'integer',
                'default'           => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => function ($value) {
                    return (int) $value >= 1;
                },
            ],
            'per_page' => [
                'required'          => false,
                'type'              => 'integer',
                'default'           => 20,
                'sanitize_callback' => 'absint',
                'validate_callback' => function ($value) {
                    $v = (int) $value;
                    return $v >= 1 && $v <= 100;
                },
            ],
            'date_from' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function ($value) {
                    if ($value === '' || $value === null) {
                        return true;
                    }

                    return (bool) \DateTime::createFromFormat('Y-m-d', $value);
                },
            ],
            'date_to' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function ($value) {
                    if ($value === '' || $value === null) {
                        return true;
                    }

                    return (bool) \DateTime::createFromFormat('Y-m-d', $value);
                },
            ],
            'status' => [
                'required'          => false,
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function ($value) {
                    if ($value === '' || $value === null) {
                        return true;
                    }

                    $allowed = ['pending', 'completed', 'partially_refunded', 'cancelled', 'refunded'];

                    return in_array($value, $allowed, true);
                },
            ],
            'cashier_id' => [
                'required'          => false,
                'type'              => ['integer', 'null'],
                'sanitize_callback' => function ($value) {
                    if ($value === null || $value === '' || (int) $value <= 0) {
                        return null;
                    }

                    return absint($value);
                },
            ],
            'search' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'session_id' => [
                'required'          => false,
                'type'              => ['integer', 'null'],
                'sanitize_callback' => function ($value) {
                    if ($value === null || $value === '' || (int) $value <= 0) {
                        return null;
                    }

                    return absint($value);
                },
            ],
        ];
    }

    public function history(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $userId   = get_current_user_id();
        $page     = (int) ($request->get_param('page') ?: 1);
        $perPage  = (int) ($request->get_param('per_page') ?: 20);
        $dateFrom = $request->get_param('date_from');
        $dateTo   = $request->get_param('date_to');
        $status   = $request->get_param('status');
        $cashierId = $request->get_param('cashier_id');
        $search   = $request->get_param('search');
        $sessionId = $request->get_param('session_id');

        if (! empty($dateFrom) && ! empty($dateTo) && $dateFrom > $dateTo) {
            return new WP_Error(
                'mx_pos_invalid_date_range',
                __('date_from must not be later than date_to.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $filters = array_filter([
            'date_from'  => $dateFrom !== null && $dateFrom !== '' ? $dateFrom : null,
            'date_to'    => $dateTo !== null && $dateTo !== '' ? $dateTo : null,
            'status'     => $status !== null && $status !== '' ? $status : null,
            'cashier_id' => $cashierId !== null ? $cashierId : null,
            'search'     => $search !== null && $search !== '' ? $search : null,
            'session_id' => $sessionId !== null ? $sessionId : null,
        ], function ($value) {
            return $value !== null;
        });

        $result = $this->repository->query_paginated($userId, $filters, $page, $perPage);

        return rest_ensure_response([
            'items'      => $result['items'],
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $result['total'],
                'total_pages' => max(1, (int) ceil($result['total'] / $perPage)),
            ],
        ]);
    }

    public function detail(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $userId  = get_current_user_id();
        $saleId  = (int) $request->get_param('id');

        $detail = $this->repository->get_detail($saleId, $userId);

        if ($detail === null) {
            return new WP_Error(
                'mx_pos_sale_not_found',
                __('Sale not found.', 'mx-pos-pro'),
                ['status' => 404]
            );
        }

        return rest_ensure_response($detail);
    }

    public function cashiers(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $cashiers = $this->repository->get_distinct_cashiers();

        return rest_ensure_response(['cashiers' => $cashiers]);
    }

    public function lookup(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $userId = get_current_user_id();
        $query  = (string) $request->get_param('query');

        $result = $this->repository->lookup_by_query($userId, $query);

        return rest_ensure_response(['items' => $result]);
    }

    public function update_payment_method(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        global $wpdb;

        $requestedId = absint($request->get_param('id'));
        $newSlug     = sanitize_text_field((string) $request->get_param('payment_method'));

        if ($requestedId <= 0 || ! in_array($newSlug, ['cash', 'card'], true)) {
            return new \WP_Error(
                'mx_pos_invalid_payment_method_change',
                __('Invalid sale or payment method.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if (! function_exists('wc_get_order')) {
            return new \WP_Error(
                'mx_pos_woocommerce_unavailable',
                __('WooCommerce is not available.', 'mx-pos-pro'),
                ['status' => 500]
            );
        }

        $salesTable         = $wpdb->prefix . 'mx_pos_sales';
        $paymentsTable      = $wpdb->prefix . 'mx_pos_order_payments';
        $methodsTable       = $wpdb->prefix . 'mx_pos_payment_methods';
        $cashMovementsTable = $wpdb->prefix . 'mx_pos_cash_movements';

        $sale = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$salesTable}`
                 WHERE id = %d OR wc_order_id = %d
                 ORDER BY CASE WHEN id = %d THEN 0 ELSE 1 END
                 LIMIT 1",
                $requestedId,
                $requestedId,
                $requestedId
            ),
            ARRAY_A
        );

        if (! is_array($sale)) {
            return new \WP_Error(
                'mx_pos_sale_not_found',
                __('Sale not found.', 'mx-pos-pro'),
                ['status' => 404]
            );
        }

        $saleId  = (int) $sale['id'];
        $orderId = (int) $sale['wc_order_id'];

        if ((string) ($sale['status'] ?? '') !== 'completed' || (float) ($sale['refunded_total'] ?? 0) > 0) {
            return new \WP_Error(
                'mx_pos_payment_method_change_not_allowed',
                __('Only completed, non-refunded POS sales can be corrected.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $order = wc_get_order($orderId);

        if (! $order instanceof \WC_Order) {
            return new \WP_Error(
                'mx_pos_order_not_found',
                __('WooCommerce order not found.', 'mx-pos-pro'),
                ['status' => 404]
            );
        }

        if (in_array($order->get_status(), ['cancelled', 'refunded', 'trash', 'failed'], true)) {
            return new \WP_Error(
                'mx_pos_payment_method_change_order_status',
                __('This order status cannot be corrected from POS.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $newMethod = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$methodsTable}`
                 WHERE slug = %s AND is_active = 1
                 LIMIT 1",
                $newSlug
            ),
            ARRAY_A
        );

        if (! is_array($newMethod)) {
            return new \WP_Error(
                'mx_pos_payment_method_not_found',
                __('Payment method is not active.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $paymentRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT op.*, pm.slug, pm.name, pm.payment_type, pm.affects_cash_register
                 FROM `{$paymentsTable}` op
                 LEFT JOIN `{$methodsTable}` pm ON pm.id = op.payment_method_id
                 WHERE op.sale_id = %d AND op.status = 'completed'
                 ORDER BY op.id ASC",
                $saleId
            ),
            ARRAY_A
        );

        if (count($paymentRows) !== 1) {
            return new \WP_Error(
                'mx_pos_payment_method_change_mixed_not_supported',
                __('Only single-method POS payments can be corrected.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $paymentRow = $paymentRows[0];

        $oldSlug = (string) ($paymentRow['slug'] ?? '');
        if ($oldSlug === '') {
            $oldSlug = (string) $order->get_payment_method();
        }

        if (! in_array($oldSlug, ['cash', 'card'], true)) {
            return new \WP_Error(
                'mx_pos_payment_method_change_unsupported_old_method',
                __('Only cash/card corrections are supported.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $oldTitle = (string) ($paymentRow['name'] ?? '');
        if ($oldTitle === '') {
            $oldTitle = (string) $order->get_payment_method_title();
        }

        $newTitle       = (string) $newMethod['name'];
        $newAffectsCash = (bool) ((int) ($newMethod['affects_cash_register'] ?? 0));
        $orderTotal     = $this->mx_pos_format_decimal((float) $order->get_total());

        $clientRequestId = (string) ($sale['client_request_id'] ?? '');
        if ($clientRequestId === '') {
            $clientRequestId = (string) $order->get_meta('_mx_pos_client_request_id', true);
        }

        if ($clientRequestId === '') {
            return new \WP_Error(
                'mx_pos_missing_client_request_id',
                __('Sale has no client request id, so automatic cash movement cannot be corrected safely.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if ($oldSlug === $newSlug) {
            return rest_ensure_response([
                'sale_id'              => $saleId,
                'wc_order_id'          => $orderId,
                'payment_method'       => $newSlug,
                'payment_method_label' => $newTitle,
                'changed'              => false,
                'message'              => __('Payment method already matches.', 'mx-pos-pro'),
            ]);
        }

        $now = current_time('mysql');

        $wpdb->query('START TRANSACTION');

        try {
            $order->set_payment_method($newSlug);
            $order->set_payment_method_title($newTitle);
            $order->update_meta_data('_mx_pos_payment_method', $newSlug);
            $order->update_meta_data('_mx_pos_payment_method_title', $newTitle);
            $order->update_meta_data('_mx_pos_payment_completed', 'yes');
            $order->update_meta_data('metodo_pago', $newSlug);
            $order->update_meta_data('metodo_pago_nombre', $newTitle);
            $order->save();

            $paymentUpdated = $wpdb->update(
                $paymentsTable,
                [
                    'payment_method_id' => (int) $newMethod['id'],
                    'amount'            => $orderTotal,
                    'tendered_amount'   => $newAffectsCash ? $orderTotal : null,
                    'change_amount'     => '0.0000',
                    'card_reference'    => null,
                    'updated_at'        => $now,
                ],
                ['id' => (int) $paymentRow['id']],
                ['%d', '%s', '%s', '%s', '%s', '%s'],
                ['%d']
            );

            if ($paymentUpdated === false) {
                throw new \RuntimeException('Failed to update mx_pos_order_payments: ' . $wpdb->last_error);
            }

            $summary = $this->mx_pos_decode_json_array($sale['payment_summary'] ?? null);
            $summary['payment'] = array_merge(
                is_array($summary['payment'] ?? null) ? $summary['payment'] : [],
                [
                    'method'             => $newSlug,
                    'method_name'        => $newTitle,
                    'total_due'          => $orderTotal,
                    'total_paid'         => $orderTotal,
                    'change'             => '0.0000',
                    'payment_lines'      => [
                        [
                            'method'                => $newSlug,
                            'method_name'           => $newTitle,
                            'amount'                => $orderTotal,
                            'reference'             => null,
                            'card_fee'              => null,
                            'affects_cash_register' => $newAffectsCash,
                        ],
                    ],
                    'card_fee_total'     => '0.0000',
                    'transaction_id'     => (string) $order->get_transaction_id(),
                    'corrected_at'       => $now,
                    'corrected_from'     => [
                        'method'      => $oldSlug,
                        'method_name' => $oldTitle,
                    ],
                ]
            );

            $saleUpdated = $wpdb->update(
                $salesTable,
                ['payment_summary' => wp_json_encode($summary)],
                ['id' => $saleId],
                ['%s'],
                ['%d']
            );

            if ($saleUpdated === false) {
                throw new \RuntimeException('Failed to update mx_pos_sales.payment_summary: ' . $wpdb->last_error);
            }

            $cashAction = $this->mx_pos_sync_payment_cash_movement(
                $cashMovementsTable,
                $sale,
                $order,
                $clientRequestId,
                $orderTotal,
                $newAffectsCash,
                $newTitle
            );

            $wpdb->query('COMMIT');

            if (function_exists('wc_delete_shop_order_transients')) {
                wc_delete_shop_order_transients($orderId);
            }

            if (class_exists('\\MXPOSPro\\Audit\\AuditLogger')) {
                \MXPOSPro\Audit\AuditLogger::log('sale_payment_method_changed', [
                    'entity_type'     => 'sale',
                    'entity_id'       => $saleId,
                    'sale_id'         => $saleId,
                    'cash_session_id' => (int) ($sale['session_id'] ?? 0),
                    'metadata'        => [
                        'wc_order_id'      => $orderId,
                        'old_method'       => $oldSlug,
                        'old_method_title' => $oldTitle,
                        'new_method'       => $newSlug,
                        'new_method_title' => $newTitle,
                        'cash_action'      => $cashAction,
                    ],
                ]);
            }

            return rest_ensure_response([
                'sale_id'              => $saleId,
                'wc_order_id'          => $orderId,
                'payment_method'       => $newSlug,
                'payment_method_label' => $newTitle,
                'old_payment_method'   => $oldSlug,
                'old_payment_label'    => $oldTitle,
                'cash_action'          => $cashAction,
                'changed'              => true,
                'message'              => __('Payment method corrected.', 'mx-pos-pro'),
            ]);
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');

            return new \WP_Error(
                'mx_pos_payment_method_change_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    private function mx_pos_sync_payment_cash_movement(
        string $cashMovementsTable,
        array $sale,
        \WC_Order $order,
        string $clientRequestId,
        string $orderTotal,
        bool $newAffectsCash,
        string $newTitle
    ): string {
        global $wpdb;

        $movementCrid = $clientRequestId . ':cash-movement:0';
        $movement     = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$cashMovementsTable}` WHERE client_request_id = %s LIMIT 1",
                $movementCrid
            ),
            ARRAY_A
        );

        $orderNumber = (string) $order->get_order_number();
        $now         = current_time('mysql');

        if ($newAffectsCash) {
            $reason = sprintf('Venta POS — Orden #%s', $orderNumber);

            if (is_array($movement)) {
                $result = $wpdb->update(
                    $cashMovementsTable,
                    [
                        'movement_type' => 'cash_in',
                        'amount'        => $orderTotal,
                        'reason'        => $reason,
                    ],
                    ['id' => (int) $movement['id']],
                    ['%s', '%s', '%s'],
                    ['%d']
                );

                if ($result === false) {
                    throw new \RuntimeException('Failed to restore automatic cash movement: ' . $wpdb->last_error);
                }

                return 'cash_in_restored';
            }

            $result = $wpdb->insert(
                $cashMovementsTable,
                [
                    'session_id'        => (int) $sale['session_id'],
                    'branch_id'         => isset($sale['branch_id']) ? $sale['branch_id'] : null,
                    'pos_register_id'   => isset($sale['pos_register_id']) ? $sale['pos_register_id'] : null,
                    'pos_employee_id'   => isset($sale['pos_employee_id']) ? $sale['pos_employee_id'] : null,
                    'movement_type'     => 'cash_in',
                    'amount'            => $orderTotal,
                    'reason'            => $reason,
                    'created_by'        => get_current_user_id(),
                    'client_request_id' => $movementCrid,
                    'created_at'        => $now,
                ],
                ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s']
            );

            if ($result === false) {
                throw new \RuntimeException('Failed to create automatic cash movement: ' . $wpdb->last_error);
            }

            return 'cash_in_created';
        }

        if (is_array($movement)) {
            $reason = sprintf(
                'Venta POS — Orden #%s corregida a %s; sin efectivo en caja',
                $orderNumber,
                $newTitle
            );

            $result = $wpdb->update(
                $cashMovementsTable,
                [
                    'movement_type' => 'cash_in',
                    'amount'        => '0.0000',
                    'reason'        => $reason,
                ],
                ['id' => (int) $movement['id']],
                ['%s', '%s', '%s'],
                ['%d']
            );

            if ($result === false) {
                throw new \RuntimeException('Failed to neutralize automatic cash movement: ' . $wpdb->last_error);
            }

            return 'cash_in_neutralized';
        }

        return 'no_cash_movement_needed';
    }

    private function mx_pos_decode_json_array(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function mx_pos_format_decimal(float $value): string
    {
        return number_format($value, 4, '.', '');
    }



    public function permission_check(): bool
    {
        return current_user_can('mx_pos_access');
    }
}
