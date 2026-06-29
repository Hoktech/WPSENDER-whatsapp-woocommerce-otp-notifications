<?php
/**
 * HokTech Async Sender  (v2 – Shutdown-based, no loopback HTTP needed)
 *
 * كيف يشتغل:
 *  1. كل مكالمة enqueue() تضيف عنصر لقائمة static في الذاكرة.
 *  2. عند أول enqueue يُسجَّل hook واحد على 'shutdown' (أولوية 999).
 *  3. بعد ما WordPress يُرسل استجابة HTTP للمتصفح، يُشغَّل shutdown.
 *  4. لو PHP-FPM متاح → fastcgi_finish_request() يُغلق الاتصال فوراً
 *     ثم نُرسل رسائل واتساب بدون أي تأثير على سرعة الصفحة.
 *  5. لو mod_php → الإرسال يحصل بعد اكتمال الصفحة مباشرةً (فرق بسيط جداً).
 *
 *  لا يحتاج:  loopback HTTP – WP-Cron – Action Scheduler
 */

if (!defined('ABSPATH')) {
    exit;
}

class HokTech_Async_Sender {

    /** @var array[] قائمة الإشعارات المنتظرة في هذا الطلب */
    private static array $queue = [];

    /** @var bool هل تم تسجيل shutdown hook؟ */
    private static bool $shutdown_registered = false;

    /**
     * أضف إشعاراً إلى القائمة وسجّل shutdown processor إذا لم يُسجَّل بعد.
     *
     * @param string $type    'customer' | 'admin' | 'vendor'
     * @param array  $payload البيانات اللازمة للإرسال
     */
    public function enqueue(string $type, array $payload): void {
        self::$queue[] = ['type' => $type, 'payload' => $payload];

        if (!self::$shutdown_registered) {
            self::$shutdown_registered = true;
            // أولوية 999 → يعمل بعد كل شيء آخر
            add_action('shutdown', [__CLASS__, 'process_queue'], 999);
        }
    }

    /**
     * يُنفَّذ تلقائياً عند نهاية طلب WordPress.
     * static حتى يعمل حتى لو تم إنشاء instance جديدة.
     */
    public static function process_queue(): void {
        if (empty(self::$queue)) {
            return;
        }

        /*
         * أغلق الاتصال مع المتصفح قبل الإرسال:
         *  - PHP-FPM (Nginx/Apache mod_fcgid/LiteSpeed): fastcgi_finish_request() ← الأفضل
         *  - mod_php fallback: flush + ob_flush (لا يضمن الإغلاق الكامل لكنه يساعد)
         */
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (!headers_sent()) {
            header('Connection: close');
            header('Content-Encoding: none');
            ignore_user_abort(true);
            $size = ob_get_length();
            if ($size !== false) {
                header('Content-Length: ' . $size);
            }
            ob_end_flush();
            flush();
        }

        // الآن PHP لا يزال يعمل لكن المتصفح استلم الصفحة ✅
        $api = new HokTech_API_Client();

        foreach (self::$queue as $item) {
            self::dispatch($api, $item['type'], $item['payload']);
        }

        // امسح القائمة بعد الإرسال
        self::$queue = [];
    }

    // ─── Dispatch handlers ─────────────────────────────────────────────────

    private static function dispatch(HokTech_API_Client $api, string $type, array $p): void {
        switch ($type) {
            case 'customer':
                self::send_customer($api, $p);
                break;
            case 'admin':
                self::send_admin($api, $p);
                break;
            case 'vendor':
                self::send_vendor($api, $p);
                break;
        }
    }

    private static function send_customer(HokTech_API_Client $api, array $p): void {
        $order = wc_get_order($p['order_id'] ?? 0);
        if (!$order) {
            return;
        }

        $result   = $api->send_message($p['phone'], $p['message'], null, $p['media'] ?? null);
        $meta_key = '_hoktech_wa_sent_status_' . ($p['new_status'] ?? '');

        if ($result['success']) {
            update_post_meta($p['order_id'], $meta_key, true);
            $order->add_order_note(
                sprintf(
                    __('✅ تم إرسال إشعار للعميل (%1$s) - حالة: %2$s', 'sender-notification'),
                    $p['phone'],
                    $p['new_status'] ?? ''
                )
            );
        } else {
            $order->add_order_note(
                sprintf(
                    __('❌ فشل إرسال الإشعار: %s', 'sender-notification'),
                    $result['message'] ?? __('خطأ غير معروف', 'sender-notification')
                )
            );
        }

        // Log
        $log   = get_option('hoktech_wa_notification_log', []);
        $log   = count($log) >= 100 ? array_slice($log, -99) : $log;
        $log[] = [
            'order_id'  => $p['order_id'],
            'status'    => $p['new_status'] ?? '',
            'phone'     => $p['phone'],
            'result'    => $result['success'] ? 'sent' : 'failed',
            'message'   => $result['message'] ?? '',
            'timestamp' => current_time('mysql'),
        ];
        update_option('hoktech_wa_notification_log', $log);
    }

    private static function send_admin(HokTech_API_Client $api, array $p): void {
        $order = wc_get_order($p['order_id'] ?? 0);
        if (!$order) {
            return;
        }

        $sent = 0;
        $fail = 0;

        foreach (($p['phones'] ?? []) as $phone) {
            $r = $api->send_message($phone, $p['message']);
            $r['success'] ? $sent++ : $fail++;
        }

        if ($sent > 0) {
            $order->add_order_note(
                sprintf(__('👥 تم إرسال إشعار الإدارة إلى %d رقم بنجاح', 'sender-notification'), $sent)
            );
        }
        if ($fail > 0) {
            $order->add_order_note(
                sprintf(__('⚠️ فشل إرسال إشعار الإدارة إلى %d رقم', 'sender-notification'), $fail)
            );
        }
    }

    private static function send_vendor(HokTech_API_Client $api, array $p): void {
        $order = wc_get_order($p['order_id'] ?? 0);
        if (!$order) {
            return;
        }

        $result = $api->send_message($p['phone'], $p['message']);

        if (!$result['success']) {
            $order->add_order_note(
                sprintf(
                    __('❌ فشل إرسال إشعار للفيندور %1$s (%2$s): %3$s', 'sender-notification'),
                    $p['vendor_name'] ?? '',
                    $p['phone'],
                    $result['message'] ?? __('خطأ غير معروف', 'sender-notification')
                )
            );
        }
    }
}
