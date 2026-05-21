<?php
if (!defined('ABSPATH')) { exit; }

class LMT_OS_Tax {
    public static function estimate($payload, $subtotal) {
        $subtotal = floatval($subtotal);
        if ($subtotal <= 0) return null;

        $taxjar_key = defined('LMT_OS_TAXJAR_API_KEY') ? LMT_OS_TAXJAR_API_KEY : '';
        if ($taxjar_key) {
            $res = self::estimate_taxjar($payload, $subtotal, $taxjar_key);
            if ($res) return $res;
        }

        $state = strtoupper(sanitize_text_field($payload['state'] ?? ''));
        $rate = LMT_OS_Pricing::tax_rate($state);
        return [
            'rate' => $rate,
            'tax' => round($subtotal * $rate, 2),
            'source' => 'state_fallback',
        ];
    }

    private static function estimate_taxjar($payload, $subtotal, $api_key) {
        $body = [
            'to_country' => strtoupper(sanitize_text_field($payload['country'] ?? 'US')) === 'CANADA' ? 'CA' : 'US',
            'to_zip' => sanitize_text_field($payload['zip'] ?? ''),
            'to_state' => strtoupper(sanitize_text_field($payload['state'] ?? '')),
            'to_city' => sanitize_text_field($payload['city'] ?? ''),
            'to_street' => sanitize_text_field($payload['addr1'] ?? ''),
            'amount' => round($subtotal, 2),
            'shipping' => 0,
        ];

        $resp = wp_remote_post('https://api.taxjar.com/v2/taxes', [
            'headers' => [
                'Authorization' => 'Token token="' . $api_key . '"',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
            'timeout' => 12,
        ]);

        if (is_wp_error($resp)) return null;
        $code = wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) return null;
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (!isset($data['tax']['rate'])) return null;

        return [
            'rate' => floatval($data['tax']['rate']),
            'tax' => round(floatval($data['tax']['amount_to_collect'] ?? 0), 2),
            'source' => 'taxjar',
        ];
    }
}