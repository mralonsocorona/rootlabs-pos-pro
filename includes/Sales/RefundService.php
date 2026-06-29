<?php

namespace MXPOSPro\Sales;

defined('ABSPATH') || exit;

use MXPOSPro\Audit\AuditLogger;
use MXPOSPro\Cash\CashSessionService;
use MXPOSPro\Cash\CashMovementService;
use WP_Error;

class RefundService
{
    public const MAX_CLIENT_REQUEST_ID_LENGTH = 100;

    private SaleRepository $saleRepo;
    private RefundRepository $refundRepo;
    private CashSessionService $sessionService;
    private CashMovementService $cashMovementService;

    public function __construct(
        SaleRepository $saleRepo,
        RefundRepository $refundRepo,
        CashSessionService $sessionService,
        CashMovementService $cashMovementService
    ) {
        $this->saleRepo            = $saleRepo;
        $this->refundRepo          = $refundRepo;
        $this->sessionService      = $sessionService;
        $this->cashMovementService = $cashMovementService;
    }

    public function cancel(int $userId, int $saleId, string $reason, string $clientRequestId): array|WP_Error
    {
        if ($userId <= 0) {
            return new WP_Error(
                'mx_pos_invalid_user',
                __('Sesión no válida. Vuelve a iniciar sesión antes de procesar la devolución.', 'mx-pos-pro'),
                ['status' => 403]
            );
        }

        if (! current_user_can('mx_pos_refund')) {
            return new WP_Error(
                'mx_pos_refund_forbidden',
                __('You do not have permission to cancel orders.', 'mx-pos-pro'),
                ['status' => 403]
            );
        }

        $clientRequestId = trim($clientRequestId);

        if ($clientRequestId === '') {
            return new WP_Error(
                'mx_pos_missing_client_request_id',
                __('client_request_id is required.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if (mb_strlen($clientRequestId) > self::MAX_CLIENT_REQUEST_ID_LENGTH) {
            return new WP_Error(
                'mx_pos_invalid_client_request_id',
                sprintf(
                    /* translators: %d: max length */
                    __('client_request_id must not exceed %d characters.', 'mx-pos-pro'),
                    self::MAX_CLIENT_REQUEST_ID_LENGTH
                ),
                ['status' => 400]
            );
        }

        $existing = $this->refundRepo->find_by_client_request_id($clientRequestId);

        if ($existing !== null) {
            return $this->build_cancel_response($existing);
        }

        $sale = $this->saleRepo->get_by_id($saleId);

        if ($sale === null) {
            return new WP_Error(
                'mx_pos_sale_not_found',
                __('Sale not found.', 'mx-pos-pro'),
                ['status' => 404]
            );
        }

        if ($sale['status'] !== 'pending') {
            return new WP_Error(
                'mx_pos_sale_not_pending',
                __('Only pending orders can be cancelled.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $order = wc_get_order((int) $sale['wc_order_id']);

        if (! $order instanceof \WC_Order) {
            return new WP_Error(
                'mx_pos_order_not_found',
                __('Associated WooCommerce order not found.', 'mx-pos-pro'),
                ['status' => 404]
            );
        }

        if ($order->get_status() !== 'pending') {
            return new WP_Error(
                'mx_pos_sale_not_pending',
                __('Order is not in pending status and cannot be cancelled via POS.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $payment_completed = $order->get_meta('_mx_pos_payment_completed', true);

        if ($payment_completed === 'yes') {
            return new WP_Error(
                'mx_pos_sale_already_paid',
                __('This order has already been paid. Use refund instead of cancel.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $sessionResult = $this->sessionService->get_current_session($userId);

        if (! $sessionResult['has_open_session'] || $sessionResult['session'] === null) {
            return new WP_Error(
                'mx_pos_no_open_session',
                __('No open session found. Open a session before cancelling orders.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $session   = $sessionResult['session'];
        $sessionId = (int) $session['id'];

        $stockReduced = $order->get_meta('_order_stock_reduced', true);

        $order->update_status('cancelled');
        $order->add_order_note(
            sprintf(
                /* translators: %s: reason */
                $reason !== ''
                    ? __('POS: order cancelled — %s.', 'mx-pos-pro')
                    : __('POS: order cancelled.', 'mx-pos-pro'),
                $reason
            )
        );
        $order->save();

        if ($stockReduced === 'yes') {
            wc_increase_stock_levels($order->get_id());
        }

        $this->saleRepo->update_status($saleId, 'cancelled');

        $refundBranchId = isset($sale['branch_id']) && (int) $sale['branch_id'] > 0
            ? (int) $sale['branch_id']
            : (isset($session['branch_id']) && (int) $session['branch_id'] > 0 ? (int) $session['branch_id'] : null);
        $refundRegisterId = isset($sale['pos_register_id']) && (int) $sale['pos_register_id'] > 0
            ? (int) $sale['pos_register_id']
            : (isset($session['pos_register_id']) && (int) $session['pos_register_id'] > 0 ? (int) $session['pos_register_id'] : null);
        $refundEmployeeId = isset($sale['pos_employee_id']) && (int) $sale['pos_employee_id'] > 0
            ? (int) $sale['pos_employee_id']
            : (isset($session['pos_employee_id']) && (int) $session['pos_employee_id'] > 0 ? (int) $session['pos_employee_id'] : null);

        $refundData = $this->refundRepo->create([
            'sale_id'           => $saleId,
            'wc_refund_id'      => 0,
            'session_id'        => $sessionId,
            'branch_id'         => $refundBranchId,
            'pos_register_id'   => $refundRegisterId,
            'pos_employee_id'   => $refundEmployeeId,
            'cashier_id'        => $userId,
            'refund_type'       => 'total',
            'refund_amount'     => '0.0000',
            'refund_method'     => null,
            'items_data'        => null,
            'reason'            => $reason !== '' ? $reason : null,
            'client_request_id' => $clientRequestId,
        ]);

        if (is_wp_error($refundData)) {
            error_log(
                sprintf(
                    '[MX POS Pro] Failed to create refund record for cancelled sale %d: %s',
                    $saleId,
                    $refundData->get_error_message()
                )
            );
        }

        $this->saleRepo->create_log([
            'sale_id'    => $saleId,
            'event_type' => 'cancelled',
            'message'    => sprintf(
                __('Order #%s cancelled — %s.', 'mx-pos-pro'),
                $order->get_order_number(),
                $reason !== '' ? $reason : __('No reason provided', 'mx-pos-pro')
            ),
            'created_by' => $userId,
        ]);

        do_action('mx_pos_sale_cancelled', [
            'sale_id'      => $saleId,
            'order_id'     => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'cashier_id'   => $userId,
            'reason'       => $reason !== '' ? $reason : null,
        ]);

        AuditLogger::log('order_cancelled', [
            'entity_type'      => 'sale',
            'entity_id'        => $saleId,
            'sale_id'          => $saleId,
            'cash_session_id'  => $sessionId,
            'severity'         => 'warn',
            'message'          => sprintf(
                __('Orden #%s cancelada.', 'mx-pos-pro'),
                $order->get_order_number()
            ),
            'metadata'         => [
                'order_id'      => $order->get_id(),
                'order_number'  => (string) $order->get_order_number(),
                'reason'        => $reason !== '' ? $reason : null,
                'session_id'    => $sessionId,
            ],
        ]);

        return $this->build_cancel_response(is_array($refundData) ? $refundData : [
            'id'              => 0,
            'sale_id'         => $saleId,
            'wc_refund_id'    => 0,
            'refund_type'     => 'total',
            'refund_amount'   => '0.0000',
            'refund_method'   => null,
            'reason'          => $reason !== '' ? $reason : null,
            'created_at'      => current_time('mysql'),
        ]);
    }

    public function refund(int $userId, int $saleId, array $refundItems, string $refundMethod, string $reason, string $clientRequestId): array|WP_Error
    {
        if ($userId <= 0) {
            return new WP_Error(
                'mx_pos_invalid_user',
                __('Sesión no válida. Vuelve a iniciar sesión antes de procesar la devolución.', 'mx-pos-pro'),
                ['status' => 403]
            );
        }

        if (! current_user_can('mx_pos_refund')) {
            return new WP_Error(
                'mx_pos_refund_forbidden',
                __('You do not have permission to process refunds.', 'mx-pos-pro'),
                ['status' => 403]
            );
        }

        $clientRequestId = trim($clientRequestId);

        if ($clientRequestId === '') {
            return new WP_Error(
                'mx_pos_missing_client_request_id',
                __('client_request_id is required.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if (mb_strlen($clientRequestId) > self::MAX_CLIENT_REQUEST_ID_LENGTH) {
            return new WP_Error(
                'mx_pos_invalid_client_request_id',
                sprintf(
                    /* translators: %d: max length */
                    __('client_request_id must not exceed %d characters.', 'mx-pos-pro'),
                    self::MAX_CLIENT_REQUEST_ID_LENGTH
                ),
                ['status' => 400]
            );
        }

        if (! in_array($refundMethod, ['cash', 'card'], true)) {
            return new WP_Error(
                'mx_pos_invalid_refund_method',
                __('refund_method must be cash or card.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $existing = $this->refundRepo->find_by_client_request_id($clientRequestId);

        if ($existing !== null) {
            $sale = $this->saleRepo->get_by_id($saleId);

            return $this->build_refund_response($existing, $sale);
        }

        $sale = $this->saleRepo->get_by_id($saleId);

        if ($sale === null) {
            return new WP_Error(
                'mx_pos_sale_not_found',
                __('Sale not found.', 'mx-pos-pro'),
                ['status' => 404]
            );
        }

        if (! in_array($sale['status'], ['completed', 'processing'], true)) {
            return new WP_Error(
                'mx_pos_sale_not_completed',
                __('Only completed or processing orders can be refunded.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $order = wc_get_order((int) $sale['wc_order_id']);

        if (! $order instanceof \WC_Order) {
            return new WP_Error(
                'mx_pos_order_not_found',
                __('Associated WooCommerce order not found.', 'mx-pos-pro'),
                ['status' => 404]
            );
        }

        $sessionResult = $this->sessionService->get_current_session($userId);

        if (! $sessionResult['has_open_session'] || $sessionResult['session'] === null) {
            return new WP_Error(
                'mx_pos_no_open_session',
                __('No open session found. Open a session before processing refunds.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $session   = $sessionResult['session'];
        $sessionId = (int) $session['id'];

        $saleTotal       = (float) $sale['total'];
        $refundedTotal   = (float) ($sale['refunded_total'] ?? '0.0000');
        $remainingAmount = $saleTotal - $refundedTotal;

        if ($remainingAmount <= 0) {
            return new WP_Error(
                'mx_pos_sale_fully_refunded',
                __('This sale has already been fully refunded.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $lineItems  = [];
        $totalRefundAmount = 0.0;
        $itemsData  = [];
        $isTotal    = empty($refundItems);

        if ($isTotal) {
            foreach ($order->get_items('line_item') as $item) {
                if (! $item instanceof \WC_Order_Item_Product) {
                    continue;
                }

                $orderItemId     = $item->get_id();
                $originalQty     = (int) $item->get_quantity();
                $qtyRefunded     = $this->get_refunded_quantity($order, $orderItemId);
                $refundableQty   = $originalQty - $qtyRefunded;

                if ($refundableQty <= 0) {
                    continue;
                }

                $unitTotal       = $originalQty > 0
                    ? (float) $item->get_total() / $originalQty
                    : 0.0;
                $refundLineTotal = $unitTotal * $refundableQty;

                $unitTax         = $originalQty > 0
                    ? (float) $item->get_total_tax() / $originalQty
                    : 0.0;
                $refundTax       = $unitTax * $refundableQty;

                $lineItemData = [
                    'qty'          => $refundableQty,
                    'refund_total' => $this->formatDecimal($refundLineTotal),
                ];

                if ($refundTax > 0) {
                    $taxes = $item->get_taxes();
                    $lineItemData['refund_tax'] = $this->build_refund_taxes($taxes, $refundableQty, $originalQty);
                }

                $lineItems[$orderItemId] = $lineItemData;

                $totalRefundAmount += $refundLineTotal;

                $itemsData[] = [
                    'order_item_id' => $orderItemId,
                    'product_id'    => $item->get_product_id(),
                    'name'          => $item->get_name(),
                    'quantity'      => $refundableQty,
                    'refund_total'  => $this->formatDecimal($refundLineTotal),
                ];
            }

            if ($totalRefundAmount <= 0) {
                return new WP_Error(
                    'mx_pos_nothing_to_refund',
                    __('All items have already been refunded.', 'mx-pos-pro'),
                    ['status' => 409]
                );
            }
        } else {
            foreach ($refundItems as $ri) {
                if (! isset($ri['order_item_id']) || ! isset($ri['quantity'])) {
                    continue;
                }

                $orderItemId = (int) $ri['order_item_id'];
                $refundQty   = (int) $ri['quantity'];

                if ($orderItemId <= 0 || $refundQty <= 0) {
                    continue;
                }

                $item = $order->get_item($orderItemId);

                if (! $item instanceof \WC_Order_Item_Product) {
                    return new WP_Error(
                        'mx_pos_invalid_order_item',
                        sprintf(
                            /* translators: %d: order item ID */
                            __('Order item #%d not found in this order.', 'mx-pos-pro'),
                            $orderItemId
                        ),
                        ['status' => 400]
                    );
                }

                $originalQty   = (int) $item->get_quantity();
                $qtyRefunded   = $this->get_refunded_quantity($order, $orderItemId);
                $refundableQty = $originalQty - $qtyRefunded;

                if ($refundQty > $refundableQty) {
                    return new WP_Error(
                        'mx_pos_refund_exceeds_available',
                        sprintf(
                            /* translators: 1: refund qty, 2: refundable qty, 3: item name */
                            __('Cannot refund %1$d units of "%3$s". Only %2$d refundable.', 'mx-pos-pro'),
                            $refundQty,
                            $refundableQty,
                            $item->get_name()
                        ),
                        ['status' => 400]
                    );
                }

                $unitTotal       = $originalQty > 0
                    ? (float) $item->get_total() / $originalQty
                    : 0.0;
                $refundLineTotal = $unitTotal * $refundQty;

                $unitTax         = $originalQty > 0
                    ? (float) $item->get_total_tax() / $originalQty
                    : 0.0;
                $refundTax       = $unitTax * $refundQty;

                $lineItemData = [
                    'qty'          => $refundQty,
                    'refund_total' => $this->formatDecimal($refundLineTotal),
                ];

                if ($refundTax > 0) {
                    $taxes = $item->get_taxes();
                    $lineItemData['refund_tax'] = $this->build_refund_taxes($taxes, $refundQty, $originalQty);
                }

                $lineItems[$orderItemId] = $lineItemData;

                $totalRefundAmount += $refundLineTotal;

                $itemsData[] = [
                    'order_item_id' => $orderItemId,
                    'product_id'    => $item->get_product_id(),
                    'name'          => $item->get_name(),
                    'quantity'      => $refundQty,
                    'refund_total'  => $this->formatDecimal($refundLineTotal),
                ];
            }

            if (empty($lineItems)) {
                return new WP_Error(
                    'mx_pos_no_valid_refund_items',
                    __('No valid items to refund.', 'mx-pos-pro'),
                    ['status' => 400]
                );
            }
        }

        $schemaReady = $this->refundRepo->validate_schema();

        if (is_wp_error($schemaReady)) {
            error_log(
                sprintf(
                    '[MX POS Pro] Refund schema validation failed before creating WC refund for sale %d: %s',
                    $saleId,
                    $schemaReady->get_error_message()
                )
            );

            return $schemaReady;
        }

        $refundArgs = [
            'amount'         => $this->formatDecimal($totalRefundAmount),
            'reason'         => $reason !== '' ? $reason : __('POS refund', 'mx-pos-pro'),
            'order_id'       => $order->get_id(),
            'line_items'     => $lineItems,
            'refund_payment' => false,
            'restock_items'  => true,
        ];

        $wcRefund = wc_create_refund($refundArgs);

        if (is_wp_error($wcRefund)) {
            error_log(
                sprintf(
                    '[MX POS Pro] wc_create_refund failed for order %d: %s',
                    $order->get_id(),
                    $wcRefund->get_error_message()
                )
            );

            return new WP_Error(
                'mx_pos_refund_create_failed',
                __('No se pudo crear la devolución. Intenta nuevamente o contacta a un administrador.', 'mx-pos-pro'),
                ['status' => 500]
            );
        }

        $wcRefundId = $wcRefund->get_id();
        $newRefundedTotal = $refundedTotal + $totalRefundAmount;
        $fullRefundCompleted = $newRefundedTotal >= $saleTotal - 0.00001;

        $refundBranchId = isset($sale['branch_id']) && (int) $sale['branch_id'] > 0
            ? (int) $sale['branch_id']
            : (isset($session['branch_id']) && (int) $session['branch_id'] > 0 ? (int) $session['branch_id'] : null);
        $refundRegisterId = isset($sale['pos_register_id']) && (int) $sale['pos_register_id'] > 0
            ? (int) $sale['pos_register_id']
            : (isset($session['pos_register_id']) && (int) $session['pos_register_id'] > 0 ? (int) $session['pos_register_id'] : null);
        $refundEmployeeId = isset($sale['pos_employee_id']) && (int) $sale['pos_employee_id'] > 0
            ? (int) $sale['pos_employee_id']
            : (isset($session['pos_employee_id']) && (int) $session['pos_employee_id'] > 0 ? (int) $session['pos_employee_id'] : null);

        $refundData = $this->refundRepo->create([
            'sale_id'           => $saleId,
            'wc_refund_id'      => $wcRefundId,
            'session_id'        => $sessionId,
            'branch_id'         => $refundBranchId,
            'pos_register_id'   => $refundRegisterId,
            'pos_employee_id'   => $refundEmployeeId,
            'cashier_id'        => $userId,
            'refund_type'       => $fullRefundCompleted ? 'total' : 'partial',
            'refund_amount'     => $this->formatDecimal($totalRefundAmount),
            'refund_method'     => $refundMethod,
            'items_data'        => wp_json_encode($itemsData),
            'reason'            => $reason !== '' ? $reason : null,
            'client_request_id' => $clientRequestId,
        ]);

        if (is_wp_error($refundData)) {
            error_log(
                sprintf(
                    '[MX POS Pro] WC refund %d created but mx_pos_refunds insert failed for sale %d: %s',
                    $wcRefundId,
                    $saleId,
                    $refundData->get_error_message()
                )
            );

            return new WP_Error(
                'mx_pos_refund_pos_record_failed',
                __('La devolución fue creada en WooCommerce, pero no se pudo guardar el registro POS. Revisa el log antes de reintentar.', 'mx-pos-pro'),
                ['status' => 500]
            );
        }

        $newRefundedTotalStr = $this->formatDecimal($newRefundedTotal);

        $this->saleRepo->update_refunded_total($saleId, $newRefundedTotalStr);

        if ($refundMethod === 'cash' && $totalRefundAmount > 0) {
            $this->record_cash_out(
                $sessionId,
                $userId,
                (float) $totalRefundAmount,
                (string) $order->get_order_number(),
                $clientRequestId
            );
        }

        if ($fullRefundCompleted) {
            $this->cancel_order_after_full_refund($order, $reason);
            $this->saleRepo->update_status($saleId, 'cancelled');
        }

        $this->saleRepo->create_log([
            'sale_id'    => $saleId,
            'event_type' => $fullRefundCompleted ? 'refunded_total_cancelled' : 'refunded_partial',
            'message'    => sprintf(
                /* translators: 1: refund amount, 2: order number, 3: refund method */
                __('Refunded $%1$s (%3$s) — Order #%2$s.', 'mx-pos-pro'),
                $this->formatDecimal($totalRefundAmount),
                $order->get_order_number(),
                $refundMethod === 'cash' ? __('Efectivo', 'mx-pos-pro') : __('Tarjeta', 'mx-pos-pro')
            ),
            'created_by' => $userId,
        ]);

        if ($fullRefundCompleted) {
            $this->saleRepo->create_log([
                'sale_id'    => $saleId,
                'event_type' => 'cancelled',
                'message'    => sprintf(
                    __('Order #%s cancelled after full refund.', 'mx-pos-pro'),
                    $order->get_order_number()
                ),
                'created_by' => $userId,
            ]);
        }

        $sale = $this->saleRepo->get_by_id($saleId);

        do_action('mx_pos_sale_refunded', [
            'sale_id'       => $saleId,
            'order_id'      => $order->get_id(),
            'order_number'  => $order->get_order_number(),
            'cashier_id'    => $userId,
            'refund_amount' => $this->formatDecimal($totalRefundAmount),
            'refund_method' => $refundMethod,
            'reason'        => $reason !== '' ? $reason : null,
        ]);

        AuditLogger::log('refund_created', [
            'entity_type'      => 'sale',
            'entity_id'        => $saleId,
            'sale_id'          => $saleId,
            'cash_session_id'  => $sessionId,
            'severity'         => 'warn',
            'message'          => sprintf(
                __('Devolucion de $%1$s (%2$s) — Orden #%3$s.', 'mx-pos-pro'),
                $this->formatDecimal($totalRefundAmount),
                $refundMethod === 'cash' ? __('Efectivo', 'mx-pos-pro') : __('Tarjeta', 'mx-pos-pro'),
                $order->get_order_number()
            ),
            'metadata'         => [
                'order_id'       => $order->get_id(),
                'order_number'   => (string) $order->get_order_number(),
                'refund_amount'  => $this->formatDecimal($totalRefundAmount),
                'refund_method'  => $refundMethod,
                'refund_type'    => $fullRefundCompleted ? 'total' : 'partial',
                'order_status'   => $order->get_status(),
                'sale_status'    => $sale['status'] ?? null,
                'reason'         => $reason !== '' ? $reason : null,
                'session_id'     => $sessionId,
            ],
        ]);

        return $this->build_refund_response($refundData, $sale);
    }

    public function get_refund_options(int $saleId): array|WP_Error
    {
        $sale = $this->saleRepo->get_by_id($saleId);

        if ($sale === null) {
            return new WP_Error(
                'mx_pos_sale_not_found',
                __('Sale not found.', 'mx-pos-pro'),
                ['status' => 404]
            );
        }

        $order = wc_get_order((int) $sale['wc_order_id']);

        if (! $order instanceof \WC_Order) {
            return new WP_Error(
                'mx_pos_order_not_found',
                __('Associated WooCommerce order not found.', 'mx-pos-pro'),
                ['status' => 404]
            );
        }

        $paymentSummary = $this->decode_payment_summary($sale['payment_summary'] ?? null);
        $payment        = $paymentSummary['payment'] ?? [];
        $paymentMethod  = $payment['method'] ?? '';

        $saleTotal       = (float) $sale['total'];
        $refundedTotal   = (float) ($sale['refunded_total'] ?? '0.0000');
        $remainingAmount = $saleTotal - $refundedTotal;

        if ($remainingAmount < 0) {
            $remainingAmount = 0;
        }

        $items = [];

        foreach ($order->get_items('line_item') as $item) {
            if (! $item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $originalQty     = (int) $item->get_quantity();
            $qtyRefunded     = $this->get_refunded_quantity($order, $item->get_id());
            $refundableQty   = $originalQty - $qtyRefunded;

            if ($refundableQty <= 0) {
                continue;
            }

            $unitTotal   = $originalQty > 0
                ? (float) $item->get_total() / $originalQty
                : 0.0;
            $lineTotal   = (float) $item->get_total();

            $items[] = [
                'order_item_id'      => $item->get_id(),
                'product_id'         => $item->get_product_id(),
                'variation_id'       => $item->get_variation_id() > 0
                    ? $item->get_variation_id()
                    : null,
                'name'               => $item->get_name(),
                'sku'                => '',
                'quantity'           => $originalQty,
                'refunded_quantity'  => $qtyRefunded,
                'refundable_quantity' => $refundableQty,
                'unit_total'         => $this->formatDecimal($unitTotal),
                'line_total'         => $this->formatDecimal($lineTotal),
            ];
        }

        return [
            'sale' => [
                'id'                      => (int) $sale['id'],
                'order_id'                => $order->get_id(),
                'order_number'            => (string) $order->get_order_number(),
                'status'                  => $sale['status'],
                'payment_method'          => $paymentMethod,
                'total'                   => $this->formatDecimal($saleTotal),
                'refunded_total'          => $this->formatDecimal($refundedTotal),
                'remaining_refund_total'  => $this->formatDecimal($remainingAmount),
                'created_at'              => $sale['created_at'],
            ],
            'items' => $items,
        ];
    }

    private function build_refund_taxes(array $taxes, int $refundQty, int $originalQty): array
    {
        if ($originalQty <= 0) {
            return [];
        }

        $result = [];

        foreach ($taxes['total'] ?? [] as $rateId => $taxTotal) {
            $proportionalTax = ((float) $taxTotal / $originalQty) * $refundQty;

            if ($proportionalTax > 0) {
                $result[$rateId] = $this->formatDecimal($proportionalTax);
            }
        }

        return $result;
    }

    private function record_cash_out(int $sessionId, int $userId, float $amount, string $orderNumber, string $clientRequestId): void
    {
        try {
            $reason = sprintf('Devolución en efectivo — Orden #%s', $orderNumber);
            $crid   = $clientRequestId . ':refund-out';

            $result = $this->cashMovementService->create_movement(
                $userId,
                'cash_out',
                $this->formatDecimal($amount),
                $reason,
                $crid
            );

            if (is_wp_error($result)) {
                error_log(
                    sprintf(
                        '[MX POS Pro] Failed to record cash_out for refund on order #%s: %s',
                        $orderNumber,
                        $result->get_error_message()
                    )
                );
            }
        } catch (\Exception $e) {
            error_log(
                sprintf(
                    '[MX POS Pro] Exception recording cash_out for refund on order #%s: %s',
                    $orderNumber,
                    $e->getMessage()
                )
            );
        }
    }

    private function cancel_order_after_full_refund(\WC_Order $order, string $reason): void
    {
        if ($order->get_status() === 'cancelled') {
            return;
        }

        $note = $reason !== ''
            ? sprintf(
                /* translators: %s: reason */
                __('POS: order cancelled after full refund — %s.', 'mx-pos-pro'),
                $reason
            )
            : __('POS: order cancelled after full refund.', 'mx-pos-pro');

        $order->update_status('cancelled', $note, true);
        $order->save();
    }

    private function build_cancel_response(array $refundData): array
    {
        return [
            'refund' => [
                'id'            => (int) ($refundData['id'] ?? 0),
                'sale_id'       => (int) ($refundData['sale_id'] ?? 0),
                'wc_refund_id'  => 0,
                'refund_type'   => 'total',
                'refund_amount' => '0.0000',
                'refund_method' => null,
                'reason'        => $refundData['reason'] ?? null,
                'created_at'    => $refundData['created_at'] ?? current_time('mysql'),
            ],
            'sale' => [
                'id'     => (int) ($refundData['sale_id'] ?? 0),
                'status' => 'cancelled',
            ],
        ];
    }

    private function build_refund_response(array $refundData, ?array $sale): array
    {
        $saleId       = (int) ($refundData['sale_id'] ?? 0);
        $refundedTotal = $sale !== null
            ? $this->formatDecimal((float) ($sale['refunded_total'] ?? '0'))
            : '0.0000';
        $saleTotal     = $sale !== null
            ? $this->formatDecimal((float) ($sale['total'] ?? '0'))
            : '0.0000';
        $remaining     = (float) $saleTotal - (float) $refundedTotal;

        if ($remaining < 0) {
            $remaining = 0.0;
        }

        $response = [
            'refund' => [
                'id'            => (int) ($refundData['id'] ?? 0),
                'sale_id'       => $saleId,
                'wc_refund_id'  => (int) ($refundData['wc_refund_id'] ?? 0),
                'refund_type'   => $refundData['refund_type'] ?? 'partial',
                'refund_amount' => $this->formatDecimal((float) ($refundData['refund_amount'] ?? '0')),
                'refund_method' => $refundData['refund_method'] ?? null,
                'reason'        => $refundData['reason'] ?? null,
                'created_at'    => $refundData['created_at'] ?? current_time('mysql'),
            ],
            'sale' => [
                'id'                      => $saleId,
                'order_id'                => $sale !== null ? (int) ($sale['wc_order_id'] ?? 0) : 0,
                'status'                  => $sale['status'] ?? '',
                'total'                   => $saleTotal,
                'refunded_total'          => $refundedTotal,
                'remaining_refund_total'  => $this->formatDecimal($remaining),
            ],
        ];

        return $response;
    }

    private function get_refunded_quantity(\WC_Order $order, int $orderItemId): int
    {
        $qtyRefunded = $order->get_qty_refunded_for_item($orderItemId, 'line_item');

        return absint(abs((float) $qtyRefunded));
    }

    private function decode_payment_summary(mixed $paymentSummary): array
    {
        if (! is_string($paymentSummary) || trim($paymentSummary) === '') {
            return [];
        }

        $decoded = json_decode($paymentSummary, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function formatDecimal(float $value): string
    {
        return number_format($value, 4, '.', '');
    }
}
