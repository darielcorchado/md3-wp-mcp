<?php
namespace MD3\WPMCP\OAuth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use MD3\WPMCP\OAuth\Cimd;
use MD3\WPMCP\OAuth\Entities\Client_Entity;
use MD3\WPMCP\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * DCR clients from the database; CIMD clients from their metadata document
 * (cached a day), with the row saved on approval as the fallback when the
 * document can't be fetched.
 */
final class Client_Repository implements ClientRepositoryInterface {

	public function getClientEntity( string $client_id ): ?ClientEntityInterface {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT client_id, name, redirect_uris, type FROM ' . Schema::table( 'clients' ) . ' WHERE client_id = %s', $client_id )
		);

		// A stored CIMD row is a snapshot: the document may since have changed
		// its redirect URIs, so it wins whenever it can be read.
		$doc = ( ! $row || 'cimd' === $row->type ) ? Cimd::resolve( $client_id ) : null;

		if ( null === $doc && $row ) {
			$uris = json_decode( (string) $row->redirect_uris, true );
			return new Client_Entity( $row->client_id, (string) $row->name, is_array( $uris ) ? array_filter( $uris, 'is_string' ) : [] );
		}
		if ( null === $doc ) {
			return null;
		}

		return new Client_Entity( $doc['client_id'], $doc['client_name'], $doc['redirect_uris'] );
	}

	/**
	 * Every client is public: it is valid when it exists and uses one of the
	 * two supported grants. There is no secret to check.
	 */
	public function validateClient( string $client_id, ?string $client_secret, ?string $grant_type ): bool {
		if ( ! in_array( $grant_type, Client_Entity::GRANT_TYPES, true ) ) {
			return false;
		}
		return null !== $this->getClientEntity( $client_id );
	}
}
