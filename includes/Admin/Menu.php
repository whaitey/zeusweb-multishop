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

	public static function register_settings(): void {
		// General settings
		register_setting( 'zw_ms', 'zw_ms_mode' );
		register_setting( 'zw_ms', 'zw_ms_primary_url' );
		register_setting( 'zw_ms', 'zw_ms_site_id' );
		register_setting( 'zw_ms', 'zw_ms_secret' );
		register_setting( 'zw_ms', 'zw_ms_shortage_message' );

		// Elementor template IDs
		register_setting( 'zw_ms', 'zw_ms_tpl_header_consumer' );
		register_setting( 'zw_ms', 'zw_ms_tpl_header_business' );
		register_setting( 'zw_ms', 'zw_ms_tpl_footer_consumer' );
		register_setting( 'zw_ms', 'zw_ms_tpl_footer_business' );
		register_setting( 'zw_ms', 'zw_ms_tpl_single_product_consumer' );
		register_setting( 'zw_ms', 'zw_ms_tpl_single_product_business' );
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
								<p>
									<label>Single Product (Consumer) <input name="zw_ms_tpl_single_product_consumer" type="number" value="<?php echo esc_attr( get_option( 'zw_ms_tpl_single_product_consumer', '' ) ); ?>" /></label>
								</p>
								<p>
									<label>Single Product (Business) <input name="zw_ms_tpl_single_product_business" type="number" value="<?php echo esc_attr( get_option( 'zw_ms_tpl_single_product_business', '' ) ); ?>" /></label>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>
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


