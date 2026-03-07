<?php
/**
 * sender Admin Settings Page
 * WordPress admin panel for plugin configuration
 */

if (!defined('ABSPATH')) {
    exit;
}

class HokTech_Admin_Settings {

    private $api;

    public function __construct() {
        $this->api = new HokTech_API_Client();

        add_action('admin_menu', [$this, 'add_menu']);
        add_action('wp_ajax_hoktech_login', [$this, 'ajax_login']);
        add_action('wp_ajax_hoktech_manual_connect', [$this, 'ajax_manual_connect']);
        add_action('wp_ajax_hoktech_disconnect', [$this, 'ajax_disconnect']);
        add_action('wp_ajax_hoktech_get_sessions', [$this, 'ajax_get_sessions']);
        add_action('wp_ajax_hoktech_save_session', [$this, 'ajax_save_session']);
        add_action('wp_ajax_hoktech_save_notifications', [$this, 'ajax_save_notifications']);
        add_action('wp_ajax_hoktech_save_otp_settings', [$this, 'ajax_save_otp_settings']);
    }

    public function add_menu() {
        add_menu_page(
            __('sender للإشعارات', 'sender-notification'),
            __('sender', 'sender-notification'),
            'manage_options',
            'sender-notification',
            [$this, 'render_page'],
            'dashicons-format-chat',
            56
        );
    }

    /**
     * AJAX: Login via email/password
     */
    public function ajax_login() {
        check_ajax_referer('hoktech_wa_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('صلاحيات غير كافية', 'sender-notification')]);
        }

        $api_url  = sanitize_url(wp_unslash($_POST['api_url'] ?? ''));
        $email    = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $password = sanitize_text_field(wp_unslash($_POST['password'] ?? ''));

        if (empty($api_url) || empty($email) || empty($password)) {
            wp_send_json_error(['message' => __('جميع الحقول مطلوبة', 'sender-notification')]);
        }

        $result = $this->api->login($api_url, $email, $password);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Manual API key connect
     */
    public function ajax_manual_connect() {
        check_ajax_referer('hoktech_wa_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('صلاحيات غير كافية', 'sender-notification')]);
        }

        $api_url = sanitize_url(wp_unslash($_POST['api_url'] ?? ''));
        $api_key = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));

        if (empty($api_url) || empty($api_key)) {
            wp_send_json_error(['message' => __('عنوان المنصة ومفتاح API مطلوبان', 'sender-notification')]);
        }

        $result = $this->api->connect_manual($api_url, $api_key);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Disconnect
     */
    public function ajax_disconnect() {
        check_ajax_referer('hoktech_wa_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('صلاحيات غير كافية', 'sender-notification')]);
        }

        $this->api->disconnect();
        wp_send_json_success(['message' => __('تم قطع الاتصال', 'sender-notification')]);
    }

