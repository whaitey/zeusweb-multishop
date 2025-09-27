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
		if ( ! $is_astra && self::should_inject() && ! self::is_elementor_context() ) {
			add_action( 'wp_body_open', [ __CLASS__, 'render_header_template' ], 5 );
			add_action( 'wp_footer', [ __CLASS__, 'render_footer_template' ], 5 );
		}

		// Safety net: if a segment is active, also inject via generic hooks at runtime (covers edge templates)
		add_action( 'template_redirect', function () use ( $is_astra ) {
			if ( ! Renderer::should_inject() ) {
				return;
			}
			// Do not inject during Elementor editor/preview
			if ( Renderer::is_elementor_context() ) {
				return;
			}
			if ( ! SegmentManager::get_current_segment() ) {
				return;
			}
			// Avoid duplicate injection on product pages; Astra compat handles those.
			if ( function_exists( 'is_product' ) && is_product() ) {
				return;
			}
			// If Astra is active, rely on AstraCompat hooks to render header/footer to avoid duplicates.
			if ( $is_astra ) {
				return;
			}
			add_action( 'wp_body_open', [ __CLASS__, 'render_header_template' ], 5 );
			add_action( 'wp_footer', [ __CLASS__, 'render_footer_template' ], 5 );
		}, 2 );

		// If injecting our own templates, suppress Elementor Theme Builder header/footer to avoid duplicates.
		if ( self::should_inject() ) {
			add_filter( 'elementor/theme/should_render_location', [ __CLASS__, 'should_suppress_elementor_location' ], 10, 2 );
			add_action( 'template_redirect', [ __CLASS__, 'maybe_remove_elementor_theme_hooks' ], 1 );
		}
	}

	private static function get_template_id( string $slot ): int {
		// Get current segment
		$segment = SegmentManager::get_current_segment();
		
		// If no segment is set, return 0 (no template)
		if ( ! $segment ) {
			return 0;
		}
		
		// Template selection removed; managed manually on each site
		return 0;
	}

	public static function render_header_template(): void {
		// Don't render if already rendered
		if ( did_action( 'zw_ms/header_rendered' ) ) {
			return;
		}
		
		if ( ! apply_filters( 'zw_ms_should_render_header_footer', true ) ) {
			return;
		}
		
		$id = self::get_template_id( 'header' );
		if ( $id > 0 ) {
			self::render_elementor_template( $id );
			do_action( 'zw_ms/header_rendered' );
		}
	}

	public static function render_footer_template(): void {
		// Don't render if already rendered
		if ( did_action( 'zw_ms/footer_rendered' ) ) {
			return;
		}
		
		if ( ! apply_filters( 'zw_ms_should_render_header_footer', true ) ) {
			return;
		}
		
		$id = self::get_template_id( 'footer' );
		if ( $id > 0 ) {
			self::render_elementor_template( $id );
			do_action( 'zw_ms/footer_rendered' );
		}
	}

	public static function should_suppress_elementor_location( $should_render, $location ) {
		// Never suppress Elementor Theme Builder while editing/previewing
		if ( self::is_elementor_context() ) {
			return $should_render;
		}
		// Only suppress when we are injecting our own header/footer
		if ( ! self::should_inject() ) {
			return $should_render;
		}
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
		// Do not remove Elementor theme hooks while editing/previewing
		if ( self::is_elementor_context() ) {
			return;
		}
		// Only remove if injecting custom templates
		if ( ! self::should_inject() ) {
			return;
		}
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

	private static function is_elementor_context(): bool {
		// Quick checks for editor/preview params
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) || ( isset( $_GET['action'] ) && $_GET['action'] === 'elementor' ) ) {
			return true;
		}
		if ( function_exists( 'did_action' ) && did_action( 'elementor/loaded' ) ) {
			try {
				$plugin = \Elementor\Plugin::instance();
				if ( $plugin ) {
					if ( isset( $plugin->editor ) && method_exists( $plugin->editor, 'is_edit_mode' ) && $plugin->editor->is_edit_mode() ) {
						return true;
					}
					if ( isset( $plugin->preview ) && method_exists( $plugin->preview, 'is_preview_mode' ) && $plugin->preview->is_preview_mode() ) {
						return true;
					}
				}
			} catch ( \Throwable $e ) {
				// Ignore detection errors
			}
		}
		return false;
	}

	private static function should_inject(): bool {
		return (bool) apply_filters( 'zw_ms_inject_header_footer', false );
	}


}


