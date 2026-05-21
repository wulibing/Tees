<?php
if (!defined('ABSPATH')) { exit; }

class LMT_OS_API {
    private static function request_payload(WP_REST_Request $request) {
        $payload = $request->get_json_params() ?: [];
        if (!empty($payload) && is_array($payload)) return $payload;
        $raw = $request->get_param('payload');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode(wp_unslash($raw), true);
            if (is_array($decoded)) return $decoded;
        }
        $params = $request->get_params();
        return is_array($params) ? $params : [];
    }

    private static function handle_studio_uploads() {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $uploaded = [];
        foreach (['studio_files','self_files'] as $bucket) {
            if (empty($_FILES[$bucket])) continue;
            $files = $_FILES[$bucket];
            $count = is_array($files['name']) ? count($files['name']) : 0;
            for ($i = 0; $i < $count; $i++) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                $single = [
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i],
                ];
                $move = wp_handle_upload($single, ['test_form' => false]);
                if (!empty($move['url'])) {
                    $attachment_id = self::register_media_attachment($move['file'], $move['type'], $single['name']);
                    $uploaded[] = [
                        'bucket' => sanitize_key($bucket),
                        'name' => sanitize_text_field($single['name']),
                        'url' => esc_url_raw($move['url']),
                        'attachment_id' => $attachment_id,
                    ];
                }
            }
        }
        return $uploaded;
    }

    private static function register_media_attachment($file_path, $mime_type, $title) {
        $file_path = (string) $file_path;
        if ($file_path === '' || !file_exists($file_path)) return 0;
        $upload_dir = wp_upload_dir();
        $baseurl = $upload_dir['baseurl'] ?? '';
        $basedir = $upload_dir['basedir'] ?? '';
        if (!$baseurl || !$basedir) return 0;
        $file_url = str_replace($basedir, $baseurl, $file_path);

        $attachment = [
            'post_mime_type' => sanitize_text_field($mime_type ?: mime_content_type($file_path)),
            'post_title' => sanitize_file_name(pathinfo($title, PATHINFO_FILENAME) ?: basename($file_path)),
            'post_content' => '',
            'post_status' => 'inherit',
            'guid' => esc_url_raw($file_url),
        ];
        $attachment_id = wp_insert_attachment($attachment, $file_path);
        if (is_wp_error($attachment_id) || !$attachment_id) return 0;

        $meta = wp_generate_attachment_metadata($attachment_id, $file_path);
        if (!is_wp_error($meta) && !empty($meta)) {
            wp_update_attachment_metadata($attachment_id, $meta);
        }
        return intval($attachment_id);
    }

    private static function persist_self_design_assets($self_layers, $order_number) {
        if (!is_array($self_layers)) return [];
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $saved = [];
        $allowed = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
        foreach (['tshirt', 'hoodie'] as $garment) {
            $layers = $self_layers[$garment] ?? [];
            if (!is_array($layers)) continue;
            foreach ($layers as $idx => $layer) {
                if (!is_array($layer)) continue;
                if (($layer['type'] ?? '') !== 'img') continue;
                $src = $layer['src'] ?? '';
                if (!is_string($src) || strpos($src, 'data:image/') !== 0) continue;
                if (!preg_match('#^data:image/([a-zA-Z0-9+]+);base64,(.+)$#', $src, $m)) continue;
                $ext = strtolower($m[1] === 'jpeg' ? 'jpg' : $m[1]);
                if (!isset($allowed[$ext])) continue;
                $binary = base64_decode($m[2], true);
                if ($binary === false) continue;
                $filename = sanitize_file_name(strtolower($order_number . '-' . $garment . '-layer-' . ($idx + 1) . '.' . $ext));
                $upload = wp_upload_bits($filename, null, $binary);
                if (!empty($upload['error'])) continue;
                $attachment_id = self::register_media_attachment($upload['file'], $allowed[$ext], $filename);
                $saved[] = [
                    'garment' => $garment,
                    'layer' => intval($idx) + 1,
                    'url' => esc_url_raw($upload['url']),
                    'attachment_id' => $attachment_id,
                ];
            }
        }
        return $saved;
    }

    private static function persist_self_view_exports($view_exports, $order_number) {
        if (!is_array($view_exports)) return [];
        $saved = [];
        foreach ($view_exports as $item) {
            if (!is_array($item)) continue;
            $view = sanitize_key($item['view'] ?? '');
            $src = $item['png_data'] ?? '';
            if (!$view || !is_string($src) || strpos($src, 'data:image/png;base64,') !== 0) continue;
            $binary = base64_decode(substr($src, strlen('data:image/png;base64,')), true);
            if ($binary === false) continue;
            $filename = sanitize_file_name(strtolower($order_number . '-view-' . $view . '.png'));
            $upload = wp_upload_bits($filename, null, $binary);
            if (!empty($upload['error'])) continue;
            $attachment_id = self::register_media_attachment($upload['file'], 'image/png', $filename);
            $saved[] = [
                'view' => $view,
                'url' => esc_url_raw($upload['url']),
                'attachment_id' => $attachment_id,
            ];
        }
        return $saved;
    }
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('wp_ajax_lmt_create_order', [__CLASS__, 'ajax_create_order']);
        add_action('wp_ajax_nopriv_lmt_create_order', [__CLASS__, 'ajax_create_order']);
    }

    public static function register_routes() {
        register_rest_route('lmt/v1', '/quote', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'quote'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('lmt/v1', '/order', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_order'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('lmt/v1', '/checkout', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'checkout'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function quote(WP_REST_Request $request) {
        $payload = self::request_payload($request);
        $quote = LMT_OS_Pricing::calculate($payload);
        $tax = LMT_OS_Tax::estimate($payload, $quote['subtotal']);
        if ($tax) {
            $quote['tax_rate'] = round(floatval($tax['rate']), 6);
            $quote['tax'] = round(floatval($tax['tax']), 2);
            $quote['total'] = round($quote['subtotal'] + $quote['tax'], 2);
            $quote['tax_source'] = $tax['source'];
        }
        $tax = LMT_OS_Tax::estimate($payload, $quote['subtotal']);
        if ($tax) {
            $quote['tax_rate'] = round(floatval($tax['rate']), 6);
            $quote['tax'] = round(floatval($tax['tax']), 2);
            $quote['total'] = round($quote['subtotal'] + $quote['tax'], 2);
            $quote['tax_source'] = $tax['source'];
        }
        return rest_ensure_response([
            'ok' => true,
            'quote' => $quote,
        ]);
    }

    public static function create_order(WP_REST_Request $request) {
        $payload = self::request_payload($request);
        return rest_ensure_response(self::create_order_from_payload($payload));
    }


    public static function ajax_create_order() {
        $payload = [];
        if (!empty($_POST['payload'])) {
            $decoded = json_decode(wp_unslash($_POST['payload']), true);
            if (is_array($decoded)) $payload = $decoded;
        } else {
            $raw = file_get_contents('php://input');
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) $payload = $decoded;
            }
        }
        $response = self::create_order_from_payload($payload);
        if (is_wp_error($response)) {
            wp_send_json(['ok'=>false,'message'=>$response->get_error_message()], 400);
        }
        wp_send_json($response);
    }

    private static function create_order_from_payload(array $payload) {
        global $wpdb;
        $quote = LMT_OS_Pricing::calculate($payload);
        $tax = LMT_OS_Tax::estimate($payload, $quote['subtotal']);
        if ($tax) {
            $quote['tax_rate'] = round(floatval($tax['rate']), 6);
            $quote['tax'] = round(floatval($tax['tax']), 2);
            $quote['total'] = round($quote['subtotal'] + $quote['tax'], 2);
            $quote['tax_source'] = $tax['source'];
        }

        $email = sanitize_email($payload['email'] ?? '');
        $name = sanitize_text_field($payload['name'] ?? '');
        if (!$email || !$name) {
            return new WP_REST_Response(['ok' => false, 'message' => 'name/email required'], 400);
        }

        $order_number = 'LMT-' . gmdate('Ymd') . '-' . wp_rand(10000, 99999);
        $table = $wpdb->prefix . 'lmt_orders';
        $now = current_time('mysql');

        $wpdb->insert($table, [
            'order_number' => $order_number,
            'status' => 'quoted',
            'customer_email' => $email,
            'customer_name' => $name,
            'payload' => wp_json_encode($payload),
            'quote_snapshot' => wp_json_encode($quote),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $item_type = strtoupper(sanitize_text_field($payload['item_type'] ?? ''));
        $material = sanitize_text_field($payload['material'] ?? '');
        $adult_qty = intval($payload['adult_qty'] ?? 0);
        $youth_qty = intval($payload['youth_qty'] ?? 0);
        $studio_notes = sanitize_textarea_field($payload['studio_notes'] ?? '');

        $material_labels = [
            'blend' => 'Cotton-Poly Blend',
            'cooling' => 'Cooling Fabric',
            'cotton' => '100% Cotton',
            'premium' => 'Premium Heavyweight',
            'thermal' => 'Thermal',
        ];
        $material_full = $material_labels[$material] ?? $material;

        $price_lines = 'Base: $' . number_format($quote['base_cost'], 2) . "\n"
            . 'Discount: ' . number_format($quote['discount_rate'] * 100, 0) . "%\n"
            . 'After discount: $' . number_format($quote['discounted_base_cost'], 2) . "\n"
            . 'Design fee: $' . number_format($quote['design_fee'], 2) . "\n"
            . 'Subtotal: $' . number_format($quote['subtotal'], 2) . "\n"
            . 'Tax: $' . number_format($quote['tax'], 2) . ' (' . number_format($quote['tax_rate'] * 100, 2) . "%)\n"
            . 'Total: $' . number_format($quote['total'], 2);

        $common = "Order Number: $order_number\n"
            . "Name: $name\n"
            . "Email: $email\n"
            . 'Phone: ' . sanitize_text_field($payload['phone'] ?? '') . "\n"
            . 'Address: ' . sanitize_text_field($payload['addr1'] ?? '') . ' ' . sanitize_text_field($payload['addr2'] ?? '') . ', '
            . sanitize_text_field($payload['city'] ?? '') . ', ' . sanitize_text_field($payload['state'] ?? '') . ' '
            . sanitize_text_field($payload['zip'] ?? '') . ', ' . sanitize_text_field($payload['country'] ?? '') . "\n\n"
            . "Item: $item_type\n"
            . "Material: $material_full\n"
            . "Adult Qty: $adult_qty\n"
            . "Youth Qty: $youth_qty\n"
            . "Design Mode: " . ((sanitize_text_field($payload['design_mode'] ?? 'self') === 'studio') ? 'Lee designs it for me' : 'Design it myself') . "\n"
            . ($studio_notes ? "Notes for Lee: $studio_notes\n" : '')
            . "\nPrice Breakdown\n" . $price_lines . "\n";

        $self_layers = $payload['self_layers'] ?? [];
        $self_layers_json = wp_json_encode($self_layers);
        $is_self_design = sanitize_text_field($payload['design_mode'] ?? 'self') !== 'studio';
        $has_self_layers = is_array($self_layers) && (
            !empty($self_layers['tshirt']) || !empty($self_layers['hoodie'])
        );

        $seller_body = "New order received.\n\n" . $common;

        $studio_file_names = $payload['studio_file_names'] ?? [];
        if (!is_array($studio_file_names)) $studio_file_names = [];
        $studio_uploaded = self::handle_studio_uploads();
        if (!$is_self_design) {
            if (!empty($studio_uploaded)) {
                $seller_body .= "\nStudio uploaded files (original):\n";
                foreach ($studio_uploaded as $f) {
                    $seller_body .= '- ' . $f['name'] . ': ' . $f['url'] . "\n";
                }
            }
            if (empty($studio_uploaded)) {
                $seller_body .= "
Important: No studio files were attached in this submit. Ask customer to reply with original PNG/PDF/AI files.
";
            }
        }
        if ($is_self_design && $has_self_layers) {
            $tshirt_layers = is_array($self_layers['tshirt'] ?? null) ? count($self_layers['tshirt']) : 0;
            $hoodie_layers = is_array($self_layers['hoodie'] ?? null) ? count($self_layers['hoodie']) : 0;
            $seller_body .= "\nSelf design summary:\n";
            $seller_body .= "- T-shirt layers: " . $tshirt_layers . "\n";
            $seller_body .= "- Hoodie layers: " . $hoodie_layers . "\n";
            $self_uploaded = array_values(array_filter($studio_uploaded, function($f){ return is_array($f) && (($f['bucket'] ?? '') === 'self_files'); }));
            if (!empty($self_uploaded)) {
                $seller_body .= "Self design uploaded image links:\n";
                foreach ($self_uploaded as $idx => $f) {
                    $seller_body .= '- Upload ' . (intval($idx) + 1) . ' (' . sanitize_text_field($f['name'] ?? 'image') . '): ' . esc_url_raw($f['url'] ?? '') . "\n";
                }
            }

            $saved_assets = self::persist_self_design_assets($self_layers, $order_number);
            if (!empty($saved_assets)) {
                $seller_body .= "Self design image links (from embedded layers):\n";
                foreach ($saved_assets as $asset) {
                    $seller_body .= '- ' . ucfirst($asset['garment']) . ' image layer ' . $asset['layer'] . ': ' . $asset['url'] . "\n";
                }
            } elseif (empty($self_uploaded)) {
                $seller_body .= "No standalone self-design image files were extracted.\n";
            }

            $view_links = self::persist_self_view_exports($payload['self_view_exports'] ?? [], $order_number);
            if (!empty($view_links)) {
                $seller_body .= "Self-design four-view PNG exports:\n";
                foreach ($view_links as $v) {
                    $seller_body .= '- ' . strtoupper(str_replace('_', ' ', $v['view'])) . ': ' . $v['url'] . "\n";
                }
            }

            $text_items = $payload['self_text_items'] ?? [];
            if (is_array($text_items) && !empty($text_items)) {
                $seller_body .= "Customer text placed on shirt:\n";
                foreach ($text_items as $t) {
                    if (!is_array($t)) continue;
                    $seller_body .= '- [' . sanitize_text_field($t['garment'] ?? '') . '/' . sanitize_text_field($t['surface'] ?? '') . '] '
                        . sanitize_text_field($t['text'] ?? '') . "\n";
                }
            }

            $glb_preview_url = esc_url_raw($payload['glb_preview_url'] ?? '');
            if (!empty($glb_preview_url)) {
                $seller_body .= "GLB preview package link: " . $glb_preview_url . "\n";
            }
        }

        $customer_body = "Thanks for your order request.\n\n" . $common
            . "\nLee has received your request and images (if any), and will be in touch soon. Lee will always ask for your approval before any printing starts.\n"
            . "If anything above is wrong or anything else needs to change, please reply to this email.\n"
            . "If everything looks good, please complete payment as soon as possible using the payment step on site.\n";

        $customer_headers = ['Reply-To: leemakestees@gmail.com'];
        $seller_headers = ['Reply-To: ' . $email];

        wp_mail($email, "Order $order_number received", $customer_body, $customer_headers);
        wp_mail('leemakestees@gmail.com', "New order $order_number", $seller_body, $seller_headers);

        return [
            'ok' => true,
            'order_number' => $order_number,
            'quote' => $quote,
        ];
    }

    public static function checkout(WP_REST_Request $request) {
        $payload = $request->get_json_params() ?: [];
        $provider = sanitize_text_field($payload['provider'] ?? 'paypal');
        return rest_ensure_response([
            'ok' => true,
            'message' => 'Checkout endpoint scaffold ready. Next step: connect ' . $provider . ' SDK keys in wp-config.php and implement signed session creation.',
        ]);
    }
}