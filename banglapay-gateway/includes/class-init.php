<?php
/**
 * BanglaPay_Init — Singleton that wires everything together.
 *
 * @package BanglaPay_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BanglaPay_Init {

    /** @var BanglaPay_Init|null */
    private static $instance = null;

    /**
     * Get / create singleton.
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->hooks();
    }

    private function hooks(): void {
        // Register WooCommerce gateways.
        add_filter( 'woocommerce_payment_gateways', [ $this, 'register_gateways' ] );

        // Register REST webhook endpoint.
        add_action( 'rest_api_init', [ $this, 'register_webhook_routes' ] );

        // Admin menu.
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );

        // Enqueue assets.
        add_action( 'wp_enqueue_scripts',    [ $this, 'enqueue_frontend_assets' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // Load plugin text domain.
        add_action( 'init', [ $this, 'load_textdomain' ] );
    }

    /**
     * Register our three payment gateways with WooCommerce.
     */
    public function register_gateways( array $gateways ): array {
        $gateways[] = 'BanglaPay_Gateway_BKash';
        $gateways[] = 'BanglaPay_Gateway_Nagad';
        $gateways[] = 'BanglaPay_Gateway_Rocket';
        return $gateways;
    }

    /**
     * Register REST routes for webhooks.
     */
    public function register_webhook_routes(): void {
        $handler = new BanglaPay_Webhook_Handler();
        $handler->register_routes();
    }

    /**
     * Admin settings page under WooCommerce menu.
     */
    public function register_admin_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'BanglaPay Gateway', 'banglapay-gateway' ),
            __( 'BanglaPay', 'banglapay-gateway' ),
            'manage_woocommerce',
            'banglapay-gateway',
            [ $this, 'render_admin_page' ]
        );
    }

    public function render_admin_page(): void {
        require_once BANGLAPAY_PLUGIN_DIR . 'admin/settings-page.php';
    }

    public function enqueue_frontend_assets(): void {
        if ( is_checkout() ) {
            wp_enqueue_style(
                'banglapay-checkout',
                BANGLAPAY_PLUGIN_URL . 'assets/css/checkout.css',
                [],
                BANGLAPAY_VERSION
            );
            wp_enqueue_script(
                'banglapay-checkout',
                BANGLAPAY_PLUGIN_URL . 'assets/js/checkout.js',
                [ 'jquery' ],
                BANGLAPAY_VERSION,
                true
            );
            wp_localize_script( 'banglapay-checkout', 'BanglaPay', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'banglapay_nonce' ),
            ] );
        }
    }

    public function enqueue_admin_assets( string $hook ): void {
        if ( false === strpos( $hook, 'banglapay' ) ) {
            return;
        }
        wp_enqueue_style(
            'banglapay-admin',
            BANGLAPAY_PLUGIN_URL . 'assets/css/admin.css',
            [],
            BANGLAPAY_VERSION
        );
        wp_enqueue_script(
            'banglapay-admin',
            BANGLAPAY_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            BANGLAPAY_VERSION,
            true
        );
    }

    public function load_textdomain(): void {
        load_plugin_textdomain(
            'banglapay-gateway',
            false,
            dirname( plugin_basename( BANGLAPAY_PLUGIN_FILE ) ) . '/languages'
        );
    }
}
