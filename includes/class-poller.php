<?php
/**
 * Background poller for WDK USDt payments.
 *
 * Runs as a recurring Action Scheduler action. For each pending order:
 *   1. Hit the WDK Indexer's token-transfers endpoint
 *   2. Find a matching incoming transfer
 *   3. Compute confirmations from the chain head
 *   4. Advance the order if confirmations >= required
 *   5. Reschedule itself with backoff, or stop if terminal
 *
 * @package WC_WDK_Payments
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wc_wdk_payments_poll_order', [ WDK_Poller::class, 'run' ], 10, 1 );

final class WDK_Poller {

	/** Per-chain confirmation depth. */
	private const CONFIRMATIONS_REQUIRED = [
		'tron'     => 19,
		'polygon'  => 64,
		'arbitrum' => 1,
		'ethereum' => 12,
		'sepolia'  => 3,
	];

	/** Default RPC endpoints used to fetch the current chain head. */
	private const RPC_ENDPOINTS = [
		'tron'     => 'https://api.trongrid.io',
		'polygon'  => 'https://polygon-rpc.com',
		'arbitrum' => 'https://arb1.arbitrum.io/rpc',
		'ethereum' => 'https://eth.drpc.org',
		'sepolia'  => 'https://ethereum-sepolia.publicnode.com',
	];

	/**
	 * Cadence schedule. Returns the next-poll delay in seconds given the
	 * elapsed time since order creation. Returns null to stop polling.
	 */
	private static function next_delay( int $elapsed_seconds ): ?int {
		if ( $elapsed_seconds < 120 )    { return 5;   }  // first 2 min
		if ( $elapsed_seconds < 600 )    { return 15;  }  // 2-10 min
		if ( $elapsed_seconds < 3600 )   { return 60;  }  // 10-60 min
		if ( $elapsed_seconds < 86400 )  { return 300; }  // 1-24 hr
		return null;                                       // auto-cancel
	}

	public static function run( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! in_array( $order->get_status(), [ 'pending', 'on-hold' ], true ) ) {
			return;
		}

		$chain           = (string) $order->get_meta( '_wdk_chain' );
		$deposit_address = (string) $order->get_meta( '_wdk_deposit_address' );
		$expected_amount = (string) $order->get_meta( '_wdk_expected_amount_base_units' );

		if ( $chain === '' || $deposit_address === '' || $expected_amount === '' ) {
			$order->update_status( 'failed', __( 'Missing payment meta.', 'wc-wdk-payments' ) );
			return;
		}

		$settings = get_option( 'woocommerce_wdk_usdt_settings', [] );
		$api_key  = $settings['indexer_api_key'] ?? '';
		if ( $api_key === '' ) {
			$order->add_order_note( __( 'WDK Indexer API key is not configured.', 'wc-wdk-payments' ) );
			self::reschedule( $order );
			return;
		}

		$indexer  = new WDK_Indexer_Client( $api_key );
		$transfer = $indexer->find_matching_transfer(
			$chain,
			$deposit_address,
			$expected_amount,
			(int) $order->get_date_created()->getTimestamp()
		);

		if ( $transfer === null ) {
			self::reschedule( $order );
			return;
		}

		$order->update_meta_data( '_wdk_tx_hash',      $transfer['transactionHash'] );
		$order->update_meta_data( '_wdk_from_address', $transfer['from'] );
		$order->save();

		$confirmations = self::compute_confirmations( $chain, (int) $transfer['blockNumber'] );
		$required      = self::CONFIRMATIONS_REQUIRED[ $chain ] ?? 12;

		if ( $confirmations === null ) {
			if ( $order->get_status() === 'pending' ) {
				$order->update_status(
					'on-hold',
					sprintf(
						/* translators: %s is a transaction hash */
						__( 'Transfer seen on-chain (tx: %s). Awaiting confirmations.', 'wc-wdk-payments' ),
						$transfer['transactionHash']
					)
				);
			}
			self::reschedule( $order );
			return;
		}

		if ( $confirmations < $required ) {
			if ( $order->get_status() === 'pending' ) {
				$order->update_status(
					'on-hold',
					sprintf(
						/* translators: 1: current confirmations, 2: required confirmations */
						__( 'Transfer seen on-chain. %1$d / %2$d confirmations.', 'wc-wdk-payments' ),
						$confirmations,
						$required
					)
				);
			}
			self::reschedule( $order );
			return;
		}

		$order->payment_complete( $transfer['transactionHash'] );
		$order->add_order_note(
			sprintf(
				/* translators: 1: tx hash, 2: from address */
				__( 'USDt payment confirmed. Tx: %1$s, from: %2$s', 'wc-wdk-payments' ),
				$transfer['transactionHash'],
				$transfer['from']
			)
		);
	}

	private static function reschedule( WC_Order $order ): void {
		$elapsed = time() - (int) $order->get_date_created()->getTimestamp();
		$delay   = self::next_delay( $elapsed );

		if ( $delay === null ) {
			$order->update_status( 'cancelled', __( 'Payment window expired (24h).', 'wc-wdk-payments' ) );
			return;
		}

		as_schedule_single_action(
			time() + $delay,
			'wc_wdk_payments_poll_order',
			[ 'order_id' => $order->get_id() ],
			'wc-wdk-payments'
		);
	}

	private static function compute_confirmations( string $chain, int $tx_block ): ?int {
		$rpc = self::RPC_ENDPOINTS[ $chain ] ?? null;
		if ( ! $rpc ) {
			return null;
		}
		$head = self::fetch_chain_head( $chain, $rpc );
		if ( $head === null ) {
			return null;
		}
		return max( 0, $head - $tx_block );
	}

	private static function fetch_chain_head( string $chain, string $rpc ): ?int {
		// TODO M2: per-chain HEAD fetch.
		//   EVM:  POST {"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}
		//   Tron: GET  $rpc/wallet/getnowblock
		return null;
	}
}
