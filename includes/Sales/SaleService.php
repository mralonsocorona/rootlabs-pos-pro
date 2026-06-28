<?php

namespace MXPOSPro\Sales;

defined('ABSPATH') || exit;

use MXPOSPro\Audit\AuditLogger;
use MXPOSPro\Cart\CartItemValidator;
use MXPOSPro\Cart\CartDiscountValidator;
use MXPOSPro\Cart\ParkedCartRepository;
use MXPOSPro\Cash\CashSessionService;
use MXPOSPro\Coupons\CouponLookupService;
use MXPOSPro\Customers\CustomerLookupService;
use WP_Error;

class SaleService
{
    public const MAX_ITEMS = 100;
    public const MAX_CLIENT_REQUEST_ID_LENGTH = 100;

    private SaleRepository $saleRepo;
    private CashSessionService $sessionService;
    private CartItemValidator $itemValidator;
    private CartDiscountValidator $discountValidator;
    private CustomerLookupService $customerService;
    private WooOrderFactory $orderFactory;
    private ParkedCartRepository $parkedRepo;

    public function __construct(
        SaleRepository $saleRepo,
        CashSessionService $sessionService,
        CartItemValidator $itemValidator,
        CartDiscountValidator $discountValidator,
        CustomerLookupService $customerService,
        WooOrderFactory $orderFactory,
        ParkedCartRepository $parkedRepo
    ) {
        $this->saleRepo         = $saleRepo;
        $this->sessionService   = $sessionService;
        $this->itemValidator    = $itemValidator;
        $this->discountValidator = $discountValidator;
        $this->customerService  = $customerService;
        $this->orderFactory     = $orderFactory;
        $this->parkedRepo       = $parkedRepo;
    }

