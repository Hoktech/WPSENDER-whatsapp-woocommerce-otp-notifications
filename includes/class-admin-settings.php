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
        add_action('wp_ajax_hoktech_save_admin_notifications', [$this, 'ajax_save_admin_notifications']);
        add_action('wp_ajax_hoktech_save_vendor_notifications', [$this, 'ajax_save_vendor_notifications']);
        add_action('wp_ajax_hoktech_save_vendor_session', [$this, 'ajax_save_vendor_session']);

        // Suppress all third-party admin notices on our plugin page
        add_action('admin_init', [$this, 'suppress_admin_notices']);
    }

    /**
     * Remove all admin notices (from other plugins/themes) on our own settings page.
     * Runs at admin_init so we can hook into admin_notices early enough.
     */
    public function suppress_admin_notices() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        if ($page !== 'sender-notification') {
            return;
        }

        // Remove standard WP notice hooks used by plugins/themes
        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
        remove_all_actions('user_admin_notices');
        remove_all_actions('network_admin_notices');
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
                    'message'    => hoktech_sanitize_textarea($data['message'] ?? ''),
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
            'enable_country_selector' => !empty($_POST['enable_country_selector']),
            'default_country_code'    => sanitize_text_field(wp_unslash($_POST['default_country_code'] ?? 'EG')),
            'otp_message'             => hoktech_sanitize_textarea(wp_unslash($_POST['otp_message'] ?? '')),
        ];

        update_option('hoktech_wa_otp_settings', $settings);
        wp_send_json_success(['message' => __('تم حفظ إعدادات OTP بنجاح', 'sender-notification')]);
    }

    /**
     * AJAX: Save admin notification settings
     */
    public function ajax_save_admin_notifications() {
        check_ajax_referer('hoktech_wa_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('صلاحيات غير كافية', 'sender-notification')]);
        }

        $settings = [
            'enabled'  => !empty($_POST['enabled']),
            'phones'   => hoktech_sanitize_textarea(wp_unslash($_POST['phones'] ?? '')),
            'message'  => hoktech_sanitize_textarea(wp_unslash($_POST['message'] ?? '')),
            'statuses' => isset($_POST['statuses']) && is_array($_POST['statuses'])
                ? array_map('sanitize_key', $_POST['statuses'])
                : [],
        ];

        update_option('hoktech_wa_admin_notifications', $settings);
        wp_send_json_success(['message' => __('تم حفظ إعدادات إشعارات الإدارة بنجاح', 'sender-notification')]);
    }

    /**
     * AJAX: Save vendor notification settings
     */
    public function ajax_save_vendor_notifications() {
        check_ajax_referer('hoktech_wa_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('صلاحيات غير كافية', 'sender-notification')]);
        }

        // Preserve existing session_id if not posted
        $existing = get_option('hoktech_wa_vendor_notifications', []);

        $settings = [
            'enabled'           => !empty($_POST['vendor_notif_enabled']),
            'phone_meta_key'    => sanitize_text_field(wp_unslash($_POST['phone_meta_key'] ?? '')),
            'default_dial_code' => preg_replace('/[^0-9]/', '', wp_unslash($_POST['default_dial_code'] ?? '')),
            'message'           => hoktech_sanitize_textarea(wp_unslash($_POST['vendor_message'] ?? '')),
            'item_format'       => hoktech_sanitize_textarea(wp_unslash($_POST['vendor_item_format'] ?? ''), true),
            'statuses'          => isset($_POST['vendor_statuses']) && is_array($_POST['vendor_statuses'])
                ? array_map('sanitize_key', $_POST['vendor_statuses'])
                : [],
            'session_id'        => $existing['session_id'] ?? '',
        ];

        update_option('hoktech_wa_vendor_notifications', $settings);
        wp_send_json_success(['message' => __('تم حفظ إعدادات إشعارات الفيندور بنجاح', 'sender-notification')]);
    }

    /**
     * AJAX: Save vendor dedicated session
     */
    public function ajax_save_vendor_session() {
        check_ajax_referer('hoktech_wa_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('صلاحيات غير كافية', 'sender-notification')]);
        }

        $session_id = sanitize_text_field(wp_unslash($_POST['vendor_session_id'] ?? ''));
        $vendor_settings = get_option('hoktech_wa_vendor_notifications', []);
        $vendor_settings['session_id'] = $session_id;
        update_option('hoktech_wa_vendor_notifications', $vendor_settings);

        wp_send_json_success(['message' => __('تم حفظ جلسة إرسال إشعارات الفيندور بنجاح', 'sender-notification')]);
    }

    /**
     * Render the admin page
     */
    public function render_page() {
        $connection   = get_option('hoktech_wa_connection', []);
        $is_connected = $this->api->is_connected();
        $notifications = get_option('hoktech_wa_notification_settings', []);
        $otp_settings = get_option('hoktech_wa_otp_settings', []);
        $admin_notifications = get_option('hoktech_wa_admin_notifications', []);
        $vendor_notifications = get_option('hoktech_wa_vendor_notifications', []);
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
                <button class="hoktech-tab" data-tab="vendors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 8px; vertical-align: middle;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    <?php esc_html_e('إشعارات الفيندور', 'sender-notification'); ?>
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
                            <code>{order_id}</code> <code>{customer_name}</code> <code>{order_total}</code> <code>{order_status}</code> <code>{site_name}</code> <code>{order_items}</code> <code>{billing_phone}</code> <code>{sku}</code> <code>{product_url}</code>
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
                                    <textarea name="notifications[<?php echo esc_attr($status); ?>][message]" class="hoktech-textarea" rows="3" placeholder="<?php esc_attr_e('اكتب رسالة الإشعار...', 'sender-notification'); ?>"><?php 
                                        $notif_msg_val = $setting['message'] ?? '';
                                        echo "\n" . esc_textarea($notif_msg_val) . "\n"; 
                                    ?></textarea>
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

                <!-- Admin Notifications Section -->
                <div class="hoktech-card" style="margin-top: 24px;">
                    <div class="hoktech-card-header">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 10px; vertical-align: middle;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <h2><?php esc_html_e('إشعارات الإدارة', 'sender-notification'); ?></h2>
                    </div>
                    <div class="hoktech-card-body">
                        <p class="hoktech-description"><?php esc_html_e('أرسل إشعارات تلقائية لأرقام الإدارة عند تغيير حالة الطلبات', 'sender-notification'); ?></p>
                        <form id="hoktech-admin-notifications-form">
                            <div class="hoktech-otp-option" style="margin-bottom: 20px;">
                                <label class="hoktech-toggle">
                                    <input type="checkbox" name="admin_notif_enabled" value="1" <?php checked(!empty($admin_notifications['enabled'])); ?>>
                                    <span class="hoktech-toggle-slider"></span>
                                </label>
                                <div class="hoktech-otp-option-info">
                                    <strong><?php esc_html_e('تفعيل إشعارات الإدارة', 'sender-notification'); ?></strong>
                                    <p><?php esc_html_e('عند التفعيل، سيتم إرسال إشعار واتساب لأرقام الإدارة المحددة عند تغيير حالة الطلب', 'sender-notification'); ?></p>
                                </div>
                            </div>

                            <div class="hoktech-form-group">
                                <label for="hoktech-admin-phones"><?php esc_html_e('أرقام هواتف الإدارة', 'sender-notification'); ?></label>
                                <textarea id="hoktech-admin-phones" name="admin_phones" class="hoktech-textarea" rows="3" placeholder="<?php esc_attr_e('رقم واحد في كل سطر مثل:\n201234567890\n201098765432', 'sender-notification'); ?>"><?php 
                                    $admin_phones_val = $admin_notifications['phones'] ?? '';
                                    echo "\n" . esc_textarea($admin_phones_val) . "\n"; 
                                ?></textarea>
                                <p class="description"><?php esc_html_e('أدخل رقم واحد في كل سطر أو افصل بينهم بفاصلة', 'sender-notification'); ?></p>
                            </div>

                            <div class="hoktech-form-group">
                                <label><?php esc_html_e('الحالات المُفعّلة للإشعار', 'sender-notification'); ?></label>
                                <div class="hoktech-admin-statuses-grid">
                                    <?php
                                    $wc_statuses_admin = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
                                    $enabled_admin_statuses = $admin_notifications['statuses'] ?? [];
                                    foreach ($wc_statuses_admin as $status_key => $status_label):
                                        $status_clean = str_replace('wc-', '', $status_key);
                                    ?>
                                    <label class="hoktech-admin-status-item">
                                        <input type="checkbox" name="admin_statuses[]" value="<?php echo esc_attr($status_clean); ?>" <?php checked(in_array($status_clean, $enabled_admin_statuses, true)); ?>>
                                        <span><?php echo esc_html($status_label); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="hoktech-form-group">
                                <label for="hoktech-admin-message"><?php esc_html_e('قالب رسالة الإدارة', 'sender-notification'); ?></label>
                                <textarea id="hoktech-admin-message" name="admin_message" class="hoktech-textarea" rows="4" placeholder="<?php esc_attr_e('🔔 طلب جديد #{order_id}\nالعميل: {customer_name}\nالمبلغ: {order_total}\nالحالة: {order_status}', 'sender-notification'); ?>"><?php 
                                    $admin_msg_val = $admin_notifications['message'] ?? '';
                                    echo "\n" . esc_textarea($admin_msg_val) . "\n"; 
                                ?></textarea>
                                <div class="hoktech-placeholders-info" style="margin-top: 10px; margin-bottom: 0;">
                                    <strong><?php esc_html_e('المتغيرات المتاحة:', 'sender-notification'); ?></strong>
                                    <code>{order_id}</code> <code>{customer_name}</code> <code>{order_total}</code> <code>{order_status}</code> <code>{site_name}</code> <code>{order_items}</code> <code>{billing_phone}</code> <code>{sku}</code> <code>{product_url}</code>
                                </div>
                            </div>

                            <div class="hoktech-form-actions">
                                <button type="submit" class="button button-primary">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 8px; vertical-align: middle;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                    <?php esc_html_e('حفظ إعدادات الإدارة', 'sender-notification'); ?>
                                </button>
                            </div>
                        </form>
                        <div id="hoktech-admin-notif-result" class="hoktech-result" style="display:none;"></div>
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

                            <!-- Country Code Selector Section -->
                            <div class="hoktech-country-selector-settings" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
                                <h3 style="margin: 0 0 12px; font-size: 15px; font-weight: 600; color: #1e1e1e;">
                                    <?php esc_html_e('محدد كود الدولة', 'sender-notification'); ?>
                                </h3>
                                <div class="hoktech-otp-option">
                                    <label class="hoktech-toggle">
                                        <input type="checkbox" name="enable_country_selector" value="1" <?php checked(!empty($otp_settings['enable_country_selector'])); ?>>
                                        <span class="hoktech-toggle-slider"></span>
                                    </label>
                                    <div class="hoktech-otp-option-info">
                                        <strong><?php esc_html_e('تفعيل محدد كود الدولة في الشيكاوت', 'sender-notification'); ?></strong>
                                        <p><?php esc_html_e('يضيف قائمة منسدلة بأعلام الدول وأكواد الاتصال بجانب حقل الهاتف في صفحة الدفع', 'sender-notification'); ?></p>
                                    </div>
                                </div>
                                <div class="hoktech-form-group" style="margin-top: 14px;">
                                    <label for="hoktech-default-country"><?php esc_html_e('الدولة الافتراضية', 'sender-notification'); ?></label>
                                    <select id="hoktech-default-country" name="default_country_code" class="hoktech-select" style="width: 100%; max-width: 400px;">
                                        <?php
                                        if (function_exists('hoktech_get_country_codes')) {
                                            $countries = hoktech_get_country_codes();
                                            $default_code = $otp_settings['default_country_code'] ?? 'EG';
                                            foreach ($countries as $country) {
                                                printf(
                                                    '<option value="%s" %s>%s %s (%s)</option>',
                                                    esc_attr($country['code']),
                                                    selected($default_code, $country['code'], false),
                                                    esc_html($country['flag']),
                                                    esc_html($country['name']),
                                                    esc_html($country['dial_code'])
                                                );
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <div class="hoktech-form-group">
                                <label for="hoktech-otp-message"><?php esc_html_e('نص رسالة OTP (اختياري)', 'sender-notification'); ?></label>
                                <textarea id="hoktech-otp-message" name="otp_message" class="hoktech-textarea" rows="2" placeholder="رمز التحقق الخاص بك هو: {otp_code}"><?php 
                                    $otp_msg_val = $otp_settings['otp_message'] ?? '';
                                    echo "\n" . esc_textarea($otp_msg_val) . "\n"; 
                                ?></textarea>
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

            <!-- Vendor Notifications Tab -->
            <div class="hoktech-tab-content" id="tab-vendors">
                <div class="hoktech-card">
                    <div class="hoktech-card-header">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 10px; vertical-align: middle;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                        <h2><?php esc_html_e('إشعارات الفيندور (Multi-Vendor)', 'sender-notification'); ?></h2>
                    </div>
                    <div class="hoktech-card-body">
                        <p class="hoktech-description">
                            <?php esc_html_e('عند تغيير حالة الطلب، يتم إرسال رسالة واتساب لكل فيندور تحتوي فقط على الأيتم الخاصة به.', 'sender-notification'); ?>
                        </p>

                        <!-- Multi-vendor plugin detection -->
                        <?php
                        $detected_plugins = [];
                        if (function_exists('dokan_get_vendor_by_product'))   $detected_plugins[] = 'Dokan';
                        if (class_exists('WCV_Vendors'))                      $detected_plugins[] = 'WC Vendors';
                        if (function_exists('wcfm_get_vendor_id_by_post'))    $detected_plugins[] = 'WCFM';
                        if (!empty($detected_plugins)): ?>
                        <div class="hoktech-placeholders-info" style="background:#e8f5e9;border-color:#a5d6a7;margin-bottom:18px;">
                            <strong style="color:#2e7d32;">✅ <?php esc_html_e('إضافة Multi-Vendor مكتشفة:', 'sender-notification'); ?></strong>
                            <?php echo esc_html(implode(', ', $detected_plugins)); ?>
                        </div>
                        <?php else: ?>
                        <div class="hoktech-placeholders-info" style="background:#fff3e0;border-color:#ffcc02;margin-bottom:18px;">
                            <strong style="color:#e65100;">⚠️ <?php esc_html_e('لم يتم اكتشاف إضافة Multi-Vendor. سيتم استخدام مالك المنتج (post_author) كفيندور.', 'sender-notification'); ?></strong>
                        </div>
                        <?php endif; ?>

                        <form id="hoktech-vendor-notifications-form">
                            <!-- Enable/Disable toggle -->
                            <div class="hoktech-otp-option" style="margin-bottom: 20px;">
                                <label class="hoktech-toggle">
                                    <input type="checkbox" name="vendor_notif_enabled" value="1" <?php checked(!empty($vendor_notifications['enabled'])); ?>>
                                    <span class="hoktech-toggle-slider"></span>
                                </label>
                                <div class="hoktech-otp-option-info">
                                    <strong><?php esc_html_e('تفعيل إشعارات الفيندور', 'sender-notification'); ?></strong>
                                    <p><?php esc_html_e('عند التفعيل، يتلقى كل فيندور رسالة واتساب بالأيتم الخاصة به فقط عند تغيير حالة الطلب', 'sender-notification'); ?></p>
                                </div>
                            </div>

                            <!-- Statuses -->
                            <div class="hoktech-form-group">
                                <label><?php esc_html_e('الحالات التي تُرسَل فيها الإشعارات', 'sender-notification'); ?></label>
                                <div class="hoktech-admin-statuses-grid">
                                    <?php
                                    $wc_statuses_vendor = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
                                    $enabled_vendor_statuses = $vendor_notifications['statuses'] ?? ['processing'];
                                    foreach ($wc_statuses_vendor as $status_key => $status_label):
                                        $status_clean = str_replace('wc-', '', $status_key);
                                    ?>
                                    <label class="hoktech-admin-status-item">
                                        <input type="checkbox" name="vendor_statuses[]" value="<?php echo esc_attr($status_clean); ?>" <?php checked(in_array($status_clean, $enabled_vendor_statuses, true)); ?>>
                                        <span><?php echo esc_html($status_label); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Phone meta key -->
                            <div class="hoktech-form-group">
                                <label for="hoktech-vendor-phone-meta"><?php esc_html_e('مفتاح حقل رقم هاتف الفيندور (اختياري)', 'sender-notification'); ?></label>
                                <input type="text" id="hoktech-vendor-phone-meta" name="phone_meta_key" class="hoktech-input" value="<?php echo esc_attr($vendor_notifications['phone_meta_key'] ?? ''); ?>" placeholder="<?php esc_attr_e('مثال: vendor_whatsapp أو billing_phone', 'sender-notification'); ?>">
                                <p class="description"><?php esc_html_e('إذا كان الفيندور يخزن رقم هاتفه في حقل user_meta مخصص، أدخل اسم الحقل هنا. اتركه فارغاً لاستخدام الكشف التلقائي.', 'sender-notification'); ?></p>
                            </div>

                            <!-- Default dial code -->
                            <div class="hoktech-form-group">
                                <label for="hoktech-vendor-dial-code"><?php esc_html_e('كود الدولة الافتراضي لأرقام الفيندور', 'sender-notification'); ?></label>
                                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                                    <input type="text" id="hoktech-vendor-dial-code" name="default_dial_code" class="hoktech-input" style="max-width:160px;" value="<?php echo esc_attr($vendor_notifications['default_dial_code'] ?? ''); ?>" placeholder="<?php esc_attr_e('مثال: 20', 'sender-notification'); ?>">
                                    <select id="hoktech-vendor-dial-preset" class="hoktech-select" style="max-width:220px;">
                                        <option value=""><?php esc_html_e('-- اختر دولة --', 'sender-notification'); ?></option>
                                        <option value="20" <?php selected($vendor_notifications['default_dial_code'] ?? '', '20'); ?>>🇪🇬 مصر (+20)</option>
                                        <option value="966" <?php selected($vendor_notifications['default_dial_code'] ?? '', '966'); ?>>🇸🇦 السعودية (+966)</option>
                                        <option value="971" <?php selected($vendor_notifications['default_dial_code'] ?? '', '971'); ?>>🇦🇪 الإمارات (+971)</option>
                                        <option value="965" <?php selected($vendor_notifications['default_dial_code'] ?? '', '965'); ?>>🇰🇼 الكويت (+965)</option>
                                        <option value="962" <?php selected($vendor_notifications['default_dial_code'] ?? '', '962'); ?>>🇯🇴 الأردن (+962)</option>
                                        <option value="974" <?php selected($vendor_notifications['default_dial_code'] ?? '', '974'); ?>>🇶🇦 قطر (+974)</option>
                                        <option value="973" <?php selected($vendor_notifications['default_dial_code'] ?? '', '973'); ?>>🇧🇭 البحرين (+973)</option>
                                        <option value="968" <?php selected($vendor_notifications['default_dial_code'] ?? '', '968'); ?>>🇴🇲 عُمان (+968)</option>
                                        <option value="218" <?php selected($vendor_notifications['default_dial_code'] ?? '', '218'); ?>>🇱🇾 ليبيا (+218)</option>
                                        <option value="216" <?php selected($vendor_notifications['default_dial_code'] ?? '', '216'); ?>>🇹🇳 تونس (+216)</option>
                                        <option value="213" <?php selected($vendor_notifications['default_dial_code'] ?? '', '213'); ?>>🇩🇿 الجزائر (+213)</option>
                                        <option value="212" <?php selected($vendor_notifications['default_dial_code'] ?? '', '212'); ?>>🇲🇦 المغرب (+212)</option>
                                    </select>
                                </div>
                                <p class="description"><?php esc_html_e('إذا كان رقم الفيندور بدون كود الدولة (مثل 01006...) سيُضاف هذا الكود تلقائياً. اتركه فارغاً لعدم التطبيق.', 'sender-notification'); ?></p>
                            </div>

                            <!-- Product Item Format -->
                            <div class="hoktech-form-group">
                                <label for="hoktech-vendor-item-format"><?php esc_html_e('تنسيق سطر المنتج في رسالة الفيندور', 'sender-notification'); ?></label>
                                <textarea id="hoktech-vendor-item-format" name="vendor_item_format" class="hoktech-textarea" rows="3" placeholder="<?php esc_attr_e('- {product_name} x{product_qty} ({product_subtotal})', 'sender-notification'); ?>"><?php 
                                    $item_format_val = $vendor_notifications['item_format'] ?? '- {product_name} x{product_qty} ({product_subtotal})';
                                    echo "\n" . esc_textarea($item_format_val) . "\n"; 
                                ?></textarea>
                                <div class="hoktech-placeholders-info" style="margin-top: 10px; margin-bottom: 0;">
                                    <strong><?php esc_html_e('المتغيرات المتاحة لسطر المنتج:', 'sender-notification'); ?></strong><br>
                                    <code>{product_name}</code> <?php esc_html_e('اسم المنتج', 'sender-notification'); ?> &nbsp;
                                    <code>{product_qty}</code> <?php esc_html_e('الكمية', 'sender-notification'); ?> &nbsp;
                                    <code>{product_sku}</code> <?php esc_html_e('الـ SKU', 'sender-notification'); ?> &nbsp;
                                    <code>{product_url}</code> <?php esc_html_e('رابط المنتج', 'sender-notification'); ?> &nbsp;
                                    <code>{product_subtotal}</code> <?php esc_html_e('سعر المنتجات', 'sender-notification'); ?>
                                </div>
                            </div>

                            <!-- Message template -->
                            <div class="hoktech-form-group">
                                <label for="hoktech-vendor-message"><?php esc_html_e('قالب رسالة الفيندور', 'sender-notification'); ?></label>
                                <textarea id="hoktech-vendor-message" name="vendor_message" class="hoktech-textarea" rows="7"><?php 
                                    $vendor_message_val = $vendor_notifications['message'] ?? '';
                                    echo "\n" . esc_textarea($vendor_message_val) . "\n"; 
                                ?></textarea>
                                <div class="hoktech-placeholders-info" style="margin-top: 10px; margin-bottom: 0;">
                                    <strong><?php esc_html_e('المتغيرات المتاحة للرسالة:', 'sender-notification'); ?></strong><br>
                                    <code>{vendor_name}</code> <?php esc_html_e('اسم متجر الفيندور', 'sender-notification'); ?> &nbsp;
                                    <code>{vendor_items}</code> <?php esc_html_e('الأيتم الخاصة بهذا الفيندور فقط', 'sender-notification'); ?> &nbsp;
                                    <code>{vendor_items_total}</code> <?php esc_html_e('إجمالي أيتم الفيندور', 'sender-notification'); ?><br style="margin:4px 0">
                                    <code>{order_id}</code> / <code>{sub_order_id}</code> (<?php esc_html_e('رقم الطلب الفرعي', 'sender-notification'); ?>) &nbsp;
                                    <code>{customer_name}</code> <code>{order_total}</code> <code>{order_status}</code> <code>{site_name}</code> <code>{billing_phone}</code> <code>{sku}</code> <code>{product_url}</code>
                                </div>
                            </div>

                            <!-- Vendor Dedicated Session -->
                            <div class="hoktech-form-group" style="border-top: 1px solid #e0e0e0; padding-top: 20px; margin-top: 10px;">
                                <label><?php esc_html_e('جلسة إرسال مخصصة للفيندور (اختياري)', 'sender-notification'); ?></label>
                                <p class="description" style="margin-bottom: 12px;"><?php esc_html_e('اختر جلسة واتساب مستقلة لإرسال الرسائل للفيندورز. إذا تركتها فارغة، سيتم استخدام نفس الجلسة الافتراضية المستخدمة لإشعارات العملاء.', 'sender-notification'); ?></p>
                                <div class="hoktech-session-row">
                                    <select id="hoktech-vendor-session-select" class="hoktech-select">
                                        <option value=""><?php esc_html_e('-- نفس جلسة العملاء (افتراضي) --', 'sender-notification'); ?></option>
                                    </select>
                                    <button type="button" id="hoktech-vendor-refresh-sessions" class="button">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 6px; vertical-align: middle;"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
                                        <?php esc_html_e('تحديث', 'sender-notification'); ?>
                                    </button>
                                    <button type="button" id="hoktech-vendor-save-session" class="button button-primary">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 6px; vertical-align: middle;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                        <?php esc_html_e('حفظ الجلسة', 'sender-notification'); ?>
                                    </button>
                                </div>
                                <div id="hoktech-vendor-session-result" class="hoktech-result" style="display:none; margin-top:8px;"></div>
                                <input type="hidden" id="hoktech-vendor-current-session" value="<?php echo esc_attr($vendor_notifications['session_id'] ?? ''); ?>">
                            </div>

                            <div class="hoktech-form-actions">
                                <button type="submit" class="button button-primary" id="hoktech-vendor-notif-save">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 8px; vertical-align: middle;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                    <?php esc_html_e('حفظ إعدادات الفيندور', 'sender-notification'); ?>
                                </button>
                            </div>
                        </form>
                        <div id="hoktech-vendor-notif-result" class="hoktech-result" style="display:none;"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
