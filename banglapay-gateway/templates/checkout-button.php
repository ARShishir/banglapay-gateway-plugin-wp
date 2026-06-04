<?php
/**
 * Checkout payment button template.
 *
 * Available variables:
 *  $gateway_id    — gateway ID string
 *  $gateway_title — gateway display title
 *  $order         — WC_Order instance
 *
 * @package BanglaPay_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="banglapay-checkout-button <?php echo esc_attr( 'banglapay-checkout-button--' . $gateway_id ); ?>">
    <button type="submit" class="button alt banglapay-pay-btn" id="banglapay-pay-<?php echo esc_attr( $gateway_id ); ?>">
        <?php
        echo wp_kses_post(
            sprintf(
                /* translators: %s: gateway name */
                __( 'Pay with %s', 'banglapay-gateway' ),
                '<strong>' . esc_html( $gateway_title ) . '</strong>'
            )
        );
        ?>
    </button>
</div>
