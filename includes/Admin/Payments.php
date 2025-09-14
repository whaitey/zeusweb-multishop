<?php

namespace ZeusWeb\Multishop\Admin;

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

		$option_key = 'zw_ms_gateways_matrix';
		$matrix = get_option( $option_key, [] );
		if ( ! is_array( $matrix ) ) { $matrix = []; }

		if ( isset( $_POST['zw_ms_payments_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zw_ms_payments_nonce'] ) ), 'zw_ms_payments_save' ) && current_user_can( 'manage_woocommerce' ) ) {
			$site_id = isset( $_POST['zw_ms_site_id'] ) ? sanitize_text_field( wp_unslash( $_POST['zw_ms_site_id'] ) ) : '';
			$consumer = isset( $_POST['zw_ms_gateways_consumer'] ) && is_array( $_POST['zw_ms_gateways_consumer'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['zw_ms_gateways_consumer'] ) ) : [];
			$business = isset( $_POST['zw_ms_gateways_business'] ) && is_array( $_POST['zw_ms_gateways_business'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['zw_ms_gateways_business'] ) ) : [];
			if ( $site_id !== '' ) {
				$matrix[ $site_id ] = [ 'consumer' => array_values( $consumer ), 'business' => array_values( $business ) ];
				update_option( $option_key, $matrix, false );
				add_settings_error( 'zw_ms_payments', 'saved', __( 'Payments mapping saved.', 'zeusweb-multishop' ), 'updated' );
			}
		}

		$gateways = [];
		if ( function_exists( 'WC' ) ) {
			$gw = WC()->payment_gateways();
			if ( $gw && method_exists( $gw, 'payment_gateways' ) ) {
				foreach ( $gw->payment_gateways() as $id => $g ) {
					$gateways[ $id ] = $g->get_method_title() ?: $id;
				}
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Payments Control (Primary)', 'zeusweb-multishop' ); ?></h1>
			<?php settings_errors( 'zw_ms_payments' ); ?>
			<form method="post">
				<?php wp_nonce_field( 'zw_ms_payments_save', 'zw_ms_payments_nonce' ); ?>
				<table class="form-table" role="presentation"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Secondary Site ID', 'zeusweb-multishop' ); ?></th>
						<td><input type="text" name="zw_ms_site_id" class="regular-text" placeholder="e.g. 2b9f..." required /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Allowed Gateways (Consumer)', 'zeusweb-multishop' ); ?></th>
						<td>
							<?php foreach ( $gateways as $gid => $label ) : ?>
								<label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="zw_ms_gateways_consumer[]" value="<?php echo esc_attr( $gid ); ?>" /> <?php echo esc_html( $label ); ?> (<?php echo esc_html( $gid ); ?>)</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Allowed Gateways (Business)', 'zeusweb-multishop' ); ?></th>
						<td>
							<?php foreach ( $gateways as $gid => $label ) : ?>
								<label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="zw_ms_gateways_business[]" value="<?php echo esc_attr( $gid ); ?>" /> <?php echo esc_html( $label ); ?> (<?php echo esc_html( $gid ); ?>)</label>
							<?php endforeach; ?>
						</td>
					</tr>
				</tbody></table>
				<?php submit_button( __( 'Save Mapping', 'zeusweb-multishop' ) ); ?>
			</form>

			<h2 style="margin-top:24px;"><?php esc_html_e( 'Existing Site Mappings', 'zeusweb-multishop' ); ?></h2>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Site ID', 'zeusweb-multishop' ); ?></th><th><?php esc_html_e( 'Consumer', 'zeusweb-multishop' ); ?></th><th><?php esc_html_e( 'Business', 'zeusweb-multishop' ); ?></th></tr></thead><tbody>
				<?php if ( empty( $matrix ) ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'No mappings yet.', 'zeusweb-multishop' ); ?></td></tr>
				<?php else: foreach ( $matrix as $sid => $rows ) : ?>
					<tr>
						<td><code><?php echo esc_html( (string) $sid ); ?></code></td>
						<td><?php echo esc_html( implode( ', ', array_map( 'strval', (array) ( $rows['consumer'] ?? [] ) ) ) ); ?></td>
						<td><?php echo esc_html( implode( ', ', array_map( 'strval', (array) ( $rows['business'] ?? [] ) ) ) ); ?></td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody></table>
		</div>
		<?php
	}
}
