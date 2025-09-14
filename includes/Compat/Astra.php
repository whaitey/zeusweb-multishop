<?php

namespace ZeusWeb\Multishop\Compat;

use ZeusWeb\Multishop\Elementor\Renderer as Renderer;
use ZeusWeb\Multishop\Segments\Manager as SegmentManager;

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
		
		// Check if we have any segment templates configured
		$has_header = (int) get_option( 'zw_ms_tpl_header_consumer', 0 ) || (int) get_option( 'zw_ms_tpl_header_business', 0 );
		$has_footer = (int) get_option( 'zw_ms_tpl_footer_consumer', 0 ) || (int) get_option( 'zw_ms_tpl_footer_business', 0 );

		if ( $has_header || $has_footer ) {
			// Disable Astra's native header/footer when we have segment templates
			add_action( 'template_redirect', function() {
				if ( SegmentManager::get_current_segment() ) {
					// Remove Astra header/footer
					remove_action( 'astra_header', 'astra_header_markup' );
					remove_action( 'astra_footer', 'astra_footer_markup' );
				}
			}, 1 );
		}

		if ( $has_header ) {
			// Use multiple hooks to ensure header renders
			add_action( 'astra_header', [ Renderer::class, 'render_header_template' ], 10 );
			add_action( 'astra_header_before', [ Renderer::class, 'render_header_template' ], 10 );
			add_action( 'wp_body_open', function() {
				if ( SegmentManager::get_current_segment() && ! did_action( 'zw_ms/header_rendered' ) ) {
					Renderer::render_header_template();
				}
			}, 5 );
		}

		if ( $has_footer ) {
			// Use multiple hooks to ensure footer renders
			add_action( 'astra_footer', [ Renderer::class, 'render_footer_template' ], 10 );
			add_action( 'astra_footer_before', [ Renderer::class, 'render_footer_template' ], 10 );
			add_action( 'wp_footer', function() {
				if ( SegmentManager::get_current_segment() && ! did_action( 'zw_ms/footer_rendered' ) ) {
					Renderer::render_footer_template();
				}
			}, 5 );
		}
	}
}


