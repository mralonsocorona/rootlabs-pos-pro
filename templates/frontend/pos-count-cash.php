<?php

defined('ABSPATH') || exit;

$employee           = $employee ?? [];
$selected_register   = $selected_register ?? [];
$count_cash_error   = $count_cash_error ?? '';
$logout_url         = $logout_url ?? '#';
$denom_values       = $denom_values ?? [];

$display_name  = isset($employee['display_name']) ? $employee['display_name'] : '';
$register_name = isset($selected_register['register_name']) ? $selected_register['register_name'] : '';
$branch_name   = isset($selected_register['branch_name']) ? $selected_register['branch_name'] : '';

$denominations = [
    ['key' => 'bill-1000', 'label' => 'Billete $1000',  'value' => 1000],
    ['key' => 'bill-500',  'label' => 'Billete $500',   'value' => 500],
    ['key' => 'bill-200',  'label' => 'Billete $200',   'value' => 200],
    ['key' => 'bill-100',  'label' => 'Billete $100',   'value' => 100],
    ['key' => 'bill-50',   'label' => 'Billete $50',    'value' => 50],
    ['key' => 'bill-20',   'label' => 'Billete $20',    'value' => 20],
    ['key' => 'coin-20',   'label' => 'Moneda $20',     'value' => 20],
    ['key' => 'coin-10',   'label' => 'Moneda $10',     'value' => 10],
    ['key' => 'coin-5',    'label' => 'Moneda $5',      'value' => 5],
    ['key' => 'coin-2',    'label' => 'Moneda $2',      'value' => 2],
    ['key' => 'coin-1',    'label' => 'Moneda $1',      'value' => 1],
    ['key' => 'coin-050',  'label' => 'Moneda $0.50',   'value' => 0.5],
];

$count_cash_values = isset($count_cash_values) && is_array($count_cash_values)
    ? $count_cash_values
    : array();

