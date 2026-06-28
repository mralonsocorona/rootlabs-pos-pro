<?php

namespace MXPOSPro\API;

defined('ABSPATH') || exit;

use MXPOSPro\Cart\CartDiscountValidator;
use MXPOSPro\Cart\CartItemValidator;
use MXPOSPro\Cart\CartValidationResult;
use MXPOSPro\Coupons\CouponLookupService;
use MXPOSPro\Core\RestSecurity;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

class CartValidationController
{
    public const MAX_ITEMS = 100;
    public const MAX_QUANTITY = 999;

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route('mx-pos/v1', '/cart/validate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'validate'],
            'permission_callback' => [$this, 'permission_callback'],
            'args'                => [
                'items' => [
                    'required'          => true,
                    'type'              => 'array',
                    'minItems'          => 1,
                    'maxItems'          => self::MAX_ITEMS,
                    'sanitize_callback' => function ($items) {
                        if (! is_array($items)) {
                            return [];
                        }

                        return array_values(array_map(function ($item) {
                            if (! is_array($item)) {
                                return [
                                    'product_id'   => 0,
                                    'variation_id'  => null,
                                    'quantity'      => 0,
                                ];
                            }

                            return [
                                'product_id'     => isset($item['product_id']) ? absint($item['product_id']) : 0,
                                'variation_id'   => isset($item['variation_id']) && $item['variation_id'] !== null
                                    ? absint($item['variation_id'])
                                    : null,
                                'quantity'       => isset($item['quantity'])
                                    ? min(absint($item['quantity']), self::MAX_QUANTITY)
                                    : 0,
                                'manual_discount' => isset($item['manual_discount']) && is_array($item['manual_discount'])
                                    ? [
                                        'type'   => isset($item['manual_discount']['type']) ? sanitize_text_field((string) $item['manual_discount']['type']) : '',
                                        'value'  => isset($item['manual_discount']['value']) ? (string) $item['manual_discount']['value'] : '',
                                        'reason' => isset($item['manual_discount']['reason']) ? sanitize_text_field((string) $item['manual_discount']['reason']) : '',
                                    ]
                                    : null,
                            ];
                        }, $items));
                    },
                    'validate_callback' => function ($items) {
                        if (! is_array($items) || count($items) === 0) {
                            return false;
                        }

                        foreach ($items as $index => $item) {
                            if (! is_array($item)) {
                                return false;
                            }

                            if (! isset($item['product_id']) || (int) $item['product_id'] < 1) {
                                return false;
                            }

                            if (! isset($item['quantity']) || (int) $item['quantity'] < 1) {
                                return false;
                            }

                            if (isset($item['variation_id']) && $item['variation_id'] !== null) {
                                if ((int) $item['variation_id'] < 1) {
                                    return false;
                                }
                            }
                        }

                        return true;
                    },
                ],
                'discount' => [
                    'required'          => false,
                    'type'              => ['object', 'null'],
                    'sanitize_callback' => function ($discount) {
                        if ($discount === null || ! is_array($discount)) {
                            return null;
                        }

                        return [
                            'type'   => isset($discount['type']) ? sanitize_text_field($discount['type']) : '',
                            'value'  => isset($discount['value']) ? (string) $discount['value'] : '',
                            'reason' => isset($discount['reason']) ? sanitize_text_field($discount['reason']) : '',
                        ];
                    },
                ],
                'coupon_code' => [
                    'required'          => false,
                    'type'              => ['string', 'null'],
                    'sanitize_callback' => function ($value) {
                        if ($value === null || $value === '') {
                            return null;
                        }
                        return sanitize_text_field((string) $value);
                    },
                ],
            ],
        ]);
    }

    public function validate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $items       = $request->get_param('items');
        $discount    = $request->get_param('discount');
        $couponCode  = $request->get_param('coupon_code');

        if (! is_array($items) || count($items) === 0) {
            return new WP_Error(
                'mx_pos_invalid_cart',
                __('Items must be a non-empty array.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if (count($items) > self::MAX_ITEMS) {
            return new WP_Error(
                'mx_pos_invalid_cart',
                sprintf(
                    /* translators: %d: max items */
                    __('Maximum %d items allowed.', 'mx-pos-pro'),
                    self::MAX_ITEMS
                ),
                ['status' => 400]
            );
        }

        $validator = new CartItemValidator();
        $validated_items = [];
        $all_valid = true;
        $global_errors = [];

        foreach ($items as $item) {
            $result = $validator->validate($item);
            $validated_items[] = $result;

            if (! $result->valid) {
                $all_valid = false;
            }
        }

        $subtotal = 0.0;
        $lineDiscountTotal = 0.0;

        foreach ($validated_items as $validated) {
            if ($validated->valid) {
                $lineSubtotal = $validated->line_subtotal !== '' ? $validated->line_subtotal : $validated->line_total;
                $subtotal += (float) $lineSubtotal;
                $lineDiscountTotal += (float) ($validated->line_discount_total ?? 0);
            }
        }

        $subtotal_str = $this->formatDecimal($subtotal);

        $coupon_total_str   = '0.0000';
        $validated_coupon    = null;
        $coupon_error        = null;
        $discountBaseSubtotal = max(0, $subtotal - $lineDiscountTotal);

        if ($couponCode !== null && $couponCode !== '') {
            $couponService = new CouponLookupService();
            $couponResult  = $couponService->validate_for_items($couponCode, $validated_items, get_current_user_id());

            if (is_wp_error($couponResult)) {
                $coupon_error = $couponResult->get_error_code() === 'mx_pos_coupon_not_found'
                    ? __('Coupon not found.', 'mx-pos-pro')
                    : $couponResult->get_error_message();
            } else {
                $validated_coupon  = $couponResult;
                $coupon_total_str  = $couponResult['discount_total'];
                $discountBaseSubtotal = max(0, $discountBaseSubtotal - (float) $coupon_total_str);
            }
        }

        $globalDiscountTotalStr = '0.0000';
        $discount_total_str  = $this->formatDecimal($lineDiscountTotal);
        $validated_discount   = null;

        if ($discount !== null && is_array($discount)) {
            $discountValidator = new CartDiscountValidator();
            $discountResult    = $discountValidator->validate(
                $discount,
                $this->formatDecimal($discountBaseSubtotal),
                get_current_user_id()
            );

            if (is_wp_error($discountResult)) {
                return $discountResult;
            }

            $validated_discount   = $discountResult['discount'];
            $globalDiscountTotalStr = $discountResult['discount_total'];
            $discount_total_str   = $this->formatDecimal($lineDiscountTotal + (float) $globalDiscountTotalStr);
        }

        $total = (float) $subtotal_str - (float) $coupon_total_str - (float) $discount_total_str;
        if ($total < 0) {
            $total = 0;
        }
        $total_str = $this->formatDecimal($total);

        if (! $all_valid) {
            $invalid_count = 0;

            foreach ($validated_items as $v) {
                if (! $v->valid) {
                    $invalid_count++;
                }
            }

            $global_errors[] = sprintf(
                /* translators: %d: number of invalid items */
                __('%d item(s) could not be validated.', 'mx-pos-pro'),
                $invalid_count
            );
        }

        $result = new CartValidationResult(
            $all_valid,
            $validated_items,
            $validated_discount,
            $validated_coupon,
            $coupon_error,
            [
                'subtotal'       => $subtotal_str,
                'coupon_total'   => $coupon_total_str,
                'discount_total' => $discount_total_str,
                'total'          => $total_str,
            ],
            $global_errors
        );

        return rest_ensure_response($result->to_array());
    }

    public function permission_callback(WP_REST_Request $request): bool|WP_Error
    {
        return RestSecurity::verify_mutation($request, 'mx_pos_sell');
    }

    private function formatDecimal(float $value): string
    {
        return number_format($value, 4, '.', '');
    }
}
