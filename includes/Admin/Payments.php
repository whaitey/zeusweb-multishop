<?php

namespace ZeusWeb\Multishop\Admin;

use ZeusWeb\Multishop\DB\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Payments {
	public static function render_page(): void {
		if ( get_option( 'zw_ms_mode', 'primary' ) !== 'primary' ) {
			?>
			<div class="wrap"><h1><?php esc_html_e( 'Payments Control', 'zeusweb-multishop' ); ?></h1>
			<p><?php esc_html_e( 'This page is available only on the Primary site.', 'zeusweb-multishop' ); ?></p></div>
			<?php
			return;
		}

		$site_id = isset( $_GET['site_id'] ) ? sanitize_text_field( wp_unslash( $_GET['site_id'] ) ) : '';
		if ( isset( $_POST['zw_ms_payments_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zw_ms_payments_nonce'] ) ), 'zw_ms_payments_save' ) && current_user_can( 'manage_woocommerce' ) ) {
			$action = isset( $_POST['zw_ms_action'] ) ? sanitize_text_field( wp_unslash( $_POST['zw_ms_action'] ) ) : '';
			if ( $action === 'save_gateways' ) {
				self::handle_save_gateways();
			} elseif ( $action === 'add_site' ) {
				self::handle_add_site();
			} elseif ( $action === 'delete_site' ) {
				self::handle_delete_site();
			}
		}

		if ( $site_id !== '' ) {
			self::render_manage_site( $site_id );
			return;
		}
		self::render_sites_list();
	}

	private static function render_sites_list(): void {
		global $wpdb;
		$table = Tables::sites();
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Payments Control', 'zeusweb-multishop' ); ?></h1>
			<h2><?php esc_html_e( 'Secondary Sites', 'zeusweb-multishop' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Site ID', 'zeusweb-multishop' ); ?></th><th><?php esc_html_e( 'URL', 'zeusweb-multishop' ); ?></th><th><?php esc_html_e( 'Status', 'zeusweb-multishop' ); ?></th><th><?php esc_html_e( 'Actions', 'zeusweb-multishop' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No sites yet. Add one below.', 'zeusweb-multishop' ); ?></td></tr>
					<?php else: foreach ( $rows as $r ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) $r->site_id ); ?></code></td>
							<td><a href="<?php echo esc_url( (string) $r->site_url ); ?>" target="_blank"><?php echo esc_html( (string) $r->site_url ); ?></a></td>
							<td><?php echo esc_html( (string) $r->status ); ?></td>
							<td>
								<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=zw-ms-payments&site_id=' . rawurlencode( (string) $r->site_id ) ) ); ?>"><?php esc_html_e( 'Manage gateways', 'zeusweb-multishop' ); ?></a>
								<form method="post" style="display:inline-block;margin-left:6px;">
									<?php wp_nonce_field( 'zw_ms_payments_save', 'zw_ms_payments_nonce' ); ?>
									<input type="hidden" name="zw_ms_action" value="delete_site" />
									<input type="hidden" name="site_id" value="<?php echo esc_attr( (string) $r->site_id ); ?>" />
									<button class="button" onclick="return confirm('Delete this site?');"><?php esc_html_e( 'Delete', 'zeusweb-multishop' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; endif; ?>
				</tbody>
			</table>

			<h2 style="margin-top:24px;"><?php esc_html_e( 'Add Site', 'zeusweb-multishop' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'zw_ms_payments_save', 'zw_ms_payments_nonce' ); ?>
				<input type="hidden" name="zw_ms_action" value="add_site" />
				<table class="form-table" role="presentation"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Site ID', 'zeusweb-multishop' ); ?></th>
						<td><input type="text" name="site_id" class="regular-text" placeholder="e.g. 2b9f..." required /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Site URL', 'zeusweb-multishop' ); ?></th>
						<td><input type="url" name="site_url" class="regular-text" placeholder="https://example.com" required /></td>
					</tr>
				</tbody></table>
				<?php submit_button( __( 'Add Site', 'zeusweb-multishop' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function render_manage_site( string $site_id ): void {
		$option_key = 'zw_ms_gateways_matrix';
		$matrix = get_option( $option_key, [] );
		if ( ! is_array( $matrix ) ) { $matrix = []; }
		$sel_consumer = array_map( 'strval', (array) ( $matrix[ $site_id ]['consumer'] ?? [] ) );
		$sel_business = array_map( 'strval', (array) ( $matrix[ $site_id ]['business'] ?? [] ) );
		$gateways = [];
		if ( function_exists( 'WC' ) ) {
			$gw = \WC()->payment_gateways();
			if ( $gw && method_exists( $gw, 'payment_gateways' ) ) {
				foreach ( $gw->payment_gateways() as $id => $g ) {
					$gateways[ $id ] = $g->get_method_title() ?: $id;
				}
			}
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( sprintf( __( 'Manage gateways for site %s', 'zeusweb-multishop' ), $site_id ) ); ?></h1>
			<form method="post">
				<?php wp_nonce_field( 'zw_ms_payments_save', 'zw_ms_payments_nonce' ); ?>
				<input type="hidden" name="zw_ms_action" value="save_gateways" />
				<input type="hidden" name="zw_ms_site_id" value="<?php echo esc_attr( $site_id ); ?>" />
				<table class="form-table" role="presentation"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Allowed Gateways (Consumer)', 'zeusweb-multishop' ); ?></th>
						<td>
							<?php foreach ( $gateways as $gid => $label ) : ?>
								<label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="zw_ms_gateways_consumer[]" value="<?php echo esc_attr( $gid ); ?>" <?php checked( in_array( $gid, $sel_consumer, true ) ); ?> /> <?php echo esc_html( $label ); ?> (<?php echo esc_html( $gid ); ?>)</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Allowed Gateways (Business)', 'zeusweb-multishop' ); ?></th>
						<td>
							<?php foreach ( $gateways as $gid => $label ) : ?>
								<label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="zw_ms_gateways_business[]" value="<?php echo esc_attr( $gid ); ?>" <?php checked( in_array( $gid, $sel_business, true ) ); ?> /> <?php echo esc_html( $label ); ?> (<?php echo esc_html( $gid ); ?>)</label>
							<?php endforeach; ?>
						</td>
					</tr>
				</tbody></table>
				<?php submit_button( __( 'Save Mapping', 'zeusweb-multishop' ) ); ?>
			</form>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=zw-ms-payments' ) ); ?>">&larr; <?php esc_html_e( 'Back to sites', 'zeusweb-multishop' ); ?></a></p>
		</div>
		<?php
	}

	private static function handle_save_gateways(): void {
		$option_key = 'zw_ms_gateways_matrix';
		$matrix = get_option( $option_key, [] );
		if ( ! is_array( $matrix ) ) { $matrix = []; }
		$site_id = isset( $_POST['zw_ms_site_id'] ) ? sanitize_text_field( wp_unslash( $_POST['zw_ms_site_id'] ) ) : '';
		$consumer = isset( $_POST['zw_ms_gateways_consumer'] ) && is_array( $_POST['zw_ms_gateways_consumer'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['zw_ms_gateways_consumer'] ) ) : [];
		$business = isset( $_POST['zw_ms_gateways_business'] ) && is_array( $_POST['zw_ms_gateways_business'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['zw_ms_gateways_business'] ) ) : [];
		if ( $site_id !== '' ) {
			$matrix[ $site_id ] = [ 'consumer' => array_values( $consumer ), 'business' => array_values( $business ) ];
			update_option( $option_key, $matrix, false );
			add_settings_error( 'zw_ms_payments', 'saved', __( 'Payments mapping saved.', 'zeusweb-multishop' ), 'updated' );
		}
	}

	private static function handle_add_site(): void {
		$site_id = isset( $_POST['site_id'] ) ? sanitize_text_field( wp_unslash( $_POST['site_id'] ) ) : '';
		$site_url = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '';
		if ( $site_id === '' || $site_url === '' ) { return; }
		global $wpdb;
		$table = Tables::sites();
		$wpdb->insert( $table, [ 'site_id' => $site_id, 'site_url' => $site_url, 'api_key' => '', 'status' => 'active', 'created_at' => current_time( 'mysql', 1 ) ], [ '%s', '%s', '%s', '%s', '%s' ] );
		add_settings_error( 'zw_ms_payments', 'site_added', __( 'Site added.', 'zeusweb-multishop' ), 'updated' );
	}

	private static function handle_delete_site(): void {
		$site_id = isset( $_POST['site_id'] ) ? sanitize_text_field( wp_unslash( $_POST['site_id'] ) ) : '';
		if ( $site_id === '' ) { return; }
		global $wpdb;
		$table = Tables::sites();
		$wpdb->delete( $table, [ 'site_id' => $site_id ], [ '%s' ] );
		add_settings_error( 'zw_ms_payments', 'site_deleted', __( 'Site deleted.', 'zeusweb-multishop' ), 'updated' );
	}
}
