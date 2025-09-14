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
		$canvas = WP_PLUGIN_DIR . '/elementor/modules/page-templates/templates/canvas.php';
		if ( file_exists( $canvas ) ) {
			return $canvas;
		}
		return $template;
	}
}


