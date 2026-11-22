<?php

namespace SupplierPriceImport;

defined( 'ABSPATH' ) || exit;

final class Importer {

	public static function save_profile( array $data ): array {
		$profile = array(
			'name'  => Input::identifier( $data['name'] ?? null ),
			'code'  => Input::identifier( $data['code'] ?? null ),
			'price' => Input::identifier( $data['price'] ?? null ),
			'unit'  => Input::unit( $data['unit'] ?? null ),
			'match' => $data['match'] ?? '',
		);
		if ( $profile['code'] === $profile['price'] || ! in_array( $profile['match'], array( 'sku', 'mapping' ), true ) ) {
			throw new Problem( __( 'Choose different code and price headings and a valid matching method.', 'supplier-price-import-for-woocommerce' ) );
		}
		return Store::locked(
			static function () use ( $profile, $data ) {
				$all = Store::profiles();
				$id  = absint( $data['id'] ?? 0 );
				if ( ! $id ) {
					$id = $all ? max( array_keys( $all ) ) + 1 : 1;
				} else {
					Store::profile( $id );
				}
				$effective = $profile;
				unset( $effective['name'] );
				$profile['revision'] = hash( 'sha256', wp_json_encode( $effective ) );
				if ( ( $all[ $id ] ?? null ) !== $profile ) {
					$all[ $id ] = $profile;
					Store::assert_db( update_option( 'spifw_profiles', $all, false ) );
				}
				return array(
					'id'       => $id,
					'profiles' => $all,
				);
			}
		);
	}

	public static function base(): string {
		return Input::unit( get_option( 'woocommerce_currency' ) );
	}

	public static function position( array $header, string $name ): ?int {
		$positions = array_keys( $header, $name, true );
		return 1 === count( $positions ) ? $positions[0] : null;
	}

	private static function message( array $row, string $state, string $reason = '' ): array {
		$row['state']  = $state;
		$row['reason'] = $reason;
		return $row;
	}

	private static function public_row( array $row ): array {
		unset( $row['snapshot'], $row['mapping'], $row['previous'], $row['origin'] );
		return $row;
	}

	private static function preview_result( string $kind, array $detail, array $rows ): array {
		$preview         = Store::preview( $kind, $detail, $rows );
		$preview['rows'] = array_map( array( self::class, 'public_row' ), $rows );
		return $preview;
	}

	public static function preview_prices( int $profile_id, array $data, array $rows, int $code, int $price ): array {
		$profile = Store::profile( $profile_id );
		$base    = self::base();
		if ( ( $data['base'] ?? '' ) !== $base || ( $data['unit'] ?? '' ) !== $profile['unit'] ) {
			throw new Problem( __( 'The base currency or supplier unit changed. Inspect the file again.', 'supplier-price-import-for-woocommerce' ), 409 );
		}
		unset( $rows[1] );
		$result        = array();
		$found_codes   = array();
		$found_targets = array();
		foreach ( $rows as $line => $cells ) {
			$code_cell  = $cells[ $code ] ?? '';
			$price_cell = $cells[ $price ] ?? '';
			if ( '' === ( is_array( $code_cell ) ? $code_cell['value'] : trim( $code_cell ) )
				&& '' === ( is_array( $price_cell ) ? $price_cell['value'] : trim( $price_cell ) ) ) {
				continue;
			}
			$row = array(
				'code'   => '',
				'unit'   => $base,
				'source' => $profile['unit'],
			);
			try {
				list( $raw, $numeric )                   = Input::cell( $code_cell, true );
				$row['code']                             = Input::identifier( $raw );
				$found_codes[ 'code:' . $row['code'] ][] = $line;
				$row['mapping']                          = 'mapping' === $profile['match'] ? Store::mapping( $profile_id, $row['code'] ) : null;
				if ( 'mapping' === $profile['match'] && ! $row['mapping'] ) {
					throw new Problem( __( 'No matching code in this supplier profile.', 'supplier-price-import-for-woocommerce' ) );
				}
				$row['sku']                         = $row['mapping']['sku'] ?? $row['code'];
				$product                            = Catalog::target( $row['sku'], (int) ( $row['mapping']['product'] ?? 0 ) );
				$row['product']                     = $product->get_id();
				$found_targets[ $row['product'] ][] = $line;
				$row['name']                        = $product->get_name();
				$row['snapshot']                    = Catalog::snapshot( $product );
				$row['before']                      = $row['snapshot']['regular'];
				$row['after']                       = Input::price( Input::cell( $price_cell, false )[0], $profile['unit'], $base );
				$row['percent']                     = '0' === $row['before'] ? null : round( ( (float) $row['after'] / (float) $row['before'] - 1 ) * 100, 2 );
				Catalog::sale_guard( $row['snapshot'], $row['after'] );
				if ( $row['before'] === $row['after'] ) {
					$row = self::message( $row, 'same', __( 'The price is already current.', 'supplier-price-import-for-woocommerce' ) );
				} elseif ( $numeric || Catalog::high_change( $row['before'], $row['after'] ) ) {
					$row = self::message(
						$row,
						'manual',
						$numeric
						? __( 'Numeric Excel code. Verify the exact SKU and select this row manually.', 'supplier-price-import-for-woocommerce' )
						: __( 'Change of at least 30% or an existing zero price. Select manually.', 'supplier-price-import-for-woocommerce' )
					);
				} else {
					$row = self::message( $row, 'ready' );
				}
			} catch ( Problem $error ) {
				if ( '' === $row['code'] ) {
					$row['code'] = self::diagnostic_code( $code_cell );
				}
				$row = self::message( $row, 'invalid', $error->getMessage() );
			}
			$result[ $line ] = $row;
		}
		self::duplicates( $result, $found_targets, __( 'More than one input row targets this product.', 'supplier-price-import-for-woocommerce' ) );
		self::duplicates( $result, $found_codes, __( 'This supplier code occurs more than once in the file.', 'supplier-price-import-for-woocommerce' ) );
		if ( ! $result ) {
			throw new Problem( __( 'No data rows were found.', 'supplier-price-import-for-woocommerce' ) );
		}
		return self::preview_result(
			'price',
			array(
				'profile'  => $profile_id,
				'name'     => $profile['name'],
				'revision' => $profile['revision'],
				'unit'     => $base,
			),
			$result
		);
	}

	private static function diagnostic_code( mixed $cell ): string {
		try {
			return is_array( $cell ) && 'f' === $cell['type'] ? '' : Input::text( is_array( $cell ) ? $cell['value'] : $cell );
		} catch ( Problem $error ) {
			return '';
		}
	}

	private static function duplicates( array &$rows, array $groups, string $reason ): void {
		foreach ( $groups as $lines ) {
			if ( count( $lines ) > 1 ) {
				foreach ( $lines as $line ) {
					$rows[ $line ] = self::message( $rows[ $line ], 'invalid', $reason );
				}
			}
		}
	}

}
