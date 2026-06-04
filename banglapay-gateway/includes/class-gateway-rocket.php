<?php
/**
 * BanglaPay_Gateway_Rocket — Manual payment gateway for Rocket (DBBL).
 *
 * Since Rocket does not provide a public merchant API, this implements
 * a manual verification flow:
 *  1. Customer sends money to the merchant's Rocket number.
 *  2. Customer enters the Transaction ID at checkout.
 *  3. Order is placed with "on-hold" status.
 *  4. Admin verifies the Transaction ID and manually completes the order.
 *
 * @package BanglaPay_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BanglaPay_Gateway_Rocket extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'banglapay_rocket';
        $this->icon               = BANGLAPAY_PLUGIN_URL . 'assets/images/rocket.png';
        $this->has_fields         = true;  // We render a custom field.
        $this->method_title       = __( 'Rocket (DBBL)', 'banglapay-gateway' );
        $this->method_description = __( 'Accept payments via Rocket (Dutch-Bangla Bank) mobile banking. Manual verification.', 'banglapay-gateway' );
        $this->supports           = [ 'products' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );

        // Show Transaction ID in admin order view.
        add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'display_transaction_id_in_admin' ] );
    }

    // =========================================================================
    // Admin form fields
    // =========================================================================

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled'           => [
                'title'   => __( 'Enable/Disable', 'banglapay-gateway' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Rocket Payment Gateway', 'banglapay-gateway' ),
                'default' => 'no',
            ],
            'title'             => [
                'title'    => __( 'Title', 'banglapay-gateway' ),
                'type'     => 'text',
                'default'  => __( 'Rocket (DBBL)', 'banglapay-gateway' ),
                'desc_tip' => true,
            ],
            'description'       => [
                'title'   => __( 'Description', 'banglapay-gateway' ),
                'type'    => 'textarea',
                'default' => __( 'Send money to our Rocket number and enter your Transaction ID.', 'banglapay-gateway' ),
            ],
            'merchant_number'   => [
                'title'       => __( 'Merchant Rocket Number', 'banglapay-gateway' ),
                'type'        => 'text',
                'description' => __( 'Your Rocket merchant number customers will send money to.', 'banglapay-gateway' ),
                'desc_tip'    => true,
            ],
            'instructions'      => [
                'title'   => __( 'Payment Instructions', 'banglapay-gateway' ),
                'type'    => 'textarea',
                'default' => __( 'Send the exact order amount to our Rocket number. Then enter the Transaction ID below.', 'banglapay-gateway' ),
            ],
        ];
    }

    // =========================================================================
    // Custom checkout field
    // =========================================================================

    public function payment_fields(): void {
        $description      = $this->get_description();
        $merchant_number  = $this->get_option( 'merchant_number' );
        $instructions     = $this->get_option( 'instructions' );

        if ( $description ) {
            echo '<p>' . wp_kses_post( $description ) . '</p>';
        }

        if ( $merchant_number ) {
            echo '<p><strong>'
                . esc_html__( 'Rocket Number: ', 'banglapay-gateway' )
                . '</strong>'
                . esc_html( $merchant_number )
                . '</p>';
        }

        if ( $instructions ) {
            echo '<p>' . wp_kses_post( $instructions ) . '</p>';
        }

        // Render the transaction ID input.
        ?>
        <fieldset id="<?php echo esc_attr( $this->id ); ?>-rocket-form">
            <p class="form-row form-row-wide">
                <label for="rocket_transaction_id">
                    <?php esc_html_e( 'Rocket Transaction ID', 'banglapay-gateway' ); ?>
                    <span class="required">*</span>
                </label>
                <input
                    id="rocket_transaction_id"
                    name="rocket_transaction_id"
                    type="text"
                    autocomplete="off"
                    placeholder="<?php esc_attr_e( 'e.g. 1234567890', 'banglapay-gateway' ); ?>"
                    class="input-text"
                />
            </p>
        </fieldset>
        <?php
    }

    // =========================================================================
    // Validate checkout field
    // =========================================================================

    public function validate_fields(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification
        $transaction_id = isset( $_POST['rocket_transaction_id'] )
            ? sanitize_text_field( wp_unslash( $_POST['rocket_transaction_id'] ) )
            : '';

        if ( empty( $transaction_id ) ) {
            wc_add_notice(
                __( 'Please enter your Rocket Transaction ID.', 'banglapay-gateway' ),
                'error'
            );
            return false;
        }

        if ( ! preg_match( '/^[A-Za-z0-9]{6,20}$/', $transaction_id ) ) {
            wc_add_notice(
                __( 'Invalid Rocket Transaction ID format. Please check and try again.', 'banglapay-gateway' ),
                'error'
            );
            return false;
        }

        return true;
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

        // phpcs:ignore WordPress.Security.NonceVerification
        $transaction_id = isset( $_POST['rocket_transaction_id'] )
            ? sanitize_text_field( wp_unslash( $_POST['rocket_transaction_id'] ) )
            : '';

        if ( empty( $transaction_id ) ) {
            wc_add_notice( __( 'Transaction ID is required.', 'banglapay-gateway' ), 'error' );
            return [ 'result' => 'failure' ];
        }

        // Save Transaction ID to order.
        $order->update_meta_data( '_rocket_transaction_id', $transaction_id );

        // Put order on-hold pending admin verification.
        $order->update_status(
            'on-hold',
            sprintf(
                /* translators: %s: transaction ID */
                __( 'Awaiting Rocket payment verification. Transaction ID: %s', 'banglapay-gateway' ),
                esc_html( $transaction_id )
            )
        );

        $order->save();

        // Empty cart.
        WC()->cart->empty_cart();

        banglapay_log( "Rocket payment submitted for order #{$order_id}. TrxID: {$transaction_id}" );

        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        ];
    }

    // =========================================================================
    // Display transaction ID in admin order
    // =========================================================================

    public function display_transaction_id_in_admin( WC_Order $order ): void {
        if ( $this->id !== $order->get_payment_method() ) {
            return;
        }

        $transaction_id = $order->get_meta( '_rocket_transaction_id' );
        if ( $transaction_id ) {
            echo '<p><strong>'
                . esc_html__( 'Rocket Transaction ID:', 'banglapay-gateway' )
                . '</strong> '
                . esc_html( $transaction_id )
                . '</p>';
        }
    }

    // =========================================================================
    // Thank-you page instructions
    // =========================================================================

    public function thankyou_page( $order_id ): void {
        $instructions = $this->get_option( 'instructions' );
        if ( $instructions ) {
            echo '<p>' . wp_kses_post( $instructions ) . '</p>';
        }
    }
}
