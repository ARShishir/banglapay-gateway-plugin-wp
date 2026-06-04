<?php
/**
 * Global helper functions for BanglaPay Gateway.
 *
 * @package BanglaPay_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Write a message to the plugin debug log.
 *
 * @param string $message Log message.
 * @param string $level   'INFO' | 'ERROR' | 'DEBUG'.
 */
function banglapay_log( string $message, string $level = 'INFO' ): void {
    $log_file = BANGLAPAY_LOG_DIR . 'debug.log';
    $timestamp = gmdate( 'Y-m-d H:i:s' );
    $entry = sprintf( "[%s] [%s] %s\n", $timestamp, strtoupper( $level ), $message );
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
    file_put_contents( $log_file, $entry, FILE_APPEND | LOCK_EX );
}

/**
 * Retrieve a BanglaPay option by key with optional default.
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function banglapay_get_option( string $key, $default = '' ) {
    $options = get_option( 'banglapay_settings', [] );
    return isset( $options[ $key ] ) ? $options[ $key ] : $default;
}

/**
 * Check whether the plugin is in sandbox mode.
 */
function banglapay_is_sandbox(): bool {
    return 'yes' === banglapay_get_option( 'sandbox_mode', 'yes' );
}

/**
 * Generate a unique transaction reference.
 *
 * @param int $order_id WooCommerce order ID.
 * @return string
 */
function banglapay_generate_transaction_ref( int $order_id ): string {
    return 'BP-' . $order_id . '-' . strtoupper( wp_generate_password( 6, false ) );
}

/**
 * Sanitize a phone number — keep only digits and leading +.
 *
 * @param string $phone
 * @return string
 */
function banglapay_sanitize_phone( string $phone ): string {
    return preg_replace( '/[^\d+]/', '', sanitize_text_field( $phone ) );
}

/**
 * Return the site callback (return) URL for a given gateway.
 *
 * @param string $gateway 'bkash' | 'nagad' | 'rocket'.
 * @param int    $order_id
 * @return string
 */
function banglapay_callback_url( string $gateway, int $order_id ): string {
    return add_query_arg(
        [
            'banglapay_gateway' => sanitize_key( $gateway ),
            'order_id'          => absint( $order_id ),
            'nonce'             => wp_create_nonce( 'banglapay_callback_' . $order_id ),
        ],
        site_url( '/banglapay-callback/' )
    );
}

/**
 * Read and return the last N lines of the debug log.
 *
 * @param int $lines
 * @return string
 */
function banglapay_read_log( int $lines = 200 ): string {
    $log_file = BANGLAPAY_LOG_DIR . 'debug.log';
    if ( ! file_exists( $log_file ) ) {
        return __( 'No log file found.', 'banglapay-gateway' );
    }
    $content = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    if ( false === $content ) {
        return __( 'Unable to read log file.', 'banglapay-gateway' );
    }
    $last = array_slice( $content, - $lines );
    return implode( "\n", array_reverse( $last ) );
}

/**
 * Clear the debug log.
 */
function banglapay_clear_log(): void {
    $log_file = BANGLAPAY_LOG_DIR . 'debug.log';
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
    file_put_contents( $log_file, '' );
}
