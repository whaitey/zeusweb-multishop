<?php

namespace ZeusWeb\Multishop\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CanvasEnforcer {
	public static function init(): void {
		add_filter( 'template_include', [ __CLASS__, 'force_canvas_template' ], 9999 );
	}

	public static function force_canvas_template( $template ) {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return $template;
		}
		// Do not force Canvas while editing or previewing with Elementor
		if ( self::is_elementor_context() ) {
			return $template;
		}
		$canvas = WP_PLUGIN_DIR . '/elementor/modules/page-templates/templates/canvas.php';
		if ( file_exists( $canvas ) ) {
			return $canvas;
		}
		return $template;
	}

	private static function is_elementor_context(): bool {
		// Query signals
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) || ( isset( $_GET['action'] ) && $_GET['action'] === 'elementor' ) ) {
			return true;
		}
		// Runtime signals
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
}


