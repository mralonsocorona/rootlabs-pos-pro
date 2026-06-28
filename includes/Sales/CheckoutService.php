<?php

namespace MXPOSPro\Sales;

defined('ABSPATH') || exit;

use MXPOSPro\Audit\AuditLogger;
use MXPOSPro\Cart\CartItemValidator;
use MXPOSPro\Cart\CartDiscountValidator;
use MXPOSPro\Cart\ParkedCartRepository;
use MXPOSPro\Cash\CashSessionService;
use MXPOSPro\Cash\CashMovementService;
use MXPOSPro\Payments\PaymentMethodRepository;
use MXPOSPro\Payments\OrderPaymentRepository;
use MXPOSPro\Coupons\CouponLookupService;
use MXPOSPro\Customers\CustomerLookupService;
use WP_Error;

class CheckoutService
{
    public const MAX_ITEMS = 100;
    public const MAX_PAYMENT_LINES = 20;
    public const PAYMENT_TOLERANCE = 0.01;
    public const MAX_CLIENT_REQUEST_ID_LENGTH = 100;

    private SaleRepository $saleRepo;
    private CashSessionService $sessionService;
    private CashMovementService $cashMovementService;
    private CartItemValidator $itemValidator;
    private CartDiscountValidator $discountValidator;
    private CustomerLookupService $customerService;
    private WooOrderFactory $orderFactory;
    private ParkedCartRepository $parkedRepo;
    private PaymentMethodRepository $methodRepo;
    private OrderPaymentRepository $orderPaymentRepo;

    public function __construct(
        SaleRepository $saleRepo,
        CashSessionService $sessionService,
        CashMovementService $cashMovementService,
        CartItemValidator $itemValidator,
        CartDiscountValidator $discountValidator,
        CustomerLookupService $customerService,
        WooOrderFactory $orderFactory,
        ParkedCartRepository $parkedRepo,
        PaymentMethodRepository $methodRepo,
        OrderPaymentRepository $orderPaymentRepo
    ) {
        $this->saleRepo            = $saleRepo;
        $this->sessionService      = $sessionService;
        $this->cashMovementService = $cashMovementService;
        $this->itemValidator       = $itemValidator;
        $this->discountValidator   = $discountValidator;
        $this->customerService     = $customerService;
        $this->orderFactory        = $orderFactory;
        $this->parkedRepo          = $parkedRepo;
        $this->methodRepo          = $methodRepo;
        $this->orderPaymentRepo    = $orderPaymentRepo;
    }