    /**
     * AJAX: Get sessions
     */
    public function ajax_get_sessions() {
        check_ajax_referer('hoktech_wa_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('صلاحيات غير كافية', 'sender-notification')]);
        }

        $force = isset($_POST['force']) && $_POST['force'] === 'true';
        $result = $this->api->get_sessions($force);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Save selected session
     */
    public function ajax_save_session() {
        check_ajax_referer('hoktech_wa_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('صلاحيات غير كافية', 'sender-notification')]);
        }

        $session_id = sanitize_text_field(wp_unslash($_POST['session_id'] ?? ''));
        $connection = get_option('hoktech_wa_connection', []);
        $connection['session_id'] = $session_id;
        update_option('hoktech_wa_connection', $connection);

        wp_send_json_success(['message' => __('تم حفظ الجلسة بنجاح', 'sender-notification')]);
    }

    /**
     * AJAX: Save notification settings
     */
    public function ajax_save_notifications() {
        check_ajax_referer('hoktech_wa_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('صلاحيات غير كافية', 'sender-notification')]);
        }

        $notifications = isset($_POST['notifications']) ? wp_unslash($_POST['notifications']) : [];
        $settings = [];

        if (is_array($notifications)) {
            foreach ($notifications as $status => $data) {
                $status = sanitize_key($status);
                $settings[$status] = [
                    'enabled'    => !empty($data['enabled']),
                    'send_image' => !empty($data['send_image']),
                    'message'    => sanitize_textarea_field($data['message'] ?? ''),
                ];
            }
        }

        update_option('hoktech_wa_notification_settings', $settings);
        wp_send_json_success(['message' => __('تم حفظ إعدادات الإشعارات بنجاح', 'sender-notification')]);
    }

    /**
     * AJAX: Save OTP settings
     */
    public function ajax_save_otp_settings() {
        check_ajax_referer('hoktech_wa_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('صلاحيات غير كافية', 'sender-notification')]);
        }

        $settings = [
            'enable_checkout_otp'     => !empty($_POST['enable_checkout_otp']),
            'enable_registration_otp' => !empty($_POST['enable_registration_otp']),
            'otp_message'             => sanitize_textarea_field(wp_unslash($_POST['otp_message'] ?? '')),
        ];

        update_option('hoktech_wa_otp_settings', $settings);
        wp_send_json_success(['message' => __('تم حفظ إعدادات OTP بنجاح', 'sender-notification')]);
    }

    /**
     * Render the admin page
     */
    public function render_page() {
        $connection   = get_option('hoktech_wa_connection', []);
        $is_connected = $this->api->is_connected();
        $notifications = get_option('hoktech_wa_notification_settings', []);
        $otp_settings = get_option('hoktech_wa_otp_settings', []);
        ?>
        <div class="wrap hoktech-wrap" dir="rtl">
            <div class="hoktech-header">
                <div class="hoktech-header-content">
                    <div class="hoktech-logo">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#25d366" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 12px;"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 1 1-7.6-7.6 8.38 8.38 0 0 1 3.8.9L21 3.5z"/></svg>
                        <h1><?php esc_html_e('sender - Order Notifications', 'sender-notification'); ?></h1>
                    </div>
                    <div class="hoktech-connection-badge <?php echo $is_connected ? 'connected' : 'disconnected'; ?>">
                        <span class="status-dot"></span>
                        <span class="status-text">
                            <?php echo $is_connected ? esc_html__('متصل', 'sender-notification') : esc_html__('غير متصل', 'sender-notification'); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="hoktech-tabs">
                <button class="hoktech-tab active" data-tab="connection">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 8px; vertical-align: middle;"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    <?php esc_html_e('الاتصال', 'sender-notification'); ?>
                </button>
                <button class="hoktech-tab" data-tab="notifications">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 8px; vertical-align: middle;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    <?php esc_html_e('إشعارات الطلبات', 'sender-notification'); ?>
                </button>
                <button class="hoktech-tab" data-tab="otp">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 8px; vertical-align: middle;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <?php esc_html_e('التحقق OTP', 'sender-notification'); ?>
                </button>
                <button class="hoktech-tab" data-tab="messages">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 8px; vertical-align: middle;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <?php esc_html_e('رسائل مخصصة', 'sender-notification'); ?>
                </button>
            </div>

            <!-- Connection Tab -->
            <div class="hoktech-tab-content active" id="tab-connection">
                <?php if ($is_connected): ?>
                    <div class="hoktech-card hoktech-connected-card">
                        <div class="hoktech-card-header success">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 12px;"><polyline points="20 6 9 17 4 12"/></svg>
                            <h2><?php esc_html_e('متصل بالمنصة', 'sender-notification'); ?></h2>
                        </div>
                        <div class="hoktech-card-body">
                            <div class="hoktech-info-grid">
                                <div class="hoktech-info-item">
                                    <label><?php esc_html_e('عنوان المنصة', 'sender-notification'); ?></label>
                                    <span><?php echo esc_html($connection['api_url'] ?? ''); ?></span>
                                </div>
                                <?php if (!empty($connection['user_name'])): ?>
                                <div class="hoktech-info-item">
                                    <label><?php esc_html_e('اسم المستخدم', 'sender-notification'); ?></label>
                                    <span><?php echo esc_html($connection['user_name']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($connection['user_email'])): ?>
                                <div class="hoktech-info-item">
                                    <label><?php esc_html_e('البريد الإلكتروني', 'sender-notification'); ?></label>
                                    <span><?php echo esc_html($connection['user_email']); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="hoktech-info-item">
                                    <label><?php esc_html_e('طريقة الاتصال', 'sender-notification'); ?></label>
                                    <span><?php echo ($connection['connection_method'] ?? '') === 'login' ? esc_html__('تسجيل دخول', 'sender-notification') : esc_html__('مفتاح API يدوي', 'sender-notification'); ?></span>
                                </div>
                                <div class="hoktech-info-item">
                                    <label><?php esc_html_e('تاريخ الاتصال', 'sender-notification'); ?></label>
                                    <span><?php echo esc_html($connection['connected_at'] ?? ''); ?></span>
                                </div>
                            </div>

                            <!-- Session Selector -->
                            <div class="hoktech-session-selector">
                                <h3><?php esc_html_e('اختيار الجلسة', 'sender-notification'); ?></h3>
                                <p class="description"><?php esc_html_e('اختر جلسة الإرسال التي سيتم استخدامها لإرسال الرسائل والإشعارات', 'sender-notification'); ?></p>
                                <div class="hoktech-session-row">
                                    <select id="hoktech-session-select" class="hoktech-select">
                                        <option value=""><?php esc_html_e('-- جاري تحميل الجلسات --', 'sender-notification'); ?></option>
                                    </select>
                                    <button type="button" id="hoktech-refresh-sessions" class="button">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 6px; vertical-align: middle;"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
                                        <?php esc_html_e('تحديث', 'sender-notification'); ?>
                                    </button>
                                    <button type="button" id="hoktech-save-session" class="button button-primary">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 6px; vertical-align: middle;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                        <?php esc_html_e('حفظ', 'sender-notification'); ?>
                                    </button>
                                </div>
                                <input type="hidden" id="hoktech-current-session" value="<?php echo esc_attr($connection['session_id'] ?? ''); ?>">
                            </div>

                            <div class="hoktech-actions">
                                <button type="button" id="hoktech-disconnect" class="button hoktech-btn-danger">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 8px; vertical-align: middle;"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/></svg>
                                    <?php esc_html_e('قطع الاتصال', 'sender-notification'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Connection Methods -->
                    <div class="hoktech-connection-methods">
                        <div class="hoktech-method-tabs">
                            <button class="hoktech-method-tab active" data-method="login">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 8px; vertical-align: middle;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <?php esc_html_e('تسجيل الدخول', 'sender-notification'); ?>
                            </button>
                            <button class="hoktech-method-tab" data-method="manual">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 8px; vertical-align: middle;"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
                                <?php esc_html_e('مفتاح API يدوي', 'sender-notification'); ?>
                            </button>
                        </div>

                        <!-- Login Method -->
                        <div class="hoktech-card hoktech-method-content active" id="method-login">
                            <div class="hoktech-card-header">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 10px; vertical-align: middle;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <h2><?php esc_html_e('تسجيل الدخول بحساب المنصة', 'sender-notification'); ?></h2>
                            </div>
                            <div class="hoktech-card-body">
                                <p class="hoktech-description"><?php esc_html_e('قم بتسجيل الدخول بحسابك في منصة sender وسيتم ربط المتجر تلقائياً', 'sender-notification'); ?></p>
                                <form id="hoktech-login-form">
                                    <div class="hoktech-form-group">
                                        <label for="hoktech-api-url"><?php esc_html_e('عنوان المنصة (URL)', 'sender-notification'); ?></label>
                                        <input type="url" id="hoktech-api-url" class="hoktech-input" placeholder="https://your-platform.com" required>
                                    </div>
                                    <div class="hoktech-form-group">
                                        <label for="hoktech-email"><?php esc_html_e('البريد الإلكتروني', 'sender-notification'); ?></label>
                                        <input type="email" id="hoktech-email" class="hoktech-input" placeholder="email@example.com" required>
                                    </div>
                                    <div class="hoktech-form-group">
                                        <label for="hoktech-password"><?php esc_html_e('كلمة المرور', 'sender-notification'); ?></label>
                                        <input type="password" id="hoktech-password" class="hoktech-input" required>
                                    </div>
                                    <div class="hoktech-form-actions">
                                        <button type="submit" class="button button-primary button-hero hoktech-btn-connect">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 10px; vertical-align: middle;"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                            <?php esc_html_e('اتصال', 'sender-notification'); ?>
                                        </button>
                                    </div>
                                </form>
                                <div id="hoktech-login-result" class="hoktech-result" style="display:none;"></div>
                            </div>
                        </div>

                        <!-- Manual API Key Method -->
                        <div class="hoktech-card hoktech-method-content" id="method-manual">
                            <div class="hoktech-card-header">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 10px; vertical-align: middle;"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
                                <h2><?php esc_html_e('ربط يدوي بمفتاح API', 'sender-notification'); ?></h2>
                            </div>
                            <div class="hoktech-card-body">
                                <p class="hoktech-description"><?php esc_html_e('أدخل مفتاح API الخاص بحسابك في المنصة مباشرة. يمكنك الحصول عليه من إعدادات حسابك', 'sender-notification'); ?></p>
                                <form id="hoktech-manual-form">
                                    <div class="hoktech-form-group">
                                        <label for="hoktech-manual-url"><?php esc_html_e('عنوان المنصة (URL)', 'sender-notification'); ?></label>
                                        <input type="url" id="hoktech-manual-url" class="hoktech-input" placeholder="https://your-platform.com" required>
                                    </div>
                                    <div class="hoktech-form-group">
                                        <label for="hoktech-manual-key"><?php esc_html_e('مفتاح API', 'sender-notification'); ?></label>
                                        <input type="text" id="hoktech-manual-key" class="hoktech-input hoktech-api-key-input" placeholder="hk_xxxxxxxxxxxxxxxx" required>
                                    </div>
                                    <div class="hoktech-form-actions">
                                        <button type="submit" class="button button-primary button-hero hoktech-btn-connect">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 10px; vertical-align: middle;"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                            <?php esc_html_e('ربط', 'sender-notification'); ?>
                                        </button>
                                    </div>
                                </form>
                                <div id="hoktech-manual-result" class="hoktech-result" style="display:none;"></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Notifications Tab -->
            <div class="hoktech-tab-content" id="tab-notifications">
                <div class="hoktech-card">
                    <div class="hoktech-card-header">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 10px; vertical-align: middle;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                        <h2><?php esc_html_e('إشعارات حالة الطلبات', 'sender-notification'); ?></h2>
                    </div>
                    <div class="hoktech-card-body">
                        <p class="hoktech-description"><?php esc_html_e('قم بتفعيل وتخصيص الرسائل لكل حالة من حالات الطلب', 'sender-notification'); ?></p>
                        <div class="hoktech-placeholders-info">
                            <strong><?php esc_html_e('المتغيرات المتاحة:', 'sender-notification'); ?></strong>
                            <code>{order_id}</code> <code>{customer_name}</code> <code>{order_total}</code> <code>{order_status}</code> <code>{site_name}</code> <code>{order_items}</code> <code>{billing_phone}</code>
                        </div>
                        <form id="hoktech-notifications-form">
                            <?php
                            // Get all WooCommerce order statuses dynamically
                            $wc_statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
                            $status_labels = [];
                            foreach ($wc_statuses as $key => $label) {
                                $status_labels[str_replace('wc-', '', $key)] = $label;
                            }

                            $status_icons = [
                                'pending'    => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#e67e22" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
                                'processing' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#3498db" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>',
                                'completed'  => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#27ae60" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
                                'cancelled'  => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#e74c3c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
                                'refunded'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#27ae60" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
                                'on-hold'    => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#95a5a6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="10" y1="15" x2="10" y2="9"/><line x1="14" y1="15" x2="14" y2="9"/></svg>',
                                'failed'     => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f39c12" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
                                'default'    => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#7f8c8d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
                            ];

                            foreach ($status_labels as $status => $label):
                                $setting = $notifications[$status] ?? ['enabled' => false, 'message' => ''];
                            ?>
                            <div class="hoktech-notification-item">
                                <div class="hoktech-notification-header">
                                    <label class="hoktech-toggle">
                                        <input type="checkbox" name="notifications[<?php echo esc_attr($status); ?>][enabled]" value="1" <?php checked(!empty($setting['enabled'])); ?>>
                                        <span class="hoktech-toggle-slider"></span>
                                    </label>
                                    <span class="hoktech-notification-icon">
                                        <?php 
                                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                        echo $status_icons[$status] ?? $status_icons['default']; 
                                        ?>
                                    </span>
                                    <span class="hoktech-notification-label"><?php echo esc_html($label); ?></span>
                                    
                                    <label class="hoktech-toggle" style="margin-right: auto; margin-left: 10px;">
                                        <input type="checkbox" name="notifications[<?php echo esc_attr($status); ?>][send_image]" value="1" <?php checked(!empty($setting['send_image'])); ?>>
                                        <span class="hoktech-toggle-slider"></span>
                                    </label>
                                    <span class="hoktech-notification-label" style="font-size: 13px; color: #666;"><?php esc_html_e('إرفاق صورة منتج', 'sender-notification'); ?></span>
                                </div>
                                <div class="hoktech-notification-body">
                                    <textarea name="notifications[<?php echo esc_attr($status); ?>][message]" class="hoktech-textarea" rows="3" placeholder="<?php esc_attr_e('اكتب رسالة الإشعار...', 'sender-notification'); ?>"><?php echo esc_textarea($setting['message'] ?? ''); ?></textarea>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <div class="hoktech-form-actions">
                                <button type="submit" class="button button-primary">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 8px; vertical-align: middle;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                    <?php esc_html_e('حفظ الإعدادات', 'sender-notification'); ?>
                                </button>
                            </div>
                        </form>
                        <div id="hoktech-notifications-result" class="hoktech-result" style="display:none;"></div>
                    </div>
                </div>
            </div>

            <!-- OTP Tab -->
            <div class="hoktech-tab-content" id="tab-otp">
                <div class="hoktech-card">
                    <div class="hoktech-card-header">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 10px; vertical-align: middle;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <h2><?php esc_html_e('إعدادات التحقق OTP', 'sender-notification'); ?></h2>
                    </div>
                    <div class="hoktech-card-body">
                        <p class="hoktech-description"><?php esc_html_e('فعّل التحقق من رقم الهاتف عبر OTP أثناء الدفع أو التسجيل', 'sender-notification'); ?></p>
                        <form id="hoktech-otp-form">
                            <div class="hoktech-otp-options">
                                <div class="hoktech-otp-option">
                                    <label class="hoktech-toggle">
                                        <input type="checkbox" name="enable_checkout_otp" value="1" <?php checked(!empty($otp_settings['enable_checkout_otp'])); ?>>
                                        <span class="hoktech-toggle-slider"></span>
                                    </label>
                                    <div class="hoktech-otp-option-info">
                                        <strong><?php esc_html_e('التحقق أثناء اتمام الطلب (Checkout)', 'sender-notification'); ?></strong>
                                        <p><?php esc_html_e('يتطلب من العميل التحقق من رقم هاتف الفاتورة قبل إتمام الطلب', 'sender-notification'); ?></p>
                                    </div>
                                </div>
                                <div class="hoktech-otp-option">
                                    <label class="hoktech-toggle">
                                        <input type="checkbox" name="enable_registration_otp" value="1" <?php checked(!empty($otp_settings['enable_registration_otp'])); ?>>
                                        <span class="hoktech-toggle-slider"></span>
                                    </label>
                                    <div class="hoktech-otp-option-info">
                                        <strong><?php esc_html_e('التحقق أثناء التسجيل', 'sender-notification'); ?></strong>
                                        <p><?php esc_html_e('يتطلب من المستخدم التحقق من رقم هاتفه عند إنشاء حساب جديد', 'sender-notification'); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="hoktech-form-group">
                                <label for="hoktech-otp-message"><?php esc_html_e('نص رسالة OTP (اختياري)', 'sender-notification'); ?></label>
                                <textarea id="hoktech-otp-message" name="otp_message" class="hoktech-textarea" rows="2" placeholder="رمز التحقق الخاص بك هو: {otp_code}"><?php echo esc_textarea($otp_settings['otp_message'] ?? ''); ?></textarea>
                                <p class="description"><?php esc_html_e('اتركه فارغاً لاستخدام الرسالة الافتراضية من المنصة', 'sender-notification'); ?></p>
                            </div>
                            <div class="hoktech-form-actions">
                                <button type="submit" class="button button-primary">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 8px; vertical-align: middle;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                    <?php esc_html_e('حفظ الإعدادات', 'sender-notification'); ?>
                                </button>
                            </div>
                        </form>
                        <div id="hoktech-otp-result" class="hoktech-result" style="display:none;"></div>
                    </div>
                </div>
            </div>

            <!-- Messages Tab -->
            <div class="hoktech-tab-content" id="tab-messages">
                <div class="hoktech-card">
                    <div class="hoktech-card-header">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 10px; vertical-align: middle;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        <h2><?php esc_html_e('إرسال رسالة مخصصة', 'sender-notification'); ?></h2>
                    </div>
                    <div class="hoktech-card-body">
                        <p class="hoktech-description"><?php esc_html_e('أرسل رسالة واتساب مخصصة لأي رقم هاتف مباشرة من لوحة التحكم', 'sender-notification'); ?></p>
                        <form id="hoktech-message-form">
                            <div class="hoktech-form-group">
                                <label for="hoktech-msg-phone"><?php esc_html_e('رقم الهاتف', 'sender-notification'); ?></label>
                                <input type="tel" id="hoktech-msg-phone" class="hoktech-input" placeholder="<?php esc_attr_e('مثال: 201234567890', 'sender-notification'); ?>" required>
                            </div>
                            <div class="hoktech-form-group">
                                <label for="hoktech-msg-text"><?php esc_html_e('نص الرسالة', 'sender-notification'); ?></label>
                                <textarea id="hoktech-msg-text" class="hoktech-textarea" rows="4" placeholder="<?php esc_attr_e('اكتب رسالتك هنا...', 'sender-notification'); ?>" required></textarea>
                            </div>
                            <div class="hoktech-form-actions">
                                <button type="submit" class="button button-primary">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 8px; vertical-align: middle;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                    <?php esc_html_e('إرسال', 'sender-notification'); ?>
                                </button>
                            </div>
                        </form>
                        <div id="hoktech-message-result" class="hoktech-result" style="display:none;"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
