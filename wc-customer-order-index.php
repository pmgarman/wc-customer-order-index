<?php
/**
 * Plugin Name:     WooCommerce - Customer Order Index
 * Plugin URI:      http://garman.io
 * Description:     Create a more scalable index of orders and the associated customers.
 * Version:         1.0.0
 * Author:          Patrick Garman
 * Author URI:      http://pmgarman.me
 * Text Domain:     wc-customer-order-index
 */

// Exit if accessed directly
if( !defined( 'ABSPATH' ) ) {
    exit;
}

if( !class_exists( 'WC_Customer_Order_Index' ) ) :

    class WC_Customer_Order_Index {

        public $table_name = 'woocommerce_customer_order_index';

        /**
         * Construct the plugin.
         */
        public function __construct() {
            // Install database table on activation
            register_activation_hook( __FILE__, array( $this, 'install' ) );

            // Include the CLI
            require_once 'inc/class-wc-customer-order-index-cli.php';

            // Hook into add/update post meta to keep index updated on the fly
            add_action( 'add_post_meta', array( $this, 'update_index_from_meta' ), 10, 3 );
            add_action( 'update_post_meta', array( $this, 'update_index_from_meta_update' ), 10, 4 );
        }

        public function install() {
            global $wpdb;

            $table_name      = $wpdb->prefix . 'woocommerce_customer_order_index';
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE $table_name (
              `order_id` int(11) unsigned NOT NULL,
              `user_id` int(11) DEFAULT NULL,
              PRIMARY KEY (`order_id`),
              KEY `user_id` (`user_id`)
            ) $charset_collate;";

            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
            dbDelta( $sql );
        }

        public function update_index_from_meta( $object_id, $meta_key, $meta_value ) {
            if( 'shop_order' == get_post_type( $object_id ) && '_customer_user' == $meta_key ) {
                $this->update_index( $object_id, absint( $meta_value ) );
            }
        }

        public function update_index_from_meta_update( $meta_id, $object_id, $meta_key, $meta_value ) {
            $this->update_index_from_meta( $object_id, $meta_key, $meta_value );
        }

        /**
         * Update order index
         *
         * @param  int $order_id Order ID
         * @param  int $user_id User ID
         * @return bool           If the index is updated, true will be returned. If no update was performed (either due to a failure or if the index was already up to date) false will be returned.
         */
        public function update_index( $order_id, $user_id = null ) {
            global $wpdb;

            $sql    = $wpdb->prepare( "INSERT INTO {$wpdb->prefix}{$this->table_name} (`order_id`,`user_id`) VALUES (%d, %d) ON DUPLICATE KEY UPDATE `user_id` = %d;", $order_id, $user_id, $user_id );
            $result = $wpdb->query( $sql );
            if( !is_wp_error( $result ) ) {
                return (bool)$result;
            } else {
                return false;
            }
        }

        /**
         * Get customer who placed an order
         *
         * @param  int $order_id Order ID
         * @return int           Customer ID who placed order
         */
        public function get_order_customer( $order_id ) {
            global $wpdb;

            $sql    = $wpdb->prepare( "SELECT `user_id` FROM {$wpdb->prefix}{$this->table_name} WHERE `order_id` = %d", $order_id );
            $result = $wpdb->get_var( $sql );

            if( !is_wp_error( $result ) ) {
                return absint( $result );
            } else {
                return 0;
            }
        }

        /**
         * Get all orders placed by a customer
         *
         * @param  int $user_id Customer's user ID
         * @return array           Array of all orders placed by customer
         */
        public function get_customers_orders( $user_id ) {
            global $wpdb;

            // Don't pull all guest orders this way, things may break.
            if( 0 == $user_id ) {
                return array();
            }

            $sql    = $wpdb->prepare( "SELECT `order_id` FROM {$wpdb->prefix}{$this->table_name} WHERE `user_id` = %d ORDER BY `order_id` DESC", $user_id );
            $result = $wpdb->get_col( $sql );

            if( !is_wp_error( $result ) && is_array( $result ) ) {
                return $result;
            } else {
                return array();
            }
        }

        /**
         * Server intense function to get all orders that were placed by a guest.
         *
         * @return array Array of returned order IDs
         */
        public function get_guest_orders() {
            global $wpdb;

            $sql    = $wpdb->prepare( "SELECT `order_id` FROM {$wpdb->prefix}{$this->table_name} WHERE `user_id` = %d", 0 );
            $result = $wpdb->get_col( $sql );

            if( !is_wp_error( $result ) && is_array() ) {
                return $result;
            } else {
                return array();
            }
        }

    }

    global $WC_Customer_Order_Index;
    $WC_Customer_Order_Index = new WC_Customer_Order_Index( __FILE__ );

endif;

/**
 * Get the WC Customer Order Index
 */
function WC_COI() {
    global $WC_Customer_Order_Index;

    if( class_exists( 'WC_Customer_Order_Index' ) && !is_null( $WC_Customer_Order_Index ) ) {
        return $WC_Customer_Order_Index;
    } else {
        return false;
    }
}
