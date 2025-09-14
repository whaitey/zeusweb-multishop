<?php
/*
Plugin Name: ZeusWeb Multishop
Plugin URI: https://zeusweb.example
Description: Multishop system for WooCommerce with Primary/Secondary architecture, Consumer/Business segments, CD key allocation, and Elementor templates.
Version: 0.1.60
Author: ZeusWeb
Author URI: https://zeusweb.example
Text Domain: zeusweb-multishop
Domain Path: /languages
Requires Plugins: woocommerce
GitHub Plugin URI: https://github.com/whaitey/zeusweb-multishop
Primary Branch: main
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constants
define( 'ZW_MS_VERSION', '0.1.60' );
define( 'ZW_MS_PLUGIN_FILE', __FILE__ );
define( 'ZW_MS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZW_MS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Simple PSR-4 autoloader for this plugin.
spl_autoload_register( function ( $class ) {
	$prefix = 'ZeusWeb\\Multishop\\';
	$len    = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}
	$relative_class = substr( $class, $len );
	$relative_path  = str_replace( '\\', '/', $relative_class );
	$file           = ZW_MS_PLUGIN_DIR . 'includes/' . $relative_path . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// Bootstrap
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && $screen->parent_base !== 'plugins' ) {
				return;
			}
			echo '<div class="notice notice-error"><p>' . esc_html__( 'ZeusWeb Multishop requires WooCommerce to be active.', 'zeusweb-multishop' ) . '</p></div>';
		} );
		return;
	}

	$plugin = ZeusWeb\Multishop\Plugin::instance();
	$plugin->init();
} );

register_activation_hook( __FILE__, function () {
	ZeusWeb\Multishop\Plugin::instance()->activate();
	// Flush rewrites for /lakossagi and /uzleti
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
	ZeusWeb\Multishop\Plugin::instance()->deactivate();
	flush_rewrite_rules();
} );


