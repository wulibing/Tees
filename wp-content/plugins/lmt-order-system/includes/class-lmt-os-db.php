<?php
if (!defined('ABSPATH')) { exit; }

class LMT_OS_DB {
    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $orders = $wpdb->prefix . 'lmt_orders';

        $sql = "CREATE TABLE $orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_number VARCHAR(32) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'draft',
            customer_email VARCHAR(190) NOT NULL,
            customer_name VARCHAR(190) NOT NULL,
            payload LONGTEXT NOT NULL,
            quote_snapshot LONGTEXT NOT NULL,
            payment_provider VARCHAR(32) DEFAULT '',
            payment_ref VARCHAR(190) DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY order_number (order_number),
            KEY customer_email (customer_email),
            KEY status (status)
        ) $charset;";

        dbDelta($sql);
    }
}
