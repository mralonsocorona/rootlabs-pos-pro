<?php
/**
 * POS payment method correction endpoint.
 *
 * Keeps WooCommerce order payment meta, POS sale summary, cash movement
 * and audit logs in sync when correcting completed POS sales.
 */
if (! defined('ABSPATH')) {
    return;
}

add_action('rest_api_init', function (): void {
    register_rest_route('mx-pos/v1', '/sales/(?P<id>\d+)/payment-method', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'mx_pos_pro_payment_correction_update_sale_payment_method',
        'permission_callback' => 'mx_pos_pro_payment_correction_payment_method_permission',
        'args'                => [
            'id' => [
                'required'          => true,
                'sanitize_callback' => 'absint',
                'validate_callback' => static function ($value): bool {
                    return absint($value) > 0;
                },
            ],
            'payment_method' => [
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => static function ($value): bool {
                    return in_array((string) $value, ['cash', 'card'], true);
                },
            ],
        ],
    ]);
});

function mx_pos_pro_payment_correction_payment_method_permission(WP_REST_Request $request): bool
{
    if (current_user_can('mx_pos_sell') || current_user_can('mx_pos_manage_cash') || current_user_can('manage_woocommerce')) {
        return true;
    }

    if (
        class_exists('\MXPOSPro\Auth\POSAuthService')
        && class_exists('\MXPOSPro\Entities\EmployeeRepository')
    ) {
        try {
            $auth = new \MXPOSPro\Auth\POSAuthService(new \MXPOSPro\Entities\EmployeeRepository());
            $employee = $auth->get_current_employee();

            return is_array($employee)
                && ! empty($employee['id'])
                && (! isset($employee['is_active']) || (int) $employee['is_active'] === 1);
        } catch (\Throwable $e) {
            return false;
        }
    }

    return false;
}

