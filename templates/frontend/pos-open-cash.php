<?php

defined('ABSPATH') || exit;

$employee           = $employee ?? [];
$registers          = $registers ?? [];
$open_cash_error    = $open_cash_error ?? '';
$logout_url         = $logout_url ?? '#';
$selected_register_id = $selected_register_id ?? '';

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
            min-height: 100vh;
        }
        body {
            background-color: #f9f9f9;
            background-image: radial-gradient(#d4d4d4 1px, transparent 1px);
            background-size: 20px 20px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #1b1b1b;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            z-index: 1;
        }
        body::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            z-index: -1;
            width: 45vw;
            height: 45vw;
            background: radial-gradient(circle, rgba(171, 194, 255, 0.4) 0%, transparent 70%);
            bottom: -5vw;
            right: -10vw;
        }
        .mx-card {
            width: 100%;
            max-width: 540px;
            margin: 16px;
            padding: 48px 56px;
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.8);
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
        }
        .mx-card__brand {
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 4px;
            text-align: center;
        }
        .mx-card__title {
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
        .mx-card__error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 10px 14px;
            border-radius: 4px;
            font-size: 13px;
            margin-bottom: 16px;
            text-align: center;
        }
        .mx-card__field {
            margin-bottom: 16px;
        }
        .mx-card__label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 4px;
            color: #3c3c3c;
        }
        .mx-card__select {
            display: block;
            width: 100%;
            padding: 10px 12px;
            font-size: 14px;
            border: 1px solid #d4d4d4;
            border-radius: 4px;
            background: #fff;
            color: #1b1b1b;
            outline: none;
        }
        .mx-card__select:focus {
            border-color: #1b1b1b;
        }
        .mx-card__submit {
            display: block;
            width: 100%;
            padding: 11px 0;
            font-size: 14px;
            font-weight: 600;
            color: #fff;
            background: #1b1b1b;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 8px;
        }
        .mx-card__submit:hover {
            background: #333;
        }
        .mx-canvas-logout {
            position: absolute;
            top: 24px;
            right: 24px;
            display: inline-block;
            padding: 10px 18px;
            font-size: 13px;
            font-weight: 700;
            color: #fff;
            background: #e11d48;
            border-radius: 8px;
            text-decoration: none;
            z-index: 20;
            box-shadow: 0 4px 12px rgba(225, 29, 72, 0.3);
            transition: background 0.2s ease, transform 0.2s ease;
        }
        .mx-canvas-logout:hover {
            background: #be123c;
            transform: translateY(-1px);
        }
        .mx-card__no-registers {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            color: #075985;
            padding: 14px 18px;
            border-radius: 4px;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 16px;
            text-align: center;
        }
    </style>
    <?php \MXPOSPro\Core\Assets::print_pos_styles(); ?>
</head>
<body>
    <main class="mx-card">
        <p class="mx-card__brand"><?php echo esc_html__('Bienvenido', 'mx-pos-pro'); ?></p>
        <p class="mx-card__title"><?php echo esc_html__('Seleccionar caja', 'mx-pos-pro'); ?></p>
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

        <?php if ($open_cash_error !== ''): ?>
            <div class="mx-card__error"><?php echo esc_html($open_cash_error); ?></div>
        <?php endif; ?>

        <?php if (empty($registers)): ?>
            <div class="mx-card__no-registers">
                <?php echo esc_html__(
                    'No hay cajas disponibles. Contacte al administrador.',
                    'mx-pos-pro'
                ); ?>
            </div>
        <?php else: ?>
            <form method="post" action="<?php echo esc_url(home_url('/pos')); ?>">
                <?php wp_nonce_field('mx_pos_select_register', 'mx_pos_select_register_nonce'); ?>
                <input type="hidden" name="mx_pos_select_register" value="1" />

                <div class="mx-card__field">
                    <label class="mx-card__label" for="mx-pos-register">
                        <?php echo esc_html__('Caja', 'mx-pos-pro'); ?>
                    </label>
                    <select
                        id="mx-pos-register"
                        class="mx-card__select"
                        name="pos_register_id"
                        required
                    >
                        <option value="">
                            <?php echo esc_html__('Seleccionar caja…', 'mx-pos-pro'); ?>
                        </option>
                        <?php foreach ($registers as $reg): ?>
                            <option
                                value="<?php echo esc_attr((string) $reg['id']); ?>"
                                <?php selected($selected_register_id, (string) $reg['id']); ?>
                            >
                                <?php
                                echo esc_html(
                                    sprintf('%s — %s', $reg['name'], $reg['branch_name'])
                                );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="mx-card__submit">
                    <?php echo esc_html__('Seleccionar caja', 'mx-pos-pro'); ?>
                </button>
            </form>
        <?php endif; ?>

    </main>
    <a href="<?php echo esc_url($logout_url); ?>" class="mx-canvas-logout">
        <?php echo esc_html__('Cerrar sesión', 'mx-pos-pro'); ?>
    </a>
    <footer style="position: absolute; bottom: 24px; width: 100%; text-align: center; font-size: 13px; color: #7e7e7e; z-index: 10; font-weight: 500;">
        Desarrollado por rootlabs.mx
    </footer>
    <?php \MXPOSPro\Core\Assets::print_pos_runtime(); ?>
</body>
</html>
