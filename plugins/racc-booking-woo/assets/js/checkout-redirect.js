/**
 * RACC Booking — WooCommerce Bridge
 * Frontend: enhances service cards with pricing from raccWooBridge.priceMap.
 * Booking-to-checkout redirect is handled directly in booking-form.js response flow.
 */
(function () {
    'use strict';

    if (typeof raccWooBridge === 'undefined') return;

    var bridge = raccWooBridge;

    // ── 1. Enhance service cards with pricing ────────────────────────────────
    // MutationObserver watches #racc-services-list and injects price badges
    // after the booking form JS renders service cards.

    function injectPriceOnCards() {
        if (bridge.priceDisplay !== 'yes') return;
        if (!bridge.priceMap || Object.keys(bridge.priceMap).length === 0) return;

        var cards = document.querySelectorAll('#racc-services-list .racc-service-card');
        cards.forEach(function (card) {
            // Skip if already priced.
            if (card.querySelector('.racc-woo-price')) return;

            var nameEl = card.querySelector('.racc-service-name');
            if (!nameEl) return;

            var serviceName = nameEl.textContent.trim();
            var price = bridge.priceMap[serviceName];
            if (!price) return;

            var badge = document.createElement('div');
            badge.className = 'racc-woo-price';
            badge.style.cssText = 'font-size:0.85em;color:#059669;font-weight:600;margin-top:6px;';
            badge.innerHTML = price;
            card.appendChild(badge);
        });
    }

    var servicesList = document.getElementById('racc-services-list');
    if (servicesList) {
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                if (m.addedNodes.length) {
                    injectPriceOnCards();
                }
            });
        });
        observer.observe(servicesList, { childList: true, subtree: true });
    }

    // Redirect behavior intentionally left to booking-form.js.

})();
