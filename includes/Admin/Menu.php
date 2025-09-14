<?php

namespace ZeusWeb\Multishop\Admin;

use ZeusWeb\Multishop\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Menu {
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_bar_menu', [ __CLASS__, 'add_adminbar_segment_switch' ], 100 );
	}

	public static function register_menu(): void {
		add_menu_page(
			__( 'ZeusWeb Multishop', 'zeusweb-multishop' ),
			__( 'Multishop', 'zeusweb-multishop' ),
			'manage_woocommerce',
			'zw-ms',
			[ __CLASS__, 'render_settings_page' ],
			'dashicons-store'
		);

		add_submenu_page( 'zw-ms', __( 'Settings', 'zeusweb-multishop' ), __( 'Settings', 'zeusweb-multishop' ), 'manage_woocommerce', 'zw-ms', [ __CLASS__, 'render_settings_page' ] );
		add_submenu_page( 'zw-ms', __( 'Sites', 'zeusweb-multishop' ), __( 'Sites', 'zeusweb-multishop' ), 'manage_woocommerce', 'zw-ms-sites', [ __CLASS__, 'render_sites_page' ] );
		add_submenu_page( 'zw-ms', __( 'Logs', 'zeusweb-multishop' ), __( 'Logs', 'zeusweb-multishop' ), 'manage_woocommerce', 'zw-ms-logs', [ __CLASS__, 'render_logs_page' ] );
	}

	public static function add_adminbar_segment_switch( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$wp_admin_bar->add_menu( [
			'id'    => 'zw-ms-segment',
			'title' => 'Multishop Segment',
			'href'  => '#',
		] );
		$base = remove_query_arg( [ 'zw_ms_set_segment' ] );
		$wp_admin_bar->add_node( [
			'parent' => 'zw-ms-segment',
			'id'     => 'zw-ms-segment-consumer',
			'title'  => 'Consumer',
			'href'   => add_query_arg( 'zw_ms_set_segment', 'consumer', $base ),
		] );
		$wp_admin_bar->add_node( [
			'parent' => 'zw-ms-segment',
			'id'     => 'zw-ms-segment-business',
			'title'  => 'Business',
			'href'   => add_query_arg( 'zw_ms_set_segment', 'business', $base ),
		] );
	}

	public static function register_settings(): void {
		// General settings
		register_setting( 'zw_ms', 'zw_ms_mode' );
		register_setting( 'zw_ms', 'zw_ms_primary_url' );
		register_setting( 'zw_ms', 'zw_ms_site_id' );
		register_setting( 'zw_ms', 'zw_ms_secret' );
		register_setting( 'zw_ms', 'zw_ms_shortage_message' );
        register_setting( 'zw_ms', 'zw_ms_enable_custom_email_only' );
        register_setting( 'zw_ms', 'zw_ms_custom_email_subject' );

		// Elementor template IDs
		register_setting( 'zw_ms', 'zw_ms_tpl_header_consumer' );
		register_setting( 'zw_ms', 'zw_ms_tpl_header_business' );
		register_setting( 'zw_ms', 'zw_ms_tpl_footer_consumer' );
		register_setting( 'zw_ms', 'zw_ms_tpl_footer_business' );
		// Single product template override removed; Elementor Theme Builder handles single product.

		// Handle sync action
		add_action( 'admin_init', function() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
			if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) { return; }
			if ( ! isset( $_POST['zw_ms_action'] ) || $_POST['zw_ms_action'] !== 'sync_catalog' ) { return; }
			if ( ! isset( $_POST['zw_ms_sync_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zw_ms_sync_nonce'] ) ), 'zw_ms_sync_catalog' ) ) { return; }
			if ( get_option( 'zw_ms_mode', 'primary' ) !== 'secondary' ) { return; }
			self::handle_sync_catalog();
		} );
	}

	private static function handle_sync_catalog(): void {
		$primary = (string) get_option( 'zw_ms_primary_url', '' );
		$secret  = (string) get_option( 'zw_ms_secret', '' );
		if ( ! $primary || ! $secret ) {
			add_settings_error( 'zw_ms', 'sync_missing', __( 'Primary URL or secret missing.', 'zeusweb-multishop' ), 'error' );
			return;
		}
		$path = '/wp-json/zw-ms/v1/catalog';
		$method = 'GET';
		$timestamp = (string) time();
		$nonce = wp_generate_uuid4();
		$body = '';
		$signature = \ZeusWeb\Multishop\Rest\HMAC::sign( $method, $path, $timestamp, $nonce, $body, $secret );
		$url = rtrim( $primary, '/' ) . $path;
		$args = [
			'headers' => [
				'X-ZW-Timestamp' => $timestamp,
				'X-ZW-Nonce' => $nonce,
				'X-ZW-Signature' => $signature,
				'Accept' => 'application/json',
			],
			'timeout' => 30,
		];
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			add_settings_error( 'zw_ms', 'sync_error', $response->get_error_message(), 'error' );
			return;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! is_array( $data['items'] ?? null ) ) {
			add_settings_error( 'zw_ms', 'sync_invalid', __( 'Invalid catalog response.', 'zeusweb-multishop' ), 'error' );
			return;
		}
		$created = 0; $updated = 0; $skipped = 0;
		foreach ( $data['items'] as $row ) {
			$sku = trim( (string) ( $row['sku'] ?? '' ) );
			if ( $sku === '' ) { $skipped++; continue; }
			$title = (string) ( $row['title'] ?? $sku );
			$price = isset( $row['price'] ) ? (float) $row['price'] : 0;
			$business_price = isset( $row['business_price'] ) ? (float) $row['business_price'] : null;
			$custom_email = (string) ( $row['custom_email'] ?? '' );

			// Find by SKU
			$product_id = wc_get_product_id_by_sku( $sku );
			if ( $product_id ) {
				// Update existing
				wp_update_post( [ 'ID' => $product_id, 'post_title' => $title ] );
				$product = wc_get_product( $product_id );
				if ( $product ) {
					$product->set_regular_price( (string) $price );
					$product->save();
					if ( $business_price !== null ) {
						update_post_meta( $product_id, \ZeusWeb\Multishop\Products\Meta::META_BUSINESS_PRICE, (string) $business_price );
					} else {
						delete_post_meta( $product_id, \ZeusWeb\Multishop\Products\Meta::META_BUSINESS_PRICE );
					}
					update_post_meta( $product_id, \ZeusWeb\Multishop\Products\Meta::META_CUSTOM_EMAIL, $custom_email );
				}
				$updated++;
			} else {
				// Create new simple product
				$new_id = wp_insert_post( [
					'post_type' => 'product',
					'post_status' => 'publish',
					'post_title' => $title,
				] );
				if ( $new_id && ! is_wp_error( $new_id ) ) {
					update_post_meta( $new_id, '_sku', $sku );
					$product = wc_get_product( $new_id );
					if ( $product ) {
						$product->set_regular_price( (string) $price );
						$product->save();
					}
					if ( $business_price !== null ) {
						update_post_meta( $new_id, \ZeusWeb\Multishop\Products\Meta::META_BUSINESS_PRICE, (string) $business_price );
					}
					if ( $custom_email !== '' ) {
						update_post_meta( $new_id, \ZeusWeb\Multishop\Products\Meta::META_CUSTOM_EMAIL, $custom_email );
					}
					$created++;
				} else {
					$skipped++;
				}
			}
		}
		add_settings_error( 'zw_ms', 'sync_done', sprintf( __( 'Catalog sync complete. Created: %d, Updated: %d, Skipped: %d', 'zeusweb-multishop' ), $created, $updated, $skipped ), 'updated' );
	}

	public static function render_settings_page(): void {
		$plugin = Plugin::instance();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'ZeusWeb Multishop Settings', 'zeusweb-multishop' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'zw_ms' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Mode', 'zeusweb-multishop' ); ?></th>
							<td>
								<select name="zw_ms_mode">
									<?php $mode = get_option( 'zw_ms_mode', 'primary' ); ?>
									<option value="primary" <?php selected( $mode, 'primary' ); ?>><?php esc_html_e( 'Primary', 'zeusweb-multishop' ); ?></option>
									<option value="secondary" <?php selected( $mode, 'secondary' ); ?>><?php esc_html_e( 'Secondary', 'zeusweb-multishop' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'This Site ID', 'zeusweb-multishop' ); ?></th>
							<td><code><?php echo esc_html( $plugin->get_site_id() ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Primary URL (for Secondary mode)', 'zeusweb-multishop' ); ?></th>
							<td><input type="url" class="regular-text" name="zw_ms_primary_url" value="<?php echo esc_attr( get_option( 'zw_ms_primary_url', '' ) ); ?>" placeholder="https://primary.example.com" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Shortage message (appended on partial key delivery)', 'zeusweb-multishop' ); ?></th>
							<td>
								<textarea name="zw_ms_shortage_message" class="large-text" rows="4"><?php echo esc_textarea( get_option( 'zw_ms_shortage_message', __( 'Some keys are delayed and will arrive within 24 hours.', 'zeusweb-multishop' ) ) ); ?></textarea>
							</td>
						</tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Custom email only (disable Woo customer emails)', 'zeusweb-multishop' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="zw_ms_enable_custom_email_only" value="yes" <?php checked( get_option( 'zw_ms_enable_custom_email_only', 'no' ), 'yes' ); ?> /> <?php esc_html_e( 'Send only the custom keys email to customers', 'zeusweb-multishop' ); ?></label>
                                <p>
                                    <label><?php esc_html_e( 'Custom email subject', 'zeusweb-multishop' ); ?>
                                        <input type="text" class="regular-text" name="zw_ms_custom_email_subject" value="<?php echo esc_attr( get_option( 'zw_ms_custom_email_subject', 'Your {site_name} order keys (#{order_number})' ) ); ?>" />
                                    </label>
                                </p>
                                <p class="description"><?php esc_html_e( 'Body is built from per-product custom emails with placeholders.', 'zeusweb-multishop' ); ?></p>
                            </td>
                        </tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Elementor Templates (IDs)', 'zeusweb-multishop' ); ?></th>
							<td>
								<p>
									<label>Header (Consumer) <input name="zw_ms_tpl_header_consumer" type="number" value="<?php echo esc_attr( get_option( 'zw_ms_tpl_header_consumer', '' ) ); ?>" /></label>
								</p>
								<p>
									<label>Header (Business) <input name="zw_ms_tpl_header_business" type="number" value="<?php echo esc_attr( get_option( 'zw_ms_tpl_header_business', '' ) ); ?>" /></label>
								</p>
								<p>
									<label>Footer (Consumer) <input name="zw_ms_tpl_footer_consumer" type="number" value="<?php echo esc_attr( get_option( 'zw_ms_tpl_footer_consumer', '' ) ); ?>" /></label>
								</p>
								<p>
									<label>Footer (Business) <input name="zw_ms_tpl_footer_business" type="number" value="<?php echo esc_attr( get_option( 'zw_ms_tpl_footer_business', '' ) ); ?>" /></label>
								</p>
								<!-- Single product override removed; managed by Elementor Theme Builder conditions. -->
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php if ( get_option( 'zw_ms_mode', 'primary' ) === 'secondary' ) : ?>
				<hr />
				<form method="post">
					<?php wp_nonce_field( 'zw_ms_sync_catalog', 'zw_ms_sync_nonce' ); ?>
					<p><button class="button button-primary" name="zw_ms_action" value="sync_catalog"><?php esc_html_e( 'Sync Catalog from Primary', 'zeusweb-multishop' ); ?></button></p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_sites_page(): void {
		// Placeholder: Will implement CRUD for sites registry on Primary.
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Secondary Sites', 'zeusweb-multishop' ); ?></h1>
			<p><?php esc_html_e( 'Manage registered Secondary sites and API keys (to be implemented).', 'zeusweb-multishop' ); ?></p>
		</div>
		<?php
	}

	public static function render_logs_page(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'zw_ms_logs';
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 200" );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Multishop Logs', 'zeusweb-multishop' ); ?></h1>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'zeusweb-multishop' ); ?></th>
						<th><?php esc_html_e( 'Level', 'zeusweb-multishop' ); ?></th>
						<th><?php esc_html_e( 'Message', 'zeusweb-multishop' ); ?></th>
						<th><?php esc_html_e( 'Context', 'zeusweb-multishop' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( $rows ) : foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo esc_html( $r->timestamp ); ?></td>
							<td><?php echo esc_html( strtoupper( $r->level ) ); ?></td>
							<td><?php echo esc_html( $r->message ); ?></td>
							<td><code><?php echo esc_html( (string) $r->context ); ?></code></td>
						</tr>
					<?php endforeach; else : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No logs yet.', 'zeusweb-multishop' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}


