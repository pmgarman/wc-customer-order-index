<?php // phpcs:ignore WordPress.Files.FileName

class WC_Customer_Data_Store_Performant extends WC_Customer_Data_Store {
	public function get_order_count( &$customer ) {
		return count( WC_COI()->get_customers_orders( $customer->get_id() ) );
	}

	public function get_last_order( &$customer ) {
		global $wpdb;
		$table_name = WC_COI()->table_name;

		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$last_order = $wpdb->get_var(
			"SELECT posts.ID
			FROM $wpdb->posts AS posts
			INNER JOIN {$wpdb->prefix}{$table_name} wccoi ON posts.ID = wccoi.order_id AND wccoi.user_id = '" . esc_sql( $customer->get_id() ) . "'
			AND   posts.post_type = 'shop_order'
			AND   posts.post_status IN ( '" . implode( "','", array_map( 'esc_sql', array_keys( wc_get_order_statuses() ) ) ) . "' )
			ORDER BY posts.ID DESC"
		);
		// phpcs:enable

		if ( ! $last_order ) {
			return false;
		}

		return wc_get_order( absint( $last_order ) );
	}
}
