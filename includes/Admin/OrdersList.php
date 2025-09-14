<?php

namespace ZeusWeb\Multishop\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OrdersList {
	public static function render_page(): void {
		if ( get_option( 'zw_ms_mode', 'primary' ) !== 'primary' ) {
			?>
			<div class="wrap"><h1><?php esc_html_e( 'Orders (Multishop)', 'zeusweb-multishop' ); ?></h1>
			<p><?php esc_html_e( 'This page is available only on the Primary site.', 'zeusweb-multishop' ); ?></p></div>
			<?php
			return;
		}

		$status   = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$segment  = isset( $_GET['segment'] ) ? sanitize_text_field( wp_unslash( $_GET['segment'] ) ) : '';
		$origin   = isset( $_GET['origin'] ) ? sanitize_text_field( wp_unslash( $_GET['origin'] ) ) : '';
		$page     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per_page = 20;

		$args = [
			'type'      => 'shop_order',
			'limit'     => $per_page,
			'page'      => $page,
			'paginate'  => true,
			'orderby'   => 'date',
			'order'     => 'DESC',
		];

		if ( $status !== '' ) {
			$args['status'] = [ $status ];
		}

		$meta_query = [];
		if ( $origin !== '' ) {
			if ( $origin === 'local' ) {
				$meta_query[] = [ 'key' => '_zw_ms_remote_site_id', 'compare' => 'NOT EXISTS' ];
			} else {
				$meta_query[] = [ 'key' => '_zw_ms_remote_site_id', 'value' => $origin, 'compare' => '=' ];
			}
		}
		if ( $segment !== '' ) {
			$meta_query[] = [ 'key' => '_zw_ms_remote_segment', 'value' => $segment, 'compare' => '=' ];
		}
		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query;
		}

		$result = function_exists( 'wc_get_orders' ) ? wc_get_orders( $args ) : [ 'orders' => [], 'total' => 0, 'pages' => 0 ];
		$orders = is_array( $result ) && isset( $result['orders'] ) ? $result['orders'] : [];
		$total  = is_array( $result ) && isset( $result['total'] ) ? (int) $result['total'] : 0;
		$pages  = is_array( $result ) && isset( $result['pages'] ) ? (int) $result['pages'] : 0;

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Orders (Multishop)', 'zeusweb-multishop' ); ?></h1>
			<form method="get" style="margin:12px 0;">
				<input type="hidden" name="page" value="zw-ms-orders" />
				<select name="status">
					<option value=""><?php esc_html_e( 'Any status', 'zeusweb-multishop' ); ?></option>
					<?php foreach ( [ 'pending', 'processing', 'completed', 'on-hold', 'failed', 'cancelled', 'refunded' ] as $st ) : ?>
						<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $status, $st ); ?>><?php echo esc_html( ucfirst( $st ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="segment">
					<option value=""><?php esc_html_e( 'Any segment', 'zeusweb-multishop' ); ?></option>
					<option value="consumer" <?php selected( $segment, 'consumer' ); ?>><?php esc_html_e( 'Consumer', 'zeusweb-multishop' ); ?></option>
					<option value="business" <?php selected( $segment, 'business' ); ?>><?php esc_html_e( 'Business', 'zeusweb-multishop' ); ?></option>
				</select>
				<input type="text" name="origin" value="<?php echo esc_attr( $origin ); ?>" placeholder="<?php esc_attr_e( 'Origin site ID or local', 'zeusweb-multishop' ); ?>" />
				<button class="button"><?php esc_html_e( 'Filter', 'zeusweb-multishop' ); ?></button>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Order', 'zeusweb-multishop' ); ?></th>
						<th><?php esc_html_e( 'Date', 'zeusweb-multishop' ); ?></th>
						<th><?php esc_html_e( 'Status', 'zeusweb-multishop' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'zeusweb-multishop' ); ?></th>
						<th><?php esc_html_e( 'Segment', 'zeusweb-multishop' ); ?></th>
						<th><?php esc_html_e( 'Origin Site', 'zeusweb-multishop' ); ?></th>
						<th><?php esc_html_e( 'Total', 'zeusweb-multishop' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $orders ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No orders found.', 'zeusweb-multishop' ); ?></td></tr>
					<?php else : foreach ( $orders as $order ) : /** @var \WC_Order $order */ ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ) ); ?>">
									#<?php echo esc_html( $order->get_order_number() ); ?>
								</a>
							</td>
							<td><?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '' ); ?></td>
							<td><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></td>
							<td><?php echo esc_html( $order->get_billing_email() ?: $order->get_formatted_billing_full_name() ); ?></td>
							<td><?php echo esc_html( (string) $order->get_meta( '_zw_ms_remote_segment' ) ); ?></td>
							<td><?php echo esc_html( (string) $order->get_meta( '_zw_ms_remote_site_id' ) ?: 'local' ); ?></td>
							<td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
						</tr>
					<?php endforeach; endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<p style="margin-top:12px;">
					<?php for ( $i = 1; $i <= $pages; $i++ ) : $url = add_query_arg( [ 'page' => 'zw-ms-orders', 'status' => $status, 'segment' => $segment, 'origin' => $origin, 'paged' => $i ], admin_url( 'admin.php' ) ); ?>
						<a class="button<?php echo $i === $page ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( (string) $i ); ?></a>
					<?php endfor; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}