function mx_pos_pro_payment_correction_update_sale_payment_method(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    global $wpdb;

    $requestedId = absint($request->get_param('id'));
    $newSlug     = sanitize_text_field((string) $request->get_param('payment_method'));

    if ($requestedId <= 0 || ! in_array($newSlug, ['cash', 'card'], true)) {
        return new WP_Error(
            'mx_pos_invalid_payment_method_change',
            'Venta o método de pago inválido.',
            ['status' => 400]
        );
    }

    if (! function_exists('wc_get_order')) {
        return new WP_Error(
            'mx_pos_woocommerce_unavailable',
            'WooCommerce no está disponible.',
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
        return new WP_Error(
            'mx_pos_sale_not_found',
            'Venta POS no encontrada.',
            ['status' => 404]
        );
    }

    $saleId  = (int) $sale['id'];
    $orderId = (int) $sale['wc_order_id'];

    if ((string) ($sale['status'] ?? '') !== 'completed' || (float) ($sale['refunded_total'] ?? 0) > 0) {
        return new WP_Error(
            'mx_pos_payment_method_change_not_allowed',
            'Solo se pueden corregir ventas completadas y sin devoluciones.',
            ['status' => 400]
        );
    }

    $order = wc_get_order($orderId);

    if (! $order instanceof WC_Order) {
        return new WP_Error(
            'mx_pos_order_not_found',
            'Orden WooCommerce no encontrada.',
            ['status' => 404]
        );
    }

    if (in_array($order->get_status(), ['cancelled', 'refunded', 'trash', 'failed'], true)) {
        return new WP_Error(
            'mx_pos_payment_method_change_order_status',
            'Esta orden no puede corregirse por su estado actual.',
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
        return new WP_Error(
            'mx_pos_payment_method_not_found',
            'El método de pago no está activo.',
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
        return new WP_Error(
            'mx_pos_payment_method_change_mixed_not_supported',
            'Esta corrección solo corrige ventas con un único método de pago.',
            ['status' => 400]
        );
    }

    $paymentRow = $paymentRows[0];
    $oldSlug    = (string) ($paymentRow['slug'] ?? '');

    if ($oldSlug === '') {
        $oldSlug = (string) $order->get_payment_method();
    }

    if (! in_array($oldSlug, ['cash', 'card'], true)) {
        return new WP_Error(
            'mx_pos_payment_method_change_unsupported_old_method',
            'Esta corrección solo permite corregir entre efectivo y tarjeta.',
            ['status' => 400]
        );
    }

    $oldTitle = (string) ($paymentRow['name'] ?? '');
    if ($oldTitle === '') {
        $oldTitle = (string) $order->get_payment_method_title();
    }

    $newTitle       = (string) $newMethod['name'];
    $newAffectsCash = (bool) ((int) ($newMethod['affects_cash_register'] ?? 0));
    $orderTotal     = mx_pos_pro_payment_correction_format_decimal((float) $order->get_total());

    $clientRequestId = (string) ($sale['client_request_id'] ?? '');
    if ($clientRequestId === '') {
        $clientRequestId = (string) $order->get_meta('_mx_pos_client_request_id', true);
    }

    if ($clientRequestId === '') {
        return new WP_Error(
            'mx_pos_missing_client_request_id',
            'La venta no tiene client_request_id; no se puede corregir caja con seguridad.',
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
            'message'              => 'El método de pago ya coincide.',
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
            throw new RuntimeException('No se pudo actualizar mx_pos_order_payments: ' . $wpdb->last_error);
        }

        $summary = mx_pos_pro_payment_correction_decode_json_array($sale['payment_summary'] ?? null);
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
            throw new RuntimeException('No se pudo actualizar mx_pos_sales.payment_summary: ' . $wpdb->last_error);
        }

        $cashAction = mx_pos_pro_payment_correction_sync_cash_movement(
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

        mx_pos_pro_payment_correction_log_audit($sale, $orderId, $oldSlug, $oldTitle, $newSlug, $newTitle, $cashAction);

        return rest_ensure_response([
            'sale_id'              => $saleId,
            'wc_order_id'          => $orderId,
            'payment_method'       => $newSlug,
            'payment_method_label' => $newTitle,
            'old_payment_method'   => $oldSlug,
            'old_payment_label'    => $oldTitle,
            'cash_action'          => $cashAction,
            'changed'              => true,
            'message'              => 'Método de pago corregido.',
        ]);
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');

        return new WP_Error(
            'mx_pos_payment_method_change_failed',
            $e->getMessage(),
            ['status' => 500]
        );
    }
}

function mx_pos_pro_payment_correction_sync_cash_movement(
    string $cashMovementsTable,
    array $sale,
    WC_Order $order,
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
                throw new RuntimeException('No se pudo restaurar movimiento de caja: ' . $wpdb->last_error);
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
            throw new RuntimeException('No se pudo crear movimiento de caja: ' . $wpdb->last_error);
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
            throw new RuntimeException('No se pudo neutralizar movimiento de caja: ' . $wpdb->last_error);
        }

        return 'cash_in_neutralized';
    }

    return 'no_cash_movement_needed';
}

function mx_pos_pro_payment_correction_log_audit(
    array $sale,
    int $orderId,
    string $oldSlug,
    string $oldTitle,
    string $newSlug,
    string $newTitle,
    string $cashAction
): void {
    global $wpdb;

    if (class_exists('\\MXPOSPro\\Audit\\AuditLogger')) {
        \MXPOSPro\Audit\AuditLogger::log('sale_payment_method_changed', [
            'entity_type'     => 'sale',
            'entity_id'       => (int) $sale['id'],
            'sale_id'         => (int) $sale['id'],
            'cash_session_id' => (int) ($sale['session_id'] ?? 0),
            'metadata'        => [
                'wc_order_id'      => $orderId,
                'old_method'       => $oldSlug,
                'old_method_title' => $oldTitle,
                'new_method'       => $newSlug,
                'new_method_title' => $newTitle,
                'cash_action'      => $cashAction,
                'source'           => 'mu_payment_method_correction',
            ],
        ]);
        return;
    }

    $table = $wpdb->prefix . 'mx_pos_audit_logs';

    $wpdb->insert(
        $table,
        [
            'actor_id'           => get_current_user_id() ?: null,
            'branch_id'          => isset($sale['branch_id']) ? $sale['branch_id'] : null,
            'pos_register_id'    => isset($sale['pos_register_id']) ? $sale['pos_register_id'] : null,
            'pos_employee_id'    => isset($sale['pos_employee_id']) ? $sale['pos_employee_id'] : null,
            'action'             => 'sale_payment_method_changed',
            'entity_type'        => 'sale',
            'entity_id'          => (int) $sale['id'],
            'ip_address'         => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) $_SERVER['REMOTE_ADDR']) : null,
            'user_agent'         => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field((string) $_SERVER['HTTP_USER_AGENT']) : null,
            'context_data'       => wp_json_encode([
                'sale_id'          => (int) $sale['id'],
                'cash_session_id'  => (int) ($sale['session_id'] ?? 0),
                'wc_order_id'      => $orderId,
                'old_method'       => $oldSlug,
                'old_method_title' => $oldTitle,
                'new_method'       => $newSlug,
                'new_method_title' => $newTitle,
                'cash_action'      => $cashAction,
                'source'           => 'mu_payment_method_correction',
            ]),
            'created_at'         => current_time('mysql'),
        ],
        ['%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
    );
}

function mx_pos_pro_payment_correction_decode_json_array(mixed $value): array
{
    if (! is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);

    return is_array($decoded) ? $decoded : [];
}

function mx_pos_pro_payment_correction_format_decimal(float $value): string
{
    return number_format($value, 4, '.', '');
}
