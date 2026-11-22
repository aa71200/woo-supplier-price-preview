<?php

namespace SupplierPriceImport;


defined( 'ABSPATH' ) || exit;

final class Input {
	private const MAX_BYTES = 2097152;

	public static function text( mixed $value, int $bytes = 400 ): string {
		if ( ! is_string( $value ) || strlen( $value ) > $bytes || 1 !== preg_match( '//u', $value ) || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ) {
			throw new Problem( __( 'Invalid text length or encoding.', 'supplier-price-import-for-woocommerce' ) );
		}
		return preg_replace( '/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $value );
	}

	public static function identifier( mixed $value ): string {
		$value = self::text( $value );
		if ( '' === $value || preg_match( '/[\r\n\t]/', $value ) ) {
			throw new Problem( __( 'Missing or invalid item code.', 'supplier-price-import-for-woocommerce' ) );
		}
		return $value;
	}

	public static function csv( string $bytes, string $delimiter, int $max_rows ): array {
		if ( strlen( $bytes ) > self::MAX_BYTES || 1 !== preg_match( '//u', $bytes ) || str_contains( $bytes, "\0" ) ) {
			throw new Problem( __( 'Use UTF-8 text of up to 2 MiB.', 'supplier-price-import-for-woocommerce' ) );
		}
		$bytes     = str_starts_with( $bytes, "\xEF\xBB\xBF" ) ? substr( $bytes, 3 ) : $bytes;
		$separator = preg_quote( $delimiter, '~' );
		$pattern   = '~\G(?:"((?:[^"]++|"")*)"|([^"' . $separator . '\r\n]*))(' . $separator . '|\r\n|\r|\n|\z)~';
		$length    = strlen( $bytes );
		$rows      = array();
		$row       = array();
		$position  = 0;
		while ( $position < $length || $row ) {
			if ( ! preg_match( $pattern, $bytes, $match, PREG_UNMATCHED_AS_NULL, $position ) ) {
				throw new Problem( __( 'Invalid quote or separator in the text file.', 'supplier-price-import-for-woocommerce' ) );
			}
			$row[]     = null === $match[1] ? $match[2] : str_replace( '""', '"', $match[1] );
			$position += strlen( $match[0] );
			if ( count( $row ) > 50 ) {
				throw new Problem( __( 'The file exceeds 50 columns.', 'supplier-price-import-for-woocommerce' ) );
			}
			if ( $match[3] !== $delimiter ) {
				$rows[ count( $rows ) + 1 ] = $row;
				$row                        = array();
				if ( count( $rows ) > $max_rows + 1 ) {
					throw new Problem( __( 'The file has too many data rows.', 'supplier-price-import-for-woocommerce' ) );
				}
			}
		}
		return $rows;
	}

	public static function columns( array $rows ): array {
		return array_map(
			static function ( $cell ) {
				return self::text( is_array( $cell ) ? ( 'f' === $cell['type'] ? '' : $cell['value'] ) : $cell );
			},
			$rows[1] ?? array()
		);
	}

	// The native CSV reader returns plain strings. Workbook cells belong to the full plugin.
	public static function cell( mixed $cell, bool $code ): array {
		if ( ! is_string( $cell ) ) {
			throw new Problem( 'This preview sample expects CSV text cells.' );
		}
		return array( $cell, false );
	}
	public static function unit( mixed $value ): string {
		if ( ! in_array( $value, array( 'IRR', 'IRT' ), true ) ) {
			throw new Problem( __( 'Choose rial or toman. The WooCommerce base currency must also be IRR or IRT.', 'supplier-price-import-for-woocommerce' ) );
		}
		return $value;
	}

	public static function price( string $value, string $from, string $to ): string {
		$value = strtr(
			self::text( $value, 120 ),
			array(
				'۰' => '0',
				'۱' => '1',
				'۲' => '2',
				'۳' => '3',
				'۴' => '4',
				'۵' => '5',
				'۶' => '6',
				'۷' => '7',
				'۸' => '8',
				'۹' => '9',
				'٠' => '0',
				'١' => '1',
				'٢' => '2',
				'٣' => '3',
				'٤' => '4',
				'٥' => '5',
				'٦' => '6',
				'٧' => '7',
				'٨' => '8',
				'٩' => '9',
			)
		);
		if ( ! preg_match( '/\A[0-9]+\z/D', $value ) ) {
			if ( ! preg_match( '/\A[0-9]{1,3}([,٬ \x{00A0}\x{202F}])[0-9]{3}(?:\1[0-9]{3})*\z/uD', $value, $group ) ) {
				throw new Problem( __( 'Enter a positive whole price with valid thousands separators.', 'supplier-price-import-for-woocommerce' ) );
			}
			$value = str_replace( $group[1], '', $value );
		}
		$value = ltrim( $value, '0' );
		if ( '' === $value ) {
			throw new Problem( __( 'The incoming price must be greater than zero.', 'supplier-price-import-for-woocommerce' ) );
		}
		if ( $from !== $to ) {
			if ( 'IRT' === $from ) {
				$value .= '0';
			} elseif ( ! str_ends_with( $value, '0' ) ) {
				throw new Problem( __( 'The rial price is not divisible by ten.', 'supplier-price-import-for-woocommerce' ) );
			} else {
				$value = substr( $value, 0, -1 );
			}
		}
		if ( strlen( $value ) > 15 ) {
			throw new Problem( __( 'The converted price exceeds the WooCommerce price limit.', 'supplier-price-import-for-woocommerce' ) );
		}
		return $value;
	}

}
