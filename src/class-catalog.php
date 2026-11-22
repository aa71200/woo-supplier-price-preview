<?php

namespace SupplierPriceImport;

defined( 'ABSPATH' ) || exit;


final class Catalog {

	public static function target( string $sku, int $expected = 0 ): \WC_Product {
		global $wpdb;
		if ( 1 !== preg_match( '/\A.{1,100}\z/usD', $sku ) ) {
			throw new Problem( __( 'The store SKU exceeds the supported length.', 'supplier-price-import-for-woocommerce' ) );
		}
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM %i AS l INNER JOIN %i AS p ON l.product_id=p.ID
			WHERE l.sku=%s AND BINARY l.sku=BINARY %s AND p.post_status<>'trash'
			AND p.post_type IN ('product','product_variation') LIMIT 2",
				$wpdb->wc_product_meta_lookup,
				$wpdb->posts,
				$sku,
				$sku
			)
		);
		Store::assert_db( '' === $wpdb->last_error );
		if ( 1 !== count( $ids ) ) {
			throw new Problem(
				count( $ids )
				? __( 'Duplicate exact SKU in the catalog.', 'supplier-price-import-for-woocommerce' )
				: __( 'No product with this exact SKU.', 'supplier-price-import-for-woocommerce' )
			);
		}
		$id = (int) $ids[0];
		if ( $expected && $id !== $expected ) {
			throw new Problem( __( 'The mapped product ID and SKU no longer match.', 'supplier-price-import-for-woocommerce' ) );
		}

		$product = wc_get_product( $id );
		if ( ! $product || $product->get_sku( 'edit' ) !== $sku || ! $product->is_type( array( 'simple', 'variation' ) )
			|| 'publish' !== $product->get_status( 'edit' ) || '' === $product->get_regular_price( 'edit' ) ) {
			throw new Problem( __( 'The product type, SKU, status or regular price is ineligible.', 'supplier-price-import-for-woocommerce' ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			throw new Problem( __( 'You cannot edit the matched product.', 'supplier-price-import-for-woocommerce' ), 403 );
		}
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $product->variation_is_active() || ! $parent || ! $parent->is_type( 'variable' ) || 'publish' !== $parent->get_status( 'edit' ) ) {
				throw new Problem( __( 'The variation or its parent is inactive.', 'supplier-price-import-for-woocommerce' ) );
			}
			if ( ! current_user_can( 'edit_post', $parent->get_id() ) ) {
				throw new Problem( __( 'You cannot edit the variation parent.', 'supplier-price-import-for-woocommerce' ), 403 );
			}
		}
		return $product;
	}

	public static function decimal( string $price ): string {
		if ( ! preg_match( '/\A([0-9]{1,15})(?:\.([0-9]{1,30}))?\z/D', $price, $match ) ) {
			throw new Problem( __( 'The existing WooCommerce price is invalid or outside the supported range.', 'supplier-price-import-for-woocommerce' ) );
		}
		$whole    = ltrim( $match[1], '0' );
		$fraction = rtrim( $match[2] ?? '', '0' );
		return ( '' === $whole ? '0' : $whole ) . ( '' === $fraction ? '' : '.' . $fraction );
	}

	private static function scaled( string $price, int $multiplier ): string {
		$parts  = explode( '.', $price, 2 );
		$digits = $parts[0] . str_pad( $parts[1] ?? '', 30, '0' );
		$carry  = 0;
		$result = '';
		for ( $i = strlen( $digits ) - 1; $i >= 0; --$i ) {
			$value  = (int) $digits[ $i ] * $multiplier + $carry;
			$result = ( $value % 10 ) . $result;
			$carry  = intdiv( $value, 10 );
		}
		return ltrim( $carry . $result, '0' );
	}

	private static function compare( string $left, string $right ): int {
		$lengths = strlen( $left ) <=> strlen( $right );
		return $lengths ? $lengths : strcmp( $left, $right );
	}

	public static function high_change( string $old, string $target_price ): bool {
		$next = self::scaled( $target_price, 10 );
		return '0' === $old || self::compare( $next, self::scaled( $old, 13 ) ) >= 0
			|| self::compare( $next, self::scaled( $old, 7 ) ) <= 0;
	}

	public static function snapshot( \WC_Product $product ): array {
		$sale = $product->get_sale_price( 'edit' );
		return array(
			'regular' => self::decimal( $product->get_regular_price( 'edit' ) ),
			'sale'    => '' === $sale ? '' : self::decimal( $sale ),
			'from'    => $product->get_date_on_sale_from( 'edit' )?->getTimestamp(),
			'to'      => $product->get_date_on_sale_to( 'edit' )?->getTimestamp(),
			'parent'  => $product->get_parent_id( 'edit' ),
			'type'    => $product->get_type(),
		);
	}

	public static function sale_guard( array $snapshot, string $target_price ): void {
		if ( '' !== $snapshot['sale'] && self::compare( self::scaled( $snapshot['sale'], 1 ), self::scaled( $target_price, 1 ) ) >= 0 ) {
			throw new Problem( __( 'The proposed regular price is at or below the stored sale price.', 'supplier-price-import-for-woocommerce' ) );
		}
	}

}
