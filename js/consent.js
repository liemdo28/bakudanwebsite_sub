/**
 * Bakudan Ramen - Cookie Consent Banner
 * CCPA compliant cookie consent management
 */

(function() {
    'use strict';

    const CONSENT_KEY = 'bakudan_cookie_consent';
    const CONSENT_TIMESTAMP_KEY = 'bakudan_consent_timestamp';
    const CONSENT_EXPIRY_DAYS = 365;

    /**
     * Check if consent has been given and is still valid
     */
    function hasValidConsent() {
        const consent = localStorage.getItem(CONSENT_KEY);
        const timestamp = localStorage.getItem(CONSENT_TIMESTAMP_KEY);

        if (!consent || !timestamp) return false;

        // Check if consent has expired
        const expiryMs = CONSENT_EXPIRY_DAYS * 24 * 60 * 60 * 1000;
        const elapsed = Date.now() - parseInt(timestamp, 10);
        return elapsed < expiryMs;
    }

    /**
     * Save consent preference
     */
    function saveConsent(type) {
        localStorage.setItem(CONSENT_KEY, type);
        localStorage.setItem(CONSENT_TIMESTAMP_KEY, Date.now().toString());
    }

    /**
     * Get current consent level
     */
    function getConsentLevel() {
        return localStorage.getItem(CONSENT_KEY) || 'none';
    }

    /**
     * Load analytics/tracking scripts only if full consent given
     */
    function loadOptionalScripts() {
        if (getConsentLevel() === 'all') {
            // Load Google Analytics or other tracking here
            // Example:
            // const script = document.createElement('script');
            // script.src = 'https://www.googletagmanager.com/gtag/js?id=GA_TRACKING_ID';
            // script.async = true;
            // document.head.appendChild(script);
        }
    }

    /**
     * Hide the consent banner
     */
    function hideBanner() {
        const banner = document.querySelector('.cookie-banner');
        if (banner) {
            banner.classList.remove('show');
            banner.setAttribute('aria-hidden', 'true');
        }
    }

    /**
     * Show the consent banner
     */
    function showBanner() {
        const banner = document.querySelector('.cookie-banner');
        if (banner) {
            banner.classList.add('show');
            banner.setAttribute('aria-hidden', 'false');
            // Focus first interactive element for accessibility
            const firstBtn = banner.querySelector('button');
            if (firstBtn) {
                setTimeout(() => firstBtn.focus(), 100);
            }
        }
    }

    /**
     * Initialize consent system
     */
    function init() {
        if (hasValidConsent()) {
            loadOptionalScripts();
            return;
        }

        // Wait for DOM
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', showBanner);
        } else {
            showBanner();
        }

        // Set up button handlers
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('[data-consent]');
            if (!btn) return;

            const action = btn.getAttribute('data-consent');

            if (action === 'accept-all') {
                saveConsent('all');
                loadOptionalScripts();
            } else if (action === 'essential-only') {
                saveConsent('essential');
            }

            hideBanner();
        });

        // Close on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const banner = document.querySelector('.cookie-banner');
                if (banner && banner.classList.contains('show')) {
                    saveConsent('essential');
                    hideBanner();
                }
            }
        });
    }

    // Expose for external use
    window.BakudanConsent = {
        getLevel: getConsentLevel,
        hasConsent: hasValidConsent
    };

    init();
})();
