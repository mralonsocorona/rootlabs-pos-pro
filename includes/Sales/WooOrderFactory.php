<?php

namespace MXPOSPro\Sales;

defined('ABSPATH') || exit;

use WP_Error;

class WooOrderFactory
{
    public const POS_SOURCE = 'POS';

    public function create(
        array $validatedItems,
        string $subtotal,
        string $discountTotal,
        ?array $discount,
        ?string $couponCode,
        ?int $customerId,
        array $customerSnapshot,
        array $posMeta
    ): \WC_Order|WP_Error {
        $orderArgs = [
            'status'      => 'pending',
            'created_via' => self::POS_SOURCE,
        ];

        if ($customerId !== null && $customerId > 0) {
            $orderArgs['customer_id'] = $customerId;
        }

        $order = wc_create_order($orderArgs);

        if (! $order instanceof \WC_Order) {
            return new WP_Error(
                'mx_pos_order_create_failed',
                __('Failed to create WooCommerce order.', 'mx-pos-pro'),
                ['status' => 500]
            );
        }

        self::apply_pos_origin_meta($order);

        $lineDiscountTotal = 0.0;

        foreach ($validatedItems as $item) {
            $productId   = (int) $item['product_id'];
            $variationId = isset($item['variation_id']) && $item['variation_id'] !== null
                ? (int) $item['variation_id']
                : 0;
            $quantity    = (int) $item['quantity'];

            $product = wc_get_product($variationId > 0 ? $variationId : $productId);

            if (! $product || ! $product->exists()) {
                continue;
            }

            $orderItem = new \WC_Order_Item_Product();
            $orderItem->set_product($product);
            $orderItem->set_quantity($quantity);

            $unitPrice  = (float) $item['unit_price'];
            $lineSubtotal = isset($item['line_subtotal']) ? (float) $item['line_subtotal'] : (float) $item['line_total'];
            $lineTotal  = (float) $item['line_total'];
            $lineDiscount = isset($item['line_discount_total']) ? (float) $item['line_discount_total'] : max(0, $lineSubtotal - $lineTotal);
            $lineDiscountTotal += $lineDiscount;

            $orderItem->set_subtotal($lineSubtotal);
            $orderItem->set_total($lineTotal);

            if ($lineDiscount > 0 && isset($item['manual_discount']) && is_array($item['manual_discount'])) {
                $orderItem->add_meta_data('_mx_pos_line_discount_type', $item['manual_discount']['type'] ?? '');
                $orderItem->add_meta_data('_mx_pos_line_discount_value', $item['manual_discount']['value'] ?? '');
                $orderItem->add_meta_data('_mx_pos_line_discount_amount', number_format($lineDiscount, 4, '.', ''));
                $orderItem->add_meta_data('_mx_pos_line_discount_reason', $item['manual_discount']['reason'] ?? '');
            }

            $order->add_item($orderItem);
        }

        $couponApplied = false;

        if ($couponCode !== null && $couponCode !== '') {
            $order->save();
            $applied = $order->apply_coupon($couponCode);

            if (is_wp_error($applied)) {
                return $applied;
            }

            if ($applied) {
                $couponApplied = true;

                $cashierId = isset($posMeta['cashier_id']) ? (int) $posMeta['cashier_id'] : 0;

                $order->add_meta_data('_mx_pos_coupon_code', $couponCode);
                $order->add_meta_data('_mx_pos_coupon_applied_by', $cashierId);
                $order->add_meta_data('_mx_pos_coupon_applied_at', current_time('mysql'));
            }
        }

        $feeDiscountApplied = false;

        if ($discount !== null && (float) $discountTotal > 0) {
            $fee = new \WC_Order_Item_Fee();
            $fee->set_name(__('Descuento POS', 'mx-pos-pro'));
            $fee->set_total('-' . $discountTotal);
            $fee->set_tax_class('');
            $fee->add_meta_data('_mx_pos_is_pos_discount', 'yes');
            $fee->add_meta_data('_mx_pos_discount_type', $discount['type']);
            $fee->add_meta_data('_mx_pos_discount_value', $discount['value']);
            $fee->add_meta_data('_mx_pos_discount_reason', $discount['reason']);
            $fee->calculate_taxes(false);
            $order->add_item($fee);
            $feeDiscountApplied = true;
        }

        self::apply_pos_origin_meta($order);
        $order->add_meta_data('_mx_pos_session_id', $posMeta['session_id']);
        $order->add_meta_data('_mx_pos_cashier_id', $posMeta['cashier_id']);

        if (! empty($posMeta['branch_id'])) {
            $order->add_meta_data('_mx_pos_branch_id', (int) $posMeta['branch_id']);
        }
        if (! empty($posMeta['branch_name'])) {
            $order->add_meta_data('_mx_pos_branch_name', sanitize_text_field($posMeta['branch_name']));
        }
        if (! empty($posMeta['register_id'])) {
            $order->add_meta_data('_mx_pos_register_id', (int) $posMeta['register_id']);
        }
        if (! empty($posMeta['register_name'])) {
            $order->add_meta_data('_mx_pos_register_name', sanitize_text_field($posMeta['register_name']));
        }
        if (! empty($posMeta['employee_id'])) {
            $order->add_meta_data('_mx_pos_employee_id', (int) $posMeta['employee_id']);
        }

        $cashierName = isset($posMeta['cashier_name']) && is_string($posMeta['cashier_name'])
            ? sanitize_text_field($posMeta['cashier_name'])
            : '';

        if ($cashierName !== '') {
            $order->add_meta_data('_mx_pos_cashier_name', $cashierName);
            $order->add_meta_data('_yith_pos_cashier', $cashierName);
        }

        $order->add_meta_data('_mx_pos_client_request_id', $posMeta['client_request_id']);

        if ($lineDiscountTotal > 0) {
            $order->add_meta_data('_mx_pos_line_discount_total', number_format($lineDiscountTotal, 4, '.', ''));
        }

        if ($feeDiscountApplied && $discount !== null) {
            $order->add_meta_data('_mx_pos_discount_type', $discount['type']);
            $order->add_meta_data('_mx_pos_discount_amount', $discountTotal);
            $order->add_meta_data('_mx_pos_discount_reason', $discount['reason']);
        }

        if (! empty($customerSnapshot)) {
            if (! empty($customerSnapshot['id'])) {
                $order->add_meta_data('_mx_pos_customer_id', $customerSnapshot['id']);
            }
            if (! empty($customerSnapshot['display_name'])) {
                $order->add_meta_data('_mx_pos_customer_name', $customerSnapshot['display_name']);
            }
            if (! empty($customerSnapshot['email'])) {
                $order->add_meta_data('_mx_pos_customer_email', $customerSnapshot['email']);
            }
            if (! empty($customerSnapshot['phone'])) {
                $order->add_meta_data('_mx_pos_customer_phone', $customerSnapshot['phone']);
            }
        }

        $order->calculate_totals();
        $order->save();

        $couponAmount = 0.0;

        foreach ($order->get_coupons() as $couponItem) {
            $couponAmount += (float) $couponItem->get_discount();
        }

        $expectedTotal = (float) $subtotal - $couponAmount - $lineDiscountTotal - (float) $discountTotal;

        if ($expectedTotal < 0) {
            $expectedTotal = 0;
        }

        $orderTotal        = (float) $order->get_total();
        $expectedFormatted = number_format($expectedTotal, 4, '.', '');
        $orderFormatted    = number_format($orderTotal, 4, '.', '');

        if ($expectedFormatted !== $orderFormatted) {
            $order->set_total($expectedFormatted);
            $order->save();
        }

        return $order;
    }

