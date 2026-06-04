<?php
/**
 * Plugin Name:       BanglaPay Gateway
 * Plugin URI:        https://yoursite.com/banglapay-gateway
 * Description:       WooCommerce payment gateway integrating bKash, Nagad, and Rocket (DBBL) for Bangladesh.
 * Version:           1.0.0
 * Author:            Your Name
 * Author URI:        https://yoursite.com
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       banglapay-gateway
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 * WC tested up to:   8.5
 */

// Security: Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants.
define( 'BANGLAPAY_VERSION',     '1.0.0' );
define( 'BANGLAPAY_PLUGIN_FILE', __FILE__ );
define( 'BANGLAPAY_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'BANGLAPAY_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'BANGLAPAY_LOG_DIR',     BANGLAPAY_PLUGIN_DIR . 'logs/' );

/**
 * Check if WooCommerce is active. If not, show admin notice and bail.
 */
function banglapay_check_woocommerce() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'banglapay_missing_woocommerce_notice' );
        return false;
    }
    return true;
}

function banglapay_missing_woocommerce_notice() {
    echo '<div class="error"><p>'
        . esc_html__( 'BanglaPay Gateway requires WooCommerce to be installed and active.', 'banglapay-gateway' )
        . '</p></div>';
}

/**
 * Load all includes.
 */
function banglapay_load_includes() {
    require_once BANGLAPAY_PLUGIN_DIR . 'includes/helpers.php';
    require_once BANGLAPAY_PLUGIN_DIR . 'includes/class-api-handler.php';
    require_once BANGLAPAY_PLUGIN_DIR . 'includes/class-webhook-handler.php';
    require_once BANGLAPAY_PLUGIN_DIR . 'includes/class-gateway-bkash.php';
    require_once BANGLAPAY_PLUGIN_DIR . 'includes/class-gateway-nagad.php';
    require_once BANGLAPAY_PLUGIN_DIR . 'includes/class-gateway-rocket.php';
    require_once BANGLAPAY_PLUGIN_DIR . 'includes/class-init.php';
}

/**
 * Main plugin init, hooked after plugins_loaded so WooCommerce is available.
 */
function banglapay_init() {
    if ( ! banglapay_check_woocommerce() ) {
        return;
    }

    banglapay_load_includes();

    // Boot the plugin.
    BanglaPay_Init::instance();
}
add_action( 'plugins_loaded', 'banglapay_init' );

/**
 * Declare WooCommerce HPOS (High-Performance Order Storage) compatibility.
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

/**
 * Activation hook — create log file.
 */
register_activation_hook( __FILE__, 'banglapay_activate' );
function banglapay_activate() {
    if ( ! file_exists( BANGLAPAY_LOG_DIR ) ) {
        wp_mkdir_p( BANGLAPAY_LOG_DIR );
    }
    $log_file = BANGLAPAY_LOG_DIR . 'debug.log';
    if ( ! file_exists( $log_file ) ) {
        file_put_contents( $log_file, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }
    // Add .htaccess to protect log directory.
    $htaccess = BANGLAPAY_LOG_DIR . '.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        file_put_contents( $htaccess, "deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }
}

/**
 * Deactivation hook.
 */
register_deactivation_hook( __FILE__, 'banglapay_deactivate' );
function banglapay_deactivate() {
    // Flush rewrite rules to remove webhook endpoint.
    flush_rewrite_rules();
}
