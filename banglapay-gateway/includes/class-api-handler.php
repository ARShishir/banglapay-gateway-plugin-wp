<?php
/**
 * BanglaPay_API_Handler — Centralised HTTP request wrapper.
 *
 * @package BanglaPay_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BanglaPay_API_Handler {

    /**
     * Make a POST request and return decoded JSON body.
     *
     * @param string $url     Endpoint URL.
     * @param array  $body    Request body (will be JSON-encoded).
     * @param array  $headers HTTP headers.
     * @param int    $timeout Seconds before timeout.
     *
     * @return array|WP_Error Decoded response array or WP_Error.
     */
    public function post( string $url, array $body = [], array $headers = [], int $timeout = 30 ) {
        $args = [
            'method'      => 'POST',
            'timeout'     => $timeout,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => array_merge( [ 'Content-Type' => 'application/json' ], $headers ),
            'body'        => wp_json_encode( $body ),
            'sslverify'   => true,
        ];

        banglapay_log( 'API POST → ' . $url );
        banglapay_log( 'Request body: ' . wp_json_encode( $body ) );

        $response = wp_remote_post( $url, $args );

        return $this->handle_response( $response );
    }

    /**
     * Make a GET request and return decoded JSON body.
     *
     * @param string $url     Endpoint URL.
     * @param array  $headers HTTP headers.
     * @param int    $timeout Seconds before timeout.
     *
     * @return array|WP_Error
     */
    public function get( string $url, array $headers = [], int $timeout = 30 ) {
        $args = [
            'method'      => 'GET',
            'timeout'     => $timeout,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => array_merge( [ 'Content-Type' => 'application/json' ], $headers ),
            'sslverify'   => true,
        ];

        banglapay_log( 'API GET → ' . $url );

        $response = wp_remote_get( $url, $args );

        return $this->handle_response( $response );
    }

    /**
     * Parse a wp_remote_* response.
     *
     * @param array|WP_Error $response
     * @return array|WP_Error
     */
    private function handle_response( $response ) {
        if ( is_wp_error( $response ) ) {
            banglapay_log( 'API Error: ' . $response->get_error_message() );
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $raw_body    = wp_remote_retrieve_body( $response );

        banglapay_log( 'API Response [' . $status_code . ']: ' . $raw_body );

        $decoded = json_decode( $raw_body, true );

        if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
            return new WP_Error(
                'banglapay_json_error',
                __( 'Invalid JSON response from payment gateway.', 'banglapay-gateway' ),
                [ 'status' => $status_code, 'raw' => $raw_body ]
            );
        }

        if ( $status_code >= 400 ) {
            $message = isset( $decoded['message'] ) ? $decoded['message'] : __( 'Unknown API error.', 'banglapay-gateway' );
            return new WP_Error(
                'banglapay_api_error_' . $status_code,
                $message,
                $decoded
            );
        }

        return $decoded ?? [];
    }
}
