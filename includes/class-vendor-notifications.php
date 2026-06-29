<?php
/**
 * sender Vendor Notifications
 * ترسل رسالة واتساب لكل فيندور بالأيتم الخاصة بيه فقط
 *
 * تدعم إضافات Multi-Vendor:
 *  - Dokan
 *  - WC Vendors
 *  - WCFM (WC Frontend Manager)
 *  - رقم يدوي (Meta حقل يدوي على بروفايل الفيندور)
 */

if (!defined('ABSPATH')) {
    exit;
}

class HokTech_Vendor_Notifications {

    private $api;

    public function __construct() {
        $this->api = new HokTech_API_Client();
        add_action('woocommerce_order_status_changed', [$this, 'on_status_changed'], 20, 4);
    }

    /**
     * Handle order status change → send per-vendor notifications
     */
    public function on_status_changed($order_id, $old_status, $new_status, $order) {
        // تجاهل السب-أوردر (multi-vendor sub-orders) لمنع التكرار
        if ($order->get_parent_id() > 0) {
            return;
        }

        if ($old_status === $new_status) {
            return;
        }

        // منع الإرسال المكرر لنفس الحالة
        $meta_key = '_hoktech_vendor_notif_sent_' . $new_status;
        if (get_post_meta($order_id, $meta_key, true)) {
            return;
        }

        // تحقق من الاتصال
        if (!$this->api->is_connected()) {
            return;
        }

        // جلب إعدادات إشعارات الفيندور
        $vendor_settings = get_option('hoktech_wa_vendor_notifications', []);

        if (empty($vendor_settings['enabled'])) {
            return;
        }

        // تحقق أن هذه الحالة مفعّلة
        $enabled_statuses = $vendor_settings['statuses'] ?? [];
        if (!in_array($new_status, $enabled_statuses, true)) {
            return;
        }

        $message_template = $vendor_settings['message'] ?? '';
        if (empty($message_template)) {
            return;
        }

        // جمع الأيتم مقسمة حسب الفيندور
        $vendor_items = $this->group_items_by_vendor($order);

        if (empty($vendor_items)) {
            return;
        }

        $sent_count  = 0;
        $fail_count  = 0;
        $no_phone    = 0;

        foreach ($vendor_items as $vendor_id => $data) {
            $vendor_phone = $this->get_vendor_phone($vendor_id, $vendor_settings);

            if (empty($vendor_phone)) {
                $no_phone++;
                continue;
            }

            // بناء الرسالة مع أيتم الفيندور هذا فقط
            $message = $this->parse_template($message_template, $order, $data['items'], $data['vendor_name'], $vendor_id);

            // استخدم الجلسة المخصصة للفيندور إن وجدت، وإلا تُرك null ليستخدم الجلسة الافتراضية
            $vendor_session_id = !empty($vendor_settings['session_id']) ? $vendor_settings['session_id'] : null;

            // إرسال غير محجوب (blocking=false) ← الـ checkout لا ينتظر
            $result = $this->api->send_message($vendor_phone, $message, $vendor_session_id, null, true);

            if ($result['success']) {
                $sent_count++;
            } else {
                $fail_count++;
            }
        }

        // تسجيل ملاحظة على الأوردر
        if ($sent_count > 0) {
            update_post_meta($order_id, $meta_key, true);
            $order->add_order_note(
                sprintf(
                    __('🏪 تم إرسال إشعار واتساب لـ %d فيندور بنجاح (حالة: %s)', 'sender-notification'),
                    $sent_count,
                    $new_status
                )
            );
        }

        if ($no_phone > 0) {
            $order->add_order_note(
                sprintf(
                    __('⚠️ %d فيندور بدون رقم هاتف - لم يتم إرسال إشعار لهم', 'sender-notification'),
                    $no_phone
                )
            );
        }
    }

