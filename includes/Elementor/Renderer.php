<?php

namespace ZeusWeb\Multishop\Elementor;

use ZeusWeb\Multishop\Segments\Manager as SegmentManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Renderer {
	public static function init(): void {
		// Single product override using template_include to inject Elementor template content
		add_filter( 'template_include', [ __CLASS__, 'maybe_render_single_product_template' ], 99 );

		// Only use generic header/footer injection when not Astra; Astra compat handles it.
		$theme = wp_get_theme();
		$is_astra = $theme && ( $theme->get_template() === 'astra' || stripos( (string) $theme->get( 'Name' ), 'astra' ) !== false );
		if ( ! $is_astra ) {
			add_action( 'wp_body_open', [ __CLASS__, 'render_header_template' ], 5 );
			add_action( 'wp_footer', [ __CLASS__, 'render_footer_template' ], 5 );
		}

		// If a segment is active, suppress Elementor Theme Builder header/footer to avoid duplicates.
		add_filter( 'elementor/theme/should_render_location', [ __CLASS__, 'should_suppress_elementor_location' ], 10, 2 );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_remove_elementor_theme_hooks' ], 1 );
	}

	private static function get_template_id( string $slot ): int {
		$segment = SegmentManager::is_business() ? 'business' : 'consumer';
		switch ( $slot . '_' . $segment ) {
			case 'header_consumer':
				return (int) get_option( 'zw_ms_tpl_header_consumer', 0 );
			case 'header_business':
				return (int) get_option( 'zw_ms_tpl_header_business', 0 );
			case 'footer_consumer':
				return (int) get_option( 'zw_ms_tpl_footer_consumer', 0 );
			case 'footer_business':
				return (int) get_option( 'zw_ms_tpl_footer_business', 0 );
			// Single product handled by Elementor Theme Builder
		}
		return 0;
	}

	public static function render_header_template(): void {
		if ( ! apply_filters( 'zw_ms_should_render_header_footer', true ) ) {
			return;
		}
		$id = self::get_template_id( 'header' );
		if ( $id ) {
			self::render_elementor_template( $id );
		}
	}

	public static function render_footer_template(): void {
		if ( ! apply_filters( 'zw_ms_should_render_header_footer', true ) ) {
			return;
		}
		$id = self::get_template_id( 'footer' );
		if ( $id ) {
			self::render_elementor_template( $id );
		}
	}

	public static function should_suppress_elementor_location( $should_render, $location ) {
		$segment = SegmentManager::get_current_segment();
		if ( $segment && in_array( $location, [ 'header', 'footer' ], true ) ) {
			return false;
		}
		return $should_render;
	}

	public static function maybe_render_single_product_template( $template ) {
		// Single product override removed; let Elementor Theme Builder handle it.
		return $template;
	}

	public static function maybe_remove_elementor_theme_hooks(): void {
		if ( ! SegmentManager::get_current_segment() ) {
			return;
		}
		if ( function_exists( 'remove_all_actions' ) ) {
			remove_all_actions( 'elementor/theme/header' );
			remove_all_actions( 'elementor/theme/footer' );
		}
	}

	private static function render_elementor_template( int $template_id ): void {
		if ( $template_id <= 0 ) { return; }
		if ( did_action( 'elementor/loaded' ) ) {
			echo \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $template_id );
			return;
		}
		// Fallback via shortcode if Elementor not fully loaded here
		echo do_shortcode( '[elementor-template id="' . intval( $template_id ) . '"]' );
	}


}


