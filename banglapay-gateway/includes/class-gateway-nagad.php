<?php
/**
 * BanglaPay_Gateway_Nagad — WooCommerce payment gateway for Nagad.
 *
 * Flow:
 *  1. process_payment() → initialize payment (signed) → redirect.
 *  2. Nagad POSTs back to callback URL.
 *  3. handle_callback() → verify payment → complete order.
 *
 * @package BanglaPay_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BanglaPay_Gateway_Nagad extends WC_Payment_Gateway {

    const SANDBOX_BASE = 'http://sandbox.mynagad.com:10080/remote-payment-gateway-1.0/api/dfs';
    const LIVE_BASE    = 'https://api.mynagad.com/api/dfs';

    /** @var BanglaPay_API_Handler */
    private BanglaPay_API_Handler $api;

    public function __construct() {
        $this->id                 = 'banglapay_nagad';
        $this->icon               = BANGLAPAY_PLUGIN_URL . 'assets/images/nagad.png';
        $this->has_fields         = false;
        $this->method_title       = __( 'Nagad', 'banglapay-gateway' );
        $this->method_description = __( 'Accept payments via Nagad mobile banking.', 'banglapay-gateway' );
        $this->supports           = [ 'products' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );

        $this->api = new BanglaPay_API_Handler();

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'woocommerce_api_' . $this->id, [ $this, 'handle_callback' ] );
    }

    // =========================================================================
    // Admin form fields
    // =========================================================================

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled'          => [
                'title'   => __( 'Enable/Disable', 'banglapay-gateway' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Nagad Payment Gateway', 'banglapay-gateway' ),
                'default' => 'no',
            ],
            'title'            => [
                'title'    => __( 'Title', 'banglapay-gateway' ),
                'type'     => 'text',
                'default'  => __( 'Nagad', 'banglapay-gateway' ),
                'desc_tip' => true,
            ],
            'description'      => [
                'title'   => __( 'Description', 'banglapay-gateway' ),
                'type'    => 'textarea',
                'default' => __( 'Pay securely via Nagad mobile banking.', 'banglapay-gateway' ),
            ],
            'sandbox'          => [
                'title'   => __( 'Sandbox Mode', 'banglapay-gateway' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable sandbox (test) mode', 'banglapay-gateway' ),
                'default' => 'yes',
            ],
            'merchant_id'      => [
                'title'    => __( 'Merchant ID', 'banglapay-gateway' ),
                'type'     => 'text',
                'desc_tip' => true,
            ],
            'merchant_number'  => [
                'title'    => __( 'Merchant Number', 'banglapay-gateway' ),
                'type'     => 'text',
                'desc_tip' => true,
            ],
            'public_key'       => [
                'title'       => __( 'Nagad Public Key', 'banglapay-gateway' ),
                'type'        => 'textarea',
                'description' => __( 'PEM-formatted public key from Nagad dashboard.', 'banglapay-gateway' ),
                'desc_tip'    => true,
            ],
            'merchant_private_key' => [
                'title'       => __( 'Merchant Private Key', 'banglapay-gateway' ),
                'type'        => 'textarea',
                'description' => __( 'PEM-formatted RSA private key (2048-bit).', 'banglapay-gateway' ),
                'desc_tip'    => true,
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

    /**
     * Encrypt data with Nagad's public key (RSA/PKCS1 padding).
     */
    private function encrypt_with_nagad_public_key( string $data ): string {
        $public_key_pem = $this->get_option( 'public_key' );
        $public_key     = openssl_pkey_get_public( $public_key_pem );
        if ( ! $public_key ) {
            banglapay_log( 'Nagad: invalid public key.', 'ERROR' );
            return '';
        }
        $encrypted = '';
        openssl_public_encrypt( $data, $encrypted, $public_key, OPENSSL_PKCS1_PADDING );
        return base64_encode( $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    /**
     * Sign data with the merchant private key.
     */
    private function sign_with_private_key( string $data ): string {
        $private_key_pem = $this->get_option( 'merchant_private_key' );
        $private_key     = openssl_pkey_get_private( $private_key_pem );
        if ( ! $private_key ) {
            banglapay_log( 'Nagad: invalid private key.', 'ERROR' );
            return '';
        }
        $signature = '';
        openssl_sign( $data, $signature, $private_key, OPENSSL_ALGO_SHA256 );
        return base64_encode( $signature ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    /**
     * Decrypt data with the merchant private key.
     */
    private function decrypt_with_private_key( string $encrypted_data ): string {
        $private_key_pem = $this->get_option( 'merchant_private_key' );
        $private_key     = openssl_pkey_get_private( $private_key_pem );
        if ( ! $private_key ) {
            return '';
        }
        $decoded = base64_decode( $encrypted_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        $decrypted = '';
        openssl_private_decrypt( $decoded, $decrypted, $private_key, OPENSSL_PKCS1_OAEP_PADDING );
        return $decrypted;
    }

    // =========================================================================
    // Step 1: Initialize payment
    // =========================================================================

    private function initialize_payment( WC_Order $order ): array|WP_Error {
        $merchant_id     = $this->get_option( 'merchant_id' );
        $merchant_number = $this->get_option( 'merchant_number' );
        $order_id        = $order->get_id();
        $datetime        = gmdate( 'YmdHis' );
        $invoice         = banglapay_generate_transaction_ref( $order_id );
        $callback        = WC()->api_request_url( $this->id );

        // Sensitive payload — encrypt with Nagad public key.
        $sensitive_data = wp_json_encode( [
            'merchantId'            => $merchant_id,
            'datetime'              => $datetime,
            'orderId'               => (string) $order_id,
            'challenge'             => $invoice,
        ] );
        $encrypted_payload = $this->encrypt_with_nagad_public_key( $sensitive_data );

        // Signature — sign with merchant private key.
        $signature_data = $merchant_id . $datetime . $order_id . $invoice;
        $signature      = $this->sign_with_private_key( $signature_data );

        $body = [
            'accountNumber'    => $merchant_number,
            'dateTime'         => $datetime,
            'sensitiveData'    => $encrypted_payload,
            'signature'        => $signature,
        ];

        $endpoint = $this->base_url() . '/check-out/initialize/' . $merchant_id . '/' . $order_id;

        // Store invoice reference.
        $order->update_meta_data( '_nagad_invoice', $invoice );
        $order->save();

        return $this->api->post( $endpoint, $body, [ 'X-KM-Api-Version' => 'v-0.2.0' ] );
    }

    // =========================================================================
    // Step 2: Complete checkout (get redirect URL)
    // =========================================================================

    private function complete_checkout( WC_Order $order, string $payment_ref_id, string $challenge ): array|WP_Error {
        $merchant_id     = $this->get_option( 'merchant_id' );
        $merchant_number = $this->get_option( 'merchant_number' );
        $datetime        = gmdate( 'YmdHis' );
        $order_id        = $order->get_id();
        $callback        = WC()->api_request_url( $this->id );

        $sensitive_data = wp_json_encode( [
            'merchantId'      => $merchant_id,
            'orderId'         => (string) $order_id,
            'currencyCode'    => '050',
            'amount'          => number_format( (float) $order->get_total(), 2, '.', '' ),
            'challenge'       => $challenge,
        ] );
        $encrypted_payload = $this->encrypt_with_nagad_public_key( $sensitive_data );

        $signature_data = $merchant_id . $order_id . '050' . number_format( (float) $order->get_total(), 2, '.', '' ) . $challenge;
        $signature      = $this->sign_with_private_key( $signature_data );

        $body = [
            'sensitiveData'    => $encrypted_payload,
            'signature'        => $signature,
            'merchantCallbackURL' => $callback,
            'additionalMerchantInfo' => [
                'emiData' => [],
            ],
        ];

        $endpoint = $this->base_url() . '/check-out/complete/' . $merchant_id . '/' . $order_id;

        return $this->api->post( $endpoint, $body, [ 'X-KM-Api-Version' => 'v-0.2.0' ] );
    }

    // =========================================================================
    // Step 3: Verify payment
    // =========================================================================

    private function verify_payment( string $payment_ref_id ): array|WP_Error {
        $merchant_id = $this->get_option( 'merchant_id' );
        $endpoint    = $this->base_url() . '/verify/payment/' . $payment_ref_id;

        return $this->api->get( $endpoint, [
            'X-KM-Api-Version' => 'v-0.2.0',
            'X-KM-Merchant-Id' => $merchant_id,
        ] );
    }

    // =========================================================================
    // process_payment
    // =========================================================================

    public function process_payment( $order_id ): array {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wc_add_notice( __( 'Order not found.', 'banglapay-gateway' ), 'error' );
            return [ 'result' => 'failure' ];
        }

        try {
            // Step 1 — initialize.
            $init_response = $this->initialize_payment( $order );
            if ( is_wp_error( $init_response ) ) {
                throw new Exception( $init_response->get_error_message() );
            }

            if ( empty( $init_response['sensitiveData'] ) ) {
                throw new Exception( __( 'Nagad initialization failed.', 'banglapay-gateway' ) );
            }

            // Decrypt the sensitive data returned by Nagad.
            $decrypted = $this->decrypt_with_private_key( $init_response['sensitiveData'] );
            $nagad_data = json_decode( $decrypted, true );

            if ( empty( $nagad_data['paymentReferenceId'] ) || empty( $nagad_data['challenge'] ) ) {
                throw new Exception( __( 'Invalid Nagad initialization response.', 'banglapay-gateway' ) );
            }

            $payment_ref_id = sanitize_text_field( $nagad_data['paymentReferenceId'] );
            $challenge      = sanitize_text_field( $nagad_data['challenge'] );

            // Step 2 — complete checkout to get redirect URL.
            $complete_response = $this->complete_checkout( $order, $payment_ref_id, $challenge );
            if ( is_wp_error( $complete_response ) ) {
                throw new Exception( $complete_response->get_error_message() );
            }

            if ( empty( $complete_response['callBackUrl'] ) ) {
                throw new Exception( __( 'Nagad did not return a redirect URL.', 'banglapay-gateway' ) );
            }

            $order->update_meta_data( '_nagad_payment_ref_id', $payment_ref_id );
            $order->save();

            banglapay_log( "Nagad payment initiated for order #{$order_id}. RefID: {$payment_ref_id}" );

            return [
                'result'   => 'success',
                'redirect' => esc_url_raw( $complete_response['callBackUrl'] ),
            ];

        } catch ( Exception $e ) {
            banglapay_log( 'Nagad process_payment error: ' . $e->getMessage(), 'ERROR' );
            wc_add_notice( __( 'Nagad payment error: ', 'banglapay-gateway' ) . esc_html( $e->getMessage() ), 'error' );
            return [ 'result' => 'failure' ];
        }
    }

    // =========================================================================
    // Callback handler
    // =========================================================================

    public function handle_callback(): void {
        // phpcs:disable WordPress.Security.NonceVerification
        $payment_ref_id = isset( $_GET['payment_ref_id'] ) ? sanitize_text_field( wp_unslash( $_GET['payment_ref_id'] ) ) : '';
        $order_id       = isset( $_GET['order_id'] )       ? absint( $_GET['order_id'] )                                  : 0;
        $status         = isset( $_GET['status'] )         ? sanitize_text_field( wp_unslash( $_GET['status'] ) )         : '';
        // phpcs:enable

        if ( ! $order_id || ! $payment_ref_id ) {
            banglapay_log( 'Nagad callback: missing parameters.', 'ERROR' );
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            banglapay_log( "Nagad callback: order #{$order_id} not found.", 'ERROR' );
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        if ( 'Success' !== $status ) {
            $order->update_status( 'failed', __( 'Nagad payment failed or cancelled.', 'banglapay-gateway' ) );
            banglapay_log( "Nagad payment failed for order #{$order_id}.", 'ERROR' );
            wp_safe_redirect( $order->get_cancel_order_url() );
            exit;
        }

        try {
            $verify = $this->verify_payment( $payment_ref_id );
            if ( is_wp_error( $verify ) ) {
                throw new Exception( $verify->get_error_message() );
            }

            if ( empty( $verify['status'] ) || 'Success' !== $verify['status'] ) {
                throw new Exception( 'Nagad verification failed. Status: ' . ( $verify['status'] ?? 'unknown' ) );
            }

            $order->payment_complete( sanitize_text_field( $verify['merchantInvoiceNumber'] ?? $payment_ref_id ) );
            $order->add_order_note(
                sprintf(
                    /* translators: %s: payment reference ID */
                    __( 'Nagad payment verified. RefID: %s', 'banglapay-gateway' ),
                    esc_html( $payment_ref_id )
                )
            );
            $order->save();

            banglapay_log( "Nagad payment completed for order #{$order_id}." );
            wp_safe_redirect( $this->get_return_url( $order ) );
            exit;

        } catch ( Exception $e ) {
            $order->update_status( 'failed', $e->getMessage() );
            banglapay_log( 'Nagad callback error: ' . $e->getMessage(), 'ERROR' );
            wp_safe_redirect( $order->get_cancel_order_url() );
            exit;
        }
    }
}
