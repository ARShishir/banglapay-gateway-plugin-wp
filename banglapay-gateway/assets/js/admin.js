/**
 * BanglaPay Gateway — Admin JS
 */
(function ($) {
    'use strict';

    // Tab switching is handled inline in settings-page.php (jQuery already loaded).
    // This file is reserved for future admin interactivity.

    // Auto-scroll log textarea to bottom.
    var $log = $('textarea[readonly]');
    if ($log.length) {
        $log.scrollTop($log[0].scrollHeight);
    }

})(jQuery);
