<?php
/**
 * BanglaPay_Gateway_BKash — WooCommerce payment gateway for bKash.
 *
 * Flow:
 *  1. process_payment()  → grant token → create payment → redirect user to bKash URL.
 *  2. User completes payment on bKash.
 *  3. bKash redirects back to our callback URL.
 *  4. handle_callback() → execute payment → update order.
 *
 * @package BanglaPay_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BanglaPay_Gateway_BKash extends WC_Payment_Gateway {

    // bKash API base URLs.
    const SANDBOX_BASE = 'https://tokenized.sandbox.bka.sh/v1.2.0-beta';
    const LIVE_BASE    = 'https://tokenized.pay.bka.sh/v1.2.0-beta';

    /** @var BanglaPay_API_Handler */
    private BanglaPay_API_Handler $api;

    public function __construct() {
        $this->id                 = 'banglapay_bkash';
        $this->icon               = BANGLAPAY_PLUGIN_URL . 'assets/images/bkash.png';
        $this->has_fields         = false;
        $this->method_title       = __( 'bKash', 'banglapay-gateway' );
        $this->method_description = __( 'Accept payments via bKash mobile banking.', 'banglapay-gateway' );
        $this->supports           = [ 'products' ];

        $this->init_form_fields();
        $this->init_settings();

        // Map settings to properties.
        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );

        $this->api = new BanglaPay_API_Handler();

        // Save admin settings.
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );

        // Handle payment callback.
        add_action( 'woocommerce_api_' . $this->id, [ $this, 'handle_callback' ] );
    }

    // =========================================================================
    // Admin form fields
    // =========================================================================

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled'     => [
                'title'   => __( 'Enable/Disable', 'banglapay-gateway' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable bKash Payment Gateway', 'banglapay-gateway' ),
                'default' => 'no',
            ],
            'title'       => [
                'title'       => __( 'Title', 'banglapay-gateway' ),
                'type'        => 'text',
                'description' => __( 'Displayed to customer at checkout.', 'banglapay-gateway' ),
                'default'     => __( 'bKash', 'banglapay-gateway' ),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'   => __( 'Description', 'banglapay-gateway' ),
                'type'    => 'textarea',
                'default' => __( 'Pay securely via bKash mobile banking.', 'banglapay-gateway' ),
            ],
            'sandbox'     => [
                'title'   => __( 'Sandbox Mode', 'banglapay-gateway' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable sandbox (test) mode', 'banglapay-gateway' ),
                'default' => 'yes',
            ],
            'app_key'     => [
                'title'       => __( 'App Key', 'banglapay-gateway' ),
                'type'        => 'text',
                'description' => __( 'Your bKash App Key.', 'banglapay-gateway' ),
                'desc_tip'    => true,
            ],
            'app_secret'  => [
                'title'       => __( 'App Secret', 'banglapay-gateway' ),
                'type'        => 'password',
                'description' => __( 'Your bKash App Secret.', 'banglapay-gateway' ),
                'desc_tip'    => true,
            ],
            'username'    => [
                'title'    => __( 'Username', 'banglapay-gateway' ),
                'type'     => 'text',
                'desc_tip' => true,
            ],
            'password'    => [
                'title'    => __( 'Password', 'banglapay-gateway' ),
                'type'     => 'password',
                'desc_tip' => true,
            ],
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function is_sandbox(): bool {
        return 'yes' === $this->get_option( 'sandbox' );
    }

    private function base_url(): string {
        return $this->is_sandbox() ? self::SANDBOX_BASE : self::LIVE_BASE;
    }

    private function credentials(): array {
        return [
            'app_key'    => $this->get_option( 'app_key' ),
            'app_secret' => $this->get_option( 'app_secret' ),
            'username'   => $this->get_option( 'username' ),
            'password'   => $this->get_option( 'password' ),
        ];
    }

    // =========================================================================
    // Step 1: Grant Token
    // =========================================================================

    private function grant_token(): string|WP_Error {
        $creds = $this->credentials();

        $response = $this->api->post(
            $this->base_url() . '/tokenized/checkout/token/grant',
            [
                'app_key'    => $creds['app_key'],
                'app_secret' => $creds['app_secret'],
            ],
            [
                'username' => $creds['username'],
                'password' => $creds['password'],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response['id_token'] ) ) {
            return new WP_Error( 'bkash_token_error', __( 'bKash token grant failed.', 'banglapay-gateway' ) );
        }

        return $response['id_token'];
    }

    // =========================================================================
    // Step 2: Create Payment
    // =========================================================================

    private function create_payment( string $token, WC_Order $order ): array|WP_Error {
        $creds    = $this->credentials();
        $callback = WC()->api_request_url( $this->id );

        $body = [
            'mode'                    => '0011',
            'payerReference'          => (string) $order->get_id(),
            'callbackURL'             => $callback,
            'merchantAssociationInfo' => 'MI05MID54RF09123456One',
            'amount'                  => number_format( (float) $order->get_total(), 2, '.', '' ),
            'currency'                => 'BDT',
            'intent'                  => 'sale',
            'merchantInvoiceNumber'   => banglapay_generate_transaction_ref( $order->get_id() ),
        ];

        return $this->api->post(
            $this->base_url() . '/tokenized/checkout/create',
            $body,
            [
                'Authorization' => $token,
                'X-APP-Key'     => $creds['app_key'],
            ]
        );
    }

    // =========================================================================
    // Step 3: Execute Payment (called on callback)
    // =========================================================================

    private function execute_payment( string $token, string $payment_id ): array|WP_Error {
        $creds = $this->credentials();

        return $this->api->post(
            $this->base_url() . '/tokenized/checkout/execute',
            [ 'paymentID' => $payment_id ],
            [
                'Authorization' => $token,
                'X-APP-Key'     => $creds['app_key'],
            ]
        );
    }

    // =========================================================================
    // WooCommerce: process_payment
    // =========================================================================

    public function process_payment( $order_id ): array {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wc_add_notice( __( 'Order not found.', 'banglapay-gateway' ), 'error' );
            return [ 'result' => 'failure' ];
        }

        try {
            // 1. Grant token.
            $token = $this->grant_token();
            if ( is_wp_error( $token ) ) {
                throw new Exception( $token->get_error_message() );
            }

            // 2. Create payment.
            $payment = $this->create_payment( $token, $order );
            if ( is_wp_error( $payment ) ) {
                throw new Exception( $payment->get_error_message() );
            }

            if ( empty( $payment['bkashURL'] ) ) {
                throw new Exception( __( 'bKash did not return a payment URL.', 'banglapay-gateway' ) );
            }

            // Store token & paymentID for the callback.
            $order->update_meta_data( '_bkash_token',      sanitize_text_field( $token ) );
            $order->update_meta_data( '_bkash_payment_id', sanitize_text_field( $payment['paymentID'] ) );
            $order->save();

            banglapay_log( "bKash payment created for order #{$order_id}. PaymentID: {$payment['paymentID']}" );

            return [
                'result'   => 'success',
                'redirect' => $payment['bkashURL'],
            ];

        } catch ( Exception $e ) {
            banglapay_log( 'bKash process_payment error: ' . $e->getMessage(), 'ERROR' );
            wc_add_notice( __( 'Payment error: ', 'banglapay-gateway' ) . esc_html( $e->getMessage() ), 'error' );
            return [ 'result' => 'failure' ];
        }
    }

    // =========================================================================
    // Callback handler (WooCommerce API hook)
    // =========================================================================

    public function handle_callback(): void {
        // phpcs:disable WordPress.Security.NonceVerification
        $payment_id = isset( $_GET['paymentID'] ) ? sanitize_text_field( wp_unslash( $_GET['paymentID'] ) ) : '';
        $status     = isset( $_GET['status'] )    ? sanitize_text_field( wp_unslash( $_GET['status'] ) )    : '';
        $order_id   = isset( $_GET['merchantInvoiceNumber'] )
            ? absint( preg_replace( '/[^0-9]/', '', wp_unslash( $_GET['merchantInvoiceNumber'] ) ) )
            : 0;
        // phpcs:enable

        if ( ! $order_id || ! $payment_id ) {
            banglapay_log( 'bKash callback: missing paymentID or order ID.', 'ERROR' );
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            banglapay_log( "bKash callback: order #{$order_id} not found.", 'ERROR' );
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        if ( 'success' !== strtolower( $status ) ) {
            $order->update_status( 'failed', __( 'bKash payment failed or cancelled.', 'banglapay-gateway' ) );
            banglapay_log( "bKash payment failed for order #{$order_id}. Status: {$status}", 'ERROR' );
            wp_safe_redirect( $order->get_cancel_order_url() );
            exit;
        }

        try {
            $token = $order->get_meta( '_bkash_token' );
            if ( empty( $token ) ) {
                throw new Exception( 'bKash token not found in order meta.' );
            }

            $result = $this->execute_payment( $token, $payment_id );
            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }

            if ( empty( $result['transactionStatus'] ) || 'Completed' !== $result['transactionStatus'] ) {
                throw new Exception( 'Transaction not completed. Status: ' . ( $result['transactionStatus'] ?? 'unknown' ) );
            }

            // Success — complete the order.
            $order->payment_complete( sanitize_text_field( $result['trxID'] ?? $payment_id ) );
            $order->add_order_note(
                sprintf(
                    /* translators: %1$s: payment ID, %2$s: transaction ID */
                    __( 'bKash payment successful. PaymentID: %1$s | TrxID: %2$s', 'banglapay-gateway' ),
                    esc_html( $payment_id ),
                    esc_html( $result['trxID'] ?? '' )
                )
            );
            $order->delete_meta_data( '_bkash_token' );
            $order->save();

            banglapay_log( "bKash payment completed for order #{$order_id}. TrxID: {$result['trxID']}" );

            wp_safe_redirect( $this->get_return_url( $order ) );
            exit;

        } catch ( Exception $e ) {
            $order->update_status( 'failed', $e->getMessage() );
            banglapay_log( 'bKash execute error: ' . $e->getMessage(), 'ERROR' );
            wp_safe_redirect( $order->get_cancel_order_url() );
            exit;
        }
    }
}
