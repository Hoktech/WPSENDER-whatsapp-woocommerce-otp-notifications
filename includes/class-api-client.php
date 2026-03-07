<?php
/**
 * sender API Client
 * Handles all communication with the sender platform API
 */

if (!defined('ABSPATH')) {
    exit;
}

class HokTech_API_Client {

    private $api_url;
    private $api_key;

    public function __construct() {
        $connection = get_option('hoktech_wa_connection', []);
        $this->api_url = rtrim($connection['api_url'] ?? '', '/');
        $this->api_key = $connection['api_key'] ?? '';
    }

    /**
     * Check if the plugin is connected
     */
    public function is_connected() {
        return !empty($this->api_url) && !empty($this->api_key);
    }

    /**
     * Get the API URL
     */
    public function get_api_url() {
        return $this->api_url;
    }

    /**
     * Core HTTP request wrapper
     */
    private function make_request($method, $url, $args = []) {
        // WordPress handles encoding and HTTP versions natively via the WP HTTP API.
        // We do not use cURL overrides directly as it is discouraged by WP.org.

        // Mimic a modern browser exactly
        $browser_headers = [
            'User-Agent'                => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept'                    => 'application/json, text/plain, */*',
            'Accept-Language'           => 'en-US,en;q=0.9,ar;q=0.8',
            'sec-ch-ua'                 => '"Chromium";v="122", "Not(A:Brand";v="24", "Google Chrome";v="122"',
            'sec-ch-ua-mobile'          => '?0',
            'sec-ch-ua-platform'        => '"Windows"',
            'sec-fetch-dest'            => 'empty',
            'sec-fetch-mode'            => 'cors',
            'sec-fetch-site'            => 'cross-site',
            'Referer'                   => get_site_url(),
        ];

        if (!isset($args['headers'])) {
            $args['headers'] = [];
        }
        
        $args['headers'] = array_merge($browser_headers, $args['headers']);
        
        // Enable decompression so WordPress handles gzip/deflate automatically
        $args['decompress'] = true;
        
        if (!isset($args['timeout'])) {
            $args['timeout'] = 30;
        }

        if (strtoupper($method) === 'GET') {
            $response = wp_remote_get($url, $args);
        } else {
            $response = wp_remote_post($url, $args);
        }

        // If it's a WP_Error, try to give more info
        if (is_wp_error($response) && strpos($response->get_error_message(), 'error 61') !== false) {
             // If we STILL get 61, it means the server is forcing Brotli regardless of headers
             // We return a custom message to help the user
             return new WP_Error('encoding_error', __('خطأ في تشفير البيانات من السيرفر (Cloudflare Brotli). يرجى التأكد من إيقاف Brotli في إعدادات Cloudflare للمسار /api/*', 'sender-notification'));
        }

        return $response;
    }

    /**
     * Login with email and password
     */
    public function login($api_url, $email, $password) {
        $api_url = rtrim($api_url, '/');

        $response = $this->make_request('POST', $api_url . '/api/auth/login', [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'email'    => $email,
                'password' => $password,
            ]),
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200 || ($body['status'] ?? '') !== 'success') {
            return [
                'success' => false,
                'message' => $body['message'] ?? __('فشل تسجيل الدخول', 'sender-notification'),
            ];
        }

        $api_key = $body['data']['user']['api_key'] ?? '';
        $user_name = $body['data']['user']['full_name'] ?? '';
        $user_email = $body['data']['user']['email'] ?? '';

        if (empty($api_key)) {
            return [
                'success' => false,
                'message' => __('لم يتم العثور على مفتاح API في بيانات المستخدم', 'sender-notification'),
            ];
        }

        // Save connection data
        $connection = get_option('hoktech_wa_connection', []);
        $connection['api_url'] = $api_url;
        $connection['api_key'] = $api_key;
        $connection['user_name'] = $user_name;
        $connection['user_email'] = $user_email;
        $connection['connected_at'] = current_time('mysql');
        $connection['connection_method'] = 'login';
        update_option('hoktech_wa_connection', $connection);

        // Update instance vars
        $this->api_url = $api_url;
        $this->api_key = $api_key;

        return [
            'success'   => true,
            'message'   => __('تم الاتصال بنجاح', 'sender-notification'),
            'user_name' => $user_name,
            'api_key'   => $api_key,
        ];
    }

    /**
     * Connect manually with API key
     */
    public function connect_manual($api_url, $api_key) {
        $api_url = rtrim($api_url, '/');

        // Verify the API key by fetching sessions
        $response = $this->make_request('GET', $api_url . '/api/whatsapp-session/current', [
            'headers' => [
                'x-api-key' => $api_key,
                'Referer'   => get_site_url(),
            ],
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 401) {
            return [
                'success' => false,
                'message' => __('مفتاح API غير صالح', 'sender-notification'),
            ];
        }

        if ($status_code !== 200) {
            return [
                'success' => false,
                'message' => __('فشل الاتصال بالمنصة. تأكد من عنوان URL', 'sender-notification'),
            ];
        }

        // Save connection data
        $connection = get_option('hoktech_wa_connection', []);
        $connection['api_url'] = $api_url;
        $connection['api_key'] = $api_key;
        $connection['connected_at'] = current_time('mysql');
        $connection['connection_method'] = 'manual';
        update_option('hoktech_wa_connection', $connection);

        // Update instance vars
        $this->api_url = $api_url;
        $this->api_key = $api_key;

        return [
            'success' => true,
            'message' => __('تم الاتصال بنجاح عبر مفتاح API', 'sender-notification'),
        ];
    }

    /**
     * Disconnect
     */
    public function disconnect() {
        delete_option('hoktech_wa_connection');
        delete_transient('hoktech_wa_sessions_cache');
        $this->api_url = '';
        $this->api_key = '';
    }

    /**
     * Get available messaging sessions
     */
    public function get_sessions($force_refresh = false) {
        if (!$this->is_connected()) {
            return ['success' => false, 'message' => __('غير متصل بالمنصة', 'sender-notification')];
        }

        // Cache for 5 minutes
        if (!$force_refresh) {
            $cached = get_transient('hoktech_wa_sessions_cache');
            if ($cached !== false) {
                return $cached;
            }
        }

        $response = $this->make_request('GET', $this->api_url . '/api/whatsapp-session/current', [
            'headers' => [
                'x-api-key' => $this->api_key,
                'Referer'   => get_site_url(),
            ],
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            return [
                'success' => false,
                'message' => $body['message'] ?? __('فشل جلب الجلسات', 'sender-notification'),
            ];
        }

        $sessions = $body['data']['sessions'] ?? $body['data'] ?? [];

        $result = [
            'success'  => true,
            'sessions' => $sessions,
        ];

        set_transient('hoktech_wa_sessions_cache', $result, 5 * MINUTE_IN_SECONDS);

        return $result;
    }

    /**
     * Send a message
     */
    public function send_message($recipient, $message, $session_id = null, $media = null) {
        if (!$this->is_connected()) {
            return ['success' => false, 'message' => __('غير متصل بالمنصة', 'sender-notification')];
        }

        if (empty($session_id)) {
            $connection = get_option('hoktech_wa_connection', []);
            $session_id = $connection['session_id'] ?? '';
        }

        $payload = [
            'recipients' => [$recipient],
            'message'    => $message,
        ];

        if (!empty($media)) {
            $payload['contentType'] = 'MessageMedia';
            $payload['content'] = $media;
            $payload['no_duplication'] = false;
        }

        if (!empty($session_id)) {
            $payload['session_id'] = $session_id;
        }

        $response = $this->make_request('POST', $this->api_url . '/api/messages/send', [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key'    => $this->api_key,
                'Referer'      => get_site_url(),
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200 || ($body['status'] ?? '') !== 'success') {
            return [
                'success' => false,
                'message' => $body['message'] ?? __('فشل إرسال الرسالة', 'sender-notification'),
            ];
        }

        return [
            'success' => true,
            'message' => __('تم إرسال الرسالة بنجاح', 'sender-notification'),
            'data'    => $body['data'] ?? [],
        ];
    }

    /**
     * Send OTP
     */
    public function send_otp($recipient, $custom_message = null) {
        if (!$this->is_connected()) {
            return ['success' => false, 'message' => __('غير متصل بالمنصة', 'sender-notification')];
        }

        $connection = get_option('hoktech_wa_connection', []);
        $session_id = $connection['session_id'] ?? '';

        $payload = [
            'recipient' => $recipient,
        ];

        // Format message
        if (!empty($custom_message)) {
            $message = str_replace('{otp_code}', '{OTP}', $custom_message);
            $message = str_replace('{site_name}', get_bloginfo('name'), $message);
            $payload['message'] = $message;
        } else {
            /* translators: %s: Site Name */
            $payload['message'] = sprintf(__('رمز التحقق الخاص بك لـ %s هو: {OTP}', 'sender-notification'), get_bloginfo('name'));
        }

        $session_id = $connection['session_id'] ?? '';
        if (!empty($session_id)) {
            $payload['session_id'] = $session_id;
        }

        $response = $this->make_request('POST', $this->api_url . '/api/otp/send', [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key'    => $this->api_key,
                'Referer'      => get_site_url(),
                'Accept'       => 'application/json, text/plain, */*',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200 || ($body['status'] ?? '') !== 'success') {
            return [
                'success' => false,
                'message' => $body['message'] ?? __('فشل إرسال رمز التحقق', 'sender-notification'),
                'debug'   => [
                    'status_code' => $status_code,
                    'body'        => wp_remote_retrieve_body($response),
                ]
            ];
        }

        return [
            'success' => true,
            'data'    => $body['data'] ?? [],
        ];
    }

    /**
     * Verify OTP
     */
    public function verify_otp($recipient, $otp_code) {
        if (!$this->is_connected()) {
            return ['success' => false, 'message' => __('غير متصل بالمنصة', 'sender-notification')];
        }

        $response = $this->make_request('POST', $this->api_url . '/api/otp/verify', [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key'    => $this->api_key,
                'Referer'      => get_site_url(),
            ],
            'body' => wp_json_encode([
                'recipient' => $recipient,
                'otp_code'  => $otp_code,
            ]),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200 || ($body['status'] ?? '') !== 'success') {
            return [
                'success' => false,
                'message' => $body['message'] ?? __('رمز التحقق غير صحيح', 'sender-notification'),
            ];
        }

        return [
            'success' => true,
            'data'    => $body['data'] ?? [],
        ];
    }
}
