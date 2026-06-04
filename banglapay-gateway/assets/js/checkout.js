/**
 * BanglaPay Gateway — Checkout JS
 * Handles loading state on payment button click.
 */
(function ($) {
    'use strict';

    $(document).on('click', '.banglapay-pay-btn', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text($btn.text() + ' …');
    });

    // Re-enable buttons if WooCommerce triggers a checkout error.
    $(document.body).on('checkout_error', function () {
        $('.banglapay-pay-btn').each(function () {
            var $btn = $(this);
            $btn.prop('disabled', false);
        });
    });

})(jQuery);
