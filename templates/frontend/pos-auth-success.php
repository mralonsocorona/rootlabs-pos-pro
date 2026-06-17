<?php

defined('ABSPATH') || exit;

$employee   = $employee ?? [];
$logout_url = $logout_url ?? '#';

$display_name = isset($employee['display_name']) ? $employee['display_name'] : '';

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <?php
    if (function_exists('wp_site_icon')) {
        wp_site_icon();
    }
    ?>
<meta charset="<?php echo esc_attr(get_bloginfo('charset', 'display')); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex,nofollow" />
    <title><?php echo esc_html__('Rootlabs Pos Pro', 'mx-pos-pro'); ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body {
            margin: 0;
            min-height: 100%;
            background: #f9f9f9;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #1b1b1b;
        }
        .mx-auth-ok {
            max-width: 480px;
            margin: 80px auto 0;
            padding: 40px 48px;
            background: #fff;
            border: 1px solid #e2e2e2;
            border-radius: 8px;
            text-align: center;
        }
        .mx-auth-ok__brand {
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 4px;
        }
        .mx-auth-ok__title {
            font-size: 16px;
            font-weight: 600;
            margin: 20px 0 8px;
        }
        .mx-auth-ok__name {
            font-size: 15px;
            color: #3c3c3c;
            margin: 0 0 24px;
        }
        .mx-auth-ok__notice {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            color: #075985;
            padding: 14px 18px;
            border-radius: 4px;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 24px;
            text-align: left;
        }
        .mx-auth-ok__logout {
            display: inline-block;
            padding: 8px 20px;
            font-size: 13px;
            font-weight: 500;
            color: #7e7e7e;
            background: #f5f5f5;
            border: 1px solid #d4d4d4;
            border-radius: 4px;
            text-decoration: none;
        }
        .mx-auth-ok__logout:hover {
            background: #e8e8e8;
        }
    </style>
</head>
<body>
    <main class="mx-auth-ok">
        <p class="mx-auth-ok__brand">MX POS Pro</p>
        <p class="mx-auth-ok__title"><?php echo esc_html__('Sesión POS iniciada', 'mx-pos-pro'); ?></p>
        <p class="mx-auth-ok__name">
            <?php
            echo esc_html(
                sprintf(
                    /* translators: %s: employee display name */
                    __('Autenticado como %s', 'mx-pos-pro'),
                    $display_name
                )
            );
            ?>
        </p>

        <div class="mx-auth-ok__notice">
            <?php echo esc_html__(
                'Para continuar, falta abrir una sesión de caja. Esta pantalla se conectará al flujo de apertura en el siguiente sprint.',
                'mx-pos-pro'
            ); ?>
        </div>

        <a href="<?php echo esc_url($logout_url); ?>" class="mx-auth-ok__logout">
            <?php echo esc_html__('Cerrar sesión', 'mx-pos-pro'); ?>
        </a>
    </main>
</body>
</html>
