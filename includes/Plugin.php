<?php

namespace ZeusWeb\Multishop;

use ZeusWeb\Multishop\Utils\Crypto;
use ZeusWeb\Multishop\Logger\Logger;
use ZeusWeb\Multishop\Logger\DBLogger;
use ZeusWeb\Multishop\Install\Installer;
use ZeusWeb\Multishop\Segments\Manager as SegmentManager;
use ZeusWeb\Multishop\Products\Meta as ProductMeta;
use ZeusWeb\Multishop\Pricing\Resolver as PricingResolver;
use ZeusWeb\Multishop\Emails\Hooks as EmailHooks;
use ZeusWeb\Multishop\Admin\Menu as AdminMenu;
use ZeusWeb\Multishop\Rest\Routes as RestRoutes;
use ZeusWeb\Multishop\Orders\SecondaryHooks;
// use ZeusWeb\Multishop\Orders\MirrorRetry;
use ZeusWeb\Multishop\Orders\PrimaryHooks;
use ZeusWeb\Multishop\Admin\CDKeys as AdminCDKeys;
use ZeusWeb\Multishop\Admin\OrdersColumns;
use ZeusWeb\Multishop\Templates\CanvasEnforcer;
use ZeusWeb\Multishop\Elementor\Renderer as ElementorRenderer;
use ZeusWeb\Multishop\Compat\Astra as AstraCompat;
use ZeusWeb\Multishop\Checkout\Notices as CheckoutNotices;
use ZeusWeb\Multishop\Orders\OrderNumbers;
use ZeusWeb\Multishop\Payments\Enforcer as PaymentsEnforcer;
use ZeusWeb\Multishop\Sync\Service as SyncService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {
	/** @var Plugin */
	private static $instance;

	/** @var string */
	private $mode_option_key = 'zw_ms_mode'; // primary|secondary

	/** @var string */
	private $secret_option_key = 'zw_ms_secret';

	/** @var string */
	private $site_id_option_key = 'zw_ms_site_id';

	/**
	 * Singleton instance accessor.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		load_plugin_textdomain( 'zeusweb-multishop', false, dirname( plugin_basename( ZW_MS_PLUGIN_FILE ) ) . '/languages' );

		// Ensure DB tables exist even after updates
		Installer::maybe_install();

		// Initialize core services that do not depend on DB tables existing.
		Logger::instance();
		DBLogger::init();

		// Ensure critical options exist even if activation hook didn't run (e.g., after manual install/update).
		$site_id = get_option( $this->site_id_option_key, '' );
		if ( ! is_string( $site_id ) || $site_id === '' ) {
			$this->generate_site_id();
		}
		$secret = get_option( $this->secret_option_key, '' );
		if ( ! is_string( $secret ) || $secret === '' ) {
			$this->generate_secret();
		}

		// Later steps will hook: routing, pricing, REST, Elementor, etc.
		SegmentManager::init();
		ProductMeta::init();
		PricingResolver::init();
		EmailHooks::init();
		AdminMenu::init();
		OrdersColumns::init();
		RestRoutes::init();
		SecondaryHooks::init();
		PrimaryHooks::init();
		OrderNumbers::init();

		// Allow searching orders by customer IP address in admin (legacy CPT and HPOS orders table)
		add_filter( 'woocommerce_shop_order_search_fields', function( array $search_fields ): array {
			$search_fields[] = '_customer_ip_address';
			return array_values( array_unique( $search_fields ) );
		} );
		add_filter( 'woocommerce_orders_table_search_query_meta_keys', function( array $meta_keys ): array {
			$meta_keys[] = '_customer_ip_address';
			return array_values( array_unique( $meta_keys ) );
		} );
		add_action( 'admin_menu', function() {
			if ( get_option( 'zw_ms_mode', 'primary' ) === 'primary' ) {
				add_submenu_page( 'zw-ms', __( 'CD Keys', 'zeusweb-multishop' ), __( 'CD Keys', 'zeusweb-multishop' ), 'manage_woocommerce', 'zw-ms-keys', [ AdminCDKeys::class, 'render_page' ] );
			}
		} );

		// Enforce Elementor Canvas template site-wide (per user request)
		CanvasEnforcer::init();
		ElementorRenderer::init();
		AstraCompat::init();

		// Show shortage notice on thank-you and view-order if relevant
		CheckoutNotices::init();

		// Payments enforcement on Secondary
		PaymentsEnforcer::init();

		// Scheduled catalog sync for Secondary
		SyncService::init();
		SyncService::schedule_if_needed();
	}

	public function activate() {
		// Ensure site identifier exists.
		$site_id = get_option( $this->site_id_option_key, '' );
		if ( ! is_string( $site_id ) || $site_id === '' ) {
			$this->generate_site_id();
		}

		// Ensure secret exists for crypto/HMAC.
		$secret = get_option( $this->secret_option_key, '' );
		if ( ! is_string( $secret ) || $secret === '' ) {
			$this->generate_secret();
		}

		// Default mode: primary (can be changed later)
		if ( ! get_option( $this->mode_option_key ) ) {
			add_option( $this->mode_option_key, 'primary' );
		}

		Installer::maybe_install();
	}

	public function deactivate() {
		// Unschedule tasks if any (to be added when cron jobs are implemented)
	}

	public function is_primary(): bool {
		return get_option( $this->mode_option_key, 'primary' ) === 'primary';
	}

	public function get_site_id(): string {
		return (string) get_option( $this->site_id_option_key, '' );
	}

	public function get_secret(): string {
		return trim( (string) get_option( $this->secret_option_key, '' ) );
	}

	private function generate_site_id(): void {
		$time     = microtime( true );
		$random   = wp_generate_uuid4();
		$hostname = wp_parse_url( home_url(), PHP_URL_HOST );
		$hash     = hash( 'sha256', $time . '|' . $random . '|' . $hostname );
		update_option( $this->site_id_option_key, substr( $hash, 0, 32 ), false );
	}

	private function generate_secret(): void {
		$secret = wp_generate_password( 64, false, false );
		update_option( $this->secret_option_key, $secret, false );
	}
}


