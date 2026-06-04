<?php
/**
 * BanglaPay Gateway — Admin Settings Page.
 *
 * @package BanglaPay_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Handle form save.
if ( isset( $_POST['banglapay_save_settings'] ) ) {
    check_admin_referer( 'banglapay_settings_nonce', 'banglapay_nonce' );

    $settings = [
        'sandbox_mode'    => isset( $_POST['sandbox_mode'] ) ? 'yes' : 'no',
        'webhook_secret'  => sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ?? '' ) ),
    ];

    update_option( 'banglapay_settings', $settings );
    echo '<div class="notice notice-success is-dismissible"><p>'
        . esc_html__( 'Settings saved.', 'banglapay-gateway' )
        . '</p></div>';
}

// Handle log clear.
if ( isset( $_POST['banglapay_clear_log'] ) ) {
    check_admin_referer( 'banglapay_settings_nonce', 'banglapay_nonce' );
    banglapay_clear_log();
    echo '<div class="notice notice-success is-dismissible"><p>'
        . esc_html__( 'Log cleared.', 'banglapay-gateway' )
        . '</p></div>';
}

$options      = get_option( 'banglapay_settings', [] );
$sandbox_mode = isset( $options['sandbox_mode'] ) ? $options['sandbox_mode'] : 'yes';
$webhook_secret = isset( $options['webhook_secret'] ) ? $options['webhook_secret'] : '';
$log_content  = banglapay_read_log( 300 );
?>

<div class="wrap banglapay-admin-wrap">
    <h1><?php esc_html_e( 'BanglaPay Gateway — Settings', 'banglapay-gateway' ); ?></h1>

    <nav class="nav-tab-wrapper">
        <a href="#tab-general"  class="nav-tab nav-tab-active" data-tab="tab-general">
            <?php esc_html_e( 'General', 'banglapay-gateway' ); ?>
        </a>
        <a href="#tab-gateways" class="nav-tab" data-tab="tab-gateways">
            <?php esc_html_e( 'Gateway Settings', 'banglapay-gateway' ); ?>
        </a>
        <a href="#tab-logs"     class="nav-tab" data-tab="tab-logs">
            <?php esc_html_e( 'Logs', 'banglapay-gateway' ); ?>
        </a>
        <a href="#tab-docs"     class="nav-tab" data-tab="tab-docs">
            <?php esc_html_e( 'Docs', 'banglapay-gateway' ); ?>
        </a>
    </nav>

    <!-- ===== GENERAL TAB ===== -->
    <div id="tab-general" class="banglapay-tab-content">
        <form method="post" action="">
            <?php wp_nonce_field( 'banglapay_settings_nonce', 'banglapay_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="sandbox_mode"><?php esc_html_e( 'Sandbox Mode', 'banglapay-gateway' ); ?></label>
                    </th>
                    <td>
                        <input type="checkbox"
                               id="sandbox_mode"
                               name="sandbox_mode"
                               value="yes"
                               <?php checked( $sandbox_mode, 'yes' ); ?> />
                        <label for="sandbox_mode">
                            <?php esc_html_e( 'Enable sandbox/test mode for all gateways', 'banglapay-gateway' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="webhook_secret"><?php esc_html_e( 'Webhook Secret', 'banglapay-gateway' ); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="webhook_secret"
                               name="webhook_secret"
                               value="<?php echo esc_attr( $webhook_secret ); ?>"
                               class="regular-text" />
                        <p class="description">
                            <?php
                            echo wp_kses(
                                sprintf(
                                    /* translators: %s: webhook URL */
                                    __( 'Shared secret for the webhook endpoint: <code>%s</code>', 'banglapay-gateway' ),
                                    esc_url( get_rest_url( null, 'banglapay/v1/webhook' ) )
                                ),
                                [ 'code' => [] ]
                            );
                            ?>
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="banglapay_save_settings" class="button button-primary">
                    <?php esc_html_e( 'Save Settings', 'banglapay-gateway' ); ?>
                </button>
            </p>
        </form>
    </div>

    <!-- ===== GATEWAY SETTINGS TAB ===== -->
    <div id="tab-gateways" class="banglapay-tab-content" style="display:none;">
        <p><?php esc_html_e( 'Configure individual gateways via WooCommerce → Settings → Payments.', 'banglapay-gateway' ); ?></p>
        <ul>
            <li>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=banglapay_bkash' ) ); ?>">
                    <?php esc_html_e( 'bKash Settings', 'banglapay-gateway' ); ?>
                </a>
            </li>
            <li>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=banglapay_nagad' ) ); ?>">
                    <?php esc_html_e( 'Nagad Settings', 'banglapay-gateway' ); ?>
                </a>
            </li>
            <li>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=banglapay_rocket' ) ); ?>">
                    <?php esc_html_e( 'Rocket Settings', 'banglapay-gateway' ); ?>
                </a>
            </li>
        </ul>
    </div>

    <!-- ===== LOGS TAB ===== -->
    <div id="tab-logs" class="banglapay-tab-content" style="display:none;">
        <form method="post" action="">
            <?php wp_nonce_field( 'banglapay_settings_nonce', 'banglapay_nonce' ); ?>
            <p>
                <button type="submit" name="banglapay_clear_log" class="button button-secondary"
                    onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to clear the log?', 'banglapay-gateway' ); ?>');">
                    <?php esc_html_e( 'Clear Log', 'banglapay-gateway' ); ?>
                </button>
            </p>
        </form>
        <textarea
            readonly
            style="width:100%;height:500px;font-family:monospace;font-size:12px;background:#1e1e1e;color:#d4d4d4;padding:12px;border:none;"
        ><?php echo esc_textarea( $log_content ); ?></textarea>
    </div>

    <!-- ===== DOCS TAB ===== -->
    <div id="tab-docs" class="banglapay-tab-content" style="display:none;">
        <h2><?php esc_html_e( 'Quick Reference', 'banglapay-gateway' ); ?></h2>

        <h3><?php esc_html_e( 'Webhook Endpoint', 'banglapay-gateway' ); ?></h3>
        <p><code><?php echo esc_url( get_rest_url( null, 'banglapay/v1/webhook' ) ); ?></code></p>

        <h3><?php esc_html_e( 'Webhook Payload (POST JSON)', 'banglapay-gateway' ); ?></h3>
        <pre style="background:#f0f0f0;padding:12px;"><?php
            echo esc_html( json_encode([
                'gateway'    => 'bkash|nagad|rocket',
                'event'      => 'payment.success|payment.failed|payment.cancelled',
                'order_id'   => 123,
                'payment_id' => 'TRX123456',
                'amount'     => '500.00',
                'secret'     => '<your_webhook_secret>',
            ], JSON_PRETTY_PRINT) );
        ?></pre>

        <h3><?php esc_html_e( 'Plugin Version', 'banglapay-gateway' ); ?></h3>
        <p><?php echo esc_html( BANGLAPAY_VERSION ); ?></p>
    </div>
</div>

<script>
(function($){
    // Simple tab switcher.
    $('.nav-tab').on('click', function(e){
        e.preventDefault();
        var tab = $(this).data('tab');
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.banglapay-tab-content').hide();
        $('#' + tab).show();
    });
})(jQuery);
</script>
