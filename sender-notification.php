<?php
/**
 * Plugin Name: sender - Order Notifications & Messaging
 * Plugin URI: https://www.wpsenderx.com
 * Description: إضافة ووردبريس لربط متجر WooCommerce بمنصة sender للإشعارات - تنبيهات الطلبات، التحقق عبر OTP، ورسائل مخصصة
 * Version: 1.0.1
 * Author: sender
 * Text Domain: sender-notification
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('HOKTECH_WA_VERSION', '1.0.1');
define('HOKTECH_WA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HOKTECH_WA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HOKTECH_WA_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main Plugin Class
 */
final class HokTech_sender {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    private function includes() {
        require_once HOKTECH_WA_PLUGIN_DIR . 'includes/class-api-client.php';
        require_once HOKTECH_WA_PLUGIN_DIR . 'includes/class-admin-settings.php';
        require_once HOKTECH_WA_PLUGIN_DIR . 'includes/class-order-notifications.php';
        require_once HOKTECH_WA_PLUGIN_DIR . 'includes/class-otp-verification.php';
        require_once HOKTECH_WA_PLUGIN_DIR . 'includes/class-custom-message.php';
    }

    private function init_hooks() {
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);

        // Declare WooCommerce HPOS & Blocks compatibility
        add_action('before_woocommerce_init', [$this, 'declare_wc_compatibility']);

        // Standard initialization for components on init
        add_action('init', [$this, 'init_components'], 11);
    }

    /**
     * Declare compatibility with WooCommerce features (HPOS, Blocks)
     */
    public function declare_wc_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        }
    }

    /**
     * Initialize plugin components
     */
    public function init_components() {
        // Admin and messaging can load normally
        new HokTech_Admin_Settings();
        new HokTech_Custom_Message();

        // WooCommerce dependent components
        if (class_exists('WooCommerce')) {
            new HokTech_Order_Notifications();
            new HokTech_OTP_Verification();
        }
    }

    public function admin_assets($hook) {
        if (strpos($hook, 'sender-notification') === false) {
            return;
        }
        wp_enqueue_style('hoktech-wa-admin', HOKTECH_WA_PLUGIN_URL . 'assets/css/admin-style.css', [], HOKTECH_WA_VERSION);
        wp_enqueue_script('hoktech-wa-admin', HOKTECH_WA_PLUGIN_URL . 'assets/js/admin-script.js', ['jquery'], HOKTECH_WA_VERSION, true);
        wp_localize_script('hoktech-wa-admin', 'hoktechWA', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('hoktech_wa_nonce'),
            'strings' => [
                'connecting'   => __('جاري الاتصال...', 'sender-notification'),
                'connected'    => __('متصل', 'sender-notification'),
                'disconnected' => __('غير متصل', 'sender-notification'),
                'sending'      => __('جاري الإرسال...', 'sender-notification'),
                'sent'         => __('تم الإرسال بنجاح', 'sender-notification'),
                'error'        => __('حدث خطأ', 'sender-notification'),
                'confirm_disconnect' => __('هل أنت متأكد من قطع الاتصال؟', 'sender-notification'),
            ]
        ]);
    }

    public function frontend_assets() {
        $otp_settings = get_option('hoktech_wa_otp_settings', []);
        if (!empty($otp_settings['enable_checkout_otp']) || !empty($otp_settings['enable_registration_otp'])) {
            wp_enqueue_style('hoktech-wa-frontend', HOKTECH_WA_PLUGIN_URL . 'assets/css/frontend-style.css', [], HOKTECH_WA_VERSION);
            wp_enqueue_script('hoktech-wa-frontend', HOKTECH_WA_PLUGIN_URL . 'assets/js/frontend-otp.js', ['jquery'], HOKTECH_WA_VERSION, true);
            wp_localize_script('hoktech-wa-frontend', 'hoktechOTP', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('hoktech_otp_nonce'),
            ]);
        }
    }
}

// Initialize plugin
HokTech_sender::instance();

// Activation hook
register_activation_hook(__FILE__, function () {
    // Set default options
    if (!get_option('hoktech_wa_connection')) {
        update_option('hoktech_wa_connection', [
            'api_url'    => '',
            'api_key'    => '',
            'session_id' => '',
        ]);
    }
    if (!get_option('hoktech_wa_notification_settings')) {
        update_option('hoktech_wa_notification_settings', [
            'pending'    => ['enabled' => false, 'message' => 'مرحباً {customer_name}، تم استلام طلبك رقم #{order_id} بقيمة {order_total}. سنقوم بمعالجته قريباً. شكراً لتسوقك من {site_name}'],
            'processing' => ['enabled' => true,  'message' => 'مرحباً {customer_name}، طلبك رقم #{order_id} قيد المعالجة الآن. سنعلمك فور شحنه. {site_name}'],
            'completed'  => ['enabled' => true,  'message' => 'مرحباً {customer_name}، طلبك رقم #{order_id} تم تسليمه بنجاح! شكراً لثقتك بنا. {site_name}'],
            'cancelled'  => ['enabled' => false, 'message' => 'مرحباً {customer_name}، تم إلغاء طلبك رقم #{order_id}. إذا كان هذا خطأ، تواصل معنا. {site_name}'],
            'refunded'   => ['enabled' => false, 'message' => 'مرحباً {customer_name}، تم استرداد المبلغ لطلبك رقم #{order_id}. {site_name}'],
            'on-hold'    => ['enabled' => false, 'message' => 'مرحباً {customer_name}، طلبك رقم #{order_id} قيد الانتظار. يرجى إتمام الدفع لمتابعة المعالجة. {site_name}'],
            'failed'     => ['enabled' => false, 'message' => 'مرحباً {customer_name}، فشل طلبك رقم #{order_id}. يرجى المحاولة مرة أخرى أو التواصل معنا. {site_name}'],
        ]);
    }
    if (!get_option('hoktech_wa_otp_settings')) {
        update_option('hoktech_wa_otp_settings', [
            'enable_checkout_otp'     => false,
            'enable_registration_otp' => false,
            'otp_message'             => 'رمز التحقق الخاص بك هو: {otp_code} - {site_name}',
        ]);
    }
});

// Deactivation hook
register_deactivation_hook(__FILE__, function () {
    // Cleanup transients
    delete_transient('hoktech_wa_sessions_cache');
});
