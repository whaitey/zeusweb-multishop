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
		
		// Template injection removed; manage templates manually per site in multi-domain mode
	}
}


