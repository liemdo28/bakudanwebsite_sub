/**
 * Bakudan Ramen - Delivery Announcement Pop-up
 * Shows once per visitor to announce new delivery service
 */

(function() {
    'use strict';

    var POPUP_KEY = 'bakudan_delivery_popup_seen';
    var POPUP_EXPIRY_DAYS = 7;

    function hasSeenPopup() {
        var timestamp = localStorage.getItem(POPUP_KEY);
        if (!timestamp) return false;
        var expiryMs = POPUP_EXPIRY_DAYS * 24 * 60 * 60 * 1000;
        return (Date.now() - parseInt(timestamp, 10)) < expiryMs;
    }

    function markSeen() {
        localStorage.setItem(POPUP_KEY, Date.now().toString());
    }

    function closePopup() {
        var overlay = document.getElementById('delivery-popup-overlay');
        if (overlay) {
            overlay.classList.remove('show');
            overlay.setAttribute('aria-hidden', 'true');
            markSeen();
            // Return focus to body
            document.body.style.overflow = '';
            var trigger = document.querySelector('.site-header a');
            if (trigger) trigger.focus();
        }
    }

    function showPopup() {
        var overlay = document.getElementById('delivery-popup-overlay');
        if (!overlay) return;

        // Small delay so page loads first
        setTimeout(function() {
            overlay.classList.add('show');
            overlay.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';

            // Focus the close button for accessibility
            var closeBtn = overlay.querySelector('.delivery-popup-close');
            if (closeBtn) closeBtn.focus();
        }, 1200);
    }

    function init() {
        if (hasSeenPopup()) return;

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', showPopup);
        } else {
            showPopup();
        }

        // Close button
        document.addEventListener('click', function(e) {
            if (e.target.closest('.delivery-popup-close')) {
                closePopup();
                return;
            }
            // Close on overlay click (outside the popup box)
            if (e.target.id === 'delivery-popup-overlay') {
                closePopup();
                return;
            }
            // "Order Now" button — close popup and navigate
            if (e.target.closest('.delivery-popup-cta')) {
                markSeen();
                // Let the link navigate naturally
            }
        });

        // Close on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                var overlay = document.getElementById('delivery-popup-overlay');
                if (overlay && overlay.classList.contains('show')) {
                    closePopup();
                }
            }
        });
    }

    init();
})();