function mx_get_denom_val(array $values, string $key): string
{
    return isset($values[$key]) ? esc_attr($values[$key]) : '';
}

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
            max-width: 1080px;
            margin: 16px;
            padding: 32px 40px;
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.8);
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            max-height: calc(100vh - 80px);
            overflow-y: auto;
        }

        .mx-card__brand {
            font-size: 24px;
            line-height: 30px;
            font-weight: 800;
            margin: 0;
            text-align: center;
            letter-spacing: -0.02em;
        }

        .mx-card__title {
            font-size: 13px;
            line-height: 18px;
            color: #7e7e7e;
            margin: 2px 0 14px;
            text-align: center;
        }

        .mx-card__context {
            font-size: 13px;
            line-height: 18px;
            color: #1b1b1b;
            margin: 0 0 18px;
            text-align: center;
        }

        .mx-card__error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 10px 14px;
            border-radius: 4px;
            font-size: 13px;
            margin-bottom: 14px;
            text-align: center;
        }

        .mx-card__form {
            display: grid;
            grid-template-columns: minmax(0, 1.12fr) minmax(0, .88fr);
            gap: 16px 20px;
            align-items: start;
        }

        .mx-card__denom-section {
            display: flex;
            flex-direction: column;
            gap: 0;
            min-width: 0;
        }

        .mx-card__section-title {
            font-size: 12px;
            line-height: 16px;
            font-weight: 800;
            letter-spacing: .02em;
            color: #1b1b1b;
            margin: 0;
            padding-bottom: 6px;
            border-bottom: 1px solid #dcdcdc;
        }

        .mx-card__denom-row {
            display: grid;
            grid-template-columns: minmax(120px, 1fr) 92px 96px;
            align-items: center;
            gap: 10px;
            min-height: 38px;
            padding: 4px 0;
            border-bottom: 1px solid #eeeeee;
        }

        .mx-card__denom-row:last-of-type {
            border-bottom: none;
        }

        .mx-card__denom-label {
            font-size: 13px;
            line-height: 18px;
            font-weight: 500;
            color: #1b1b1b;
        }

        .mx-card__denom-input {
            width: 92px;
            height: 30px;
            padding: 4px 8px;
            font-size: 13px;
            line-height: 18px;
            border: 1px solid #d4d4d4;
            border-radius: 4px;
            background: #fff;
            color: #1b1b1b;
            text-align: center;
            outline: none;
        }

        .mx-card__denom-input:focus {
            border-color: #1b1b1b;
        }

        .mx-card__denom-subtotal {
            font-size: 13px;
            line-height: 18px;
            color: #4c4c4c;
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .mx-card__actions {
            grid-column: 1 / -1;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            margin-top: 0;
        }

        .mx-card__total {
            grid-column: 1 / -1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-top: 2px;
            padding: 12px 0 0;
            border-top: 2px solid #1b1b1b;
        }

        .mx-card__total-label {
            font-size: 15px;
            line-height: 20px;
            font-weight: 800;
        }

        .mx-card__total-amount {
            font-size: 20px;
            line-height: 28px;
            font-weight: 900;
            font-variant-numeric: tabular-nums;
        }

        .mx-card__submit {
            min-width: 260px;
            height: 42px;
            padding: 0 24px;
            font-size: 14px;
            font-weight: 800;
            color: #fff;
            background: #1b1b1b;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .mx-card__submit:hover {
            background: #000;
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

        @media (max-height: 800px) {
            .mx-card {
                margin-top: 14px;
                margin-bottom: 14px;
                padding: 18px 24px 16px;
            }

            .mx-card__brand {
                font-size: 22px;
                line-height: 28px;
            }

            .mx-card__title {
                margin-bottom: 10px;
            }

            .mx-card__context {
                margin-bottom: 12px;
            }

            .mx-card__form {
                gap: 10px 18px;
            }

            .mx-card__denom-row {
                min-height: 34px;
                padding: 2px 0;
            }

            .mx-card__denom-input {
                height: 28px;
            }

            .mx-card__total {
                padding-top: 10px;
            }

            .mx-card__submit {
                height: 40px;
            }
        }

        @media (max-width: 760px) {
            .mx-card {
                width: calc(100vw - 24px);
                margin: 12px auto;
                padding: 20px 18px;
            }

            .mx-card__form {
                grid-template-columns: 1fr;
            }

            .mx-card__denom-row {
                grid-template-columns: minmax(100px, 1fr) 84px 84px;
            }

            .mx-card__denom-input {
                width: 84px;
            }

            .mx-card__submit {
                width: 100%;
                min-width: 0;
                justify-self: stretch;
            }
        }

        /* Sprint 8 — Conteo inicial polish */

        .mx-card__brand {
            font-size: 23px;
            line-height: 28px;
            margin-bottom: 2px;
        }

        .mx-card__title {
            margin-bottom: 12px;
        }

        .mx-card__context {
            margin-bottom: 18px;
        }

        .mx-card__form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, .9fr);
            gap: 16px 18px;
            align-items: start;
        }

        .mx-card__denom-section {
            display: flex;
            flex-direction: column;
            min-width: 0;
            padding: 12px 14px 10px;
            background: #fbfbfb;
            border: 1px solid #e6e6e6;
            border-radius: 8px;
        }

        .mx-card__denom-section--bills {
            background: #ffffff;
        }

        .mx-card__section-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 0 0 6px;
            padding: 0 0 8px;
            border-bottom: 1px solid #d9d9d9;
            font-size: 12px;
            line-height: 16px;
            font-weight: 800;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .mx-card__denom-row {
            display: grid;
            grid-template-columns: minmax(124px, 1fr) 92px 88px;
            align-items: center;
            gap: 10px;
            min-height: 38px;
            padding: 4px 0;
            border-bottom: 1px solid #ececec;
        }

        .mx-card__denom-row:last-child {
            border-bottom: none;
        }

        .mx-card__denom-label {
            font-size: 13px;
            line-height: 18px;
            font-weight: 600;
            color: #1b1b1b;
        }

        .mx-card__denom-input {
            width: 92px;
            height: 30px;
            padding: 4px 8px;
            font-size: 13px;
            line-height: 18px;
            font-weight: 700;
            text-align: center;
            border: 1px solid #cfcfcf;
            border-radius: 6px;
            background: #fff;
        }

        .mx-card__denom-input:focus {
            border-color: #000;
            box-shadow: 0 0 0 2px rgba(0, 0, 0, .08);
        }

        .mx-card__denom-subtotal {
            font-size: 13px;
            line-height: 18px;
            font-weight: 700;
            color: #1b1b1b;
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .mx-card__total {
            grid-column: 1 / -1;
            margin-top: 0;
            padding: 14px 16px;
            background: #f7f7f7;
            border: 1px solid #dadada;
            border-top: 2px solid #1b1b1b;
            border-radius: 8px;
        }

        .mx-card__total-label {
            font-size: 15px;
            line-height: 20px;
            font-weight: 900;
        }

        .mx-card__total-amount {
            font-size: 22px;
            line-height: 28px;
            font-weight: 900;
        }

        .mx-card__actions {
            grid-column: 1 / -1;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            margin-top: -2px;
        }

        .mx-card__submit {
            width: min(280px, 100%);
            min-width: 260px;
            height: 44px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 900;
        }



        @media (max-height: 800px) {
            .mx-card {
                margin-top: 12px;
                padding: 18px 22px 16px;
            }

            .mx-card__context {
                margin-bottom: 12px;
            }

            .mx-card__form {
                gap: 12px 16px;
            }

            .mx-card__denom-section {
                padding: 10px 12px 8px;
            }

            .mx-card__denom-row {
                min-height: 34px;
                padding: 2px 0;
            }

            .mx-card__denom-input {
                height: 28px;
            }

            .mx-card__total {
                padding: 12px 14px;
            }

            .mx-card__submit {
                height: 40px;
            }
        }

        @media (max-width: 760px) {
            .mx-card {
                width: calc(100vw - 24px);
                margin: 12px auto;
                padding: 18px 16px;
            }

            .mx-card__form {
                grid-template-columns: 1fr;
            }

            .mx-card__denom-row {
                grid-template-columns: minmax(100px, 1fr) 84px 84px;
            }

            .mx-card__denom-input {
                width: 84px;
            }

            .mx-card__actions {
                justify-content: stretch;
            }

            .mx-card__submit {
                width: 100%;
                min-width: 0;
            }
        }

    </style>
    <?php \MXPOSPro\Core\Assets::print_pos_styles(); ?>
</head>
<body>
    <main class="mx-card">
        <p class="mx-card__brand"><?php echo esc_html__('Bienvenido', 'mx-pos-pro'); ?></p>
        <p class="mx-card__title"><?php echo esc_html__('Conteo inicial', 'mx-pos-pro'); ?></p>
        <p class="mx-card__context">
            <?php
            echo esc_html(
                sprintf(
                    /* translators: 1: employee name, 2: register name, 3: branch name */
                    __('%1$s — %2$s — %3$s', 'mx-pos-pro'),
                    $display_name,
                    $register_name,
                    $branch_name
                )
            );
            ?>
        </p>

        <?php if ($count_cash_error !== ''): ?>
            <div class="mx-card__error"><?php echo esc_html($count_cash_error); ?></div>
        <?php endif; ?>

        <form class="mx-card__form" method="post" action="<?php echo esc_url(home_url('/pos')); ?>" id="mx-count-form">
            <?php wp_nonce_field('mx_pos_count_cash', 'mx_pos_count_cash_nonce'); ?>
            <input type="hidden" name="mx_pos_count_cash" value="1" />

            <section class="mx-card__denom-section mx-card__denom-section--bills" aria-labelledby="mx-count-bills-title">
                <p class="mx-card__section-title" id="mx-count-bills-title"><?php echo esc_html__('Billetes', 'mx-pos-pro'); ?></p>

                <?php foreach ($denominations as $d) : ?>
                    <?php
                    $denom_type = strtolower((string) ($d['type'] ?? ''));
                    $denom_key = strtolower((string) ($d['key'] ?? ''));
                    $denom_label = strtolower((string) ($d['label'] ?? ''));

                    $is_bill_denom =
                        in_array($denom_type, array('bill', 'bills', 'billete', 'billetes'), true)
                        || false !== strpos($denom_key, 'bill')
                        || false !== strpos($denom_key, 'billete')
                        || false !== strpos($denom_label, 'billete');

                    if (! $is_bill_denom) :
                    ?>
                        <?php continue; ?>
                    <?php endif; ?>

                    <div class="mx-card__denom-row">
                        <label class="mx-card__denom-label" for="mx-denom-<?php echo esc_attr($d['key']); ?>">
                            <?php echo esc_html($d['label']); ?>
                        </label>
                        <input
                            id="mx-denom-<?php echo esc_attr($d['key']); ?>"
                            class="mx-card__denom-input mx-denom-input"
                            type="number"
                            min="0"
                            step="1"
                            inputmode="numeric"
                            name="denominations[<?php echo esc_attr($d['key']); ?>]"
                            value="<?php echo esc_attr(mx_get_denom_val($count_cash_values, $d['key'])); ?>"
                            data-value="<?php echo esc_attr((string) $d['value']); ?>"
                        />
                        <span
                            class="mx-card__denom-subtotal mx-denom-subtotal"
                            data-key="<?php echo esc_attr($d['key']); ?>"
                        >$0.00</span>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="mx-card__denom-section mx-card__denom-section--coins" aria-labelledby="mx-count-coins-title">
                <p class="mx-card__section-title" id="mx-count-coins-title"><?php echo esc_html__('Monedas', 'mx-pos-pro'); ?></p>

                <?php foreach ($denominations as $d) : ?>
                    <?php
                    $denom_type = strtolower((string) ($d['type'] ?? ''));
                    $denom_key = strtolower((string) ($d['key'] ?? ''));
                    $denom_label = strtolower((string) ($d['label'] ?? ''));

                    $is_coin_denom =
                        in_array($denom_type, array('coin', 'coins', 'moneda', 'monedas'), true)
                        || false !== strpos($denom_key, 'coin')
                        || false !== strpos($denom_key, 'moneda')
                        || false !== strpos($denom_label, 'moneda');

                    if (! $is_coin_denom) :
                    ?>
                        <?php continue; ?>
                    <?php endif; ?>

                    <div class="mx-card__denom-row">
                        <label class="mx-card__denom-label" for="mx-denom-<?php echo esc_attr($d['key']); ?>">
                            <?php echo esc_html($d['label']); ?>
                        </label>
                        <input
                            id="mx-denom-<?php echo esc_attr($d['key']); ?>"
                            class="mx-card__denom-input mx-denom-input"
                            type="number"
                            min="0"
                            step="1"
                            inputmode="numeric"
                            name="denominations[<?php echo esc_attr($d['key']); ?>]"
                            value="<?php echo esc_attr(mx_get_denom_val($count_cash_values, $d['key'])); ?>"
                            data-value="<?php echo esc_attr((string) $d['value']); ?>"
                        />
                        <span
                            class="mx-card__denom-subtotal mx-denom-subtotal"
                            data-key="<?php echo esc_attr($d['key']); ?>"
                        >$0.00</span>
                    </div>
                <?php endforeach; ?>
            </section>

            <div class="mx-card__total">
                <span class="mx-card__total-label"><?php echo esc_html__('Total apertura', 'mx-pos-pro'); ?></span>
                <span class="mx-card__total-amount" id="mx-count-total">$0.00</span>
            </div>
            <div class="mx-card__actions">

            <button type="submit" class="mx-card__submit">
                <?php echo esc_html__('Confirmar conteo', 'mx-pos-pro'); ?>
            </button>
            </div>
        </form>

    </main>
    <a href="<?php echo esc_url($logout_url); ?>" class="mx-canvas-logout">
        <?php echo esc_html__('Cerrar sesión', 'mx-pos-pro'); ?>
    </a>

    <script>
    (function() {
        var form = document.getElementById('mx-count-form');
        if (!form) return;

        var inputs = form.querySelectorAll('.mx-denom-input');
        var subtotals = form.querySelectorAll('.mx-denom-subtotal');
        var totalEl = document.getElementById('mx-count-total');

        function formatCurrency(amount) {
            return '$' + amount.toFixed(2);
        }

        function recalc() {
            var total = 0;
            inputs.forEach(function(input, i) {
                var qty = parseInt(input.value, 10) || 0;
                if (qty < 0) qty = 0;
                var val = parseFloat(input.getAttribute('data-denom-value')) || 0;
                var subtotal = qty * val;
                if (subtotals[i]) {
                    subtotals[i].textContent = formatCurrency(subtotal);
                }
                total += subtotal;
            });
            if (totalEl) {
                totalEl.textContent = formatCurrency(total);
            }
        }

        inputs.forEach(function(input) {
            input.addEventListener('input', recalc);
        });

        recalc();
    })();
    </script>

    <script>
    (function () {
        var form = document.getElementById('mx-count-form');

        if (!form) {
            return;
        }

        var inputs = Array.prototype.slice.call(form.querySelectorAll('.mx-denom-input'));
        var totalNode = document.getElementById('mx-count-total');

        function parseMoneyValue(input) {
            var raw = input.getAttribute('data-value') || input.dataset.value || '';

            raw = String(raw).replace(',', '.').replace(/[^0-9.]/g, '');

            var amount = Number.parseFloat(raw);

            if (!Number.isFinite(amount) || amount <= 0) {
                var row = input.closest('.mx-card__denom-row');
                var label = row ? row.querySelector('.mx-card__denom-label') : null;
                var labelText = label ? label.textContent : '';
                var match = labelText.match(/([0-9]+(?:[.,][0-9]+)?)/);

                if (match) {
                    amount = Number.parseFloat(match[1].replace(',', '.'));
                }
            }

            return Number.isFinite(amount) ? amount : 0;
        }

        function parseQuantity(input) {
            var qty = Number.parseInt(input.value || '0', 10);

            if (!Number.isFinite(qty) || qty < 0) {
                return 0;
            }

            return qty;
        }

        function formatMoney(value) {
            return value.toLocaleString('es-MX', {
                style: 'currency',
                currency: 'MXN',
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function recalcCountCash() {
            var total = 0;

            inputs.forEach(function (input) {
                var row = input.closest('.mx-card__denom-row');
                var subtotalNode = row ? row.querySelector('.mx-denom-subtotal') : null;
                var amount = parseMoneyValue(input);
                var qty = parseQuantity(input);
                var subtotal = amount * qty;

                total += subtotal;

                if (subtotalNode) {
                    subtotalNode.textContent = formatMoney(subtotal);
                }
            });

            if (totalNode) {
                totalNode.textContent = formatMoney(total);
            }
        }

        inputs.forEach(function (input) {
            input.addEventListener('input', recalcCountCash);
            input.addEventListener('change', recalcCountCash);
        });

        recalcCountCash();
    })();
    </script>

    <footer style="position: absolute; bottom: 24px; width: 100%; text-align: center; font-size: 13px; color: #7e7e7e; z-index: 10; font-weight: 500;">
        Desarrollado por rootlabs.mx
    </footer>
    <?php \MXPOSPro\Core\Assets::print_pos_runtime(); ?>
</body>
</html>
