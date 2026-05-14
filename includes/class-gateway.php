<?php
/**
 * WDK USDt payment gateway for WooCommerce.
 *
 * Implements the WC_Payment_Gateway base class to add a self-custodial
 * USDt payment method at checkout. On order placement, generates a
 * deposit address + payment URI, redirects the customer to a payment
 * page, and schedules a background poller against the WDK Indexer.
 *
 * @package WC_WDK_Payments
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Gateway_WDK_USDt extends WC_Payment_Gateway {

	public const GATEWAY_ID = 'wdk_usdt';

	private const META_DEPOSIT_ADDRESS = '_wdk_deposit_address';
	private const META_EXPECTED_AMOUNT = '_wdk_expected_amount_base_units';
	private const META_CHAIN           = '_wdk_chain';
	private const META_TX_HASH         = '_wdk_tx_hash';
	private const META_FROM_ADDRESS    = '_wdk_from_address';

	/**
	 * Map of chain id => human label. Mirrors the WDK Indexer's supported chains.
	 *
	 * @var array<string, string>
	 */
	private array $supported_chains = [
		'tron'     => 'Tron (TRC-20)',
		'polygon'  => 'Polygon (ERC-20)',
		'arbitrum' => 'Arbitrum',
		'ethereum' => 'Ethereum',
		'sepolia'  => 'Sepolia (testnet)',
	];

	public function __construct() {
		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'WDK - Pay with USDt', 'wc-wdk-payments' );
		$this->method_description = __(
			'Accept USDt payments directly to your own wallet via the Wallet Development Kit. Self-custodial: funds settle on-chain to addresses you control.',
			'wc-wdk-payments'
		);
		$this->has_fields = false;

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Pay with USDt', 'wc-wdk-payments' ) );
		$this->description = $this->get_option( 'description', '' );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			[ $this, 'process_admin_options' ]
		);

		add_action( 'init', [ $this, 'register_endpoints' ] );
		add_action( 'template_redirect', [ $this, 'maybe_render_payment_page' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		add_action( 'woocommerce_order_status_pending', [ $this, 'schedule_initial_poll' ], 10, 2 );
	}

	// ------------------------------------------------------------------------
	// Settings UI
	// ------------------------------------------------------------------------

	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled' => [
				'title'   => __( 'Enable / Disable', 'wc-wdk-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable WDK USDt payments', 'wc-wdk-payments' ),
				'default' => 'no',
			],
			'title' => [
				'title'       => __( 'Title shown at checkout', 'wc-wdk-payments' ),
				'type'        => 'text',
				'default'     => __( 'Pay with USDt', 'wc-wdk-payments' ),
				'desc_tip'    => true,
				'description' => __( 'Label that customers see in the checkout payment list.', 'wc-wdk-payments' ),
			],
			'description' => [
				'title'   => __( 'Description shown at checkout', 'wc-wdk-payments' ),
				'type'    => 'textarea',
				'default' => __( 'Pay with USDt from your self-custodial wallet. No accounts, no chargebacks.', 'wc-wdk-payments' ),
			],
			'indexer_api_key' => [
				'title'       => __( 'WDK Indexer API key', 'wc-wdk-payments' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s is a URL */
					__( 'Request a key at <a href="%s" target="_blank" rel="noopener">wdk-api.tether.io/register</a>.', 'wc-wdk-payments' ),
					'https://wdk-api.tether.io/register'
				),
			],
			'address_strategy' => [
				'title'       => __( 'Address strategy', 'wc-wdk-payments' ),
				'type'        => 'select',
				'default'     => 'static',
				'options'     => [
					'static' => __( 'Static address per chain (simplest)', 'wc-wdk-payments' ),
					'xpub'   => __( 'Derived per-order addresses from xpub', 'wc-wdk-payments' ),
				],
				'description' => __( 'Static is simpler; xpub gives a fresh address per order for privacy. See the README.', 'wc-wdk-payments' ),
			],
			'address_tron'     => [ 'title' => __( 'Tron receive address', 'wc-wdk-payments' ),     'type' => 'text', 'default' => '' ],
			'address_polygon'  => [ 'title' => __( 'Polygon receive address', 'wc-wdk-payments' ),  'type' => 'text', 'default' => '' ],
			'address_arbitrum' => [ 'title' => __( 'Arbitrum receive address', 'wc-wdk-payments' ), 'type' => 'text', 'default' => '' ],
			'address_ethereum' => [ 'title' => __( 'Ethereum receive address', 'wc-wdk-payments' ), 'type' => 'text', 'default' => '' ],
			'xpub' => [
				'title'       => __( 'BIP-32 xpub (Strategy B only)', 'wc-wdk-payments' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Extended public key exported from your WDK wallet. Public-only - the plugin cannot spend.', 'wc-wdk-payments' ),
			],
			'enabled_chains' => [
				'title'   => __( 'Enabled chains', 'wc-wdk-payments' ),
				'type'    => 'multiselect',
				'options' => $this->supported_chains,
				'default' => [ 'tron', 'polygon' ],
				'class'   => 'wc-enhanced-select',
			],
		];
	}

	// ------------------------------------------------------------------------
	// Checkout flow
	// ------------------------------------------------------------------------

	public function is_available(): bool {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		return $this->has_at_least_one_configured_chain();
	}

	public function payment_fields(): void {
		if ( $this->description ) {
			echo wpautop( wp_kses_post( $this->description ) );
		}

		$enabled = (array) $this->get_option( 'enabled_chains', [] );
		if ( empty( $enabled ) ) {
			return;
		}

		echo '<p><label for="wdk_chain"><strong>' .
			esc_html__( 'Network', 'wc-wdk-payments' ) .
			'</strong></label></p>';
		echo '<select name="wdk_chain" id="wdk_chain" class="wc-enhanced-select">';
		foreach ( $enabled as $chain ) {
			$label = $this->supported_chains[ $chain ] ?? $chain;
			printf(
				'<option value="%s">%s</option>',
				esc_attr( $chain ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * @return array{result: string, redirect: string}
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			throw new Exception( esc_html__( 'Order not found.', 'wc-wdk-payments' ) );
		}

		$chain = isset( $_POST['wdk_chain'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['wdk_chain'] ) )
			: '';

		if ( ! array_key_exists( $chain, $this->supported_chains ) ) {
			wc_add_notice( __( 'Please select a valid network.', 'wc-wdk-payments' ), 'error' );
			return [ 'result' => 'failure', 'redirect' => '' ];
		}

		// USD to USDt (6 decimals). Real impl uses a price oracle for non-USD stores.
		$base_amount = (int) round( (float) $order->get_total() * 1_000_000 );

		// TODO M2: WDK_Address_Resolver class for Strategy A/B logic.
		$deposit_address = (string) $this->get_option( 'address_' . $chain, '' );
		$expected_amount = (string) $base_amount;

		$order->update_meta_data( self::META_CHAIN,           $chain );
		$order->update_meta_data( self::META_DEPOSIT_ADDRESS, $deposit_address );
		$order->update_meta_data( self::META_EXPECTED_AMOUNT, $expected_amount );
		$order->update_status(
			'pending',
			__( 'Awaiting on-chain USDt payment.', 'wc-wdk-payments' )
		);
		$order->save();

		WC()->cart->empty_cart();

		return [
			'result'   => 'success',
			'redirect' => $this->get_payment_page_url( $order ),
		];
	}

	// ------------------------------------------------------------------------
	// Payment page + polling
	// ------------------------------------------------------------------------

	public function register_endpoints(): void {
		add_rewrite_endpoint( 'wdk-pay', EP_ROOT | EP_PAGES );
	}

	public function maybe_render_payment_page(): void {
		// TODO M2: load templates/payment-page.php for matching /wdk-pay/{order}/ URLs.
	}

	public function register_rest_routes(): void {
		register_rest_route(
			'wc-wdk/v1',
			'/orders/(?P<id>\d+)/status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_order_status' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'id'  => [ 'validate_callback' => 'is_numeric' ],
					'key' => [ 'sanitize_callback' => 'sanitize_text_field' ],
				],
			]
		);
	}

	public function rest_order_status( WP_REST_Request $request ): WP_REST_Response {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order || $order->get_order_key() !== $request->get_param( 'key' ) ) {
			return new WP_REST_Response( [ 'error' => 'not_found' ], 404 );
		}
		return new WP_REST_Response( [ 'status' => $order->get_status() ], 200 );
	}

	// ------------------------------------------------------------------------
	// Background poller orchestration
	// ------------------------------------------------------------------------

	public function schedule_initial_poll( int $order_id, WC_Order $order ): void {
		if ( $order->get_payment_method() !== self::GATEWAY_ID ) {
			return;
		}
		as_schedule_single_action(
			time() + 5,
			'wc_wdk_payments_poll_order',
			[ 'order_id' => $order_id ],
			'wc-wdk-payments'
		);
	}

	// ------------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------------

	private function has_at_least_one_configured_chain(): bool {
		$enabled = (array) $this->get_option( 'enabled_chains', [] );
		foreach ( $enabled as $chain ) {
			$address = $this->get_option( 'address_' . $chain, '' );
			if ( ! empty( $address ) ) {
				return true;
			}
		}
		return ! empty( $this->get_option( 'xpub' ) );
	}

	private function get_payment_page_url( WC_Order $order ): string {
		return add_query_arg(
			[ 'key' => $order->get_order_key() ],
			home_url( '/wdk-pay/' . $order->get_id() . '/' )
		);
	}
}
