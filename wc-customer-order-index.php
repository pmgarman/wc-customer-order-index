<?php // phpcs:ignore WordPress.Files.FileName
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

		public $table_name_subs = 'woocommerce_customer_subscription_index';

		/**
		 * The singleton instance of the plugin.
		 *
		 * @since    1.0.0
		 * @access   private
		 * @var      object    $instance    The singleton instance of the plugin.
		 */
		private static $instance = null;

		/**
		 * Retrieves or initialize an instance of this plugin's class.
		 *
		 * @since    1.0.0
		 * @access   public
		 */
		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

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
			add_filter( 'posts_orderby', array( $this, 'wc_customer_query_orderby' ), 10, 2 );

			// Perform the email search when searching an email in admin
			add_action( 'parse_query', array( $this, 'wc_email_search' ), 9 );
			add_action( 'request', array( $this, 'wc_subscription_sort' ), 9 );

			// Keep user email updated
			add_action( 'profile_update', array( $this, 'update_customer' ) );

			// Hook into add/update user meta to keep customer name updated on the fly
			add_action( 'added_user_meta', array( $this, 'maybe_update_customer_name_add' ), 10, 4 );
			add_action( 'updated_user_meta', array( $this, 'maybe_update_customer_name_update' ), 10, 4 );

			// WC 3.0 Data Store Patch
			add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'order_data_store_cpt' ), 10, 3 );
			add_filter( 'woocommerce_customer_get_total_spent_query', array( $this, 'money_spent_query' ), 10, 2 );

			add_filter( 'wcs_get_cached_users_subscription_ids', array( $this, 'get_user_subscriptions' ), 10, 2 );
		}

		public function install() {
			global $wpdb;

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			$table_name      = $wpdb->prefix . 'woocommerce_customer_order_index';
			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE $table_name (
			  `order_id` int(11) unsigned NOT NULL,
			  `order_number` varchar(175) DEFAULT NULL,
			  `user_id` int(11) DEFAULT NULL,
			  `customer_email` varchar(175) DEFAULT NULL,
			  `billing_email` varchar(175) DEFAULT NULL,
			  `customer_name` varchar(175) DEFAULT NULL,
			  `billing_name` varchar(175) DEFAULT NULL,
			  `shipping_name` varchar(175) DEFAULT NULL,
			  `billing_city` varchar(175) DEFAULT NULL,
			  `shipping_city` varchar(175) DEFAULT NULL,
			  `billing_postcode` varchar(175) DEFAULT NULL,
			  `shipping_postcode` varchar(175) DEFAULT NULL,
			  PRIMARY KEY (`order_id`),
			  KEY `order_number` (`order_number`),
			  KEY `user_id` (`user_id`),
			  KEY `customer_email` (`customer_email`),
			  KEY `billing_email` (`billing_email`),
			  KEY `customer_name` (`customer_name`),
			  KEY `billing_name` (`billing_name`),
			  KEY `shipping_name` (`shipping_name`),
			  KEY `billing_city` (`billing_city`),
			  KEY `shipping_city` (`shipping_city`),
			  KEY `billing_postcode` (`billing_postcode`),
			  KEY `shipping_postcode` (`shipping_postcode`)
			) $charset_collate;";
			dbDelta( $sql );

			$table_name = $wpdb->prefix . 'woocommerce_customer_subscription_index';

			$sql = "CREATE TABLE $table_name (
				`subscription_id` int(11) unsigned NOT NULL,
				`order_total` DECIMAL(10,4) DEFAULT NULL,
				`start_date` DATETIME DEFAULT NULL,
				`trial_end_date` DATETIME DEFAULT NULL,
				`next_payment_date` DATETIME DEFAULT NULL,
				`end_date` DATETIME DEFAULT NULL,
				`last_payment_date` DATETIME DEFAULT NULL,
				PRIMARY KEY (`subscription_id`),
				KEY `order_total` (`order_total`),
				KEY `start_date` (`start_date`),
				KEY `trial_end_date` (`trial_end_date`),
				KEY `next_payment_date` (`next_payment_date`),
				KEY `end_date` (`end_date`),
				KEY `last_payment_date` (`last_payment_date`)
			) $charset_collate;";
			dbDelta( $sql );
		}

		public function update_index_from_meta( $object_id, $meta_key, $meta_value ) {
			if ( ! in_array( get_post_type( $object_id ), array( 'shop_order', 'shop_subscription' ) ) ) {
				return;
			}
			if ( in_array( $meta_key, array( '_customer_user', '_order_total', '_billing_email', '_billing_first_name', '_billing_last_name', '_shipping_first_name', '_shipping_last_name', '_order_number', '_billing_city', '_shipping_city', '_billing_postcode', '_shipping_postcode' ), true ) ) {
				$this->update_index( $object_id );
			}
			if ( strpos( $meta_key, '_schedule' ) === 0 ) {
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
			$order = wc_get_order( $order_id );

			$this->update_index_order( $order );

			if ( 'shop_subscription' == $order->order_type ) {
				$this->update_index_subscription( $order );
			} elseif ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
				$subs = wcs_get_subscriptions_for_order( $order );
				if ( ! empty( $subs ) ) {
					foreach ( $subs as $sub ) {
						$this->update_index_subscription( $sub );
					}
				}
			}
		}

		public function update_index_subscription( $order ) {
			if ( doing_action( 'woocommerce_checkout_order_processed' ) ) {
				$last_order_created = new WC_DateTime();
			} else {
				$last_order_created = $order->get_date( 'last_order_date_created' );
			}

			global $wpdb;
			$fields = array(
				'order_total'       => $order->get_total( 'edit' ),
				'last_payment_date' => $last_order_created,
				'start_date'        => $order->get_date( 'date_created' ),
				'trial_end_date'    => $order->get_date( 'trial_end' ),
				'next_payment_date' => $order->get_date( 'next_payment' ),
				'end_date'          => $order->get_date( 'end' ),
			);

			$escape = array(
				'subscription_id' => '%d',
				'order_total'     => '%d',
			);

			return $this->insert_helper(
				$wpdb->prefix . $this->table_name_subs,
				$fields,
				array(
					'subscription_id' => $order->get_id(),
				),
				$escape
			);
		}

		public function insert_helper( $table, $fields, $primary, $escape ) {
			global $wpdb;
			$fields_insert        = '`' . implode( '`, `', array_keys( $fields ) ) . '`';
			$fields_insert_values = array();
			$update_statement     = array();

			foreach ( $fields as $key => $value ) {
				$escape_as              = isset( $escape[ $key ] ) ? $escape[ $key ] : '%s';
				$fields_insert_values[] = $wpdb->prepare( $escape_as, $value ); // phpcs:ignore WordPress.DB.PreparedSQL
				$update_statement[]     = $wpdb->prepare( '`' . $key . '` = ' . $escape_as, $value ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			}

			$fields_insert_values = implode( ', ', $fields_insert_values );

			foreach ( $primary as $k => $v ) {
				$fields_insert        = '`' . $k . '`, ' . $fields_insert;
				$escape_as            = isset( $escape[ $k ] ) ? $escape[ $k ] : '%s';
				$fields_insert_values = $wpdb->prepare( $escape_as, $v ) . ', ' . $fields_insert_values; // phpcs:ignore WordPress.DB.PreparedSQL
			}

			$update_statement = implode( ', ', $update_statement );

			$sql = "INSERT INTO {$table} ({$fields_insert})
				VALUES ($fields_insert_values) ON DUPLICATE KEY UPDATE {$update_statement};";

			$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			if ( ! is_wp_error( $result ) ) {
				return (bool) $result;
			} else {
				return false;
			}
		}

		public function update_index_order( $order ) {
			global $wpdb;

			$order_id = $order->get_id();

			$user_id = intval( $order->get_customer_id() );
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

			$order_number = $order->get_order_number();

			$billing_email = $order->get_billing_email();

			$billing_name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			$shipping_name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );

			$billing_city  = trim( $order->get_billing_city() );
			$shipping_city = trim( $order->get_shipping_city() );

			$billing_postcode  = trim( $order->get_billing_postcode() );
			$shipping_postcode = trim( $order->get_shipping_postcode() );

			$fields = array(
				'order_number'      => $order_number,
				'user_id'           => $user_id,
				'customer_email'    => $customer_email,
				'billing_email'     => $billing_email,
				'customer_name'     => $customer_name,
				'billing_name'      => $billing_name,
				'shipping_name'     => $shipping_name,
				'billing_city'      => $billing_city,
				'shipping_city'     => $shipping_city,
				'billing_postcode'  => $billing_postcode,
				'shipping_postcode' => $shipping_postcode,
			);

			$escape = array(
				'user_id'  => '%d',
				'order_id' => '%d',
			);

			return $this->insert_helper(
				$wpdb->prefix . $this->table_name,
				$fields,
				array(
					'order_id' => $order_id,
				),
				$escape
			);
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
				"UPDATE {$wpdb->prefix}{$this->table_name} SET `customer_email` = %s, `customer_name` = %s WHERE `user_id` = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$user->user_email,
				trim( $user->first_name . ' ' . $user->last_name ),
				$user_id
			);

			$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			if ( ! is_wp_error( $result ) ) {
				return (bool) $result;
			} else {
				return false;
			}
		}

		public function maybe_update_customer_name( $object_id, $meta_key, $meta_value ) {
			if ( in_array( get_post_type( $object_id ), array( 'shop_order', 'shop_subscription' ) ) && in_array( $meta_key, array( 'first_name', 'last_name' ) ) ) {
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

			$sql    = $wpdb->prepare( "SELECT `user_id` FROM {$wpdb->prefix}{$this->table_name} WHERE `order_id` = %d", $order_id ); // phpcs:ignore WordPress.DB.PreparedSQL
			$result = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

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
		public function get_customers_orders( $user_id, $type = 'shop_order' ) {
			global $wpdb;

			// Don't pull all guest orders this way, things may break.
			if ( 0 === $user_id ) {
				return array();
			}

			$sql    = $wpdb->prepare( "SELECT `order_id` FROM {$wpdb->prefix}{$this->table_name} wc_coi INNER JOIN {$wpdb->posts} posts ON posts.ID = wc_coi.order_id WHERE `user_id` = %d AND posts.post_type = %s ORDER BY `order_id` DESC", $user_id, $type ); // phpcs:ignore WordPress.DB.PreparedSQL
			$result = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

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

			$sql    = $wpdb->prepare( "SELECT `order_id` FROM {$wpdb->prefix}{$this->table_name} WHERE `user_id` = %d", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL
			$result = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

			if ( ! is_wp_error( $result ) && is_array( $result ) ) {
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
			$qvars[] = 'wc_customer_postcode';
			$qvars[] = 'wc_customer_city';
			$qvars[] = 'wc_full_search';
			$qvars[] = 'wc_order_id';
			$qvars[] = 'wcs_orderby';
			return $qvars;
		}

		/**
		 * Changes the my_orders query in order to use the index
		 *
		 * @param  array $params Array of query parameters
		 * @return array Query parameters
		 */
		public function my_orders_query( $params ) {
			if ( isset( $params['meta_key'] ) && '_customer_user' === $params['meta_key'] ) {
				$params['wc_customer_user'] = $params['meta_value'];
				unset( $params['meta_key'] );
				unset( $params['meta_value'] );
			}
			if ( isset( $params['customer'] ) && ! empty( $params['customer'] ) ) {
				$params['wc_customer_user'] = $params['customer'];
				if ( ! version_compare( WC_VERSION, '2.7', '<' ) ) {
					$data_store = WC_Data_Store::load( 'order' );
					if ( $data_store->get_current_class_name() === 'WC_Order_Data_Store_CPT' ) {
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
					! empty( $wpq->query_vars['wc_customer_postcode'] ) ||
					! empty( $wpq->query_vars['wc_customer_city'] ) ||
					! empty( $wpq->query_vars['wc_full_search'] ) ||
					! empty( $wpq->query_vars['wc_order_id'] )
				) && isset( $wpq->query_vars['suppress_filters'] ) ) {
				$wpq->query_vars['suppress_filters'] = false; // phpcs:ignore WordPressVIPMinimum.Hooks.PreGetPosts
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
				! empty( $wp_query->query_vars['wc_customer_postcode'] ) ||
				! empty( $wp_query->query_vars['wc_customer_city'] ) ||
				! empty( $wp_query->query_vars['wc_full_search'] ) ||
				! empty( $wp_query->query_vars['wc_order_id'] )
				) {
				$join .= " INNER JOIN {$wpdb->prefix}{$this->table_name} wc_cidx ON $wpdb->posts.ID = wc_cidx.order_id ";
			}

			if (
				! empty( $wp_query->query_vars['wcs_orderby'] )
				) {
				$join .= " INNER JOIN {$wpdb->prefix}{$this->table_name_subs} wc_cidx_subs ON $wpdb->posts.ID = wc_cidx_subs.subscription_id ";
			}

			return $join;
		}

		private function convert_to_like( $str ) {
			global $wpdb;
			$wild = '%';
			if ( substr( $str, -1 ) === '*' ) {
				$str = substr( $str, 0, -1 );
			}
			$begin = false;
			if ( substr( $str, 0, 1 ) === '*' ) {
				$begin = true;
				$str   = substr( $str, 1 );
			}
			$like = $wpdb->esc_like( $str ) . $wild;

			if ( $begin ) {
				$like = $wild . $like;
			}

			return $like;
		}

		public function wc_customer_query_orderby( $orderby, $wpq ) {
			if ( ! empty( $wpq->query_vars['wcs_orderby'] ) ) {
				$by      = $wpq->query_vars['wcs_orderby'];
				$order   = isset( $wpq->query_vars['order'] ) ? $wpq->query_vars['order'] : 'DESC';
				$orderby = 'wc_cidx_subs.' . $by . ' ' . $order;
			}
			return $orderby;
		}

		/**
		 * Use the join and add necessary where conditions
		 *
		 * @param  array $params Array of query parameters
		 * @return array Query parameters
		 */
		public function wc_customer_query_where( $where, $wp_query ) {
			global $wpdb;

			$columns = array(
				'user_id'           => true,
				'customer_email'    => true,
				'billing_email'     => true,
				'customer_name'     => true,
				'billing_name'      => true,
				'shipping_name'     => true,
				'billing_postcode'  => true,
				'shipping_postcode' => true,
				'billing_city'      => true,
				'shipping_city'     => true,
				'order_id'          => true,
				'order_number'      => true,
			);

			$parts = array();
			if ( ! empty( $wp_query->query_vars['wc_customer_user'] ) ) {
				unset( $columns['user_id'] );
				$parts[] = $wpdb->prepare( '( wc_cidx.user_id = %d )', $wp_query->query_vars['wc_customer_user'] );
			}
			if ( ! empty( $wp_query->query_vars['wc_customer_email'] ) ) {
				$email = $wp_query->query_vars['wc_customer_email'];
				$email = $this->convert_to_like( $email );
				unset( $columns['customer_email'] );
				unset( $columns['billing_email'] );
				$parts[] = $wpdb->prepare(
					'( wc_cidx.customer_email LIKE %s OR wc_cidx.billing_email LIKE %s )',
					strtolower( $email ),
					strtolower( $email )
				);
			}
			if ( ! empty( $wp_query->query_vars['wc_customer_name'] ) ) {
				$name = $wp_query->query_vars['wc_customer_name'];
				$name = $this->convert_to_like( $name );
				unset( $columns['customer_name'] );
				unset( $columns['billing_name'] );
				unset( $columns['shipping_name'] );
				$parts[] = $wpdb->prepare(
					'( wc_cidx.customer_name LIKE %s OR wc_cidx.billing_name LIKE %s OR wc_cidx.shipping_name LIKE %s )',
					strtolower( $name ),
					strtolower( $name ),
					strtolower( $name )
				);
			}
			if ( ! empty( $wp_query->query_vars['wc_customer_postcode'] ) ) {
				$name = $wp_query->query_vars['wc_customer_postcode'];
				$name = $this->convert_to_like( $name );
				unset( $columns['billing_postcode'] );
				unset( $columns['shipping_postcode'] );
				$parts[] = $wpdb->prepare(
					'( wc_cidx.billing_postcode LIKE %s OR wc_cidx.shipping_postcode LIKE %s )',
					strtolower( $name ),
					strtolower( $name )
				);
			}
			if ( ! empty( $wp_query->query_vars['wc_customer_city'] ) ) {
				$name = $wp_query->query_vars['wc_customer_city'];
				$name = $this->convert_to_like( $name );
				unset( $columns['billing_city'] );
				unset( $columns['shipping_city'] );
				$parts[] = $wpdb->prepare(
					'( wc_cidx.billing_city LIKE %s OR wc_cidx.shipping_city LIKE %s )',
					strtolower( $name ),
					strtolower( $name )
				);
			}
			if ( ! empty( $wp_query->query_vars['wc_order_id'] ) ) {
				unset( $columns['order_id'] );
				unset( $columns['order_number'] );
				$parts[] = $wpdb->prepare( '( wc_cidx.order_id = %d OR wc_cidx.order_number = %d )', $wp_query->query_vars['wc_order_id'], $wp_query->query_vars['wc_order_id'] );
			}

			if ( ! empty( $wp_query->query_vars['wc_full_search'] ) ) {
				$terms = explode( ' ', $wp_query->query_vars['wc_full_search'] );
				$terms = array_filter( $terms );

				$group = array();
				foreach ( $terms as $term ) {
					$this_columns = $columns;
					if ( ! is_numeric( $term ) ) {
						unset( $this_columns['order_id'] );
						unset( $this_columns['user_id'] );
					}

					$term = $this->convert_to_like( $term );

					foreach ( $this_columns as $col => $void ) {
						$group[] = $wpdb->prepare( "wc_cidx.{$col} LIKE %s", $term ); // phpcs:ignore WordPress.DB.PreparedSQL
					}
				}

				if ( ! empty( $group ) ) {
					$parts[] = '( ' . implode( ' OR ', $group ) . ' )';
				}
			}

			if ( ! empty( $parts ) ) {
				$where .= ' AND ( ' . implode( ' AND ', $parts ) . ' ) ';
			}

			return $where;
		}

		public function wc_subscription_sort( $wpq ) {
			global $typenow;

			if ( 'shop_subscription' !== $typenow ) {
				return $wpq;
			}

			global $pagenow;
			if ( 'edit.php' !== $pagenow || empty( $wpq['orderby'] ) || ! in_array( $wpq['post_type'], array( 'shop_subscription' ) ) ) {
				return $wpq;
			}

			switch ( $wpq['orderby'] ) {
				case 'order_total':
				case 'last_payment_date':
				case 'start_date':
				case 'trial_end_date':
				case 'next_payment_date':
				case 'end_date':
					$wpq['wcs_orderby'] = $wpq['orderby'];
					unset( $wpq['orderby'] );

					// so we know we're doing this.
					$wpq['shop_subscription_sort'] = true;
					break;
			}

			return $wpq;
		}

		/**
		 * Enables email only search within the index table
		 *
		 * @param  array $params Array of query parameters
		 * @return array Query parameters
		 */
		public function wc_email_search( $wpq ) {
			global $pagenow;
			if ( 'edit.php' != $pagenow || empty( $wpq->query_vars['s'] ) || ! in_array( $wpq->query_vars['post_type'], array( 'shop_order', 'shop_subscription' ) ) ) {
				return;
			}

			$search = trim( wp_unslash( $wpq->query_vars['s'] ) );

			$terms = array();

			$search = preg_replace_callback(
				'/(\S+)[:=](")?(\')?(.+?)(?(2)(?2)|(?(3)(?3)|(?=\s|$)))/',
				function( $matches ) use ( &$terms ) {
					$terms[ strtolower( $matches[1] ) ] = str_replace( '+', ' ', $matches[4] );
					return '';
				},
				$search
			);

			$search = trim( $search );

			$processing = false;

			if ( is_email( $search ) ) {
				$terms['email'] = $search;
				$search         = '';
			}

			if ( substr( $search, 0, 1 ) === '#' ) {
				$processing                     = true;
				$wpq->query_vars['wc_order_id'] = substr( $search, 1 );
				$search                         = '';
			}

			foreach ( $terms as $prop => $term ) {
				switch ( $prop ) {
					case 'email':
					case 'mail':
						$processing                           = true;
						$wpq->query_vars['wc_customer_email'] = $terms[ $prop ];
						break;

					case 'name':
						$processing                          = true;
						$wpq->query_vars['wc_customer_name'] = trim( $terms[ $prop ] );
						break;

					case 'post':
					case 'postal':
					case 'zip':
					case 'postcode':
					case 'postalcode':
					case 'zipcode':
						$processing                              = true;
						$wpq->query_vars['wc_customer_postcode'] = trim( $terms[ $prop ] );
						break;

					case 'suburb':
					case 'address':
					case 'city':
						$processing                          = true;
						$wpq->query_vars['wc_customer_city'] = trim( $terms[ $prop ] );
						break;
				}
			}

			if ( ! empty( $search ) ) {
				$processing                        = true;
				$wpq->query_vars['wc_full_search'] = $search;
			}

			if ( $processing ) {
				// Remove "s" - we don't want to search order name.
				unset( $wpq->query_vars['s'] );

				// so we know we're doing this.
				$wpq->query_vars['shop_order_search'] = true;
			}

			return $wpq;
		}

		public function get_user_subscriptions( $ids, $user_id ) {
			return $this->get_customers_orders( $user_id, 'shop_subscription' );
		}

	}

	WC_Customer_Order_Index::instance();

endif;

/**
 * Get the WC Customer Order Index
 */
function WC_COI() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName
	if ( class_exists( 'WC_Customer_Order_Index' ) && is_callable( array( 'WC_Customer_Order_Index', 'instance' ) ) ) {
		return WC_Customer_Order_Index::instance();
	} else {
		return false;
	}
}
