<?php

defined('ABSPATH') || exit;

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
</head>
<body>
    <main style="max-width:480px;margin:48px auto;padding:32px 48px;background:#fff;border:1px solid #dcdcde;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1d2327;">
        <h1 style="font-size:24px;line-height:32px;margin:0 0 8px;">
            <?php esc_html_e('Access denied', 'mx-pos-pro'); ?>
        </h1>
        <p style="font-size:14px;line-height:20px;margin:0;">
            <?php esc_html_e('You do not have permission to access MX POS Pro.', 'mx-pos-pro'); ?>
        </p>
    </main>
</body>
</html>