    /**
     * تجميع أيتم الأوردر مقسمة حسب الفيندور
     *
     * @param WC_Order $order
     * @return array [ vendor_id => [ 'vendor_name' => '', 'items' => [] ] ]
     */
    private function group_items_by_vendor($order) {
        $vendor_items = [];

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $product_id = $item->get_product_id();
            $vendor_id  = $this->get_vendor_id_for_product($product_id);

            if (!$vendor_id) {
                // لو مفيش فيندور (منتج مملوك للموقع الرئيسي)، اعتبره vendor_id = 0
                $vendor_id = 0;
            }

            if (!isset($vendor_items[$vendor_id])) {
                $vendor_items[$vendor_id] = [
                    'vendor_name' => $this->get_vendor_display_name($vendor_id),
                    'items'       => [],
                ];
            }

            $vendor_items[$vendor_id]['items'][] = [
                'name'     => $item->get_name(),
                'qty'      => $item->get_quantity(),
                'sku'      => $product->get_sku(),
                'url'      => $product->get_permalink(),
                'subtotal' => html_entity_decode(
                    wp_strip_all_tags(
                        wc_price($item->get_subtotal())
                    ),
                    ENT_QUOTES,
                    'UTF-8'
                ),
            ];
        }

        // إزالة vendor_id = 0 إن وُجد (منتجات الموقع الرئيسي لا يُرسَل لها)
        unset($vendor_items[0]);

