<?php
if (!defined('ABSPATH')) { exit; }

class LMT_OS_Pricing {
    public static function calculate($payload) {
        $item_type = sanitize_text_field($payload['item_type'] ?? 'tshirt');
        $material = sanitize_text_field($payload['material'] ?? 'blend');
        $design_mode = sanitize_text_field($payload['design_mode'] ?? 'self');
        $state = strtoupper(sanitize_text_field($payload['state'] ?? ''));

        $adult = intval($payload['adult_qty'] ?? 0);
        $youth = intval($payload['youth_qty'] ?? 0);
        $total_qty = max(0, $adult + $youth);

        $adult_prices = [
            'tshirt' => ['blend'=>19,'cooling'=>25,'cotton'=>35,'premium'=>49],
            'hoodie' => ['blend'=>45,'cooling'=>69,'cotton'=>79,'premium'=>99,'thermal'=>119],
        ];
        $youth_prices = [
            'tshirt' => ['blend'=>15,'cooling'=>19,'cotton'=>26,'premium'=>38],
            'hoodie' => ['blend'=>35,'cooling'=>49,'cotton'=>59,'premium'=>75,'thermal'=>89],
        ];

        $unit_adult = floatval($adult_prices[$item_type][$material] ?? 0);
        $unit_youth = floatval($youth_prices[$item_type][$material] ?? 0);

        $base_cost = ($adult * $unit_adult) + ($youth * $unit_youth);
        $discount_rate = self::discount_rate($total_qty);
        $discounted_base = $base_cost * (1 - $discount_rate);
        $design_fee = $design_mode === 'studio' ? 100 : 0;
        $subtotal = $discounted_base + $design_fee;
        $tax_rate = self::tax_rate($state);
        $tax = $subtotal * $tax_rate;
        $total = $subtotal + $tax;

        return [
            'pricing_rules_version' => '2026-05-18-v1',
            'item_type' => $item_type,
            'material' => $material,
            'adult_qty' => $adult,
            'youth_qty' => $youth,
            'total_qty' => $total_qty,
            'unit_adult' => round($unit_adult, 2),
            'unit_youth' => round($unit_youth, 2),
            'base_cost' => round($base_cost, 2),
            'discount_rate' => $discount_rate,
            'discounted_base_cost' => round($discounted_base, 2),
            'design_fee' => round($design_fee, 2),
            'subtotal' => round($subtotal, 2),
            'tax_rate' => $tax_rate,
            'tax' => round($tax, 2),
            'total' => round($total, 2),
        ];
    }

    public static function discount_rate($qty) {
        if ($qty >= 100) return 0.20;
        if ($qty >= 50) return 0.15;
        if ($qty >= 24) return 0.10;
        if ($qty >= 12) return 0.05;
        return 0;
    }

    public static function tax_rate($state) {
        $rates = ['CA'=>0.0825,'NY'=>0.0875,'TX'=>0.0625,'FL'=>0.06,'WA'=>0.065,'AK'=>0,'DE'=>0,'MT'=>0,'NH'=>0,'OR'=>0];
        if (empty($state)) return 0;
        return isset($rates[$state]) ? $rates[$state] : 0;
    }
}