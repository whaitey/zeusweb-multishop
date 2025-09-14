<?php

namespace ZeusWeb\Multishop\Admin;

use ZeusWeb\Multishop\DB\Tables;
use ZeusWeb\Multishop\Keys\Repository as KeysRepository;
use ZeusWeb\Multishop\Utils\Crypto;
use ZeusWeb\Multishop\Fulfillment\Service as FulfillmentService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDKeys {
	public static function render_page(): void {
		if ( isset( $_POST['zw_ms_keys_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zw_ms_keys_nonce'] ) ), 'zw_ms_keys_save' ) && current_user_can( 'manage_woocommerce' ) ) {
			self::handle_submit();
		}
		$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CD Keys Manager', 'zeusweb-multishop' ); ?></h1>
			<?php if ( $product_id ) { self::render_manage_product( $product_id ); } else { self::render_product_list(); } ?>
		</div>
		<?php
	}

	private static function render_manage_product( int $product_id ): void {
		// Do not allow managing keys on bundle containers
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( $product && method_exists( $product, 'is_type' ) && $product->is_type( 'bundle' ) ) {
			?>
			<p><?php esc_html_e( 'This is a product bundle. Bundles inherit keys from their child products. Manage keys on the individual child products instead.', 'zeusweb-multishop' ); ?></p>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=zw-ms-keys' ) ); ?>" class="button">&larr; <?php esc_html_e( 'Back to products', 'zeusweb-multishop' ); ?></a></p>
			<?php
			return;
		}
		self::render_stats( $product_id );
		self::render_available_keys( $product_id );
		?>
		<hr />
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'zw_ms_keys_save', 'zw_ms_keys_nonce' ); ?>
			<input type="hidden" name="product_id" value="<?php echo esc_attr( $product_id ); ?>" />
			<h2><?php esc_html_e( 'Add keys', 'zeusweb-multishop' ); ?></h2>
			<p><label><?php esc_html_e( 'CSV upload (one key per line)', 'zeusweb-multishop' ); ?> <input type="file" name="keys_csv" accept=".csv,text/csv,text/plain" /></label></p>
			<p><label><?php esc_html_e( 'Or paste keys (one per line)', 'zeusweb-multishop' ); ?><br />
				<textarea name="keys_text" class="large-text" rows="8"></textarea>
			</label></p>
			<?php submit_button( __( 'Import Keys', 'zeusweb-multishop' ) ); ?>
		</form>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=zw-ms-keys' ) ); ?>" class="button">&larr; <?php esc_html_e( 'Back to products', 'zeusweb-multishop' ); ?></a></p>
		<?php
	}

	private static function render_product_list(): void {
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = 20;
		$args = [
			'post_type'      => 'product',
			's'              => $search,
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'post_status'    => [ 'publish' ],
			'tax_query'      => [
				[
					'taxonomy' => 'product_type',
					'field'    => 'slug',
					'terms'    => [ 'bundle' ],
					'operator' => 'NOT IN',
				],
			],
		];
		$q = new \WP_Query( $args );
		?>
		<form method="get" style="margin-bottom:12px;">
			<input type="hidden" name="page" value="zw-ms-keys" />
			<p>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search products…', 'zeusweb-multishop' ); ?>" />
				<?php submit_button( __( 'Search' ), 'secondary', '', false ); ?>
			</p>
		</form>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'ID', 'zeusweb-multishop' ); ?></th>
					<th><?php esc_html_e( 'Product', 'zeusweb-multishop' ); ?></th>
					<th><?php esc_html_e( 'Available keys', 'zeusweb-multishop' ); ?></th>
					<th><?php esc_html_e( 'Assigned keys', 'zeusweb-multishop' ); ?></th>
					<th><?php esc_html_e( 'Pending backorders', 'zeusweb-multishop' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'zeusweb-multishop' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( $q->have_posts() ) : while ( $q->have_posts() ) : $q->the_post(); $pid = get_the_ID(); ?>
					<tr>
						<td><?php echo esc_html( (string) $pid ); ?></td>
						<td><a href="<?php echo esc_url( get_edit_post_link( $pid ) ); ?>" target="_blank"><?php echo esc_html( get_the_title() ); ?></a></td>
						<td><?php echo esc_html( (string) self::count_keys( $pid, 'available' ) ); ?></td>
						<td><?php echo esc_html( (string) self::count_keys( $pid, 'assigned' ) ); ?></td>
						<td><?php echo esc_html( (string) self::count_backorders( $pid ) ); ?></td>
						<td><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=zw-ms-keys&product_id=' . $pid ) ); ?>"><?php esc_html_e( 'Manage keys', 'zeusweb-multishop' ); ?></a></td>
					</tr>
				<?php endwhile; else: ?>
					<tr><td colspan="6"><?php esc_html_e( 'No products found.', 'zeusweb-multishop' ); ?></td></tr>
				<?php endif; wp_reset_postdata(); ?>
			</tbody>
		</table>
		<?php if ( $q->max_num_pages > 1 ) : $base_url = remove_query_arg( 'paged' ); ?>
			<p style="margin-top:10px;">
				<?php for ( $p = 1; $p <= $q->max_num_pages; $p++ ) : ?>
					<a class="button<?php echo $p === $paged ? ' button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'paged', (string) $p, $base_url ) ); ?>"><?php echo esc_html( (string) $p ); ?></a>
				<?php endfor; ?>
			</p>
		<?php endif; ?>
		<?php
	}

	private static function render_stats( int $product_id ): void {
		global $wpdb;
		$table = Tables::keys();
		$available = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND status = 'available'", $product_id ) );
		$assigned  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND status = 'assigned'", $product_id ) );
		$pending_bo = self::count_backorders( $product_id );
		$product_title = get_the_title( $product_id );
		?>
		<h2><?php echo esc_html( sprintf( __( 'Product: %s', 'zeusweb-multishop' ), $product_title ?: (string) $product_id ) ); ?></h2>
		<p><?php esc_html_e( 'Available keys:', 'zeusweb-multishop' ); ?> <strong><?php echo esc_html( (string) $available ); ?></strong></p>
		<p><?php esc_html_e( 'Assigned keys:', 'zeusweb-multishop' ); ?> <strong><?php echo esc_html( (string) $assigned ); ?></strong></p>
		<p><?php esc_html_e( 'Pending backorders:', 'zeusweb-multishop' ); ?> <strong><?php echo esc_html( (string) $pending_bo ); ?></strong></p>
		<?php
	}

	private static function count_keys( int $product_id, string $status ): int {
		global $wpdb;
		$table = Tables::keys();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND status = %s", $product_id, $status ) );
	}

	private static function render_available_keys( int $product_id ): void {
		list( $rows, $keys_plain ) = self::get_available_key_rows( $product_id, 500 );
		?>
		<h3><?php esc_html_e( 'Available keys (first 500)', 'zeusweb-multishop' ); ?></h3>
		<?php if ( empty( $rows ) ) : ?>
			<p><?php esc_html_e( 'No available keys for this product.', 'zeusweb-multishop' ); ?></p>
		<?php else : ?>
			<p><em><?php esc_html_e( 'These are decrypted for admin visibility only. You can edit or delete individual keys below.', 'zeusweb-multishop' ); ?></em></p>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'ID', 'zeusweb-multishop' ); ?></th><th><?php esc_html_e( 'Key', 'zeusweb-multishop' ); ?></th><th><?php esc_html_e( 'Actions', 'zeusweb-multishop' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $rows as $idx => $row ) : $id = (int) $row['id']; $plain = $keys_plain[$idx] ?? ''; ?>
					<tr>
						<td><?php echo esc_html( (string) $id ); ?></td>
						<td>
							<form method="post" style="display:flex;gap:6px;align-items:center;">
								<?php wp_nonce_field( 'zw_ms_keys_save', 'zw_ms_keys_nonce' ); ?>
								<input type="hidden" name="product_id" value="<?php echo esc_attr( $product_id ); ?>" />
								<input type="hidden" name="key_id" value="<?php echo esc_attr( $id ); ?>" />
								<input type="text" name="key_value" value="<?php echo esc_attr( $plain ); ?>" class="regular-text" style="width:100%;" />
								<button class="button button-primary" name="zw_ms_action" value="update_key"><?php esc_html_e( 'Save', 'zeusweb-multishop' ); ?></button>
								<button class="button" name="zw_ms_action" value="delete_key" onclick="return confirm('Delete this key?');"><?php esc_html_e( 'Delete', 'zeusweb-multishop' ); ?></button>
							</form>
						</td>
						<td></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private static function get_available_key_rows( int $product_id, int $limit = 500 ): array {
		global $wpdb;
		$table = Tables::keys();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, key_enc FROM {$table} WHERE product_id = %d AND status = 'available' ORDER BY id ASC LIMIT %d", $product_id, $limit ), ARRAY_A );
		$keys  = [];
		if ( empty( $rows ) ) {
			return [ [], [] ];
		}
		foreach ( $rows as $r ) {
			try {
				$keys[] = Crypto::decrypt( (string) $r['key_enc'] );
			} catch ( \Throwable $e ) {
				// Skip invalid/decrypt-failed entries
			}
		}
		return [ $rows, $keys ];
	}

	private static function handle_submit(): void {
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( ! $product_id ) {
			return;
		}
		$action = isset( $_POST['zw_ms_action'] ) ? sanitize_text_field( wp_unslash( $_POST['zw_ms_action'] ) ) : '';
		if ( $action === 'update_key' && isset( $_POST['key_id'], $_POST['key_value'] ) ) {
			$key_id = absint( $_POST['key_id'] );
			$new_plain = (string) wp_unslash( $_POST['key_value'] );
			if ( $key_id && $new_plain !== '' ) {
				$enc = Crypto::encrypt( $new_plain );
				\ZeusWeb\Multishop\Keys\Repository::update_available_key( $key_id, $enc );
				add_settings_error( 'zw_ms_keys', 'key_updated', __( 'Key updated.', 'zeusweb-multishop' ), 'updated' );
			}
			return;
		}
		if ( $action === 'delete_key' && isset( $_POST['key_id'] ) ) {
			$key_id = absint( $_POST['key_id'] );
			if ( $key_id ) {
				\ZeusWeb\Multishop\Keys\Repository::delete_available_key( $key_id );
				add_settings_error( 'zw_ms_keys', 'key_deleted', __( 'Key deleted.', 'zeusweb-multishop' ), 'updated' );
			}
			return;
		}
		$keys = [];
		if ( ! empty( $_FILES['keys_csv']['tmp_name'] ) && is_uploaded_file( $_FILES['keys_csv']['tmp_name'] ) ) {
			$raw = file_get_contents( $_FILES['keys_csv']['tmp_name'] );
			$rows = preg_split( "/\r\n|\r|\n/", (string) $raw );
			foreach ( $rows as $row ) {
				$row = trim( $row );
				if ( $row !== '' ) { $keys[] = $row; }
			}
		}
		if ( isset( $_POST['keys_text'] ) ) {
			$text = (string) wp_unslash( $_POST['keys_text'] );
			$rows = preg_split( "/\r\n|\r|\n/", $text );
			foreach ( $rows as $row ) {
				$row = trim( $row );
				if ( $row !== '' ) { $keys[] = $row; }
			}
		}
		$keys = array_values( array_unique( $keys ) );
		if ( empty( $keys ) ) {
			add_settings_error( 'zw_ms_keys', 'no_keys', __( 'No keys provided.', 'zeusweb-multishop' ), 'error' );
			return;
		}
		$inserted = KeysRepository::insert_keys( $product_id, null, $keys, function( $plain ) { return Crypto::encrypt( $plain ); } );
		add_settings_error( 'zw_ms_keys', 'keys_imported', sprintf( __( 'Imported %d keys.', 'zeusweb-multishop' ), $inserted ), 'updated' );
		// Trigger fulfillment for this product.
		FulfillmentService::fulfill_backorders_for_product( $product_id );
	}

	private static function count_backorders( int $product_id ): int {
		global $wpdb;
		$table = Tables::backorders();
		$val = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(qty_pending),0) FROM {$table} WHERE product_id = %d AND (fulfilled_at IS NULL OR fulfilled_at = '')", $product_id ) );
		return (int) $val;
	}
}