        return $vendor_items;
    }

    /**
     * الحصول على vendor_id للمنتج
     * يدعم: Dokan, WC Vendors, WCFM
     */
    private function get_vendor_id_for_product($product_id) {
        // --- Dokan ---
        if (function_exists('dokan_get_vendor_by_product')) {
            $vendor = dokan_get_vendor_by_product($product_id);
            if ($vendor && !is_wp_error($vendor)) {
                return $vendor->get_id();
            }
        }

        // --- WC Vendors ---
        if (class_exists('WCV_Vendors') && method_exists('WCV_Vendors', 'get_vendor_from_product')) {
            $vendor_id = WCV_Vendors::get_vendor_from_product($product_id);
            if ($vendor_id) {
                return $vendor_id;
            }
        }

        // --- WCFM ---
        if (function_exists('wcfm_get_vendor_id_by_post')) {
            $vendor_id = wcfm_get_vendor_id_by_post($product_id);
            if ($vendor_id) {
                return $vendor_id;
            }
        }

        // --- Fallback: مالك البوست (post_author) ---
        $post = get_post($product_id);
        if ($post && $post->post_author) {
            return (int) $post->post_author;
        }

        return 0;
    }

    /**
     * الحصول على اسم الفيندور
     */
    private function get_vendor_display_name($vendor_id) {
        if (!$vendor_id) {
            return __('الموقع الرئيسي', 'sender-notification');
        }

        // --- Dokan ---
        if (function_exists('dokan_get_store_info')) {
            $store_info = dokan_get_store_info($vendor_id);
            if (!empty($store_info['store_name'])) {
                return $store_info['store_name'];
            }
        }

        // --- WCFM ---
        $wcfm_store_name = get_user_meta($vendor_id, 'wcfmmp_profile_settings', true);
        if (is_array($wcfm_store_name) && !empty($wcfm_store_name['store_name'])) {
            return $wcfm_store_name['store_name'];
        }

        // Fallback: اسم المستخدم
        $user = get_userdata($vendor_id);
        if ($user) {
            return $user->display_name ?: $user->user_login;
        }

        return __('فيندور غير معروف', 'sender-notification');
    }

    /**
     * الحصول على رقم هاتف الفيندور مع تطبيق كود الدولة الافتراضي إذا لزم
     */
    private function get_vendor_phone($vendor_id, $vendor_settings) {
        if (!$vendor_id) {
            return '';
        }

        $custom_meta_key    = $vendor_settings['phone_meta_key']    ?? '';
        $default_dial_code  = $vendor_settings['default_dial_code'] ?? '';
        // أزل أي رموز (+، مسافات) من كود الدولة واحتفظ بالأرقام فقط
        $default_dial_code  = preg_replace('/[^0-9]/', '', $default_dial_code);

        $raw_phone = '';

        // 1. حقل meta مخصص (محدد من الإعدادات)
        if (!empty($custom_meta_key)) {
            $phone = get_user_meta($vendor_id, $custom_meta_key, true);
            if (!empty($phone)) {
                $raw_phone = $phone;
            }
        }

        // 2. Dokan
        if (empty($raw_phone) && function_exists('dokan_get_store_info')) {
            $store_info = dokan_get_store_info($vendor_id);
            $phone = $store_info['phone'] ?? '';
            if (!empty($phone)) {
                $raw_phone = $phone;
            }
        }

        // 3. WCFM
        if (empty($raw_phone)) {
            $wcfm_settings = get_user_meta($vendor_id, 'wcfmmp_profile_settings', true);
            if (is_array($wcfm_settings) && !empty($wcfm_settings['phone'])) {
                $raw_phone = $wcfm_settings['phone'];
            }
        }

        // 4. billing_phone
        if (empty($raw_phone)) {
            $billing_phone = get_user_meta($vendor_id, 'billing_phone', true);
            if (!empty($billing_phone)) {
                $raw_phone = $billing_phone;
            }
        }

        // 5. الهاتف العام للمستخدم
        if (empty($raw_phone)) {
            $raw_phone = get_user_meta($vendor_id, 'phone', true)
                      ?: get_user_meta($vendor_id, 'mobile', true)
                      ?: get_user_meta($vendor_id, 'whatsapp', true);
        }

        if (empty($raw_phone)) {
            return '';
        }

        return $this->format_phone_with_dial_code($raw_phone, $default_dial_code);
    }

    /**
     * تنظيف رقم الهاتف وإضافة كود الدولة إذا كان ناقصاً
     *
     * المنطق:
     *  - إذا الرقم يبدأ بـ + أو بكود الدولة نفسه → اتركه كما هو
     *  - إذا الرقم يبدأ بـ 00 → احذف الـ 00 واترك الباقي (هو كود دولة دولي)
     *  - إذا الرقم يبدأ بـ 0 وعندنا كود افتراضي → احذف الـ 0 وأضف الكود
     *  - إذا مفيش كود افتراضي → اتركه كما هو بعد التنظيف
     */
    private function format_phone_with_dial_code($phone, $default_dial_code) {
        // أزل كل شيء ما عدا الأرقام والـ +
        $phone_stripped = preg_replace('/[^0-9+]/', '', $phone);

        // لو بيبدأ بـ + → أزل الـ + واحتفظ بالأرقام
        if (substr($phone_stripped, 0, 1) === '+') {
            return preg_replace('/[^0-9]/', '', $phone_stripped);
        }

        $digits = preg_replace('/[^0-9]/', '', $phone_stripped);

        // لو بيبدأ بـ 00 → دولي بالفعل (0020... → 20...)
        if (substr($digits, 0, 2) === '00') {
            return substr($digits, 2);
        }

        // لو عندنا كود دولة افتراضي
        if (!empty($default_dial_code)) {
            // لو الرقم مش بيبدأ بالكود الافتراضي أصلاً
            if (strpos($digits, $default_dial_code) !== 0) {
                // احذف الصفر الأمامي لو موجود
                $local = ltrim($digits, '0');
                return $default_dial_code . $local;
            }
        }

        // بدون تعديل
        return $digits;
    }

    /**
     * استبدال المتغيرات في قالب الرسالة
     *
     * المتغيرات الإضافية للفيندور:
     *  {vendor_name}       - اسم المتجر / الفيندور
     *  {vendor_items}      - قائمة الأيتم الخاصة بالفيندور فقط
     *  {vendor_items_total} - إجمالي الأيتم الخاصة بالفيندور
     */
    private function parse_template($template, $order, $items, $vendor_name, $vendor_id = 0) {
        $vendor_settings = get_option('hoktech_wa_vendor_notifications', []);
        $item_format = !empty($vendor_settings['item_format']) ? $vendor_settings['item_format'] : "- {product_name} x{product_qty} ({product_subtotal})";

        // بناء نص الأيتم
        $items_lines = [];
        $skus = [];
        $urls = [];
        foreach ($items as $item) {
            $item_replacements = [
                '{product_name}'     => $item['name'],
                '{product_qty}'      => $item['qty'],
                '{product_sku}'      => $item['sku'] ?? '',
                '{product_url}'      => $item['url'] ?? '',
                '{product_subtotal}' => $item['subtotal'],
            ];
            $items_lines[] = str_replace(array_keys($item_replacements), array_values($item_replacements), $item_format);

            if (!empty($item['sku'])) {
                $skus[] = $item['sku'];
            }
            if (!empty($item['url'])) {
                $urls[] = $item['url'];
            }
        }
        $sku_list = !empty($skus) ? implode(', ', $skus) : '';
        $url_list = !empty($urls) ? implode(', ', $urls) : '';

        // إجمالي أيتم الفيندور من خلال الأيتم نفسها
        $vendor_total_raw = array_sum(array_map(function ($item) use ($order) {
            // نحسب من الأوردر نفسه
            foreach ($order->get_items() as $oi) {
                if ($oi->get_name() === $item['name'] && $oi->get_quantity() == $item['qty']) {
                    return (float) $oi->get_subtotal();
                }
            }
            return 0;
        }, $items));

        $vendor_total_formatted = html_entity_decode(
            wp_strip_all_tags(wc_price($vendor_total_raw)),
            ENT_QUOTES,
            'UTF-8'
        );

        // إجمالي الأوردر الكامل
        $clean_total = html_entity_decode(
            wp_strip_all_tags($order->get_formatted_order_total()),
            ENT_QUOTES,
            'UTF-8'
        );

        // جميع أيتم الأوردر (للمتغير العام)
        $all_items_text = [];
        foreach ($order->get_items() as $oi) {
            $all_items_text[] = '- ' . $oi->get_name() . ' x' . $oi->get_quantity();
        }

        // جلب رقم الطلب الفرعي للفيندور إن وُجد
        $sub_order_id = $this->get_vendor_sub_order_id($order->get_id(), $vendor_id);
        if ($sub_order_id) {
            $sub_order = wc_get_order($sub_order_id);
            $display_order_id = $sub_order ? $sub_order->get_order_number() : $sub_order_id;
        } else {
            $display_order_id = $order->get_order_number();
        }

        $replacements = [
            '{order_id}'          => $display_order_id,
            '{sub_order_id}'      => $display_order_id,
            '{customer_name}'     => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            '{order_total}'       => $clean_total,
            '{order_status}'      => wc_get_order_status_name($order->get_status()),
            '{site_name}'         => get_bloginfo('name'),
            '{order_items}'       => implode("\n", $all_items_text),
            '{billing_phone}'     => $order->get_billing_phone(),
            '{sku}'               => $sku_list,
            '{product_url}'       => $url_list,
            '{vendor_name}'       => $vendor_name,
            '{vendor_items}'      => implode("\n", $items_lines),
            '{vendor_items_total}'=> $vendor_total_formatted,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Send vendor notifications for a specific status manually
     */
    public function send_vendor_notifications_manually($order_id, $status = '') {
        $order = wc_get_order($order_id);
        if (!$order) {
            return ['success' => false, 'message' => __('الطلب غير موجود', 'sender-notification')];
        }

        if (empty($status)) {
            $status = $order->get_status();
        }

        // تحقق من الاتصال
        if (!$this->api->is_connected()) {
            return ['success' => false, 'message' => __('المنصة غير متصلة', 'sender-notification')];
        }

        // جلب إعدادات إشعارات الفيندور
        $vendor_settings = get_option('hoktech_wa_vendor_notifications', []);

        if (empty($vendor_settings['enabled'])) {
            return ['success' => false, 'message' => __('إشعارات الفيندور غير مفعّلة في الإعدادات العامة', 'sender-notification')];
        }

        $message_template = $vendor_settings['message'] ?? '';
        if (empty($message_template)) {
            return ['success' => false, 'message' => __('قالب رسالة الفيندور فارغ في الإعدادات العامة', 'sender-notification')];
        }

        // جمع الأيتم مقسمة حسب الفيندور
        $vendor_items = $this->group_items_by_vendor($order);

        if (empty($vendor_items)) {
            return ['success' => false, 'message' => __('لم يتم العثور على أي منتجات للفيندورز في هذا الطلب', 'sender-notification')];
        }

        $sent_count  = 0;
        $fail_count  = 0;
        $no_phone    = 0;

        foreach ($vendor_items as $vendor_id => $data) {
            $vendor_phone = $this->get_vendor_phone($vendor_id, $vendor_settings);

            if (empty($vendor_phone)) {
                $no_phone++;
                continue;
            }

            // بناء الرسالة مع أيتم الفيندور هذا فقط
            $message = $this->parse_template($message_template, $order, $data['items'], $data['vendor_name'], $vendor_id);

            // استخدم الجلسة المخصصة للفيندور إن وجدت، وإلا تُرك null ليستخدم الجلسة الافتراضية
            $vendor_session_id = !empty($vendor_settings['session_id']) ? $vendor_settings['session_id'] : null;

            // إرسال غير محجوب
            $result = $this->api->send_message($vendor_phone, $message, $vendor_session_id, null, true);

            if ($result['success']) {
                $sent_count++;
            } else {
                $fail_count++;
            }
        }

        if ($sent_count > 0) {
            $meta_key = '_hoktech_vendor_notif_sent_' . $status;
            update_post_meta($order_id, $meta_key, true);
            $order->add_order_note(
                sprintf(
                    __('🏪 تم إعادة إرسال إشعار واتساب لـ %d فيندور بنجاح (حالة: %s)', 'sender-notification'),
                    $sent_count,
                    wc_get_order_status_name($status)
                )
            );
        }

        if ($no_phone > 0) {
            $order->add_order_note(
                sprintf(
                    __('⚠️ %d فيندور بدون رقم هاتف - لم يتم إرسال إشعار لهم أثناء الإعادة اليدوية', 'sender-notification'),
                    $no_phone
                )
            );
        }

        if ($sent_count > 0) {
            return ['success' => true, 'message' => sprintf(__('تم إعادة إرسال الإشعارات لـ %d فيندور بنجاح', 'sender-notification'), $sent_count)];
        } else {
            return ['success' => false, 'message' => __('فشل إرسال الإشعارات للفيندورز (قد يكون لعدم وجود أرقام هواتف مسجلة للفيندورز)', 'sender-notification')];
        }
    }

    /**
     * الحصول على معرّف الطلب الفرعي للفيندور (Sub-order ID) بناءً على الطلب الأب ومعرّف الفيندور
     * يدعم إضافات Multi-Vendor المتعددة (Dokan, WCFM, WC Vendors) بشكل متوافق مع HPOS وقواعد البيانات
     *
     * @param int $parent_order_id
     * @param int $vendor_id
     * @return int
     */
    private function get_vendor_sub_order_id($parent_order_id, $vendor_id) {
        if (!$parent_order_id || !$vendor_id) {
            return 0;
        }

        // البحث عن الطلبات الابنة للطلب الأب
        $sub_orders = wc_get_orders([
            'parent_id' => $parent_order_id,
            'limit'     => -1,
        ]);

        if (empty($sub_orders)) {
            return 0;
        }

        foreach ($sub_orders as $sub_order) {
            $sub_order_id = $sub_order->get_id();

            // 1. التحقق من Dokan (حيث يكون الفيندور هو كاتب بوست الطلب الفرعي)
            $author_id = (int) get_post_field('post_author', $sub_order_id);
            if ($author_id === (int) $vendor_id) {
                return $sub_order_id;
            }

            // 2. التحقق من حقول meta الخاصة بـ WCFM
            $wcfm_vendor = (int) get_post_meta($sub_order_id, '_wcfm_vendor', true);
            if ($wcfm_vendor === (int) $vendor_id) {
                return $sub_order_id;
            }

            // 3. التحقق من حقول meta الخاصة بـ WC Vendors
            $wcv_vendor = (int) get_post_meta($sub_order_id, '_commission_vendor', true);
            if ($wcv_vendor === (int) $vendor_id) {
                return $sub_order_id;
            }

            // 4. طريقة احتياطية عامة: فحص المنتجات داخل الطلب الفرعي ومطابقة الفيندور الخاص بها
            foreach ($sub_order->get_items() as $item) {
                $product_id = $item->get_product_id();
                if ($product_id) {
                    $item_vendor_id = $this->get_vendor_id_for_product($product_id);
                    if ((int) $item_vendor_id === (int) $vendor_id) {
                        return $sub_order_id;
                    }
                }
            }
        }

        return 0;
    }
}
