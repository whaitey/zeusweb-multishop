<?php

namespace ZeusWeb\Multishop\Utils;

use ZeusWeb\Multishop\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crypto {
	/**
	 * Encrypt a plaintext string using AES-256-GCM.
	 * Returns a URL-safe base64 string containing iv:tag:ciphertext.
	 */
	public static function encrypt( string $plaintext ): string {
		$key = self::get_key_bytes();
		$iv  = random_bytes( 12 ); // 96-bit nonce for GCM
		$tag = '';
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( $ciphertext === false ) {
			throw new \RuntimeException( 'Encryption failed' );
		}
		return rtrim( strtr( base64_encode( $iv . $tag . $ciphertext ), '+/', '-_' ), '=' );
	}

	/**
	 * Decrypt a string produced by self::encrypt.
	 */
	public static function decrypt( string $encoded ): string {
		$binary = base64_decode( strtr( $encoded, '-_', '+/' ) );
		if ( $binary === false || strlen( $binary ) < 28 ) {
			throw new \InvalidArgumentException( 'Invalid ciphertext' );
		}
		$iv         = substr( $binary, 0, 12 );
		$tag        = substr( $binary, 12, 16 );
		$ciphertext = substr( $binary, 28 );
		$key        = self::get_key_bytes();
		$plaintext  = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( $plaintext === false ) {
			throw new \RuntimeException( 'Decryption failed' );
		}
		return $plaintext;
	}

	private static function get_key_bytes(): string {
		$plugin = Plugin::instance();
		$secret = $plugin->get_secret();
		$salts  = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		return hash( 'sha256', $salts . '|' . $secret, true );
	}
}


