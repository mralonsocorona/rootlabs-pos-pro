<?php
/**
 * Plugin Name:  Rootlabs Pos Pro
 * Plugin URI:   https://rootlabs.mx
 * Description:  Sistema de Punto de Venta premium para WooCommerce. Interfaz React, backend PHP seguro, compatible HPOS.
 * Version:      0.1.4
 * Author:       rootlabs.mx
 * Author URI:   https://rootlabs.mx
 * License:      GPL-2.0+
 * License URI:  http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:  mx-pos-pro
 * Domain Path:  /languages
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 *
 * @package MXPOSPro
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/API/PaymentMethodCorrectionController.php';

define('MX_POS_PRO_VERSION', '0.1.4');
define('MX_POS_PRO_DB_VERSION', '1.12');
define('MX_POS_PRO_FILE', __FILE__);
define('MX_POS_PRO_DIR', plugin_dir_path(__FILE__));
define('MX_POS_PRO_URL', plugin_dir_url(__FILE__));
define('MX_POS_PRO_INCLUDES', MX_POS_PRO_DIR . 'includes/');
define('MX_POS_PRO_ASSETS', MX_POS_PRO_URL . 'assets/');
define('MX_POS_PRO_DIST', MX_POS_PRO_ASSETS . 'dist/');

require_once MX_POS_PRO_INCLUDES . 'Core/Activation.php';
require_once MX_POS_PRO_INCLUDES . 'Core/Deactivation.php';
require_once MX_POS_PRO_INCLUDES . 'Core/Compatibility.php';

register_activation_hook(__FILE__, ['MXPOSPro\\Core\\Activation', 'activate']);
register_deactivation_hook(__FILE__, ['MXPOSPro\\Core\\Deactivation', 'deactivate']);

add_action(
    'before_woocommerce_init',
    ['MXPOSPro\\Core\\Compatibility', 'declare_hpos_compatibility']
);

require_once MX_POS_PRO_INCLUDES . 'Core/Plugin.php';

MXPOSPro\Core\Plugin::instance()->init();
