<?php
/**
 * Admin UI helpers — adds BanglaPay info to the WooCommerce orders list.
 *
 * @package BanglaPay_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add a "BanglaPay Ref" column to the orders list table.
 */
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'banglapay_add_order_column' );
add_filter( 'manage_edit-shop_order_columns', 'banglapay_add_order_column' ); // Fallback for older WC.
function banglapay_add_order_column( array $columns ): array {
    $columns['banglapay_ref'] = __( 'BanglaPay Ref', 'banglapay-gateway' );
    return $columns;
}

add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'banglapay_render_order_column', 10, 2 );
add_action( 'manage_shop_order_posts_custom_column', 'banglapay_render_order_column', 10, 2 );
function banglapay_render_order_column( string $column, $order_or_id ): void {
    if ( 'banglapay_ref' !== $column ) {
        return;
    }

    $order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
    if ( ! $order ) {
        return;
    }

    $method = $order->get_payment_method();

    if ( 'banglapay_bkash' === $method ) {
        $ref = $order->get_meta( '_bkash_payment_id' );
        echo $ref ? '<small>' . esc_html( 'bKash: ' . $ref ) . '</small>' : '—';
    } elseif ( 'banglapay_nagad' === $method ) {
        $ref = $order->get_meta( '_nagad_payment_ref_id' );
        echo $ref ? '<small>' . esc_html( 'Nagad: ' . $ref ) . '</small>' : '—';
    } elseif ( 'banglapay_rocket' === $method ) {
        $ref = $order->get_meta( '_rocket_transaction_id' );
        echo $ref ? '<small>' . esc_html( 'Rocket: ' . $ref ) . '</small>' : '—';
    } else {
        echo '—';
    }
}
