<?php

defined('ABSPATH') || exit;

$login_error     = $login_error ?? false;
$locked_minutes  = $locked_minutes ?? 0;
$username_esc    = $username_esc ?? '';

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
        .mx-login {
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
        .mx-login__brand {
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 4px;
            text-align: center;
        }
        .mx-login__title {
            font-size: 14px;
            font-weight: 400;
            color: #7e7e7e;
            margin: 0 0 28px;
            text-align: center;
        }
        .mx-login__field {
            margin-bottom: 16px;
        }
        .mx-login__label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 4px;
            color: #3c3c3c;
        }
        .mx-login__input {
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
        .mx-login__input:focus {
            border-color: #1b1b1b;
        }
        .mx-login__error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 10px 14px;
            border-radius: 4px;
            font-size: 13px;
            margin-bottom: 16px;
            text-align: center;
        }
        .mx-login__submit {
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
        .mx-login__submit:hover {
            background: #333;
        }
    </style>
    <?php \MXPOSPro\Core\Assets::print_pos_styles(); ?>
</head>
<body>
    <main class="mx-login">
        <p class="mx-login__brand"><?php echo esc_html__('Bienvenido', 'mx-pos-pro'); ?></p>
        <p class="mx-login__title"><?php echo esc_html__('Iniciar sesión', 'mx-pos-pro'); ?></p>

        <?php if ($locked_minutes > 0): ?>
            <div class="mx-login__error">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %d: minutes remaining */
                        __('Empleado bloqueado. Intente de nuevo en %d minuto(s).', 'mx-pos-pro'),
                        $locked_minutes
                    )
                );
                ?>
            </div>
        <?php elseif ($login_error): ?>
            <div class="mx-login__error">
                <?php echo esc_html__('Credenciales inválidas.', 'mx-pos-pro'); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(home_url('/pos')); ?>">
            <?php wp_nonce_field('mx_pos_login', 'mx_pos_login_nonce'); ?>
            <input type="hidden" name="mx_pos_login" value="1" />

            <div class="mx-login__field">
                <label class="mx-login__label" for="mx-pos-username">
                    <?php echo esc_html__('Usuario', 'mx-pos-pro'); ?>
                </label>
                <input
                    id="mx-pos-username"
                    class="mx-login__input"
                    type="text"
                    name="mx_pos_username"
                    value="<?php echo esc_attr($username_esc); ?>"
                    autocomplete="username"
                    required
                />
            </div>

            <div class="mx-login__field">
                <label class="mx-login__label" for="mx-pos-password">
                    <?php echo esc_html__('Contraseña', 'mx-pos-pro'); ?>
                </label>
                <input
                    id="mx-pos-password"
                    class="mx-login__input"
                    type="password"
                    name="mx_pos_password"
                    autocomplete="current-password"
                    required
                />
            </div>

            <button type="submit" class="mx-login__submit">
                <?php echo esc_html__('Entrar', 'mx-pos-pro'); ?>
            </button>
        </form>
    </main>
    <footer style="position: absolute; bottom: 24px; width: 100%; text-align: center; font-size: 13px; color: #7e7e7e; z-index: 10; font-weight: 500;">
        Desarrollado por rootlabs.mx
    </footer>
</body>
</html>
