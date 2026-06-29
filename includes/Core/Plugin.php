<?php

namespace MXPOSPro\Core;

defined('ABSPATH') || exit;

if (! class_exists(\MXPOSPro\Database\Migrator::class)) {
    require_once dirname(__DIR__) . '/Database/Migrator.php';
}

class Plugin
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init(): void
    {
        add_action('plugins_loaded', [$this, 'bootstrap'], 20);
    }

    public function bootstrap(): void
    {
        require_once MX_POS_PRO_INCLUDES . 'Core/Compatibility.php';

        if (! Compatibility::check()) {
            return;
        }

        require_once MX_POS_PRO_INCLUDES . 'Database/Schema.php';
        require_once MX_POS_PRO_INCLUDES . 'Database/Migrator.php';
        require_once MX_POS_PRO_INCLUDES . 'Core/Capabilities.php';

        \MXPOSPro\Database\Migrator::maybe_migrate();

        add_action('admin_init', ['\MXPOSPro\Database\Migrator', 'maybe_migrate']);

        require_once MX_POS_PRO_INCLUDES . 'Entities/BranchRepository.php';
        require_once MX_POS_PRO_INCLUDES . 'Entities/RegisterRepository.php';
        require_once MX_POS_PRO_INCLUDES . 'Entities/EmployeeRepository.php';
        require_once MX_POS_PRO_INCLUDES . 'Auth/POSAuthService.php';
        require_once MX_POS_PRO_INCLUDES . 'Context/POSContextService.php';
        require_once MX_POS_PRO_INCLUDES . 'Core/POSCapabilityBridge.php';
        require_once MX_POS_PRO_INCLUDES . 'Payments/PaymentMethodRepository.php';

        require_once MX_POS_PRO_INCLUDES . 'Audit/AuditLogger.php';
        require_once MX_POS_PRO_INCLUDES . 'Admin/AuditPage.php';
        require_once MX_POS_PRO_INCLUDES . 'Reports/DashboardDataService.php';
        require_once MX_POS_PRO_INCLUDES . 'Admin/DashboardPage.php';

        require_once MX_POS_PRO_INCLUDES . 'Admin/AdminPage.php';

        (new \MXPOSPro\Admin\AdminPage())->register();

        require_once MX_POS_PRO_INCLUDES . 'Core/Assets.php';

        (new \MXPOSPro\Core\Assets())->register();

        require_once MX_POS_PRO_INCLUDES . 'Core/RestSecurity.php';

        require_once MX_POS_PRO_INCLUDES . 'Frontend/PosRoute.php';

        (new \MXPOSPro\Frontend\PosRoute())->register();

        require_once MX_POS_PRO_INCLUDES . 'Products/ProductIndexRepository.php';
        require_once MX_POS_PRO_INCLUDES . 'Products/ProductSearch.php';
        require_once MX_POS_PRO_INCLUDES . 'Products/ProductIndexer.php';
        require_once MX_POS_PRO_INCLUDES . 'Products/ProductIndexHooks.php';

        (new \MXPOSPro\Products\ProductIndexHooks(
            new \MXPOSPro\Products\ProductIndexRepository()
        ))->register();

        require_once MX_POS_PRO_INCLUDES . 'API/ProductSearchController.php';
        require_once MX_POS_PRO_INCLUDES . 'API/ProductCatalogController.php';

        (new \MXPOSPro\API\ProductSearchController())->register();
        (new \MXPOSPro\API\ProductCatalogController())->register();

        require_once MX_POS_PRO_INCLUDES . 'Cart/CartValidatedItem.php';
        require_once MX_POS_PRO_INCLUDES . 'Cart/CartValidationResult.php';
        require_once MX_POS_PRO_INCLUDES . 'Cart/CartDiscountValidator.php';
        require_once MX_POS_PRO_INCLUDES . 'Cart/CartItemValidator.php';
        require_once MX_POS_PRO_INCLUDES . 'API/CartValidationController.php';

        (new \MXPOSPro\API\CartValidationController())->register();

        require_once MX_POS_PRO_INCLUDES . 'Cash/CashSessionRepository.php';
        require_once MX_POS_PRO_INCLUDES . 'Cash/CashMovementRepository.php';
        require_once MX_POS_PRO_INCLUDES . 'Cash/CashSessionService.php';
        require_once MX_POS_PRO_INCLUDES . 'API/CashSessionController.php';

        (new \MXPOSPro\API\CashSessionController())->register();

        require_once MX_POS_PRO_INCLUDES . 'Cash/CashMovementService.php';
        require_once MX_POS_PRO_INCLUDES . 'API/CashMovementController.php';

        (new \MXPOSPro\API\CashMovementController())->register();

        require_once MX_POS_PRO_INCLUDES . 'API/SessionCloseController.php';

        (new \MXPOSPro\API\SessionCloseController())->register();

        require_once MX_POS_PRO_INCLUDES . 'API/RemoteSessionCloseController.php';

        (new \MXPOSPro\API\RemoteSessionCloseController())->register();

        require_once MX_POS_PRO_INCLUDES . 'Cart/ParkedCartRepository.php';
        require_once MX_POS_PRO_INCLUDES . 'Cart/ParkedCartService.php';
        require_once MX_POS_PRO_INCLUDES . 'API/ParkedCartController.php';

        (new \MXPOSPro\API\ParkedCartController())->register();

        require_once MX_POS_PRO_INCLUDES . 'Customers/CustomerLookupService.php';
        require_once MX_POS_PRO_INCLUDES . 'API/CustomerController.php';

        (new \MXPOSPro\API\CustomerController())->register();

        require_once MX_POS_PRO_INCLUDES . 'Coupons/CouponLookupService.php';
        require_once MX_POS_PRO_INCLUDES . 'API/CouponController.php';

        (new \MXPOSPro\API\CouponController())->register();

        require_once MX_POS_PRO_INCLUDES . 'API/PaymentsController.php';

        (new \MXPOSPro\API\PaymentsController())->register();

        require_once MX_POS_PRO_INCLUDES . 'Sales/WooOrderFactory.php';
        require_once MX_POS_PRO_INCLUDES . 'Sales/SaleRepository.php';
        require_once MX_POS_PRO_INCLUDES . 'Sales/SaleService.php';
        require_once MX_POS_PRO_INCLUDES . 'API/SaleController.php';

        (new \MXPOSPro\API\SaleController())->register();

        require_once MX_POS_PRO_INCLUDES . 'Sales/PaymentService.php';
        require_once MX_POS_PRO_INCLUDES . 'API/PaymentController.php';

        (new \MXPOSPro\API\PaymentController())->register();

        require_once MX_POS_PRO_INCLUDES . 'Sales/TicketService.php';
        require_once MX_POS_PRO_INCLUDES . 'API/TicketController.php';

        (new \MXPOSPro\API\TicketController())->register();

        require_once MX_POS_PRO_INCLUDES . 'Sales/RefundRepository.php';
        require_once MX_POS_PRO_INCLUDES . 'Sales/RefundService.php';
        require_once MX_POS_PRO_INCLUDES . 'API/RefundController.php';

        (new \MXPOSPro\API\RefundController())->register();

        require_once MX_POS_PRO_INCLUDES . 'Payments/OrderPaymentRepository.php';
        require_once MX_POS_PRO_INCLUDES . 'Sales/CheckoutService.php';
        require_once MX_POS_PRO_INCLUDES . 'API/CheckoutController.php';

        (new \MXPOSPro\API\CheckoutController())->register();

        require_once MX_POS_PRO_INCLUDES . 'Cash/CashCutRepository.php';
        require_once MX_POS_PRO_INCLUDES . 'Cash/CashCutService.php';
        require_once MX_POS_PRO_INCLUDES . 'Cash/CashCutAutomationService.php';
        require_once MX_POS_PRO_INCLUDES . 'API/CashCutController.php';

        (new \MXPOSPro\API\CashCutController())->register();
        (new \MXPOSPro\Cash\CashCutAutomationService())->register();

        require_once MX_POS_PRO_INCLUDES . 'Sales/SaleHistoryRepository.php';
        require_once MX_POS_PRO_INCLUDES . 'API/SaleHistoryController.php';

        (new \MXPOSPro\API\SaleHistoryController())->register();

        require_once MX_POS_PRO_INCLUDES . 'Notifications/NotificationFormatter.php';
        require_once MX_POS_PRO_INCLUDES . 'Notifications/TelegramNotificationService.php';

        (new \MXPOSPro\Notifications\TelegramNotificationService())->register_hooks();

        if (defined('WP_CLI') && WP_CLI) {
            require_once MX_POS_PRO_INCLUDES . 'CLI/IndexCommand.php';

            \WP_CLI::add_command('mx-pos index', '\\MXPOSPro\\CLI\\IndexCommand');

            require_once MX_POS_PRO_INCLUDES . 'CLI/MxPosCommand.php';

            \WP_CLI::add_command('mx-pos', '\\MXPOSPro\\CLI\\MxPosCommand');
        }
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new \Exception('Cannot unserialize singleton');
    }
}
