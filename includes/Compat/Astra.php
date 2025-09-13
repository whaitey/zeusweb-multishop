<?php

namespace ZeusWeb\Multishop\Compat;

use ZeusWeb\Multishop\Elementor\Renderer as Renderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Astra {
	public static function init(): void {
		add_action( 'after_setup_theme', [ __CLASS__, 'maybe_hook_astra' ] );
	}

	public static function is_astra(): bool {
		$theme = wp_get_theme();
		if ( ! $theme ) { return false; }
		$stylesheet = function_exists( 'get_template' ) ? get_template() : $theme->get_stylesheet();
		return ( $theme->get_template() === 'astra' ) || ( $stylesheet === 'astra' ) || stripos( (string) $theme->get( 'Name' ), 'astra' ) !== false;
	}

	public static function maybe_hook_astra(): void {
		if ( ! self::is_astra() ) {
			return;
		}
		// Replace header/footer via Astra hook locations to ensure compatibility.
		$has_header = (int) get_option( 'zw_ms_tpl_header_consumer', 0 ) || (int) get_option( 'zw_ms_tpl_header_business', 0 );
		$has_footer = (int) get_option( 'zw_ms_tpl_footer_consumer', 0 ) || (int) get_option( 'zw_ms_tpl_footer_business', 0 );

		if ( $has_header ) {
			// Avoid double render from generic injection
			remove_action( 'wp_body_open', [ Renderer::class, 'render_header_template' ], 5 );
			// Hide Astra native header
			add_filter( 'astra_enable_header', '__return_false', 50 );
			// Render Elementor at Astra header hook position
			add_action( 'astra_header', [ Renderer::class, 'render_header_template' ], 10 );
		}

		if ( $has_footer ) {
			remove_action( 'wp_footer', [ Renderer::class, 'render_footer_template' ], 5 );
			// Hide Astra native footer
			add_filter( 'astra_enable_footer', '__return_false', 50 );
			// Render Elementor at Astra footer hook position
			add_action( 'astra_footer', [ Renderer::class, 'render_footer_template' ], 10 );
		}
	}
}


