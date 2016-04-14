<?php
// Exit if accessed directly
if( !defined( 'ABSPATH' ) ) {
    exit;
}

// Check if WP_CLI exists, and only extend it if it does
if( defined( 'WP_CLI' ) && WP_CLI && !class_exists( 'WC_Customer_Order_Index_CLI' ) ) {

    /**
     * Class WC_Customer_Order_Index_CLI
     */
    class WC_Customer_Order_Index_CLI extends WP_CLI_Command {

        private $cli_kill_switch = 'wc_coi_kill_cli';
        private $cli_status_option = 'wc_coi_status';

        public function reset_index( $args, $assoc_args ) {
            global $wpdb;

            update_option( $this->cli_kill_switch, 0 );

            $count_sql    = "SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type IN ('" . implode( "','", wc_get_order_types( 'reports' ) ) . "') ORDER BY post_date DESC";
            $order_count  = $wpdb->get_var( $count_sql );

            $orders_sql   = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('" . implode( "','", wc_get_order_types( 'reports' ) ) . "') ORDER BY post_date DESC";

            $orders_page  = 1;
            $orders_batch = isset( $assoc_args['batch'] ) ? absint( $assoc_args['batch'] ) : 10000;
            $total_pages  = $order_count / $orders_batch;

            $progress = \WP_CLI\Utils\make_progress_bar( 'Updating Index', $order_count );

            $batches_processed = 0;
            for( $page = 0; $page < $total_pages; $page++ ) {
                $this->update_option_status( $batches_processed, $total_pages );

                $offset = $page * $orders_batch;
                $sql = $wpdb->prepare( $orders_sql . ' LIMIT %d OFFSET %d', $orders_batch, $offset );
                $orders = $wpdb->get_col( $sql );

                foreach ( $orders as $order ) {
                    if( $this->should_kill_cli() ) {
                        WP_CLI::error( __( 'Index reset aborted by kill switch.', 'wc-customer-order-index' ) );
                        break;
                    }

                    $user_id = get_post_meta( $order, '_customer_user', true );
                    WC_COI()->update_index( $order, $user_id );
                    $progress->tick();
                }

                $batches_processed++;

                // Update the option status using hard values and not $progress because WPE WP-CLI doesn't like $progress
                $this->update_option_status( $batches_processed, $total_pages );
            }

            $progress->finish();
        }

        private function should_kill_cli() {
            global $wpdb;

            $sql         = $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s;", $this->cli_kill_switch );
            $should_kill = absint( $wpdb->get_var( $sql ) );

            return $should_kill > 0 ? true : false;
        }

        private function update_option_status( $current, $total ) {
            update_option( $this->cli_status_option, sprintf( __( '%s: %d of %d order batches updated', 'wc-customer-order-index' ), date( 'Y-m-d H:i:s' ), $current, $total ) );
        }

    }

    WP_CLI::add_command( 'wc_coi', 'WC_Customer_Order_Index_CLI' );
}
