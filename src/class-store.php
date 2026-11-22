<?php

namespace SupplierPriceImport;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-problem.php';

final class Store {

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'spifw_' . $name;
	}

	public static function activate( bool $network ): void {
		self::sites(
			$network,
			static function () {
				global $wpdb;
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				$collate = $wpdb->get_charset_collate();
				$maps    = self::table( 'maps' );
				$runs    = self::table( 'runs' );
				$rows    = self::table( 'rows' );

				dbDelta(
					"CREATE TABLE $maps (
				profile bigint unsigned NOT NULL,
				code varbinary(400) NOT NULL,
				product bigint unsigned NOT NULL,
				sku varchar(100) NOT NULL,
				revision char(32) NOT NULL,
				PRIMARY KEY  (profile,code)
			) ENGINE=InnoDB $collate;"
				);
				dbDelta(
					"CREATE TABLE $runs (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				token char(64) NOT NULL,
				owner bigint unsigned NOT NULL,
				kind varchar(12) NOT NULL,
				state varchar(12) NOT NULL,
				created bigint unsigned NOT NULL,
				finished bigint unsigned NOT NULL DEFAULT 0,
				detail longtext NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token (token),
				KEY state (state),
				KEY finished (finished)
			) ENGINE=InnoDB $collate;"
				);
				dbDelta(
					"CREATE TABLE $rows (
				run bigint unsigned NOT NULL,
				line int unsigned NOT NULL,
				state varchar(20) NOT NULL,
				detail longtext NOT NULL,
				PRIMARY KEY  (run,line),
				KEY run_state (run,state)
			) ENGINE=InnoDB $collate;"
				);
				foreach ( array( APPLY, MAP, HISTORY ) as $cap ) {
					get_role( 'administrator' )?->add_cap( $cap );
				}
				if ( ! wp_next_scheduled( 'spifw_cleanup' ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'spifw_cleanup' );
				}
			}
		);
	}

	private static function sites( bool $network, callable $action ): void {
		if ( ! $network || ! is_multisite() ) {
			$action();
			return;
		}
		$offset = 0;
		do {
			$ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 100,
					'offset' => $offset,
				)
			);
			foreach ( $ids as $id ) {
				switch_to_blog( (int) $id );
				try {
					$action();
				} finally {
					restore_current_blog();
				}
			}
			$offset += count( $ids );
		} while ( count( $ids ) === 100 );
	}

	private static function lock_key(): string {
		return hash( 'sha256', DB_NAME . ':' . self::table( 'runs' ) );
	}

	public static function locked( callable $operation ): mixed {
		global $wpdb;
		$key = self::lock_key();
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) ) ) {
			throw new Problem( __( 'Another request is processing changes. Refresh the operation status and retry.', 'supplier-price-import-for-woocommerce' ), 409 );
		}
		try {
			return $operation();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
		}
	}

	public static function check_lock(): void {
		global $wpdb;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', self::lock_key() ) ) ) {
			throw new Problem( __( 'The processing lock was lost. Review the operation before continuing.', 'supplier-price-import-for-woocommerce' ), 409 );
		}
	}

	public static function transaction( callable $action ): mixed {
		global $wpdb;
		self::assert_db( $wpdb->query( 'START TRANSACTION' ) );
		try {
			$result = $action();
			self::check_lock();
			self::assert_db( $wpdb->query( 'COMMIT' ) );
			return $result;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	public static function assert_db( mixed $result ): void {
		if ( false === $result ) {
			throw new Problem( __( 'Could not save or read the operation. Review its status before retrying.', 'supplier-price-import-for-woocommerce' ), 503 );
		}
	}

	public static function profiles(): array {
		return get_option( 'spifw_profiles', array() );
	}

	public static function profile( int $id ): array {
		$profiles = self::profiles();
		if ( ! isset( $profiles[ $id ] ) ) {
			throw new Problem( __( 'Select an existing supplier profile.', 'supplier-price-import-for-woocommerce' ) );
		}
		return $profiles[ $id ];
	}

	public static function mapping( int $profile, string $code ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT product,sku,revision FROM %i WHERE profile=%d AND code=%s', self::table( 'maps' ), $profile, $code ), ARRAY_A );
		self::assert_db( '' === $wpdb->last_error );
		return $row;
	}

	public static function preview( string $kind, array $detail, array $rows ): array {
		global $wpdb;
		return self::locked(
			static function () use ( $wpdb, $kind, $detail, $rows ) {
				return self::transaction(
					static function () use ( $wpdb, $kind, $detail, $rows ) {
						$token = bin2hex( random_bytes( 32 ) );
						self::assert_db(
							$wpdb->insert(
								self::table( 'runs' ),
								array(
									'token'   => hash( 'sha256', $token ),
									'owner'   => get_current_user_id(),
									'kind'    => $kind,
									'state'   => 'preview',
									'created' => time(),
									'detail'  => wp_json_encode( $detail ),
								)
							)
						);
						$id = (int) $wpdb->insert_id;
						foreach ( array_chunk( $rows, 100, true ) as $chunk ) {
							$values = array();
							foreach ( $chunk as $line => $row ) {
								array_push( $values, $id, $line, $row['state'], wp_json_encode( $row ) );
							}
							$slots = implode( ',', array_fill( 0, count( $chunk ), '(%d,%d,%s,%s)' ) );
							// Placeholders are generated from the bounded chunk size.
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							self::assert_db( $wpdb->query( $wpdb->prepare( "INSERT INTO %i (run,line,state,detail) VALUES $slots", array_merge( array( self::table( 'rows' ) ), $values ) ) ) );
						}
						return array(
							'id'    => $id,
							'token' => $token,
						);
					}
				);
			}
		);
	}

}
