<?php // phpcs:ignore WordPress.Files.FileName
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

if ( ! class_exists( 'WC_COI_CLI_Command_Base' ) ) {
	if ( class_exists( 'WPCOM_VIP_CLI_Command' ) ) {
		class WC_COI_CLI_Command_Base extends WPCOM_VIP_CLI_Command {

		}
	} else {
		// phpcs:ignore Generic.Classes.DuplicateClassName,Generic.Files.OneObjectStructurePerFile,WordPressVIPMinimum.Classes.RestrictedExtendClasses
		class WC_COI_CLI_Command_Base extends WP_CLI_Command {
			protected function stop_the_insanity() {
				/**
				 * @var \WP_Object_Cache $wp_object_cache
				 * @var \wpdb $wpdb
				 */
				global $wpdb, $wp_object_cache;

				$wpdb->queries = array(); // or define( 'WP_IMPORTING', true );

				if ( is_object( $wp_object_cache ) ) {
					$wp_object_cache->group_ops      = array();
					$wp_object_cache->stats          = array();
					$wp_object_cache->memcache_debug = array();
					$wp_object_cache->cache          = array();

					if ( method_exists( $wp_object_cache, '__remoteset' ) ) {
						$wp_object_cache->__remoteset(); // important
					}
				}
			}
			protected function start_bulk_operation() {

			}
			protected function end_bulk_operation() {

			}
		}
	}
}

// Check if WP_CLI exists, and only extend it if it does
if ( ! class_exists( 'WC_Customer_Order_Index_CLI' ) ) {

	/**
	 * Class WC_Customer_Order_Index_CLI
	 */
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile
	class WC_Customer_Order_Index_CLI extends WC_COI_CLI_Command_Base {

		private $cli_kill_switch   = 'wc_coi_kill_cli';
		private $cli_status_option = 'wc_coi_status';

		public function reset_index( $args, $assoc_args ) {
			global $wpdb;

			update_option( $this->cli_kill_switch, 0 );

			$count_sql   = $wpdb->prepare( "select count(1) from {$wpdb->posts} where post_type = %s order by post_date desc", 'shop_order' );
			$order_count = $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

			$orders_sql   = $wpdb->prepare( "select ID from {$wpdb->posts} where post_type = %s order by post_date desc", 'shop_order' );
			$orders_page  = 1;
			$orders_batch = isset( $assoc_args['batch'] ) ? absint( $assoc_args['batch'] ) : 10000;
			$total_pages  = $order_count / $orders_batch;

			$progress = \WP_CLI\Utils\make_progress_bar( 'Updating Index', $order_count );

			$batches_processed = 0;
			for ( $page = 0; $page < $total_pages; $page++ ) {
				$this->update_option_status( $batches_processed, $total_pages );

				$offset = $page * $orders_batch;
				$sql    = $wpdb->prepare( $orders_sql . ' LIMIT %d OFFSET %d', $orders_batch, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL
				$orders = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

				foreach ( $orders as $order ) {
					if ( $this->should_kill_cli() ) {
						WP_CLI::error( __( 'Index reset aborted by kill switch.', 'wc-customer-order-index' ) );
						break;
					}

					WC_COI()->update_index( $order );
					$progress->tick();
					$this->stop_the_insanity();
				}

				$batches_processed++;

				// Update the option status using hard values and not $progress because WPE WP-CLI doesn't like $progress
				$this->update_option_status( $batches_processed, $total_pages );
			}

			$progress->finish();
		}

		private function should_kill_cli() {
			global $wpdb;

			$sql         = $wpdb->prepare( "select option_value from {$wpdb->options} where option_name = %s;", $this->cli_kill_switch );
			$should_kill = absint( $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

			return $should_kill > 0 ? true : false;
		}

		private function update_option_status( $current, $total ) {
			// translators: 1$: date. 2$: current. 3$ total.
			update_option( $this->cli_status_option, sprintf( __( '%1$s: %2$d of %3$d order batches updated', 'wc-customer-order-index' ), date( 'Y-m-d H:i:s' ), $current, $total ) );
		}

	}

	WP_CLI::add_command( 'wc_coi', 'WC_Customer_Order_Index_CLI' );
}