    public function execute(int $userId, array $payload): array|WP_Error
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
            AuditLogger::log('checkout_blocked_no_session', [
                'entity_type' => 'checkout',
                'severity'    => 'warn',
                'message'     => __('Checkout bloqueado: no hay sesion de caja abierta.', 'mx-pos-pro'),
                'metadata'    => ['cashier_id' => $userId],
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
                    __('client_request_id must not exceed %d characters.', 'mx-pos-pro'),
                    self::MAX_CLIENT_REQUEST_ID_LENGTH
                ),
                ['status' => 400]
            );
        }

        $existingSale = $this->saleRepo->find_by_client_request_id($clientRequestId);

        if ($existingSale !== null) {
            $order = wc_get_order((int) $existingSale['wc_order_id']);

            if ($order instanceof \WC_Order) {
                return $this->build_response($existingSale, $order);
            }

            return $this->build_response($existingSale, null);
        }

        $existingOrders = wc_get_orders([
            'limit'      => 1,
            'return'     => 'objects',
            'meta_query' => [
                [
                    'key'   => '_mx_pos_client_request_id',
                    'value' => $clientRequestId,
                ],
            ],
        ]);

        if (! empty($existingOrders) && $existingOrders[0] instanceof \WC_Order) {
            $existingOrder = $existingOrders[0];
            $saleForOrder = $this->saleRepo->get_by_order_id($existingOrder->get_id());

            if ($saleForOrder !== null) {
                return $this->build_response($saleForOrder, $existingOrder);
            }
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
            AuditLogger::log('checkout_failed', [
                'entity_type' => 'checkout',
                'severity'    => 'warn',
                'message'     => __('Checkout fallido: items invalidos.', 'mx-pos-pro'),
                'metadata'    => [
                    'client_request_id' => $clientRequestId,
                    'errors'            => $validationErrors,
                ],
            ]);

            return new WP_Error(
                'mx_pos_invalid_items',
                implode(' ', array_merge([], ...$validationErrors)),
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

        if (! isset($payload['payment_lines']) || ! is_array($payload['payment_lines']) || count($payload['payment_lines']) === 0) {
            return new WP_Error(
                'mx_pos_no_payment_lines',
                __('At least one payment line is required.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if (count($payload['payment_lines']) > self::MAX_PAYMENT_LINES) {
            return new WP_Error(
                'mx_pos_too_many_payment_lines',
                sprintf(
                    __('Maximum %d payment lines allowed.', 'mx-pos-pro'),
                    self::MAX_PAYMENT_LINES
                ),
                ['status' => 400]
            );
        }

        $paymentLinesResult = $this->validate_payment_lines(
            $payload['payment_lines'],
            (float) $totalStr
        );

        if (is_wp_error($paymentLinesResult)) {
            return $paymentLinesResult;
        }

        $salesSchemaReady = $this->saleRepo->validate_checkout_schema();

        if (is_wp_error($salesSchemaReady)) {
            return $salesSchemaReady;
        }

        $normalizedLines = $paymentLinesResult['lines'];
        $paymentInfo     = $paymentLinesResult['info'];
        $paymentInfo['cashier_id'] = $userId;
        $paymentInfo['session_id'] = $sessionId;

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

        try {
            $transactionId = 'pos-checkout-' . $order->get_id();
            $paymentInfo['transaction_id'] = $transactionId;

            WooOrderFactory::apply_payment_meta(
                $order,
                (string) ($paymentInfo['method'] ?? 'mixed'),
                (string) ($paymentInfo['method_name'] ?? __('Pago POS', 'mx-pos-pro')),
                $clientRequestId,
                $transactionId
            );

            $this->complete_pos_order($order, $transactionId);
        } catch (\Exception $e) {
            $order->delete_meta_data('_mx_pos_payment_completed');
            $order->delete_meta_data('_mx_pos_payment_method');
            $order->delete_meta_data('_mx_pos_payment_method_title');
            $order->delete_meta_data('_mx_pos_payment_client_request_id');
            $order->delete_meta_data('_mx_pos_payment_transaction_id');
            $order->delete_meta_data('metodo_pago');
            $order->delete_meta_data('metodo_pago_nombre');

            error_log(
                sprintf(
                    '[MX POS Pro] Checkout payment_complete failed for order #%d: %s',
                    $order->get_id(),
                    $e->getMessage()
                )
            );

            try {
                $order->set_status('failed');
                $order->add_order_note(
                    __('POS checkout: payment_complete failed, order marked as failed.', 'mx-pos-pro')
                );
                $order->save();
            } catch (\Exception $innerEx) {
                error_log(
                    sprintf(
                        '[MX POS Pro] Failed to mark order #%d as failed after checkout error: %s',
                        $order->get_id(),
                        $innerEx->getMessage()
                    )
                );
            }

            return new WP_Error(
                'mx_pos_checkout_payment_failed',
                __('Failed to mark order as paid.', 'mx-pos-pro'),
                ['status' => 500]
            );
        }

        $paymentSummaryArr = [
            'cart_items'        => $validatedItemsArr,
            'discount'          => $validatedDiscount,
            'customer'          => $customerSnapshot,
            'client_request_id' => $clientRequestId,
            'subtotal'          => $subtotalStr,
            'coupon_total'      => $couponTotalStr,
            'line_discount_total' => $lineDiscountTotalForSummary,
            'global_discount_total' => $globalDiscountTotalStr,
            'discount_total'    => $discountTotalStr,
            'total'             => $totalStr,
            'payment'           => $paymentInfo,
        ];

        if ($validatedCoupon !== null) {
            $paymentSummaryArr['coupon'] = $validatedCoupon;
        }

        $paymentSummary = wp_json_encode($paymentSummaryArr);

        $saleData = [
            'wc_order_id'       => $order->get_id(),
            'session_id'        => $sessionId,
            'cashier_id'        => $userId,
            'total'             => $totalStr,
            'payment_summary'   => $paymentSummary,
            'status'            => 'completed',
            'client_request_id' => $clientRequestId,
        ];

        $sale = $this->saleRepo->create($saleData);

        if (is_wp_error($sale)) {
            $order->update_status('on-hold', __('POS: mx_pos_sales insert failed — order put on hold for review.', 'mx-pos-pro'));

            error_log(
                sprintf(
                    '[MX POS Pro] mx_pos_sales insert failed for wc_order_id=%d: %s',
                    $order->get_id(),
                    $sale->get_error_message()
                )
            );

            return new WP_Error(
                'mx_pos_sale_persist_failed',
                __('Order was created and paid, but POS record could not be saved. Order put on hold.', 'mx-pos-pro'),
                ['status' => 500]
            );
        }

        $saleId = (int) $sale['id'];

        $this->saleRepo->create_log([
            'sale_id'    => $saleId,
            'event_type' => 'checkout_completed',
            'message'    => sprintf(
                __('Order #%s completed via POS checkout.', 'mx-pos-pro'),
                $order->get_order_number()
            ),
            'created_by' => $userId,
        ]);

        foreach ($normalizedLines as $lineIndex => $line) {
            $this->write_order_payment(
                $saleId,
                $line['method_id'],
                (float) $line['amount'],
                (float) $line['tendered_amount'],
                (float) $line['change_amount'],
                $line['reference'],
                $transactionId,
                $clientRequestId,
                $lineIndex
            );
        }

        foreach ($normalizedLines as $lineIndex => $line) {
            if ($line['affects_cash_register']) {
                $netAmount = (float) $line['amount'] - (float) $line['change_amount'];
                
                if ($netAmount > 0) {
                    $this->record_cash_movement(
                        $sessionId,
                        $userId,
                        $netAmount,
                        $order->get_order_number(),
                        $clientRequestId,
                        $lineIndex
                    );
                }
            }
        }

        if ($parkedCartId !== null) {
            $converted = $this->parkedRepo->mark_converted($parkedCartId);

            if (! $converted) {
                error_log(
                    sprintf(
                        '[MX POS Pro] Failed to mark parked_cart %d as converted for checkout sale %d',
                        $parkedCartId,
                        $saleId
                    )
                );
            }
        }

        AuditLogger::log('checkout_completed', [
            'entity_type'      => 'sale',
            'entity_id'        => $saleId,
            'sale_id'          => $saleId,
            'cash_session_id'  => $sessionId,
            'severity'         => 'info',
            'message'          => sprintf(
                __('Checkout completado: Orden #%s.', 'mx-pos-pro'),
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
                'payment_lines'  => count($normalizedLines),
                'client_request_id' => $clientRequestId,
            ],
        ]);

        return $this->build_response($sale, $order);
    }

    private function validate_payment_lines(array $lines, float $total): array|WP_Error
    {
        $normalizedLines = [];
        $totalPaid       = 0.0;
        $totalNonCash    = 0.0;
        $changeAmount    = 0.0;
        $cardFeeTotal    = 0.0;
        $hasCashLike     = false;

        foreach ($lines as $index => $line) {
            $methodSlug = isset($line['method']) ? trim((string) $line['method']) : '';

            if ($methodSlug === '') {
                return new WP_Error(
                    'mx_pos_payment_invalid_method',
                    sprintf(
                        __('Payment method is required (line %d).', 'mx-pos-pro'),
                        $index + 1
                    ),
                    ['status' => 400]
                );
            }

            if ($methodSlug === 'mixed') {
                return new WP_Error(
                    'mx_pos_payment_nested_mixed',
                    __('Mixed method cannot be used as a payment line.', 'mx-pos-pro'),
                    ['status' => 400]
                );
            }

            $method = $this->methodRepo->get_by_slug($methodSlug);

            if ($method === null || ! (int) ($method['is_active'] ?? 0)) {
                return new WP_Error(
                    'mx_pos_payment_method_inactive',
                    sprintf(
                        __('Payment method "%s" is not available.', 'mx-pos-pro'),
                        $methodSlug
                    ),
                    ['status' => 400]
                );
            }

            $amount = isset($line['amount']) ? (float) $line['amount'] : 0;

            if ($amount <= 0) {
                return new WP_Error(
                    'mx_pos_payment_invalid_amount',
                    sprintf(
                        __('Payment amount must be greater than 0 (line %d).', 'mx-pos-pro'),
                        $index + 1
                    ),
                    ['status' => 400]
                );
            }

            $reference = isset($line['reference']) && is_string($line['reference']) && $line['reference'] !== ''
                ? trim($line['reference'])
                : null;

            if ($reference !== null && mb_strlen($reference) > 100) {
                $reference = mb_substr($reference, 0, 100);
            }

            $affectsCash = (bool) ((int) ($method['affects_cash_register'] ?? 0));
            $isCashLike  = $method['payment_type'] === 'cash' || $affectsCash;

            $cardFee = $this->calculate_card_fee($method, $amount);

            $normalizedLines[] = [
                'method'               => $methodSlug,
                'method_name'          => $method['name'],
                'method_id'            => (int) $method['id'],
                'amount'               => $this->formatDecimal($amount),
                'tendered_amount'      => $this->formatDecimal($amount),
                'change_amount'        => '0.0000',
                'reference'            => $reference,
                'card_fee'             => $cardFee !== null ? $this->formatDecimal($cardFee) : null,
                'affects_cash_register' => $affectsCash,
                'is_cash_like'         => $isCashLike,
            ];

            $totalPaid += $amount;

            if ($isCashLike) {
                $hasCashLike = true;
            } else {
                $totalNonCash += $amount;
            }

            if ($cardFee !== null) {
                $cardFeeTotal += $cardFee;
            }
        }

        if ($totalNonCash > $total + self::PAYMENT_TOLERANCE) {
            return new WP_Error(
                'mx_pos_payment_non_cash_over_total',
                __('Non-cash payment methods cannot exceed the amount due.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $missingAmount = $total - $totalPaid;

        if ($missingAmount > self::PAYMENT_TOLERANCE) {
            return new WP_Error(
                'mx_pos_payment_insufficient',
                sprintf(
                    __('Insufficient payment: missing $%s.', 'mx-pos-pro'),
                    number_format($missingAmount, 2, '.', ',')
                ),
                ['status' => 400]
            );
        }

        $overpaidAmount = $totalPaid - $total;

        if ($overpaidAmount > self::PAYMENT_TOLERANCE) {
            if (! $hasCashLike) {
                return new WP_Error(
                    'mx_pos_payment_only_cash_change',
                    __('Only cash or cash-affecting methods can generate change.', 'mx-pos-pro'),
                    ['status' => 400]
                );
            }

            $changeAmount = $overpaidAmount;

            for ($i = count($normalizedLines) - 1; $i >= 0; $i--) {
                if (! empty($normalizedLines[$i]['is_cash_like'])) {
                    $normalizedLines[$i]['change_amount'] = $this->formatDecimal($changeAmount);
                    break;
                }
            }
        }

        $paidAt = current_time('mysql');

        $paymentInfo = [
            'method'         => count($normalizedLines) === 1 ? $normalizedLines[0]['method'] : 'mixed',
            'method_name'    => count($normalizedLines) === 1 ? $normalizedLines[0]['method_name'] : __('Pago POS', 'mx-pos-pro'),
            'total_due'      => $this->formatDecimal($total),
            'total_paid'     => $this->formatDecimal($totalPaid),
            'change'         => $this->formatDecimal($changeAmount),
            'payment_lines'  => array_map(function ($nl) {
                return [
                    'method'               => $nl['method'],
                    'method_name'          => $nl['method_name'],
                    'amount'               => $nl['amount'],
                    'reference'            => $nl['reference'],
                    'card_fee'             => $nl['card_fee'],
                    'affects_cash_register' => $nl['affects_cash_register'],
                ];
            }, $normalizedLines),
            'card_fee_total'  => $this->formatDecimal($cardFeeTotal),
            'transaction_id'  => 'pos-checkout-' . uniqid(),
            'paid_at'         => $paidAt,
            'cashier_id'      => 0,
            'session_id'      => 0,
        ];

        return [
            'lines' => $normalizedLines,
            'info'  => $paymentInfo,
        ];
    }

    private function calculate_card_fee(array $method, float $amount): ?float
    {
        if (! (bool) ((int) ($method['card_fee_enabled'] ?? 0))) {
            return null;
        }

        $feeType  = $method['card_fee_type'] ?? null;
        $feeValue = isset($method['card_fee_value']) ? (float) $method['card_fee_value'] : 0;

        if ($feeType === null || $feeValue <= 0) {
            return null;
        }

        if ($feeType === 'percentage') {
            return round($amount * $feeValue / 100, 4);
        }

        if ($feeType === 'fixed') {
            return $feeValue;
        }

        return null;
    }

    private function write_order_payment(
        int $saleId, int $methodId, float $amount,
        float $tendered, float $change, ?string $reference, string $transactionId,
        string $clientRequestId, int $lineIndex
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'mx_pos_order_payments';

        $paymentCrid = $clientRequestId . ':payment:' . $lineIndex;

        $wpdb->insert(
            $table,
            [
                'sale_id'           => $saleId,
                'payment_method_id' => $methodId,
                'amount'            => $amount,
                'tendered_amount'   => $tendered,
                'change_amount'     => $change,
                'currency'          => 'MXN',
                'status'            => 'completed',
                'card_reference'    => $reference,
                'transaction_id'    => $transactionId,
                'client_request_id' => $paymentCrid,
                'created_at'        => current_time('mysql'),
                'updated_at'        => current_time('mysql'),
            ],
            ['%d', '%d', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    private function record_cash_movement(int $sessionId, int $userId, float $amount, string $orderNumber, string $clientRequestId, int $lineIndex): void
    {
        try {
            $reason = sprintf('Venta POS — Orden #%s', $orderNumber);
            $crid   = $clientRequestId . ':cash-movement:' . $lineIndex;

            $result = $this->cashMovementService->create_movement(
                $userId,
                'cash_in',
                $this->formatDecimal($amount),
                $reason,
                $crid
            );

            if (is_wp_error($result)) {
                error_log(
                    sprintf(
                        '[MX POS Pro] Failed to record automatic cash movement for order #%s: %s',
                        $orderNumber,
                        $result->get_error_message()
                    )
                );
            }
        } catch (\Exception $e) {
            error_log(
                sprintf(
                    '[MX POS Pro] Exception recording cash movement for order #%s: %s',
                    $orderNumber,
                    $e->getMessage()
                )
            );
        }
    }

    private function record_cash_out(int $sessionId, int $userId, float $amount, string $orderNumber, string $clientRequestId, int $lineIndex): void
    {
        try {
            $reason = sprintf('Cambio POS — Orden #%s', $orderNumber);
            $crid   = $clientRequestId . ':cash-out:' . $lineIndex;

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
                        '[MX POS Pro] Failed to record cash_out for order #%s: %s',
                        $orderNumber,
                        $result->get_error_message()
                    )
                );
            }
        } catch (\Exception $e) {
            error_log(
                sprintf(
                    '[MX POS Pro] Exception recording cash_out for order #%s: %s',
                    $orderNumber,
                    $e->getMessage()
                )
            );
        }
    }

    private function build_response(array $sale, ?\WC_Order $order): array
    {
        $saleId      = (int) $sale['id'];
        $orderId     = 0;
        $orderNumber = '';
        $orderStatus = $sale['status'] ?? 'completed';
        $customerId  = null;
        $subtotal    = '0.0000';
        $couponTotal = '0.0000';
        $discountTotal = '0.0000';
        $total       = $sale['total'] ?? '0.0000';
        $items       = [];

        $paymentSummary = $this->decode_payment_summary($sale['payment_summary'] ?? null);
        $paymentInfo    = $paymentSummary['payment'] ?? [];

        if ($order !== null) {
            $orderId     = $order->get_id();
            $orderNumber = (string) $order->get_order_number();
            $orderStatus = $order->get_status();
            $customerId  = $order->get_customer_id() > 0 ? $order->get_customer_id() : null;

            $subtotal = $this->formatDecimal((float) $order->get_subtotal());

            $couponVal = 0.0;
            foreach ($order->get_coupons() as $cp) {
                $couponVal += (float) $cp->get_discount();
            }
            $couponTotal = $this->formatDecimal($couponVal);

            foreach ($order->get_fees() as $fee) {
                if ($fee->get_meta('_mx_pos_is_pos_discount', true) === 'yes') {
                    $discountTotal = $this->formatDecimal(abs((float) $fee->get_total()));
                    break;
                }
            }

            $total = $this->formatDecimal((float) $order->get_total());

            foreach ($order->get_items() as $item) {
                $items[] = [
                    'name'     => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'total'    => $this->formatDecimal((float) $item->get_total()),
                ];
            }
        }

        if (empty($paymentInfo)) {
            $paymentInfo = [
                'method'         => '',
                'total_due'      => $total,
                'total_paid'     => $total,
                'change'         => '0.0000',
                'payment_lines'  => [],
                'card_fee_total' => '0.0000',
                'transaction_id' => '',
                'paid_at'        => null,
                'cashier_id'     => (int) ($sale['cashier_id'] ?? 0),
                'session_id'     => (int) ($sale['session_id'] ?? 0),
            ];
        }

        return [
            'sale' => [
                'id'           => $saleId,
                'order_id'     => $orderId,
                'order_number' => $orderNumber,
                'status'       => $orderStatus,
                'session_id'   => (int) ($sale['session_id'] ?? 0),
                'cashier_id'   => (int) ($sale['cashier_id'] ?? 0),
                'customer_id'  => $customerId,
                'totals'       => [
                    'subtotal'       => $subtotal,
                    'coupon_total'   => $couponTotal,
                    'discount_total' => $discountTotal,
                    'total'          => $total,
                ],
                'items'        => $items,
                'payment'      => $paymentInfo,
                'created_at'   => $sale['created_at'] ?? current_time('mysql'),
            ],
        ];
    }

    private function complete_pos_order(\WC_Order $order, string $transactionId): void
    {
        $order->payment_complete($transactionId);

        if ($order->get_status() !== 'completed') {
            $order->update_status(
                'completed',
                __('POS checkout completed and fulfilled in-store.', 'mx-pos-pro'),
                true
            );
        }
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
