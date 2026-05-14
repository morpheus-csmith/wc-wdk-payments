<?php
/**
 * Thin PHP client for the WDK Indexer REST API.
 *
 * Endpoints used:
 *   GET  /api/v1/{blockchain}/{token}/{address}/token-transfers
 *   POST /api/v1/batch/token-transfers
 *
 * Auth via `x-api-key` header.
 *
 * @package WC_WDK_Payments
 *
 * @see https://docs.wallet.tether.io/tools/indexer-api
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WDK_Indexer_Client {

	private const BASE_URL = 'https://wdk-api.tether.io';
	private const TOKEN    = 'usdt';

	public function __construct( private string $api_key, private int $timeout = 10 ) {}

	/**
	 * Find a single incoming transfer matching the order's expectations.
	 *
	 * @return array{
	 *     blockchain: string,
	 *     blockNumber: int,
	 *     transactionHash: string,
	 *     token: string,
	 *     amount: string,
	 *     timestamp: int,
	 *     from: string,
	 *     to: string,
	 * }|null
	 */
	public function find_matching_transfer(
		string $chain,
		string $address,
		string $expected_amount,
		int $not_before_ts
	): ?array {
		$transfers = $this->get_token_transfers( $chain, $address, [
			'limit'  => 25,
			'fromTs' => $not_before_ts,
		] );

		foreach ( $transfers as $t ) {
			if ( $this->matches( $t, $address, $expected_amount, $not_before_ts ) ) {
				return $t;
			}
		}
		return null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_token_transfers( string $chain, string $address, array $opts = [] ): array {
		$path = sprintf(
			'/api/v1/%s/%s/%s/token-transfers',
			rawurlencode( $chain ),
			rawurlencode( self::TOKEN ),
			rawurlencode( $address )
		);
		$response = $this->request( 'GET', $path, $opts );
		if ( $response === null ) {
			return [];
		}
		return $response['transfers'] ?? [];
	}

	/**
	 * @param array<int, array{blockchain: string, token?: string, address: string, limit?: int, fromTs?: int}> $queries
	 * @return array<int, array<string, mixed>>
	 */
	public function batch_token_transfers( array $queries ): array {
		$queries = array_map(
			static fn( $q ) => array_merge( [ 'token' => self::TOKEN ], $q ),
			$queries
		);
		$response = $this->request( 'POST', '/api/v1/batch/token-transfers', $queries );
		return $response ?? [];
	}

	// ------------------------------------------------------------------------

	private function matches(
		array $transfer,
		string $address,
		string $expected_amount,
		int $not_before_ts
	): bool {
		// Address comparison: TRC-20 is case-sensitive; EVM normalized by indexer.
		if ( ( $transfer['to'] ?? '' ) !== $address ) {
			if ( strcasecmp( (string) ( $transfer['to'] ?? '' ), $address ) !== 0 ) {
				return false;
			}
		}
		if ( (string) ( $transfer['amount'] ?? '' ) !== $expected_amount ) {
			return false;
		}
		if ( (int) ( $transfer['timestamp'] ?? 0 ) < $not_before_ts ) {
			return false;
		}
		return true;
	}

	private function request( string $method, string $path, array $body_or_query = [] ): ?array {
		$url  = self::BASE_URL . $path;
		$args = [
			'method'  => $method,
			'timeout' => $this->timeout,
			'headers' => [
				'x-api-key' => $this->api_key,
				'Accept'    => 'application/json',
			],
		];

		if ( $method === 'GET' && ! empty( $body_or_query ) ) {
			$url = add_query_arg( $body_or_query, $url );
		} elseif ( $method === 'POST' ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body_or_query );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			error_log( '[wc-wdk-payments] indexer error: ' . $response->get_error_message() );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			error_log( "[wc-wdk-payments] indexer HTTP {$code}: " . wp_remote_retrieve_body( $response ) );
			return null;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
