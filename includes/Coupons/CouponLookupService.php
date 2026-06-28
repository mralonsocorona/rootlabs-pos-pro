<?php

namespace MXPOSPro\Coupons;

defined('ABSPATH') || exit;

use WP_Error;

class CouponLookupService
{
    public const MAX_LIMIT = 30;
    public const MIN_QUERY_LENGTH = 2;
    public const MAX_QUERY_LENGTH = 100;

    public function search(string $query, int $limit = 10): array|WP_Error
    {
        $query = trim(sanitize_text_field($query));

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return new WP_Error(
                'mx_pos_invalid_query',
                sprintf(
                    __('Search query must be at least %d characters.', 'mx-pos-pro'),
                    self::MIN_QUERY_LENGTH
                ),
                ['status' => 400]
            );
        }

        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            $query = mb_substr($query, 0, self::MAX_QUERY_LENGTH);
        }

        $limit = max(1, min($limit, self::MAX_LIMIT));

        $args = [
            'post_type'      => 'shop_coupon',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'title',
            'order'          => 'ASC',
            's'              => $query,
        ];

        $posts = get_posts($args);
        $items = [];

        $now = time();

        foreach ($posts as $post) {
            $coupon = new \WC_Coupon($post->ID);

            $expires = $coupon->get_date_expires();
            if ($expires && $expires->getTimestamp() < $now) {
                continue;
            }

            $usageLimit  = $coupon->get_usage_limit();
            $usageCount  = $coupon->get_usage_count();
            if ($usageLimit > 0 && $usageCount >= $usageLimit) {
                continue;
            }

            $items[] = $this->normalize_coupon($coupon);
        }

        return ['items' => $items];
    }

    public function get_coupon_by_code(string $code): \WC_Coupon|WP_Error
    {
        $code = wc_format_coupon_code($code);

        if ($code === '') {
            return new WP_Error(
                'mx_pos_coupon_invalid',
                __('Coupon code is required.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $coupon = new \WC_Coupon($code);

        if ($coupon->get_id() === 0) {
            return new WP_Error(
                'mx_pos_coupon_not_found',
                __('Coupon not found.', 'mx-pos-pro'),
                ['status' => 404]
            );
        }

        if ($coupon->get_status() !== 'publish') {
            return new WP_Error(
                'mx_pos_coupon_not_found',
                __('Coupon is not active.', 'mx-pos-pro'),
                ['status' => 404]
            );
        }

        return $coupon;
    }

    public function validate(
        string $code,
        float $subtotal,
        ?int $customerId = null,
    ): array|WP_Error {
        $code = wc_format_coupon_code($code);

        if ($code === '') {
            return new WP_Error(
                'mx_pos_coupon_invalid',
                __('Coupon code is required.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $coupon = $this->get_coupon_by_code($code);

        if (is_wp_error($coupon)) {
            return $coupon;
        }

        $now = time();

        $expires = $coupon->get_date_expires();
        if ($expires && $expires->getTimestamp() < $now) {
            return new WP_Error(
                'mx_pos_coupon_expired',
                __('This coupon has expired.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $usageLimit = $coupon->get_usage_limit();
        $usageCount = $coupon->get_usage_count();
        if ($usageLimit > 0 && $usageCount >= $usageLimit) {
            return new WP_Error(
                'mx_pos_coupon_usage_limit',
                __('This coupon has reached its usage limit.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $minimumAmount = (float) $coupon->get_minimum_amount();
        if ($minimumAmount > 0 && $subtotal < $minimumAmount) {
            return new WP_Error(
                'mx_pos_coupon_minimum_not_met',
                sprintf(
                    __('Minimum spend of %s required for this coupon.', 'mx-pos-pro'),
                    wc_price($minimumAmount)
                ),
                ['status' => 400]
            );
        }

        $maximumAmount = (float) $coupon->get_maximum_amount();
        if ($maximumAmount > 0 && $subtotal > $maximumAmount) {
            return new WP_Error(
                'mx_pos_coupon_maximum_exceeded',
                sprintf(
                    __('This coupon is only valid for orders up to %s.', 'mx-pos-pro'),
                    wc_price($maximumAmount)
                ),
                ['status' => 400]
            );
        }

        $emailRestrictions = $coupon->get_email_restrictions();
        if (! empty($emailRestrictions)) {
            if ($customerId === null || $customerId <= 0) {
                return new WP_Error(
                    'mx_pos_coupon_email_restricted',
                    __('This coupon requires a registered customer with a matching email.', 'mx-pos-pro'),
                    ['status' => 400]
                );
            }

            $customer = get_userdata($customerId);

            if (! $customer instanceof \WP_User) {
                return new WP_Error(
                    'mx_pos_coupon_not_applicable',
                    __('Customer not found for coupon email validation.', 'mx-pos-pro'),
                    ['status' => 400]
                );
            }

            if (! in_array($customer->user_email, $emailRestrictions, true)) {
                return new WP_Error(
                    'mx_pos_coupon_email_restricted',
                    __('This coupon is restricted to specific customer emails.', 'mx-pos-pro'),
                    ['status' => 400]
                );
            }
        }

        if ($coupon->get_individual_use()) {
            // individual_use is about other coupons, not about manual discount
            // We only flag it but don't block — manual discount is a fee, not a coupon
        }

        if ($coupon->get_exclude_sale_items()) {
            // This will be validated by apply_coupon() on the order
            // We don't have per-item sale info in simple validation
        }

        $discountType    = $coupon->get_discount_type();
        $couponAmount    = (float) $coupon->get_amount();
        $discountTotal   = 0.0;

        switch ($discountType) {
            case 'percent':
                $discountTotal = $subtotal * ($couponAmount / 100);
                break;
            case 'fixed_cart':
                $discountTotal = $couponAmount;
                break;
            case 'fixed_product':
                // fixed_product is applied per-item by WC; approximate
                $discountTotal = $couponAmount;
                break;
            default:
                // Other types not supported yet
                return new WP_Error(
                    'mx_pos_coupon_not_applicable',
                    __('This coupon type is not supported in POS.', 'mx-pos-pro'),
                    ['status' => 400]
                );
        }

        if ($discountTotal > $subtotal) {
            $discountTotal = $subtotal;
        }

        return [
            'code'           => $coupon->get_code(),
            'discount_type'  => $discountType,
            'amount'         => (string) $couponAmount,
            'description'    => $coupon->get_description(),
            'discount_total' => number_format($discountTotal, 4, '.', ''),
        ];
    }

    /**
     * Validate a coupon against concrete POS cart lines.
     *
     * This keeps POS coupon totals aligned with WooCommerce sale-item rules.
     *
     * @param array<int, object|array> $items Validated cart items.
     */
    public function validate_for_items(string $code, array $items, ?int $customerId = null): array|WP_Error
    {
        $coupon = $this->get_coupon_by_code($code);

        if (is_wp_error($coupon)) {
            return $coupon;
        }

        $eligibleSubtotal = $this->calculate_coupon_eligible_subtotal($coupon, $items);

        if ($eligibleSubtotal <= 0) {
            return new WP_Error(
                'mx_pos_coupon_not_applicable',
                __('Coupon is not applicable to the selected products.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $result = $this->validate($coupon->get_code(), $eligibleSubtotal, $customerId);

        if (is_wp_error($result)) {
            return $result;
        }

        $result['eligible_subtotal'] = number_format($eligibleSubtotal, 4, '.', '');

        return $result;
    }

    /**
     * @param array<int, object|array> $items
     */
    private function calculate_coupon_eligible_subtotal(\WC_Coupon $coupon, array $items): float
    {
        $eligibleSubtotal = 0.0;

        foreach ($items as $item) {
            $valid = $this->read_item_value($item, 'valid');

            if ($valid === false) {
                continue;
            }

            $productId   = $this->read_item_int($item, 'product_id');
            $variationId = $this->read_item_int($item, 'variation_id');
            $quantity    = max(1, $this->read_item_int($item, 'quantity'));

            if ($productId <= 0) {
                continue;
            }

            $product = wc_get_product($variationId > 0 ? $variationId : $productId);

            if (! $product || ! $product->exists()) {
                continue;
            }

            if (! $this->coupon_allows_product($coupon, $product, $productId, $variationId, $quantity)) {
                continue;
            }

            $lineSubtotal = $this->read_item_float($item, 'line_subtotal');

            if ($lineSubtotal <= 0) {
                $lineSubtotal = $this->read_item_float($item, 'line_total');
            }

            $lineDiscount = $this->read_item_float($item, 'line_discount_total');

            $eligibleSubtotal += max(0, $lineSubtotal - $lineDiscount);
        }

        return $eligibleSubtotal;
    }

    private function coupon_allows_product(
        \WC_Coupon $coupon,
        \WC_Product $product,
        int $productId,
        int $variationId,
        int $quantity
    ): bool {
        if ($coupon->get_exclude_sale_items() && $this->product_is_on_sale($product)) {
            return false;
        }

        if (method_exists($coupon, 'is_valid_for_product')) {
            try {
                return (bool) $coupon->is_valid_for_product($product, [
                    'product_id'   => $productId,
                    'variation_id' => $variationId,
                    'quantity'     => $quantity,
                    'data'         => $product,
                ]);
            } catch (\Exception $e) {
                return false;
            }
        }

        return true;
    }

    private function product_is_on_sale(\WC_Product $product): bool
    {
        if ($product->is_on_sale()) {
            return true;
        }

        $sale = $product->get_sale_price('edit');

        if ($sale === '' || $sale === null || (float) $sale <= 0) {
            return false;
        }

        $regular = $product->get_regular_price('edit');

        if ($regular !== '' && $regular !== null && (float) $regular > 0) {
            return (float) $sale < (float) $regular;
        }

        return true;
    }

    private function read_item_value($item, string $key)
    {
        if (is_array($item)) {
            return $item[$key] ?? null;
        }

        if (is_object($item) && property_exists($item, $key)) {
            return $item->{$key};
        }

        return null;
    }

    private function read_item_int($item, string $key): int
    {
        $value = $this->read_item_value($item, $key);

        if ($value === null || $value === '') {
            return 0;
        }

        return (int) $value;
    }

    private function read_item_float($item, string $key): float
    {
        $value = $this->read_item_value($item, $key);

        if ($value === null || $value === '') {
            return 0.0;
        }

        return (float) $value;
    }

    public function normalize_coupon(\WC_Coupon $coupon): array
    {
        $expires = $coupon->get_date_expires();

        return [
            'id'                => $coupon->get_id(),
            'code'              => $coupon->get_code(),
            'discount_type'     => $coupon->get_discount_type(),
            'amount'            => (string) $coupon->get_amount(),
            'description'       => $coupon->get_description(),
            'date_expires'      => $expires ? $expires->format('Y-m-d\TH:i:s') : null,
            'usage_limit'       => $coupon->get_usage_limit(),
            'usage_count'       => $coupon->get_usage_count(),
            'minimum_amount'    => (string) $coupon->get_minimum_amount(),
            'maximum_amount'    => (string) $coupon->get_maximum_amount(),
            'individual_use'    => $coupon->get_individual_use(),
            'exclude_sale_items'=> $coupon->get_exclude_sale_items(),
        ];
    }
}
