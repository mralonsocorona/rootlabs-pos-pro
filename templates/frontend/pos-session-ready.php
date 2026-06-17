<?php

defined('ABSPATH') || exit;

$employee   = $employee ?? [];
$session    = $session ?? [];
$logout_url = $logout_url ?? '#';
$state      = $state ?? 'session'; // 'session' | 'register_selected'

$display_name  = isset($employee['display_name']) ? $employee['display_name'] : '';
$register_name = isset($session['register_name']) ? $session['register_name'] : '';
$branch_name   = isset($session['branch_name']) ? $session['branch_name'] : '';
$opening_amount_val = isset($session['opening_amount'])
    ? number_format((float) $session['opening_amount'], 2)
    : '0.00';

$opened_at = isset($session['opened_at'])
    ? $session['opened_at']
    : '';

$opened_date = '';
$opened_time = '';

if ($opened_at !== '') {
    $opened_dt    = \DateTime::createFromFormat('Y-m-d H:i:s', $opened_at);
    $opened_date  = $opened_dt
        ? $opened_dt->format('d M')
        : '';
    $opened_time  = $opened_dt
        ? $opened_dt->format('H:i')
        : '';
}

$is_real_session = ($state === 'session');

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
        .mx-card {
            max-width: 480px;
            margin: 60px auto 0;
            padding: 36px 44px;
            background: #fff;
            border: 1px solid #e2e2e2;
            border-radius: 8px;
        }
        .mx-card__brand {
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 4px;
            text-align: center;
        }
        .mx-card__title {
            font-size: 16px;
            font-weight: 600;
            margin: 16px 0 4px;
            text-align: center;
        }
        .mx-card__subtitle {
            font-size: 14px;
            color: #7e7e7e;
            margin: 0 0 20px;
            text-align: center;
        }
        .mx-card__employee {
            font-size: 13px;
            color: #3c3c3c;
            margin: 0 0 20px;
            text-align: center;
        }
        .mx-card__resume {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
            padding: 14px 18px;
            border-radius: 4px;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 16px;
            text-align: center;
        }
        .mx-card__info {
            background: #f5f5f5;
            border: 1px solid #e2e2e2;
            border-radius: 4px;
            padding: 16px 18px;
            margin-bottom: 20px;
            font-size: 13px;
            line-height: 1.6;
        }
        .mx-card__info-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
        }
        .mx-card__info-label {
            color: #7e7e7e;
        }
        .mx-card__info-value {
            font-weight: 600;
        }
        .mx-card__notice {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            color: #075985;
            padding: 14px 18px;
            border-radius: 4px;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 20px;
            text-align: center;
        }
        .mx-card__logout {
            display: block;
            text-align: center;
            margin-top: 16px;
            font-size: 13px;
            color: #7e7e7e;
            text-decoration: none;
        }
        .mx-card__logout:hover {
            color: #3c3c3c;
        }
    </style>
    <?php \MXPOSPro\Core\Assets::print_pos_styles(); ?>
</head>
<body>
    <main class="mx-card">
        <p class="mx-card__brand">MX POS Pro</p>
        <p class="mx-card__title">
            <?php if ($is_real_session): ?>
                <?php echo esc_html__('Sesión de caja activa', 'mx-pos-pro'); ?>
            <?php else: ?>
                <?php echo esc_html__('Caja seleccionada', 'mx-pos-pro'); ?>
            <?php endif; ?>
        </p>
        <p class="mx-card__employee">
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

        <?php if ($is_real_session): ?>
            <div class="mx-card__resume">
                <?php echo esc_html__('Retomando sesión de caja.', 'mx-pos-pro'); ?>
            </div>
        <?php endif; ?>

        <div class="mx-card__info">
            <div class="mx-card__info-row">
                <span class="mx-card__info-label"><?php echo esc_html__('Sucursal', 'mx-pos-pro'); ?></span>
                <span class="mx-card__info-value"><?php echo esc_html($branch_name); ?></span>
            </div>
            <div class="mx-card__info-row">
                <span class="mx-card__info-label"><?php echo esc_html__('Caja', 'mx-pos-pro'); ?></span>
                <span class="mx-card__info-value"><?php echo esc_html($register_name); ?></span>
            </div>
            <?php if ($is_real_session): ?>
                <?php if ($opened_date !== ''): ?>
                    <div class="mx-card__info-row">
                        <span class="mx-card__info-label"><?php echo esc_html__('Abierta el', 'mx-pos-pro'); ?></span>
                        <span class="mx-card__info-value"><?php echo esc_html($opened_date); ?> a las <?php echo esc_html($opened_time); ?></span>
                    </div>
                <?php endif; ?>
                <div class="mx-card__info-row">
                    <span class="mx-card__info-label"><?php echo esc_html__('Fondo inicial', 'mx-pos-pro'); ?></span>
                    <span class="mx-card__info-value">$<?php echo esc_html($opening_amount_val); ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="mx-card__notice">
            <?php if ($is_real_session): ?>
                <?php echo esc_html__(
                    'Sesión de caja activa. Redirige al POS operativo si esta pantalla aparece en un fallback.',
                    'mx-pos-pro'
                ); ?>
            <?php else: ?>
                <?php echo esc_html__(
                    'El siguiente paso será realizar el conteo inicial de efectivo.',
                    'mx-pos-pro'
                ); ?>
            <?php endif; ?>
        </div>

        <a href="<?php echo esc_url($logout_url); ?>" class="mx-card__logout">
            <?php echo esc_html__('Cerrar sesión', 'mx-pos-pro'); ?>
        </a>
    </main>
    <?php \MXPOSPro\Core\Assets::print_pos_runtime(); ?>
</body>
</html>
