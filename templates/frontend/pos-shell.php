<?php

use MXPOSPro\Core\Assets;

defined('ABSPATH') || exit;

$asset_data = Assets::pos_asset_data();

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
        html,
        body {
            margin: 0;
            min-height: 100%;
            background: #f9f9f9;
        }
    </style>
    <?php if ($asset_data): ?>
        <link rel="stylesheet" href="<?php echo esc_url($asset_data['css_url']); ?>" />
    <?php endif; ?>
</head>
<body class="mx-pos-pro-pos">
    <div id="mx-pos-pro-root">
        <?php if (! $asset_data): ?>
            <div style="max-width:480px;margin:48px auto;padding:32px 48px;background:#fff;border:1px solid #e2e2e2;border-radius:8px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1b1b1b;text-align:center;">
                <p style="font-size:24px;font-weight:700;margin:0 0 8px;">MX POS Pro</p>
                <p style="font-size:14px;color:#7e7e7e;margin:0;">
                    <?php esc_html_e('React build not found. Run npm run build to compile assets.', 'mx-pos-pro'); ?>
                </p>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($asset_data): ?>
        <script>
            window.mxPosProSettings = <?php echo wp_json_encode($asset_data['settings'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        </script>
        <script type="module" src="<?php echo esc_url($asset_data['js_url']); ?>"></script>
    <?php endif; ?>
</body>
</html>


<style id="mx-pos-shift-modal-ui">
/* Ventas del turno / Pre-corte: modal ancho y botones homogéneos */
#mx-pos-pro-root .mx-cut-x-modal-panel {
  width: min(96vw, 1440px) !important;
  max-width: min(96vw, 1440px) !important;
}

#mx-pos-pro-root .mx-cut-x-modal-panel .mx-ui-modal__body {
  padding-left: 28px !important;
  padding-right: 28px !important;
}

#mx-pos-pro-root .mx-cut-x-modal__columns {
  grid-template-columns: minmax(390px, 0.86fr) minmax(780px, 1.34fr) !important;
  gap: 28px !important;
  align-items: start !important;
}

#mx-pos-pro-root .mx-cut-x-modal__counter {
  min-width: 0 !important;
  width: 100% !important;
}

#mx-pos-pro-root .mx-kpi-table-container {
  width: 100% !important;
  overflow-x: visible !important;
}

#mx-pos-pro-root .mx-kpi-table {
  width: 100% !important;
  table-layout: fixed !important;
  border-collapse: collapse !important;
}

#mx-pos-pro-root .mx-kpi-table th,
#mx-pos-pro-root .mx-kpi-table td {
  padding: 12px 14px !important;
  vertical-align: middle !important;
  white-space: nowrap !important;
}

#mx-pos-pro-root .mx-kpi-table th:nth-child(1),
#mx-pos-pro-root .mx-kpi-table td:nth-child(1) {
  width: 12% !important;
}

#mx-pos-pro-root .mx-kpi-table th:nth-child(2),
#mx-pos-pro-root .mx-kpi-table td:nth-child(2) {
  width: 13% !important;
}

#mx-pos-pro-root .mx-kpi-table th:nth-child(3),
#mx-pos-pro-root .mx-kpi-table td:nth-child(3) {
  width: 29% !important;
}

#mx-pos-pro-root .mx-kpi-table th:nth-child(4),
#mx-pos-pro-root .mx-kpi-table td:nth-child(4) {
  width: 14% !important;
}

#mx-pos-pro-root .mx-kpi-table th:nth-child(5),
#mx-pos-pro-root .mx-kpi-table td:nth-child(5) {
  width: 16% !important;
}

#mx-pos-pro-root .mx-kpi-table th:nth-child(6),
#mx-pos-pro-root .mx-kpi-table td:nth-child(6) {
  width: 16% !important;
  text-align: right !important;
}

#mx-pos-pro-root .mx-kpi-table__action {
  text-align: left !important;
}

