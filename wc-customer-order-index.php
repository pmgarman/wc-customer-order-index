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
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Customer_Order_Index' ) ) :

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
			add_action( 'added_post_meta', array( $this, 'update_index_from_meta_add' ), 10, 4 );
			add_action( 'updated_post_meta', array( $this, 'update_index_from_meta_update' ), 10, 4 );

			// Add query vars
			add_filter( 'query_vars', array( $this, 'add_query_vars' ), 10, 1 );

			// Patch the customer orders query
			add_filter( 'woocommerce_my_account_my_orders_query', array( $this, 'my_orders_query' ), 10, 1 );

			// Enable filters if needed so that the join works
			add_action( 'pre_get_posts', array( $this, 'enable_filters_if_wc_customer' ), 10, 2 );

			// Perform the useful query change
			add_filter( 'posts_join', array( $this, 'wc_customer_query_join' ), 10, 2 );
			add_filter( 'posts_where', array( $this, 'wc_customer_query_where' ), 10, 2 );

			// Perform the email search when searching an email in admin
			add_action( 'parse_query', array( $this, 'wc_email_search' ), 9 );

			// Keep user email updated
			add_action( 'profile_update', array( $this, 'update_customer' ) );

			// Hook into add/update user meta to keep customer name updated on the fly
			add_action( 'added_user_meta', array( $this, 'maybe_update_customer_name_add' ), 10, 4 );
			add_action( 'updated_user_meta', array( $this, 'maybe_update_customer_name_update' ), 10, 4 );

			// WC 3.0 Data Store Patch
			add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'order_data_store_cpt' ), 10, 3 );
			add_filter( 'woocommerce_customer_get_total_spent_query', array( $this, 'money_spent_query' ), 10, 2 );
		}

		public function install() {
			global $wpdb;

			$table_name      = $wpdb->prefix . 'woocommerce_customer_order_index';
			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE $table_name (
			  `order_id` int(11) unsigned NOT NULL,
			  `user_id` int(11) DEFAULT NULL,
			  `customer_email` varchar(175) DEFAULT NULL,
			  `billing_email` varchar(175) DEFAULT NULL,
			  `customer_name` varchar(175) DEFAULT NULL,
			  `billing_name` varchar(175) DEFAULT NULL,
			  `shipping_name` varchar(175) DEFAULT NULL,
			  PRIMARY KEY (`order_id`),
			  KEY `user_id` (`user_id`),
			  KEY `customer_email` (`customer_email`),
			  KEY `billing_email` (`billing_email`),
			  KEY `customer_name` (`customer_name`),
			  KEY `billing_name` (`billing_name`),
			  KEY `shipping_name` (`shipping_name`)
			) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
		}

		public function update_index_from_meta( $object_id, $meta_key, $meta_value ) {
			if ( 'shop_order' == get_post_type( $object_id ) && in_array( $meta_key, array( '_customer_user', '_billing_email', '_billing_first_name', '_billing_last_name', '_shipping_first_name', '_shipping_last_name' ) ) ) {
				$this->update_index( $object_id );
			}
		}

		public function update_index_from_meta_add( $meta_id, $object_id, $meta_key, $meta_value ) {
			$this->update_index_from_meta( $object_id, $meta_key, $meta_value );
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
		public function update_index( $order_id ) {
			global $wpdb;

			$user_id = intval( get_post_meta( $order_id, '_customer_user', true ) );
			if ( empty( $user_id ) ) {
				$user_id = 0;
			}

			$customer_email = '';
			$customer_name  = '';

			if ( $user_id ) {
				$user_data = get_user_by( 'id', $user_id );
				if ( $user_data ) {
					$customer_email = $user_data->user_email;
					$customer_name  = trim( $user_data->first_name . ' ' . $user_data->last_name );
				}
			}

			$billing_email = get_post_meta( $order_id, '_billing_email', true );

			$billing_name  = trim( get_post_meta( $order_id, '_billing_first_name', true ) . ' ' . get_post_meta( $order_id, '_billing_last_name', true ) );
			$shipping_name = trim( get_post_meta( $order_id, '_shipping_first_name', true ) . ' ' . get_post_meta( $order_id, '_shipping_last_name', true ) );

			$sql = $wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}{$this->table_name} (`order_id`,`user_id`,`customer_email`,`billing_email`,`customer_name`,`billing_name`,`shipping_name`) 
				VALUES (%d, %d, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE `user_id` = %d, `customer_email` = %s, `billing_email` = %s, `customer_name` = %s, `billing_name` = %s, `shipping_name` = %s;",
				$order_id,
				$user_id,
				$customer_email,
				$billing_email,
				$customer_name,
				$billing_name,
				$shipping_name,
				$user_id,
				$customer_email,
				$billing_email,
				$customer_name,
				$billing_name,
				$shipping_name
			);

			$result = $wpdb->query( $sql );
			if ( ! is_wp_error( $result ) ) {
				return (bool) $result;
			} else {
				return false;
			}
		}

		/**
		 * Action that gets executed when a user profile gets updated
		 *
		 * @param  int $user_id User ID
		 */
		public function update_customer( $user_id ) {
			global $wpdb;
			$user = get_user_by( 'id', $user_id );
			$sql  = $wpdb->prepare(
				"UPDATE {$wpdb->prefix}{$this->table_name} SET `customer_email` = %s, `customer_name` = %s WHERE `user_id` = %d",
				$user->user_email,
				trim( $user->first_name . ' ' . $user->last_name ),
				$user_id
			);

			$result = $wpdb->query( $sql );
			if ( ! is_wp_error( $result ) ) {
				return (bool) $result;
			} else {
				return false;
			}
		}

		public function maybe_update_customer_name( $object_id, $meta_key, $meta_value ) {
			if ( 'shop_order' == get_post_type( $object_id ) && in_array( $meta_key, array( 'first_name', 'last_name' ) ) ) {
				$this->update_customer( $object_id );
			}
		}

		public function maybe_update_customer_name_add( $meta_id, $object_id, $meta_key, $meta_value ) {
			$this->maybe_update_customer_name( $object_id, $meta_key, $meta_value );
		}

		public function maybe_update_customer_name_update( $meta_id, $object_id, $meta_key, $meta_value ) {
			$this->maybe_update_customer_name( $object_id, $meta_key, $meta_value );
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

			if ( ! is_wp_error( $result ) ) {
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
			if ( 0 == $user_id ) {
				return array();
			}

			$sql    = $wpdb->prepare( "SELECT `order_id` FROM {$wpdb->prefix}{$this->table_name} WHERE `user_id` = %d ORDER BY `order_id` DESC", $user_id );
			$result = $wpdb->get_col( $sql );

			if ( ! is_wp_error( $result ) && is_array( $result ) ) {
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

			if ( ! is_wp_error( $result ) && is_array() ) {
				return $result;
			} else {
				return array();
			}
		}

		/**
		 * Adds a custom query parameter
		 *
		 * @param  array $qvars Array of query vars
		 * @return array Array of query vars with the new ones appended
		 */
		public function add_query_vars( $qvars ) {
			$qvars[] = 'wc_customer_user';
			$qvars[] = 'wc_customer_email';
			$qvars[] = 'wc_customer_name';
			$qvars[] = 'wc_order_id';
			return $qvars;
		}

		/**
		 * Changes the my_orders query in order to use the index
		 *
		 * @param  array $params Array of query parameters
		 * @return array Query parameters
		 */
		public function my_orders_query( $params ) {
			if ( isset( $params['meta_key'] ) && $params['meta_key'] == '_customer_user' ) {
				$params['wc_customer_user'] = $params['meta_value'];
				unset( $params['meta_key'] );
				unset( $params['meta_value'] );
			}
			if ( isset( $params['customer'] ) && ! empty( $params['customer'] ) ) {
				$params['wc_customer_user'] = $params['customer'];
				if ( ! version_compare( WC_VERSION, '2.7', '<' ) ) {
					$data_store = WC_Data_Store::load( 'order' );
					if ( $data_store->get_current_class_name() == 'WC_Order_Data_Store_CPT' ) {
						unset( $params['customer'] );
					}
				}
			}
			return $params;
		}

		/**
		 * Changes the queries to the CPT data store for orders (WC 3.0 and above)
		 *
		 * @param  array $params Array of query parameters
		 * @return array Query parameters
		 */
		public function order_data_store_cpt( $query, $args, $data_store ) {
			if ( isset( $args['wc_customer_user'] ) ) {
				$query['wc_customer_user'] = $args['wc_customer_user'];
			}
			if ( isset( $args['customer'] ) && ! empty( $args['customer'] ) ) {
				$query['wc_customer_user'] = $args['customer'];
			}

			return $query;
		}

		public function money_spent_query( $query, $customer ) {
			global $wpdb;

			$statuses = array_map( 'esc_sql', wc_get_is_paid_statuses() );

			return "SELECT SUM(meta2.meta_value)
                FROM $wpdb->posts as posts
                INNER JOIN {$wpdb->prefix}{$this->table_name} wccoi ON posts.ID = wccoi.order_id AND wccoi.user_id = '" . esc_sql( $customer->get_id() ) . "'
                LEFT JOIN {$wpdb->postmeta} AS meta2 ON posts.ID = meta2.post_id
                WHERE   posts.post_type     = 'shop_order'
                AND     posts.post_status   IN ( 'wc-" . implode( "','wc-", $statuses ) . "' )
                AND     meta2.meta_key      = '_order_total'
            ";
		}

		/**
		 * Enables filters so that the join works
		 *
		 * @param  array $params Array of query parameters
		 * @return array Query parameters
		 */
		public function enable_filters_if_wc_customer( $wpq ) {
			if ( (
					! empty( $wpq->query_vars['wc_customer_user'] ) ||
					! empty( $wpq->query_vars['wc_customer_email'] ) ||
					! empty( $wpq->query_vars['wc_customer_name'] ) ||
					! empty( $wpq->query_vars['wc_order_id'] )
				) && $wpq->query_vars['suppress_filters'] ) {
				$wpq->query_vars['suppress_filters'] = false;
			}
		}

		/**
		 * Adds a custom join to use the new index
		 *
		 * @param  array $params Array of query parameters
		 * @return array Query parameters
		 */
		public function wc_customer_query_join( $join, $wp_query ) {
			global $wpdb;

			if (
				! empty( $wp_query->query_vars['wc_customer_user'] ) ||
				! empty( $wp_query->query_vars['wc_customer_email'] ) ||
				! empty( $wp_query->query_vars['wc_customer_name'] ) ||
				! empty( $wp_query->query_vars['wc_order_id'] )
				) {
				$join .= " INNER JOIN {$wpdb->prefix}{$this->table_name} wc_cidx ON $wpdb->posts.ID = wc_cidx.order_id ";
			}

			return $join;
		}

		/**
		 * Use the join and add necessary where conditions
		 *
		 * @param  array $params Array of query parameters
		 * @return array Query parameters
		 */
		public function wc_customer_query_where( $where, $wp_query ) {
			global $wpdb;
			if ( ! empty( $wp_query->query_vars['wc_customer_user'] ) ) {
				$where .= $wpdb->prepare( ' AND wc_cidx.user_id = %d ', $wp_query->query_vars['wc_customer_user'] );
			}
			if ( ! empty( $wp_query->query_vars['wc_customer_email'] ) ) {
				$where .= $wpdb->prepare(
					' AND ( wc_cidx.customer_email = %s OR wc_cidx.billing_email = %s ) ',
					$wp_query->query_vars['wc_customer_email'],
					$wp_query->query_vars['wc_customer_email']
				);
			}
			if ( ! empty( $wp_query->query_vars['wc_customer_name'] ) ) {
				$name   = '%' . $wpdb->esc_like( $wp_query->query_vars['wc_customer_name'] ) . '%';
				$where .= $wpdb->prepare(
					' AND ( wc_cidx.customer_name LIKE %s OR wc_cidx.billing_name LIKE %s OR wc_cidx.shipping_name LIKE %s ) ',
					$name,
					$name,
					$name
				);
			}
			if ( ! empty( $wp_query->query_vars['wc_order_id'] ) ) {
				$where .= $wpdb->prepare( ' AND wc_cidx.order_id = %d ', $wp_query->query_vars['wc_order_id'] );
			}

			return $where;
		}

		/**
		 * Enables email only search within the index table
		 *
		 * @param  array $params Array of query parameters
		 * @return array Query parameters
		 */
		public function wc_email_search( $wpq ) {
			global $pagenow;
			if ( 'edit.php' != $pagenow || empty( $wpq->query_vars['s'] ) || $wpq->query_vars['post_type'] != 'shop_order' ) {
				return;
			}

			$search = trim( $wpq->query_vars['s'] );

			$processing = false;

			if ( substr( $search, 0, 1 ) == '#' ) {
				$processing                     = true;
				$wpq->query_vars['wc_order_id'] = substr( $search, 1 );
			}

			if ( is_email( $search ) ) {
				$processing                           = true;
				$wpq->query_vars['wc_customer_email'] = $search;
			}

			if ( substr( $search, 0, 5 ) == 'name:' ) {
				$processing                          = true;
				$wpq->query_vars['wc_customer_name'] = trim( substr( $search, 5 ) );
			}

			if ( $processing ) {
				// Remove "s" - we don't want to search order name.
				unset( $wpq->query_vars['s'] );

				// so we know we're doing this.
				$wpq->query_vars['shop_order_search'] = true;
			}

			return $wpq;
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

	if ( class_exists( 'WC_Customer_Order_Index' ) && ! is_null( $WC_Customer_Order_Index ) ) {
		return $WC_Customer_Order_Index;
	} else {
		return false;
	}
}