    public function create_order_from_cart(int $userId, array $payload): array|WP_Error
    {
        if ($userId <= 0) {
            return new WP_Error(
                'mx_pos_invalid_user',
                __('Invalid user.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if (! current_user_can('mx_pos_sell')) {
            return new WP_Error(
                'mx_pos_sell_forbidden',
                __('You do not have permission to sell.', 'mx-pos-pro'),
                ['status' => 403]
            );
        }

        $sessionResult = $this->sessionService->get_current_session($userId);

        if (! $sessionResult['has_open_session'] || $sessionResult['session'] === null) {
            AuditLogger::log('sale_blocked_no_session', [
                'entity_type'  => 'sale',
                'severity'     => 'warn',
                'message'      => __('Venta bloqueada: no hay sesion de caja abierta.', 'mx-pos-pro'),
                'metadata'     => [
                    'cashier_id' => $userId,
                ],
            ]);

            return new WP_Error(
                'mx_pos_no_open_session',
                __('No open session found. Open a session before creating orders.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $session   = $sessionResult['session'];
        $sessionId = (int) $session['id'];

        if (! isset($payload['client_request_id']) || ! is_string($payload['client_request_id'])) {
            return new WP_Error(
                'mx_pos_missing_client_request_id',
                __('client_request_id is required.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $clientRequestId = trim($payload['client_request_id']);

        if ($clientRequestId === '') {
            return new WP_Error(
                'mx_pos_missing_client_request_id',
                __('client_request_id cannot be empty.', 'mx-pos-pro'),
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

        $existingOrder = $this->find_existing_order($clientRequestId, $sessionId, $userId);

        if ($existingOrder !== null) {
            $existingSale = $this->saleRepo->get_by_order_id($existingOrder->get_id());

            if ($existingSale !== null) {
                return $this->build_response($existingSale, $existingOrder);
            }

            return $this->build_response_from_order($existingOrder, $session);
        }

        if (! isset($payload['items']) || ! is_array($payload['items']) || count($payload['items']) === 0) {
            return new WP_Error(
                'mx_pos_empty_items',
                __('Items must be a non-empty array.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if (count($payload['items']) > self::MAX_ITEMS) {
            return new WP_Error(
                'mx_pos_too_many_items',
                sprintf(
                    /* translators: %d: max items */
                    __('Maximum %d items allowed.', 'mx-pos-pro'),
                    self::MAX_ITEMS
                ),
                ['status' => 400]
            );
        }

        $validatedItems = [];
        $allValid       = true;
        $validationErrors = [];

        foreach ($payload['items'] as $item) {
            $result = $this->itemValidator->validate($item);
            $validatedItems[] = $result;

            if (! $result->valid) {
                $allValid = false;
                $validationErrors[] = $result->errors;
            }
        }

        if (! $allValid) {
            $flatErrors = array_merge([], ...$validationErrors);

            return new WP_Error(
                'mx_pos_invalid_items',
                implode(' ', $flatErrors),
                ['status' => 400]
            );
        }

        $subtotal = 0.0;
        $lineDiscountTotal = 0.0;

        foreach ($validatedItems as $vi) {
            $lineSubtotal = $vi->line_subtotal !== '' ? $vi->line_subtotal : $vi->line_total;
            $subtotal += (float) $lineSubtotal;
            $lineDiscountTotal += (float) ($vi->line_discount_total ?? 0);
        }

        $subtotalStr = $this->formatDecimal($subtotal);

        $lineDiscountTotalStr = $this->formatDecimal($lineDiscountTotal);
        $lineDiscountTotalForSummary = $lineDiscountTotalStr;

        $couponTotalStr = '0.0000';
        $validatedCoupon = null;
        $couponCode = null;

        $couponBaseSubtotal = max(0, $subtotal - $lineDiscountTotal);

        if (isset($payload['coupon_code']) && is_string($payload['coupon_code']) && $payload['coupon_code'] !== '') {
            $couponCode = wc_format_coupon_code($payload['coupon_code']);

            if ($couponCode !== '') {
                $couponService = new CouponLookupService();
                $couponResult  = $couponService->validate_for_items($couponCode, $validatedItems, $userId);

                if (is_wp_error($couponResult)) {
                    return $couponResult;
                }

                $validatedCoupon = $couponResult;
                $couponTotalStr  = $couponResult['discount_total'];
            }
        }

        $couponAmount = (float) $couponTotalStr;
        $discountBaseSubtotal = max(0, $subtotal - $lineDiscountTotal - $couponAmount);
        $discountBaseStr = $this->formatDecimal($discountBaseSubtotal);

        $globalDiscountTotalStr = '0.0000';
        $discountTotalStr = $this->formatDecimal($lineDiscountTotal);
        $validatedDiscount = null;

        $discount = isset($payload['discount']) && is_array($payload['discount'])
            ? $payload['discount']
            : null;

        if ($discount !== null) {
            $discountResult = $this->discountValidator->validate(
                $discount,
                $discountBaseStr,
                $userId
            );

            if (is_wp_error($discountResult)) {
                return $discountResult;
            }

            $validatedDiscount = $discountResult['discount'];
            $globalDiscountTotalStr = $discountResult['discount_total'];
            $discountTotalStr  = $this->formatDecimal($lineDiscountTotal + (float) $globalDiscountTotalStr);
        }

        $customerId = null;
        $customerSnapshot = [];

        if (isset($payload['customer_id']) && $payload['customer_id'] !== null && (int) $payload['customer_id'] > 0) {
            $customerId   = (int) $payload['customer_id'];
            $customerData = $this->customerService->get_by_id($customerId);

            if ($customerData === null) {
                return new WP_Error(
                    'mx_pos_customer_not_found',
                    __('Customer not found.', 'mx-pos-pro'),
                    ['status' => 400]
                );
            }

            $customerSnapshot = $customerData;
        }

        $parkedCartId = null;

        if (isset($payload['parked_cart_id']) && $payload['parked_cart_id'] !== null && (int) $payload['parked_cart_id'] > 0) {
            $parkedCartId = (int) $payload['parked_cart_id'];
            $parkedCart = $this->parkedRepo->get_by_id($parkedCartId);

            if ($parkedCart === null) {
                return new WP_Error(
                    'mx_pos_parked_cart_not_found',
                    __('Parked cart not found.', 'mx-pos-pro'),
                    ['status' => 404]
                );
            }

            if ($parkedCart['status'] !== 'parked') {
                return new WP_Error(
                    'mx_pos_parked_cart_not_available',
                    __('This parked cart is no longer available.', 'mx-pos-pro'),
                    ['status' => 404]
                );
            }

            if ((int) $parkedCart['session_id'] !== $sessionId) {
                return new WP_Error(
                    'mx_pos_parked_cart_forbidden',
                    __('This parked cart belongs to a different session.', 'mx-pos-pro'),
                    ['status' => 403]
                );
            }
        }

        $validatedItemsArr = array_map(function ($item) {
            return $item->to_array();
        }, $validatedItems);

        $totalStr = $this->formatDecimal((float) $subtotalStr - (float) $couponTotalStr - (float) $discountTotalStr);

        $posMeta = [
            'session_id'        => $sessionId,
            'cashier_id'        => $userId,
            'cashier_name'      => $this->resolve_cashier_name($userId, $session),
            'client_request_id' => $clientRequestId,
        ];

        $order = $this->orderFactory->create(
            $validatedItemsArr,
            $subtotalStr,
            $globalDiscountTotalStr,
            $validatedDiscount,
            $couponCode,
            $customerId,
            $customerSnapshot,
            $posMeta
        );

        if (is_wp_error($order)) {
            return $order;
        }

        $paymentSummaryArr = [
            'cart_items'         => $validatedItemsArr,
            'discount'           => $validatedDiscount,
            'customer'           => $customerSnapshot,
            'client_request_id'  => $clientRequestId,
            'subtotal'           => $subtotalStr,
            'coupon_total'       => $couponTotalStr,
            'line_discount_total' => $lineDiscountTotalForSummary,
            'global_discount_total' => $globalDiscountTotalStr,
            'discount_total'     => $discountTotalStr,
            'total'              => $totalStr,
        ];

        if ($validatedCoupon !== null) {
            $paymentSummaryArr['coupon'] = $validatedCoupon;
        }

        $paymentSummary = wp_json_encode($paymentSummaryArr);

        $saleData = [
            'wc_order_id'     => $order->get_id(),
            'session_id'      => $sessionId,
            'cashier_id'      => $userId,
            'total'           => $totalStr,
            'payment_summary' => $paymentSummary,
            'status'          => 'pending',
        ];

        $sale = $this->saleRepo->create($saleData);

        if (is_wp_error($sale)) {
            $order->set_status('failed');
            $order->add_order_note(
                __('POS: mx_pos_sales insert failed — order marked as failed.', 'mx-pos-pro')
            );
            $order->save();

            error_log(
                sprintf(
                    '[MX POS Pro] mx_pos_sales insert failed for wc_order_id=%d: %s',
                    $order->get_id(),
                    $sale->get_error_message()
                )
            );

            return new WP_Error(
                'mx_pos_sale_persist_failed',
                __('Order was created but POS record could not be saved. Order marked as failed.', 'mx-pos-pro'),
                ['status' => 500]
            );
        }

        $this->saleRepo->create_log([
            'sale_id'    => (int) $sale['id'],
            'event_type' => 'order_created',
            'message'    => sprintf(
                __('Order #%s created via POS.', 'mx-pos-pro'),
                $order->get_order_number()
            ),
            'created_by' => $userId,
        ]);

        if ($parkedCartId !== null) {
            $converted = $this->parkedRepo->mark_converted($parkedCartId);

            if (! $converted) {
                error_log(
                    sprintf(
                        '[MX POS Pro] Failed to mark parked_cart %d as converted for sale %d',
                        $parkedCartId,
                        $sale['id']
                    )
                );
            }
        }

        AuditLogger::log('sale_created', [
            'entity_type'      => 'sale',
            'entity_id'        => (int) $sale['id'],
            'sale_id'          => (int) $sale['id'],
            'cash_session_id'  => $sessionId,
            'severity'         => 'info',
            'message'          => sprintf(
                __('Venta creada: Orden #%s.', 'mx-pos-pro'),
                $order->get_order_number()
            ),
            'metadata'         => [
                'order_id'       => $order->get_id(),
                'order_number'   => (string) $order->get_order_number(),
                'total'          => $totalStr,
                'items_count'    => count($validatedItems),
                'customer_id'    => $customerId,
                'session_id'     => $sessionId,
                'coupon_code'    => $couponCode,
                'coupon_total'   => $couponTotalStr,
            ],
        ]);

        return $this->build_response($sale, $order);
    }

    private function find_existing_order(string $clientRequestId, int $sessionId, int $cashierId): ?\WC_Order
    {
        $args = [
            'limit'      => 1,
            'return'     => 'objects',
            'meta_query' => [
                [
                    'key'   => '_mx_pos_client_request_id',
                    'value' => $clientRequestId,
                ],
                [
                    'key'   => '_mx_pos_session_id',
                    'value' => $sessionId,
                ],
                [
                    'key'   => '_mx_pos_cashier_id',
                    'value' => $cashierId,
                ],
            ],
        ];

        $orders = wc_get_orders($args);

        if (! empty($orders) && $orders[0] instanceof \WC_Order) {
            return $orders[0];
        }

        return null;
    }

    private function build_response(array $sale, \WC_Order $order): array
    {
        $discountTotal = $this->resolve_pos_discount_total($sale, $order);
        $sale          = $this->repair_payment_summary_discount_total($sale, $discountTotal);

        $couponTotal = 0.0;
        foreach ($order->get_coupons() as $couponItem) {
            $couponTotal += (float) $couponItem->get_discount();
        }

        return [
            'sale' => [
                'id'           => (int) $sale['id'],
                'order_id'     => $order->get_id(),
                'order_number' => (string) $order->get_order_number(),
                'status'       => $order->get_status(),
                'session_id'   => (int) $sale['session_id'],
                'cashier_id'   => (int) $sale['cashier_id'],
                'customer_id'  => $order->get_customer_id() > 0
                    ? $order->get_customer_id()
                    : null,
                'totals'       => [
                    'subtotal'       => $this->formatDecimal((float) $order->get_subtotal()),
                    'coupon_total'   => $this->formatDecimal($couponTotal),
                    'discount_total' => $discountTotal,
                    'total'          => $this->formatDecimal((float) $order->get_total()),
                ],
                'created_at'   => $sale['created_at'],
            ],
        ];
    }

    private function build_response_from_order(\WC_Order $order, array $session): array
    {
        $discountTotal = $this->resolve_pos_discount_total(null, $order);

        return [
            'sale' => [
                'id'           => 0,
                'order_id'     => $order->get_id(),
                'order_number' => (string) $order->get_order_number(),
                'status'       => $order->get_status(),
                'session_id'   => (int) $session['id'],
                'cashier_id'   => (int) ($session['opened_by'] ?? $session['id']),
                'customer_id'  => $order->get_customer_id() > 0
                    ? $order->get_customer_id()
                    : null,
                'totals'       => [
                    'subtotal'       => $this->formatDecimal((float) $order->get_subtotal()),
                    'discount_total' => $discountTotal,
                    'total'          => $this->formatDecimal((float) $order->get_total()),
                ],
                'created_at'   => $order->get_date_created()
                    ? $order->get_date_created()->format('Y-m-d H:i:s')
                    : current_time('mysql'),
            ],
        ];
    }

    private function resolve_pos_discount_total(?array $sale, \WC_Order $order): string
    {
        $paymentSummary = $this->decode_payment_summary($sale['payment_summary'] ?? null);
        $summaryDiscountTotal = null;
        $summaryDiscountAmount = null;

        if (isset($paymentSummary['discount_total']) && is_numeric($paymentSummary['discount_total'])) {
            $summaryDiscountTotal = $this->formatDiscountTotal($paymentSummary['discount_total']);

            if ($summaryDiscountTotal !== '0.0000') {
                return $summaryDiscountTotal;
            }
        }

        if (
            isset($paymentSummary['discount'])
            && is_array($paymentSummary['discount'])
            && isset($paymentSummary['discount']['amount'])
            && is_numeric($paymentSummary['discount']['amount'])
        ) {
            $summaryDiscountAmount = $this->formatDiscountTotal($paymentSummary['discount']['amount']);

            if ($summaryDiscountAmount !== '0.0000') {
                return $summaryDiscountAmount;
            }
        }

        $metaDiscountAmount = $order->get_meta('_mx_pos_discount_amount', true);
        $metaDiscountTotal  = null;

        if (is_numeric($metaDiscountAmount)) {
            $metaDiscountTotal = $this->formatDiscountTotal($metaDiscountAmount);

            if ($metaDiscountTotal !== '0.0000') {
                return $metaDiscountTotal;
            }
        }

        $feeDiscountTotal = 0.0;

        foreach ($order->get_fees() as $fee) {
            if ($fee->get_meta('_mx_pos_is_pos_discount', true) !== 'yes') {
                continue;
            }

            $feeDiscountTotal += abs((float) $fee->get_total());
        }

        if ($feeDiscountTotal > 0) {
            return $this->formatDecimal($feeDiscountTotal);
        }

        if ($summaryDiscountTotal !== null) {
            return $summaryDiscountTotal;
        }

        if ($summaryDiscountAmount !== null) {
            return $summaryDiscountAmount;
        }

        if ($metaDiscountTotal !== null) {
            return $metaDiscountTotal;
        }

        return '0.0000';
    }

    private function resolve_cashier_name(int $cashierId, array $session): string
    {
        if (! empty($session['employee_name']) && is_string($session['employee_name'])) {
            return sanitize_text_field($session['employee_name']);
        }

        $user = get_userdata($cashierId);

        if ($user instanceof \WP_User && $user->display_name !== '') {
            return sanitize_text_field($user->display_name);
        }

        return $cashierId > 0 ? sprintf('Cajero #%d', $cashierId) : '';
    }

    private function repair_payment_summary_discount_total(array $sale, string $discountTotal): array
    {
        if (! isset($sale['id']) || (int) $sale['id'] <= 0) {
            return $sale;
        }

        $paymentSummary = $this->decode_payment_summary($sale['payment_summary'] ?? null);

        if ($paymentSummary === [] && $discountTotal === '0.0000') {
            return $sale;
        }

        $changed = false;

        if (
            ! isset($paymentSummary['discount_total'])
            || ! is_numeric($paymentSummary['discount_total'])
            || $this->formatDiscountTotal($paymentSummary['discount_total']) !== $discountTotal
        ) {
            $paymentSummary['discount_total'] = $discountTotal;
            $changed = true;
        }

        if (isset($paymentSummary['discount']) && is_array($paymentSummary['discount'])) {
            if (
                ! isset($paymentSummary['discount']['amount'])
                || ! is_numeric($paymentSummary['discount']['amount'])
                || $this->formatDiscountTotal($paymentSummary['discount']['amount']) !== $discountTotal
            ) {
                $paymentSummary['discount']['amount'] = $discountTotal;
                $changed = true;
            }
        }

        if (! $changed) {
            return $sale;
        }

        $encoded = wp_json_encode($paymentSummary);

        if (! is_string($encoded)) {
            return $sale;
        }

        $updated = $this->saleRepo->update_payment_summary((int) $sale['id'], $encoded);

        if (is_wp_error($updated)) {
            return $sale;
        }

        $sale['payment_summary'] = $encoded;

        return $sale;
    }

    private function decode_payment_summary(mixed $paymentSummary): array
    {
        if (! is_string($paymentSummary) || trim($paymentSummary) === '') {
            return [];
        }

        $decoded = json_decode($paymentSummary, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function formatDiscountTotal(mixed $value): string
    {
        return $this->formatDecimal(abs((float) $value));
    }

    private function formatDecimal(float $value): string
    {
        return number_format($value, 4, '.', '');
    }
}
