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
		// Replace header/footer via Astra hook locations to ensure compatibility.
		$has_header = (int) get_option( 'zw_ms_tpl_header_consumer', 0 ) || (int) get_option( 'zw_ms_tpl_header_business', 0 );
		$has_footer = (int) get_option( 'zw_ms_tpl_footer_consumer', 0 ) || (int) get_option( 'zw_ms_tpl_footer_business', 0 );

		if ( $has_header ) {
			add_action( 'template_redirect', function() {
				if ( SegmentManager::get_current_segment() ) {
					if ( function_exists( 'remove_all_actions' ) ) {
						remove_all_actions( 'astra_header' );
					}
					add_action( 'astra_header', [ Renderer::class, 'render_header_template' ], 10 );
				}
			}, 1 );
		}

		if ( $has_footer ) {
			add_action( 'template_redirect', function() {
				if ( SegmentManager::get_current_segment() ) {
					if ( function_exists( 'remove_all_actions' ) ) {
						remove_all_actions( 'astra_footer' );
					}
					add_action( 'astra_footer', [ Renderer::class, 'render_footer_template' ], 10 );
				}
			}, 1 );
			// Fallback: if Astra footer isn't output, render at wp_footer
			add_action( 'wp_footer', function() {
				if ( SegmentManager::get_current_segment() && ! did_action( 'astra_footer' ) ) {
					Renderer::render_footer_template();
				}
			}, 5 );
		}
	}
}


