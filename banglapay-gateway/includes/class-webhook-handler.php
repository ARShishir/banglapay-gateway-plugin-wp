<?php
/**
 * BanglaPay_Webhook_Handler — handles inbound webhooks from payment gateways.
 *
 * Endpoint: /wp-json/banglapay/v1/webhook
 *
 * Expected JSON body:
 * {
 *   "gateway":    "bkash|nagad|rocket",
 *   "event":      "payment.success|payment.failed|payment.cancelled",
 *   "order_id":   123,
 *   "payment_id": "BKASH123",
 *   "amount":     "500.00",
 *   "secret":     "<shared_webhook_secret>"
 * }
 *
 * @package BanglaPay_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BanglaPay_Webhook_Handler {

    const NAMESPACE = 'banglapay/v1';
    const ROUTE     = '/webhook';

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, self::ROUTE, [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handle' ],
                'permission_callback' => [ $this, 'verify_secret' ],
                'args'                => $this->get_args(),
            ],
        ] );
    }

    // =========================================================================
    // Argument schema
    // =========================================================================

    private function get_args(): array {
        return [
            'gateway' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => function ( $param ) {
                    return in_array( $param, [ 'bkash', 'nagad', 'rocket' ], true );
                },
            ],
            'event' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function ( $param ) {
                    return in_array( $param, [ 'payment.success', 'payment.failed', 'payment.cancelled' ], true );
                },
            ],
            'order_id' => [
                'required'          => true,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'payment_id' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'amount' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'secret' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    // =========================================================================
    // Auth: compare shared secret
    // =========================================================================

    public function verify_secret( WP_REST_Request $request ): bool|WP_Error {
        $provided = $request->get_param( 'secret' );
        $stored   = banglapay_get_option( 'webhook_secret', '' );

        if ( empty( $stored ) ) {
            banglapay_log( 'Webhook: no webhook secret configured — rejecting request.', 'ERROR' );
            return new WP_Error( 'banglapay_no_secret', __( 'Webhook secret not configured.', 'banglapay-gateway' ), [ 'status' => 403 ] );
        }

        if ( ! hash_equals( $stored, $provided ) ) {
            banglapay_log( 'Webhook: invalid secret from IP ' . ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ), 'ERROR' ); // phpcs:ignore
            return new WP_Error( 'banglapay_invalid_secret', __( 'Forbidden.', 'banglapay-gateway' ), [ 'status' => 403 ] );
        }

        return true;
    }

    // =========================================================================
    // Main handler
    // =========================================================================

    public function handle( WP_REST_Request $request ): WP_REST_Response {
        $gateway    = $request->get_param( 'gateway' );
        $event      = $request->get_param( 'event' );
        $order_id   = absint( $request->get_param( 'order_id' ) );
        $payment_id = sanitize_text_field( $request->get_param( 'payment_id' ) ?? '' );
        $amount     = sanitize_text_field( $request->get_param( 'amount' ) ?? '' );

        banglapay_log( "Webhook received: gateway={$gateway} event={$event} order={$order_id} payment={$payment_id}" );

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            banglapay_log( "Webhook: order #{$order_id} not found.", 'ERROR' );
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Order not found.' ], 404 );
        }

        switch ( $event ) {
            case 'payment.success':
                return $this->handle_success( $order, $gateway, $payment_id, $amount );

            case 'payment.failed':
            case 'payment.cancelled':
                return $this->handle_failure( $order, $gateway, $event );

            default:
                banglapay_log( "Webhook: unknown event '{$event}'.", 'ERROR' );
                return new WP_REST_Response( [ 'success' => false, 'message' => 'Unknown event.' ], 400 );
        }
    }

    // =========================================================================
    // Success handler
    // =========================================================================

    private function handle_success( WC_Order $order, string $gateway, string $payment_id, string $amount ): WP_REST_Response {
        // Idempotency — don't process already-completed orders.
        if ( $order->is_paid() ) {
            banglapay_log( "Webhook: order #{$order->get_id()} already paid. Skipping." );
            return new WP_REST_Response( [ 'success' => true, 'message' => 'Already processed.' ], 200 );
        }

        $order->payment_complete( $payment_id );
        $order->add_order_note(
            sprintf(
                /* translators: %1$s: gateway name, %2$s: payment ID */
                __( 'Payment confirmed via webhook. Gateway: %1$s | Payment ID: %2$s', 'banglapay-gateway' ),
                esc_html( strtoupper( $gateway ) ),
                esc_html( $payment_id )
            )
        );
        $order->save();

        banglapay_log( "Webhook: order #{$order->get_id()} marked as paid via {$gateway}." );

        return new WP_REST_Response( [ 'success' => true, 'message' => 'Order completed.' ], 200 );
    }

    // =========================================================================
    // Failure handler
    // =========================================================================

    private function handle_failure( WC_Order $order, string $gateway, string $event ): WP_REST_Response {
        if ( 'failed' === $order->get_status() || 'cancelled' === $order->get_status() ) {
            return new WP_REST_Response( [ 'success' => true, 'message' => 'Already failed.' ], 200 );
        }

        $new_status = 'payment.cancelled' === $event ? 'cancelled' : 'failed';
        $order->update_status(
            $new_status,
            sprintf(
                /* translators: %1$s: gateway name, %2$s: event */
                __( 'Payment %2$s via webhook. Gateway: %1$s', 'banglapay-gateway' ),
                esc_html( strtoupper( $gateway ) ),
                esc_html( $event )
            )
        );
        $order->save();

        banglapay_log( "Webhook: order #{$order->get_id()} marked as {$new_status} via {$gateway}." );

        return new WP_REST_Response( [ 'success' => true, 'message' => "Order {$new_status}." ], 200 );
    }
}