#mx-pos-pro-root .mx-kpi-table__action .mx-ui-button,
#mx-pos-pro-root .mx-pos-payment-method-correction-btn {
  min-height: 34px !important;
  height: 34px !important;
  padding: 6px 12px !important;
  border-radius: var(--mx-radius-md) !important;
  border: 1px solid var(--mx-border-medium) !important;
  background: var(--mx-action-secondary) !important;
  color: var(--mx-text-primary) !important;
  font-family: var(--mx-font-family) !important;
  font-size: 12px !important;
  line-height: 16px !important;
  font-weight: 600 !important;
  letter-spacing: .05em !important;
  text-transform: uppercase !important;
  box-sizing: border-box !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  cursor: pointer !important;
  white-space: nowrap !important;
}

#mx-pos-pro-root .mx-kpi-table__action .mx-ui-button:hover:not(:disabled),
#mx-pos-pro-root .mx-pos-payment-method-correction-btn:hover:not(:disabled) {
  background: var(--mx-bg-hover) !important;
  border-color: var(--mx-border-dark) !important;
}

#mx-pos-pro-root .mx-pos-payment-method-correction-control {
  display: inline-flex !important;
  align-items: center !important;
  gap: 8px !important;
  margin-left: 0 !important;
  margin-top: 0 !important;
  vertical-align: middle !important;
}

#mx-pos-pro-root .mx-pos-payment-method-correction-select {
  height: 34px !important;
  min-height: 34px !important;
  width: 118px !important;
  max-width: 118px !important;
  padding: 6px 30px 6px 10px !important;
  border: 1px solid var(--mx-border-medium) !important;
  border-radius: var(--mx-radius-md) !important;
  background: var(--mx-bg-surface) !important;
  color: var(--mx-text-primary) !important;
  font-family: var(--mx-font-family) !important;
  font-size: 12px !important;
  line-height: 16px !important;
  box-sizing: border-box !important;
}

#mx-pos-pro-root .mx-pos-payment-method-correction-select:focus {
  outline: none !important;
  border-color: var(--mx-action-primary) !important;
}

#mx-pos-pro-root .mx-cut-modal__actions {
  align-items: center !important;
}

#mx-pos-pro-root .mx-cut-modal__actions .mx-ui-button {
  min-height: 42px !important;
  border-radius: var(--mx-radius-md) !important;
}

@media (max-width: 1280px) {
  #mx-pos-pro-root .mx-cut-x-modal-panel {
    width: calc(100vw - 32px) !important;
    max-width: calc(100vw - 32px) !important;
  }

  #mx-pos-pro-root .mx-cut-x-modal__columns {
    grid-template-columns: minmax(360px, .9fr) minmax(700px, 1.1fr) !important;
    gap: 20px !important;
  }

  #mx-pos-pro-root .mx-kpi-table th,
  #mx-pos-pro-root .mx-kpi-table td {
    padding-left: 10px !important;
    padding-right: 10px !important;
  }
}

@media (max-width: 1080px) {
  #mx-pos-pro-root .mx-cut-x-modal__columns {
    grid-template-columns: 1fr !important;
  }

  #mx-pos-pro-root .mx-kpi-table-container {
    overflow-x: auto !important;
  }

  #mx-pos-pro-root .mx-kpi-table {
    min-width: 760px !important;
  }
}

/* Ventas del turno: indicador visual de elemento clickable */
#mx-pos-pro-root .mx-session-banner__amount--clickable {
  cursor: pointer !important;
  user-select: none !important;
  transition: background-color .15s ease, border-color .15s ease, color .15s ease, transform .12s ease !important;
}

#mx-pos-pro-root .mx-session-banner__amount--clickable:hover {
  background: var(--mx-bg-hover) !important;
  border-color: var(--mx-border-dark) !important;
  color: var(--mx-text-primary) !important;
}

#mx-pos-pro-root .mx-session-banner__amount--clickable:active {
  transform: translateY(1px) !important;
}

#mx-pos-pro-root .mx-session-banner__amount--clickable:focus-visible {
  outline: 2px solid var(--mx-action-primary) !important;
  outline-offset: 2px !important;
}
</style>

