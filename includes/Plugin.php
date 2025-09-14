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
use ZeusWeb\Multishop\Orders\PrimaryHooks;
use ZeusWeb\Multishop\Admin\CDKeys as AdminCDKeys;
use ZeusWeb\Multishop\Templates\CanvasEnforcer;
use ZeusWeb\Multishop\Elementor\Renderer as ElementorRenderer;
use ZeusWeb\Multishop\Compat\Astra as AstraCompat;
use ZeusWeb\Multishop\Checkout\Notices as CheckoutNotices;
use ZeusWeb\Multishop\Orders\OrderNumbers;

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

		// Initialize core services that do not depend on DB tables existing.
		Logger::instance();
		DBLogger::init();

		// Ensure critical options exist even if activation hook didn't run (e.g., after manual install/update).
		if ( ! get_option( $this->site_id_option_key ) ) {
			$this->generate_site_id();
		}
		if ( ! get_option( $this->secret_option_key ) ) {
			$this->generate_secret();
		}

		// Later steps will hook: routing, pricing, REST, Elementor, etc.
		SegmentManager::init();
		ProductMeta::init();
		PricingResolver::init();
		EmailHooks::init();
		AdminMenu::init();
		RestRoutes::init();
		SecondaryHooks::init();
		PrimaryHooks::init();
		OrderNumbers::init();
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
	}

	public function activate() {
		// Ensure site identifier exists.
		if ( ! get_option( $this->site_id_option_key ) ) {
			$this->generate_site_id();
		}

		// Ensure secret exists for crypto/HMAC.
		if ( ! get_option( $this->secret_option_key ) ) {
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
		return (string) get_option( $this->site_id_option_key );
	}

	public function get_secret(): string {
		return (string) get_option( $this->secret_option_key );
	}

	private function generate_site_id(): void {
		$time     = microtime( true );
		$random   = wp_generate_uuid4();
		$hostname = wp_parse_url( home_url(), PHP_URL_HOST );
		$hash     = hash( 'sha256', $time . '|' . $random . '|' . $hostname );
		update_option( $this->site_id_option_key, substr( $hash, 0, 32 ), false );
	}

	private function generate_secret(): void {
		$bytes = wp_generate_password( 64, true, true );
		update_option( $this->secret_option_key, $bytes, false );
	}
}


