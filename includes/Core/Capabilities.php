<?php

namespace MXPOSPro\Core;

defined('ABSPATH') || exit;

class Capabilities
{
    private const CASHIER_ROLE = 'mx_pos_cashier';

    public static function capabilities(): array
    {
        return [
            'mx_pos_access',
            'mx_pos_sell',
            'mx_pos_refund',
            'mx_pos_open_session',
            'mx_pos_close_session',
            'mx_pos_apply_discount',
            'mx_pos_cash_cut',
            'mx_pos_manage_cash',
            'mx_pos_manage_settings',
            'mx_pos_view_dashboard',
            'mx_pos_view_audit',
            'mx_pos_void_session',
            'mx_pos_remote_close',
        ];
    }

    public static function role_capability_map(): array
    {
        return [
            'administrator' => self::capabilities(),
            'shop_manager'  => [
                'mx_pos_access',
                'mx_pos_sell',
                'mx_pos_refund',
                'mx_pos_open_session',
                'mx_pos_close_session',
                'mx_pos_apply_discount',
                'mx_pos_cash_cut',
                'mx_pos_manage_cash',
                'mx_pos_view_dashboard',
                'mx_pos_view_audit',
            ],
            self::CASHIER_ROLE => [
                'mx_pos_access',
                'mx_pos_sell',
                'mx_pos_refund',
                'mx_pos_open_session',
                'mx_pos_close_session',
                'mx_pos_cash_cut',
                'mx_pos_manage_cash',
            ],
        ];
    }

    public static function install(): void
    {
        foreach (self::role_capability_map() as $role_name => $caps) {
            if ($role_name === self::CASHIER_ROLE) {
                if (! get_role(self::CASHIER_ROLE)) {
                    self::create_cashier_role();
                }
            }

            $role = get_role($role_name);

            if (! $role instanceof \WP_Role) {
                continue;
            }

            foreach ($caps as $cap) {
                $role->add_cap($cap, true);
            }
        }
    }

    public static function has_capabilities(): bool
    {
        foreach (self::role_capability_map() as $role_name => $caps) {
            $role = get_role($role_name);

            if (! $role instanceof \WP_Role) {
                return false;
            }

            foreach ($caps as $cap) {
                if (! $role->has_cap($cap)) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function remove_capabilities(): void
    {
        foreach (self::role_capability_map() as $role_name => $caps) {
            if ($role_name === self::CASHIER_ROLE) {
                self::remove_cashier_role();

                continue;
            }

            $role = get_role($role_name);

            if (! $role instanceof \WP_Role) {
                continue;
            }

            foreach ($caps as $cap) {
                $role->remove_cap($cap);
            }
        }
    }

    public static function remove_cashier_role(): void
    {
        remove_role(self::CASHIER_ROLE);
    }

    private static function create_cashier_role(): void
    {
        $caps = self::role_capability_map()[self::CASHIER_ROLE];

        add_role(
            self::CASHIER_ROLE,
            __('POS Cashier', 'mx-pos-pro'),
            array_fill_keys($caps, true)
        );
    }
}
