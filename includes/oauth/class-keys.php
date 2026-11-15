<?php
namespace MD3\WPMCP\OAuth;

use Defuse\Crypto\Key;
use League\OAuth2\Server\CryptKey;

defined( 'ABSPATH' ) || exit;

/**
 * The RSA signing key pair and the encryption key for codes and refresh tokens.
 *
 * wp-config constants take priority. Otherwise the keys are generated on first
 * use and kept in options that are never autoloaded.
 */
final class Keys {

	const OPT_PRIVATE    = 'md3_wp_mcp_private_key';
	const OPT_ENCRYPTION = 'md3_wp_mcp_encryption_key';

	const CONST_PRIVATE    = 'MD3_WP_MCP_PRIVATE_KEY';
	const CONST_ENCRYPTION = 'MD3_WP_MCP_ENCRYPTION_KEY';

	/**
	 * @return array{private: CryptKey, public: CryptKey, encryption: string}
	 * @throws \RuntimeException When a configured or stored private key can't be read.
	 */
	public static function get(): array {
		$private_pem = self::private_pem();

		$resource = openssl_pkey_get_private( $private_pem );
		$details  = $resource ? openssl_pkey_get_details( $resource ) : false;
		if ( ! $details || OPENSSL_KEYTYPE_RSA !== $details['type'] ) {
			throw new \RuntimeException( 'MD3 WP MCP: the OAuth private key is not a readable RSA key.' );
		}

		// The keys are in memory, so league's file-permission check doesn't apply.
		return [
			'private'    => new CryptKey( $private_pem, null, false ),
			'public'     => new CryptKey( $details['key'], null, false ),
			'encryption' => self::encryption_key(),
		];
	}

	/**
	 * Whether a key is set in wp-config.php, so rotate() can't change it.
	 */
	public static function pinned(): bool {
		return '' !== self::constant( self::CONST_PRIVATE ) || '' !== self::constant( self::CONST_ENCRYPTION );
	}

	/**
	 * Replaces the stored keys (those not pinned by constants) and revokes
	 * every connection.
	 */
	public static function rotate(): void {
		if ( ! self::constant( self::CONST_PRIVATE ) ) {
			delete_option( self::OPT_PRIVATE );
		}
		if ( ! self::constant( self::CONST_ENCRYPTION ) ) {
			delete_option( self::OPT_ENCRYPTION );
		}
		Tokens::revoke_all();
	}

	/**
	 * PEM of the private key: the constant (a PEM string or a file path), else
	 * the stored option, else a newly generated RSA 2048 key.
	 */
	private static function private_pem(): string {
		$configured = self::constant( self::CONST_PRIVATE );
		if ( '' !== $configured ) {
			if ( ! str_contains( $configured, '-----BEGIN' ) && is_readable( $configured ) ) {
				return (string) file_get_contents( $configured );
			}
			return $configured;
		}

		return self::stored(
			self::OPT_PRIVATE,
			static function (): string {
				$key = openssl_pkey_new(
					[
						'private_key_bits' => 2048,
						'private_key_type' => OPENSSL_KEYTYPE_RSA,
					]
				);
				if ( ! $key || ! openssl_pkey_export( $key, $pem ) ) {
					throw new \RuntimeException( 'MD3 WP MCP: could not generate the OAuth private key.' );
				}
				return $pem;
			}
		);
	}

	/**
	 * Defuse ASCII-safe key: the constant, else the stored option, else new.
	 */
	private static function encryption_key(): string {
		$configured = self::constant( self::CONST_ENCRYPTION );
		if ( '' !== $configured ) {
			return $configured;
		}

		return self::stored(
			self::OPT_ENCRYPTION,
			static fn (): string => Key::createNewRandomKey()->saveToAsciiSafeString()
		);
	}

	/**
	 * Reads a key option, generating and storing it (autoload off) when missing.
	 *
	 * Concurrent first requests must converge on one key. `add_option` would
	 * overwrite a key another request just stored (INSERT … ON DUPLICATE KEY
	 * UPDATE) and cache its own, so the key is inserted only if absent and the
	 * stored winner is read back from the database.
	 *
	 * @param callable(): string $generate Key generator.
	 * @throws \RuntimeException When no key could be stored.
	 */
	private static function stored( string $option, callable $generate ): string {
		global $wpdb;

		$value = get_option( $option, '' );
		if ( is_string( $value ) && '' !== $value ) {
			return $value;
		}

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				$option,
				$generate()
			)
		);

		wp_cache_delete( $option, 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
			unset( $notoptions[ $option ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}

		$stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		if ( '' === $stored ) {
			throw new \RuntimeException( 'MD3 WP MCP: could not store the OAuth keys.' );
		}

		return $stored;
	}

	private static function constant( string $name ): string {
		return defined( $name ) && is_string( constant( $name ) ) ? trim( constant( $name ) ) : '';
	}
}