<script id="mx-pos-order-folio-bridge">
(function () {
  if (window.mxPosOrderFolioBridgeInstalled) return;
  window.mxPosOrderFolioBridgeInstalled = true;

  const posToOrder = new Map();
  const orderToPos = new Map();

  function parseId(text) {
    const match = String(text || '').match(/#?\s*(\d+)/);
    return match ? parseInt(match[1], 10) : 0;
  }

  function rememberSale(item) {
    if (!item || typeof item !== 'object') return;

    const posId = parseInt(item.id || item.sale_id || item.pos_sale_id || 0, 10);
    const orderId = parseInt(item.wc_order_id || item.order_id || 0, 10);

    if (!posId || !orderId) return;

    posToOrder.set(posId, orderId);
    orderToPos.set(orderId, posId);
  }

  function rememberPayload(payload) {
    if (!payload || typeof payload !== 'object') return;

    if (Array.isArray(payload.items)) {
      payload.items.forEach(rememberSale);
    }

    if (Array.isArray(payload.sales)) {
      payload.sales.forEach(rememberSale);
    }

    if (payload.id || payload.wc_order_id || payload.order_id) {
      rememberSale(payload);
    }
  }

  function normalizeVisibleFolios() {
    document.querySelectorAll('.mx-history-table tbody tr').forEach((row) => {
      const idEl = row.querySelector('.mx-history-table__id');
      if (!idEl) return;

      const currentId = parseId(idEl.textContent);
      if (!currentId) return;

      let posId = posToOrder.has(currentId) ? currentId : 0;
      let orderId = posId ? posToOrder.get(posId) : 0;

      if (!posId && orderToPos.has(currentId)) {
        orderId = currentId;
        posId = orderToPos.get(currentId);
      }

      if (!posId || !orderId) return;

      row.dataset.mxPosSaleId = String(posId);
      row.dataset.mxWooOrderId = String(orderId);

      if (idEl.textContent.trim() !== '#' + orderId) {
        idEl.textContent = '#' + orderId;
      }

      idEl.title = 'Venta POS #' + posId;
    });
  }

  const originalFetch = window.fetch;
  window.fetch = async function () {
    const response = await originalFetch.apply(this, arguments);

    try {
      const input = arguments[0];
      const url = typeof input === 'string' ? input : input && input.url ? input.url : String(input || '');

      if (url.includes('/mx-pos/v1/sales/history') || url.includes('/mx-pos/v1/sales/lookup')) {
        response.clone().json()
          .then((payload) => {
            rememberPayload(payload);
            setTimeout(normalizeVisibleFolios, 0);
            setTimeout(normalizeVisibleFolios, 150);
            setTimeout(normalizeVisibleFolios, 500);
          })
          .catch(function () {});
      }
    } catch (err) {}

    return response;
  };

  const root = document.getElementById('mx-pos-pro-root') || document.body;
  if (root && window.MutationObserver) {
    new MutationObserver(normalizeVisibleFolios).observe(root, {
      childList: true,
      subtree: true
    });
  }

  setInterval(normalizeVisibleFolios, 1500);
})();
</script>
<script id="mx-pos-payment-method-correction">
(function () {
  if (window.mxPosPaymentMethodCorrectionInstalled) return;
  window.mxPosPaymentMethodCorrectionInstalled = true;

  const LABELS = { cash: 'Efectivo', card: 'Tarjeta' };

  function getSettings() {
    const settings = window.mxPosProSettings;
    if (!settings || !settings.root || !settings.nonce) return null;
    return settings;
  }

  function normalizeRoot(root) {
    return String(root).replace(/\/?$/, '/');
  }

  function parseId(text) {
    const match = String(text || '').match(/#?\s*(\d+)/);
    return match ? parseInt(match[1], 10) : 0;
  }

  function inferMethod(text) {
    const normalized = String(text || '').toLowerCase();
    if (normalized.includes('tarjeta') || normalized.includes('card')) return 'card';
    if (normalized.includes('efectivo') || normalized.includes('cash')) return 'cash';
    return 'cash';
  }

  async function updatePaymentMethod(id, method, row) {
    const settings = getSettings();
    if (!settings) {
      alert('No se pudo leer la configuración del POS.');
      return;
    }

    if (!id || !method) return;

    const label = LABELS[method] || method;
    const ok = window.confirm('¿Cambiar el método de pago a ' + label + '? Esto ajustará también el cierre de caja.');
    if (!ok) return;

    row.dataset.mxPaymentUpdating = '1';

    try {
      const res = await fetch(normalizeRoot(settings.root) + 'sales/' + id + '/payment-method', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': settings.nonce,
          Accept: 'application/json'
        },
        credentials: 'same-origin',
        body: JSON.stringify({ payment_method: method })
      });

      const body = await res.json().catch(() => null);

      if (!res.ok) {
        throw new Error((body && body.message) || 'No se pudo cambiar el método de pago.');
      }

      alert((body && body.message) || 'Método de pago actualizado.');
      window.location.reload();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'No se pudo cambiar el método de pago.');
      delete row.dataset.mxPaymentUpdating;
    }
  }

  function buildControl(id, currentMethod, row) {
    const wrap = document.createElement('span');
    wrap.className = 'mx-pos-payment-method-correction-control';
    wrap.style.display = 'inline-flex';
    wrap.style.gap = '4px';
    wrap.style.marginLeft = '6px';
    wrap.style.alignItems = 'center';

    const select = document.createElement('select');
    select.className = 'mx-pos-payment-method-correction-select';
    select.style.fontSize = '12px';
    select.style.maxWidth = '92px';

    Object.entries(LABELS).forEach(([value, label]) => {
      const option = document.createElement('option');
      option.value = value;
      option.textContent = label;
      option.selected = value === currentMethod;
      select.appendChild(option);
    });

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'mx-pos-payment-method-correction-btn';
    btn.textContent = 'Cambiar';
    btn.style.fontSize = '12px';
    btn.style.padding = '3px 6px';
    btn.style.border = '1px solid #d1d5db';
    btn.style.borderRadius = '6px';
    btn.style.background = '#fff';
    btn.style.cursor = 'pointer';

    btn.addEventListener('click', function () {
      if (row.dataset.mxPaymentUpdating === '1') return;
      updatePaymentMethod(id, select.value, row);
    });

    wrap.appendChild(select);
    wrap.appendChild(btn);

    return wrap;
  }

  function enhanceHistoryRows() {
    document.querySelectorAll('.mx-history-table tbody tr').forEach((row) => {
      if (row.dataset.mxPaymentMethodCorrection === '1') return;

      const idEl = row.querySelector('.mx-history-table__id');
      const methodEl = row.querySelector('.mx-history-table__method');

      if (!idEl || !methodEl) return;

      const saleId = parseInt(row.dataset.mxPosSaleId || '', 10) || parseId(idEl.textContent);
      if (!saleId) return;

      const cell = methodEl.closest('td') || methodEl.parentElement;
      if (!cell) return;

      const currentMethod = inferMethod(methodEl.textContent);

      cell.textContent = '';
      cell.appendChild(buildControl(saleId, currentMethod, row));
      row.dataset.mxPaymentMethodCorrection = '1';
    });
  }

  function enhanceCutRows() {
    document.querySelectorAll('.mx-kpi-table tbody tr').forEach((row) => {
      if (row.dataset.mxPaymentMethodCorrection === '1') return;

      const cells = row.querySelectorAll('td');
      if (cells.length < 3) return;

      const orderId = parseId(cells[0].textContent);
      if (!orderId) return;

      const methodCell = cells[2];
      const currentMethod = inferMethod(methodCell.textContent);

      methodCell.textContent = '';
      methodCell.appendChild(buildControl(orderId, currentMethod, row));
      row.dataset.mxPaymentMethodCorrection = '1';
    });
  }

  function enhance() {
    enhanceHistoryRows();
    enhanceCutRows();
  }

  const observer = new MutationObserver(enhance);

  function start() {
    enhance();

    if (document.body) {
      observer.observe(document.body, { childList: true, subtree: true });
    }

    window.setInterval(enhance, 2000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
})();
</script>