    public static function apply_pos_origin_meta(\WC_Order $order): void
    {
        $order->set_created_via(self::POS_SOURCE);
        $order->update_meta_data('_mx_pos_source', self::POS_SOURCE);
        $order->update_meta_data('_mx_pos_origin', self::POS_SOURCE);
        $order->update_meta_data('origen', self::POS_SOURCE);
    }

    public static function apply_payment_meta(
        \WC_Order $order,
        string $method,
        string $title,
        string $clientRequestId,
        ?string $transactionId = null
    ): void {
        $paymentMethod = sanitize_text_field($method);
        $paymentTitle  = sanitize_text_field($title);
        $requestId     = sanitize_text_field($clientRequestId);

        $order->set_payment_method($paymentMethod);
        $order->set_payment_method_title($paymentTitle);
        $order->update_meta_data('_mx_pos_payment_completed', 'yes');
        $order->update_meta_data('_mx_pos_payment_method', $paymentMethod);
        $order->update_meta_data('_mx_pos_payment_method_title', $paymentTitle);
        $order->update_meta_data('_mx_pos_payment_client_request_id', $requestId);
        $order->update_meta_data('metodo_pago', $paymentMethod);
        $order->update_meta_data('metodo_pago_nombre', $paymentTitle);

        if ($transactionId !== null && $transactionId !== '') {
            $order->update_meta_data('_mx_pos_payment_transaction_id', sanitize_text_field($transactionId));
        }

        $order->save();
    }
}
