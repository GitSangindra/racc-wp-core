/**
 * RACC Booking — Frontend Booking Form JavaScript
 *
 * Booking flow:
 *   Step 1 — Select Service
 *   Step 2 — Select Consultant(s) [SKIPPED — auto-selected by service mapping]
 *   Step 3 — Schedule (Date + Timezone + Availability + Review)
 *   Step 4 — Fill in customer details & submit
 *
 * Consultants are resolved automatically from the Woo service mapping (all
 * consultants mapped to the selected service). Step 2 is never shown to the user.
 * The consultant (agent_id) is resolved from the chosen slot and never displayed.
 *
 * @package RACC_Booking
 */

(function () {
    'use strict';

    // ─── State ──────────────────────────────────────────────────────────────
    var state = {
        currentStep:      1,
        totalSteps:       4,
        services:         [],
        agents:           [],
        selectedService:  null,    // string — service label
        selectedAgentIds: [],      // array of agent IDs chosen by user
        selectedDate:     null,    // 'YYYY-MM-DD'
        selectedTimezone: null,    // IANA string
        availableSlots:   [],      // [{ start, end, agent_id }, …]
        selectedSlot:     null,    // { start, end, agent_id }
        isLoading:        false
    };

    // ─── Flatpickr instance + availability calendar cache ───────────────────
    var fpInstance       = null;  // Flatpickr instance (set in initDatePicker)
    var calendarCache    = {};    // { 'YYYY-MM': ['YYYY-MM-DD', …] | null }
    var availableDatesSet = {};   // flat set { 'YYYY-MM-DD': true } for onDayCreate dots
    var availabilityTimer = null;
    var availabilityRequestId = 0;
    var calendarAvailabilityRequestId = 0;
    var phoneInputInstance = null;

    // ─── DOM References ─────────────────────────────────────────────────────
    var $app, $steps, $stepContents, $prevBtn, $nextBtn, $submitBtn, $message;

    // ─── Initialize ─────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        $app = document.getElementById('racc-booking-app');
        if (!$app) return;

        $steps        = $app.querySelectorAll('.racc-step');
        $stepContents = $app.querySelectorAll('.racc-booking-step-content');
        $prevBtn      = document.getElementById('racc-prev-step');
        $nextBtn      = document.getElementById('racc-next-step');
        $submitBtn    = document.getElementById('racc-submit-booking');
        $message      = document.getElementById('racc-booking-message');

        // Default timezone from browser
        state.selectedTimezone = (
            (typeof Intl !== 'undefined' && Intl.DateTimeFormat().resolvedOptions().timeZone) ||
            raccBooking.timezone
        );

        loadServices();
        initDatePicker();
        initTimezoneDropdown();
        initPhoneInput();
        initSearchableSelects($app);
        initVisaCountryToggle();
        initDomicileStateToggle();
        initReferralParam();
        bindNavEvents();
    });

    function initPhoneInput() {
        var phoneInput = document.getElementById('racc-client-phone');
        if (!phoneInput || typeof window.intlTelInput !== 'function') return;

        var fallbackCountry = getFallbackPhoneCountry();

        phoneInputInstance = window.intlTelInput(phoneInput, {
            initialCountry: 'auto',
            separateDialCode: true,
            nationalMode: true,
            formatOnDisplay: true,
            customPlaceholder: function(selectedCountryPlaceholder, selectedCountryData) {
                var dialCode = '+' + selectedCountryData.dialCode;
                if (selectedCountryPlaceholder.indexOf(dialCode) === 0) {
                    return selectedCountryPlaceholder.substring(dialCode.length).trim();
                }
                return selectedCountryPlaceholder;
            },
            preferredCountries: ['au', 'id', 'nz', 'us', 'gb'],
            utilsScript: (raccBooking.phone && raccBooking.phone.utilsScript) || '',
            geoIpLookup: function (success, failure) {
                fetch('https://ipapi.co/json/')
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        var countryCode = data && data.country_code ? String(data.country_code).toLowerCase() : fallbackCountry;
                        success(countryCode);
                    })
                    .catch(function () {
                        if (typeof failure === 'function') {
                            failure();
                        }
                        success(fallbackCountry);
                    });
            }
        });
    }

    function getFallbackPhoneCountry() {
        var configuredCountry = raccBooking.phone && raccBooking.phone.defaultCountry
            ? String(raccBooking.phone.defaultCountry).toLowerCase()
            : 'au';
        var timezone = (
            (typeof Intl !== 'undefined' && Intl.DateTimeFormat().resolvedOptions().timeZone) ||
            raccBooking.timezone ||
            ''
        );
        var locale = (navigator.language || raccBooking.locale || '').toLowerCase();
        var timezoneCountries = {
            'Asia/Jakarta': 'id',
            'Asia/Makassar': 'id',
            'Asia/Jayapura': 'id',
            'Australia/Sydney': 'au',
            'Australia/Melbourne': 'au',
            'Australia/Brisbane': 'au',
            'Australia/Perth': 'au',
            'Australia/Adelaide': 'au',
            'Australia/Darwin': 'au',
            'Pacific/Auckland': 'nz',
            'America/New_York': 'us',
            'America/Chicago': 'us',
            'America/Denver': 'us',
            'America/Los_Angeles': 'us',
            'Europe/London': 'gb'
        };

        if (timezoneCountries[timezone]) {
            return timezoneCountries[timezone];
        }

        if (locale.indexOf('-') !== -1) {
            return locale.split('-').pop();
        }

        return configuredCountry;
    }

    function getClientPhoneValue() {
        var phoneInput = document.getElementById('racc-client-phone');
        if (!phoneInput) return '';

        var rawValue = phoneInput.value.trim();
        if (!rawValue) return '';

        if (phoneInputInstance) {
            var internationalNumber = phoneInputInstance.getNumber();
            if (internationalNumber) {
                return internationalNumber;
            }

            var countryData = phoneInputInstance.getSelectedCountryData ? phoneInputInstance.getSelectedCountryData() : null;
            var dialCode = countryData && countryData.dialCode ? String(countryData.dialCode) : '';
            var digits = rawValue.replace(/\D+/g, '');
            if (dialCode && digits && rawValue.charAt(0) !== '+') {
                return '+' + dialCode + digits.replace(/^0+/, '');
            }
        }

        return rawValue;
    }

    function getClientPhoneIso() {
        if (phoneInputInstance && phoneInputInstance.getSelectedCountryData) {
            var countryData = phoneInputInstance.getSelectedCountryData();
            if (countryData && countryData.iso2) {
                return countryData.iso2.toUpperCase();
            }
        }
        return '';
    }

    function getClientPhoneNational() {
        var international = getClientPhoneValue();
        if (phoneInputInstance && phoneInputInstance.getSelectedCountryData) {
            var countryData = phoneInputInstance.getSelectedCountryData();
            if (countryData && countryData.dialCode) {
                var dialCodeStr = '+' + countryData.dialCode;
                if (international.indexOf(dialCodeStr) === 0) {
                    return international.substring(dialCodeStr.length);
                }
            }
        }
        return international.replace(/\D+/g, '');
    }

    function isClientPhoneValid() {
        var phoneInput = document.getElementById('racc-client-phone');
        if (!phoneInput || !phoneInput.value.trim()) return false;

        if (phoneInputInstance && window.intlTelInputUtils && typeof phoneInputInstance.isValidNumber === 'function') {
            return phoneInputInstance.isValidNumber();
        }

        return /^\+?[0-9][0-9\s().-]{5,}$/.test(getClientPhoneValue());
    }

    function isOffshoreVisa(value) {
        return String(value || '').trim().toLowerCase() === 'offshore';
    }

    function isAustralia(value) {
        return String(value || '').trim().toLowerCase() === 'australia';
    }

    function resetSearchableSelect(select) {
        if (!select) return;

        select.value = '';

        var wrapper = select.closest ? select.closest('.racc-cs') : null;
        if (!wrapper) return;

        var trigger = wrapper.querySelector('.racc-cs-trigger');
        var valueEl = wrapper.querySelector('.racc-cs-value');
        if (valueEl) {
            valueEl.textContent = select.options[0] ? select.options[0].text : '';
        }
        if (trigger) {
            trigger.classList.remove('racc-cs-has-value');
        }
    }

    function initVisaCountryToggle() {
        var visaType = document.getElementById('racc-client-visa-type');
        var visaExpiryGroup = document.getElementById('racc-client-visa-expiry-group');
        var visaExpiry = document.getElementById('racc-client-visa-expiry');

        if (!visaType || !visaExpiryGroup || !visaExpiry) return;

        function syncVisaExpiryVisibility() {
            var isOffshore = isOffshoreVisa(visaType.value);

            visaExpiryGroup.style.display = isOffshore ? 'none' : '';
            visaExpiry.required = !isOffshore;

            if (isOffshore) {
                visaExpiry.value = '';
            }
        }

        visaType.addEventListener('change', syncVisaExpiryVisibility);
        syncVisaExpiryVisibility();
    }

    function syncDomicileStateVisibility() {
        var country = document.getElementById('racc-client-country');
        var stateGroup = document.getElementById('racc-client-state-group');
        var stateSelect = document.getElementById('racc-client-state');

        if (!country || !stateGroup || !stateSelect) return;

        var showState = isAustralia(country.value);
        stateGroup.style.display = showState ? '' : 'none';
        stateSelect.required = showState;

        if (!showState) {
            stateSelect.value = 'Offshore';
        } else if (stateSelect.value === 'Offshore') {
            stateSelect.value = '';
        }
    }

    function initDomicileStateToggle() {
        var country = document.getElementById('racc-client-country');
        if (!country) return;

        country.addEventListener('change', syncDomicileStateVisibility);
        syncDomicileStateVisibility();
    }

    function initSearchableSelects(scope) {
        var root = scope || document;
        var selects = root.querySelectorAll('select[data-racc-searchable-select="1"]');

        selects.forEach(function (select) {
            if (select.dataset.raccSearchInit === '1') return;
            select.dataset.raccSearchInit = '1';

            var placeholder = select.options[0] ? select.options[0].text : '— Select —';
            var searchPlaceholder = select.getAttribute('data-search-placeholder') || 'Search...';

            var allOptions = Array.prototype.slice.call(select.options).slice(1).map(function (option) {
                return { value: option.value, text: option.text };
            });

            // Hide native select but keep it for value reading
            select.style.display = 'none';

            // Build wrapper
            var wrapper = document.createElement('div');
            wrapper.className = 'racc-cs';

            var trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'racc-cs-trigger';
            trigger.innerHTML = '<span class="racc-cs-value">' + placeholder + '</span><span class="racc-cs-arrow">&#8964;</span>';

            var panel = document.createElement('div');
            panel.className = 'racc-cs-panel';
            panel.style.display = 'none';

            var search = document.createElement('input');
            search.type = 'search';
            search.className = 'racc-cs-search';
            search.placeholder = searchPlaceholder;
            search.setAttribute('autocomplete', 'off');

            var list = document.createElement('ul');
            list.className = 'racc-cs-list';

            panel.appendChild(search);
            panel.appendChild(list);
            wrapper.appendChild(trigger);
            wrapper.appendChild(panel);
            select.parentNode.insertBefore(wrapper, select);
            wrapper.appendChild(select);

            function renderList(query) {
                list.innerHTML = '';
                var q = (query || '').toLowerCase().trim();
                var matched = allOptions.filter(function (o) {
                    return !q || o.text.toLowerCase().indexOf(q) !== -1;
                });

                if (!matched.length) {
                    var empty = document.createElement('li');
                    empty.className = 'racc-cs-empty';
                    empty.textContent = 'No results';
                    list.appendChild(empty);
                    return;
                }

                matched.forEach(function (o) {
                    var li = document.createElement('li');
                    li.className = 'racc-cs-option' + (select.value === o.value ? ' selected' : '');
                    li.setAttribute('data-value', o.value);
                    // Highlight matching text
                    if (q) {
                        var idx = o.text.toLowerCase().indexOf(q);
                        li.innerHTML = escHtml(o.text.substring(0, idx))
                            + '<mark>' + escHtml(o.text.substring(idx, idx + q.length)) + '</mark>'
                            + escHtml(o.text.substring(idx + q.length));
                    } else {
                        li.textContent = o.text;
                    }
                    li.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        select.value = o.value;
                        trigger.querySelector('.racc-cs-value').textContent = o.text;
                        trigger.classList.add('racc-cs-has-value');
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                        closePanel();
                    });
                    list.appendChild(li);
                });
            }

            function openPanel() {
                panel.style.display = 'block';
                trigger.classList.add('racc-cs-open');
                search.value = '';
                renderList('');
                search.focus();
            }

            function closePanel() {
                panel.style.display = 'none';
                trigger.classList.remove('racc-cs-open');
            }

            trigger.addEventListener('click', function () {
                panel.style.display === 'none' ? openPanel() : closePanel();
            });

            search.addEventListener('input', function () {
                renderList(search.value);
            });

            document.addEventListener('click', function (e) {
                if (!wrapper.contains(e.target)) closePanel();
            });

            // If existing value pre-selected (e.g. edit form)
            if (select.value) {
                var found = allOptions.filter(function (o) { return o.value === select.value; })[0];
                if (found) {
                    trigger.querySelector('.racc-cs-value').textContent = found.text;
                    trigger.classList.add('racc-cs-has-value');
                }
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // STEP 1 — Services
    // ═══════════════════════════════════════════════════════════════════════

    function loadServices() {
        var container = document.getElementById('racc-services-list');
        if (!container) return;
        container.innerHTML = '<div class="racc-loading"><div class="racc-spinner"></div><p>' + raccBooking.i18n.loading + '</p></div>';

        fetch(raccBooking.restUrl + 'services', {
            headers: { 'X-WP-Nonce': raccBooking.nonce }
        })
        .then(function (res) { return res.json(); })
        .then(function (services) {
            state.services = services;
            renderServices(services);
        })
        .catch(function () {
            container.innerHTML = '<p style="color:#ef4444;text-align:center;">' + raccBooking.i18n.loadServicesError + '</p>';
        });
    }

    function renderServices(services) {
        var container = document.getElementById('racc-services-list');
        container.innerHTML = '';

        if (!services || !services.length) {
            container.innerHTML = '<p style="text-align:center;color:#94a3b8;">' + raccBooking.i18n.noServices + '</p>';
            return;
        }

        services.forEach(function (service) {
            var card = document.createElement('div');
            card.className = 'racc-service-card';
            card.innerHTML =
                '<div class="racc-service-icon">📋</div>' +
                '<div class="racc-service-name">' + escHtml(service) + '</div>';

            card.addEventListener('click', function () {
                selectService(service, card);
            });

            container.appendChild(card);
        });
    }

    function selectService(service, cardEl) {
        // Reset all downstream state
        state.selectedService  = service;
        state.selectedAgentIds = [];
        state.selectedDate     = null;
        state.availableSlots   = [];
        state.selectedSlot     = null;
        calendarCache          = {};  // invalidate availability cache for the new service
        availableDatesSet      = {};  // clear available-day dot markers
        if (fpInstance) fpInstance.redraw();
        hideGoogleAlert();

        var checkBtn = document.getElementById('racc-check-availability');
        var slotsWrap = document.getElementById('racc-time-slots');
        var reviewCard = document.getElementById('racc-review-card');
        if (checkBtn) checkBtn.disabled = true;
        if (slotsWrap) slotsWrap.style.display = 'none';
        if (reviewCard) reviewCard.style.display = 'none';

        document.querySelectorAll('.racc-service-card').forEach(function (c) { c.classList.remove('selected'); });
        cardEl.classList.add('selected');

        // Auto-resolve all consultants for this service (skip step 2 UI)
        autoSelectAgentsForService(service, function () {
            goToStep(3);
        });
    }

    /**
     * Fetch agents list (cached after first call), filter by service mapping,
     * set state.selectedAgentIds to all matching IDs, then invoke callback.
     */
    function autoSelectAgentsForService(service, callback) {
        function applyFilter(agents) {
            var filtered = sortAgentsByPriority(filterAgentsByService(agents, service));
            state.selectedAgentIds = filtered.map(function (a) { return a.id; });
            if (typeof callback === 'function') callback();
            // Check Google Calendar connectivity and show early warning if needed.
            checkGoogleConnectivity();
            // Fetch availability dots for the currently visible calendar month.
            if (fpInstance && state.selectedAgentIds.length) {
                loadCalendarAvailability(fpInstance.currentYear, fpInstance.currentMonth + 1);
            }
        }

        if (state.agents.length) {
            applyFilter(state.agents);
            return;
        }

        fetch(raccBooking.restUrl + 'agents', {
            headers: { 'X-WP-Nonce': raccBooking.nonce }
        })
        .then(function (res) { return res.json(); })
        .then(function (agents) {
            state.agents = agents;
            applyFilter(agents);
        })
        .catch(function () {
            // If fetch fails, proceed with empty agents (backend fallback will handle assignment)
            if (typeof callback === 'function') callback();
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // STEP 2 — Consultants (availability source; no email/contact shown)
    // ═══════════════════════════════════════════════════════════════════════

    function loadConsultants() {
        var container = document.getElementById('racc-agents-list');
        if (!container) return;
        container.innerHTML = '<div class="racc-loading"><div class="racc-spinner"></div><p>' + raccBooking.i18n.loading + '</p></div>';

        // Re-use cached agents list when possible
        if (state.agents.length) {
            renderConsultants(sortAgentsByPriority(filterAgentsByService(state.agents, state.selectedService)));
            return;
        }

        fetch(raccBooking.restUrl + 'agents', {
            headers: { 'X-WP-Nonce': raccBooking.nonce }
        })
        .then(function (res) { return res.json(); })
        .then(function (agents) {
            state.agents = agents;
            renderConsultants(sortAgentsByPriority(filterAgentsByService(agents, state.selectedService)));
        })
        .catch(function () {
            container.innerHTML = '<p style="color:#ef4444;text-align:center;">' + raccBooking.i18n.loadAgentsError + '</p>';
        });
    }

    function filterAgentsByService(agents, service) {
        if (!service) return agents;

        if (
            typeof raccWooBridge !== 'undefined' &&
            raccWooBridge.serviceMap &&
            raccWooBridge.serviceMap[service] &&
            Array.isArray(raccWooBridge.serviceMap[service].consultant_ids)
        ) {
            var consultantIds = raccWooBridge.serviceMap[service].consultant_ids.map(function (id) {
                return parseInt(id, 10);
            });

            if (!consultantIds.length) {
                return [];
            }

            return agents.filter(function (a) {
                return consultantIds.indexOf(parseInt(a.id, 10)) !== -1;
            });
        }

        // Woo bridge is required for service mapping.
        return [];
    }

    function sortAgentsByPriority(agents) {
        var ccEl = document.getElementById('racc-client-country');
        var cnEl = document.getElementById('racc-client-nationality');
        var clientCountry = ccEl ? ccEl.value.trim() : '';
        var clientNationality = cnEl ? cnEl.value.trim() : '';

        return agents.sort(function(a, b) {
            var scoreA = 0;
            var scoreB = 0;

            var covA = Array.isArray(a.nation_coverage) ? a.nation_coverage : [];
            var covB = Array.isArray(b.nation_coverage) ? b.nation_coverage : [];

            // Tier 1: Domicile Match
            if (clientCountry && covA.indexOf(clientCountry) !== -1) scoreA += 100;
            if (clientCountry && covB.indexOf(clientCountry) !== -1) scoreB += 100;

            // Tier 2: Nationality Match
            if (clientNationality && covA.indexOf(clientNationality) !== -1) scoreA += 50;
            if (clientNationality && covB.indexOf(clientNationality) !== -1) scoreB += 50;

            if (scoreA !== scoreB) {
                return scoreB - scoreA;
            }
            return parseInt(a.id, 10) - parseInt(b.id, 10);
        });
    }

    function renderConsultants(agents) {
        var container = document.getElementById('racc-agents-list');
        container.innerHTML = '';

        if (!agents || !agents.length) {
            container.innerHTML = '<p style="text-align:center;color:#94a3b8;">' + raccBooking.i18n.noAgents + '</p>';
            return;
        }

        // ── "Select All" button ──────────────────────────────────────────
        var selectAllBtn = document.createElement('button');
        selectAllBtn.type      = 'button';
        selectAllBtn.className = 'racc-btn racc-btn-outline racc-select-all-btn';
        selectAllBtn.textContent = raccBooking.i18n.selectAll;
        selectAllBtn.addEventListener('click', function () {
            state.selectedAgentIds = agents.map(function (a) { return a.id; });
            container.querySelectorAll('.racc-agent-card').forEach(function (c) { c.classList.add('selected'); });
            selectAllBtn.classList.add('active');
            // Reset downstream
            state.selectedDate   = null;
            state.availableSlots = [];
            state.selectedSlot   = null;
            updateNavigation();
        });
        container.appendChild(selectAllBtn);

        // ── Individual consultant cards (name + avatar ONLY) ─────────────
        agents.forEach(function (agent) {
            var card = document.createElement('div');
            card.className      = 'racc-agent-card';
            card.dataset.agentId = agent.id;

            var initials = agent.name
                .split(' ')
                .map(function (n) { return n[0]; })
                .join('')
                .toUpperCase()
                .substring(0, 2);

            // NOTE: email intentionally omitted — consultant is an availability source only
            card.innerHTML =
                '<div class="racc-agent-avatar">' + initials + '</div>' +
                '<div class="racc-agent-name">' + escHtml(agent.name) + '</div>';

            card.addEventListener('click', function () {
                toggleAgentSelection(agent.id, card);
                selectAllBtn.classList.remove('active');
            });

            container.appendChild(card);
        });
    }

    function toggleAgentSelection(agentId, cardEl) {
        var idx = state.selectedAgentIds.indexOf(agentId);
        if (idx === -1) {
            state.selectedAgentIds.push(agentId);
            cardEl.classList.add('selected');
        } else {
            state.selectedAgentIds.splice(idx, 1);
            cardEl.classList.remove('selected');
        }

        // Reset downstream
        state.selectedDate   = null;
        state.availableSlots = [];
        state.selectedSlot   = null;

        var checkBtn = document.getElementById('racc-check-availability');
        var slotsWrap = document.getElementById('racc-time-slots');
        var reviewCard = document.getElementById('racc-review-card');
        if (checkBtn) checkBtn.disabled = true;
        if (slotsWrap) slotsWrap.style.display = 'none';
        if (reviewCard) reviewCard.style.display = 'none';

        updateNavigation();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // STEP 3 — Date Picker
    // ═══════════════════════════════════════════════════════════════════════

    function initDatePicker() {
        var dateInput = document.getElementById('racc-date-picker');
        if (!dateInput || typeof flatpickr === 'undefined') return;

        fpInstance = flatpickr(dateInput, {
            dateFormat:    'Y-m-d',
            minDate:       'today',
            inline:        true,
            disableMobile: true,
            onMonthChange: onCalendarMonthChange,
            onYearChange:  onCalendarMonthChange,
            onDayCreate: function (dObj, dStr, fp, dayElem) {
                if (!dayElem.dateObj) return;
                var d = dateObjToYMD(dayElem.dateObj);
                if (availableDatesSet[d]) {
                    dayElem.classList.add('racc-day-available');
                }
            },
            onChange: function (selectedDates, dateStr) {
                state.selectedDate   = dateStr;
                state.availableSlots = [];
                state.selectedSlot   = null;

                hideNearestAvailableSuggestion();

                var slotsWrap  = document.getElementById('racc-time-slots');
                var checkBtn   = document.getElementById('racc-check-availability');
                var reviewCard = document.getElementById('racc-review-card');
                if (slotsWrap)  slotsWrap.style.display  = 'none';
                if (checkBtn)   checkBtn.disabled         = !state.selectedDate;
                if (reviewCard) reviewCard.style.display  = 'none';

                updateNavigation();
                scheduleAvailabilityCheck();
            }
        });
    }

    /** Convert a JS Date object to 'YYYY-MM-DD' without timezone drift. */
    function dateObjToYMD(d) {
        var y   = d.getFullYear();
        var m   = d.getMonth() + 1;
        var day = d.getDate();
        return y + '-' + (m < 10 ? '0' + m : m) + '-' + (day < 10 ? '0' + day : day);
    }

    /** Add N days to a 'YYYY-MM-DD' string, timezone-safe. */
    function addDaysToDateStr(dateStr, days) {
        var p   = dateStr.split('-');
        var d   = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10) + days);
        return dateObjToYMD(d);
    }

    function onCalendarMonthChange(selectedDates, dateStr, fp) {
        if (!state.selectedAgentIds.length) return;
        loadCalendarAvailability(fp.currentYear, fp.currentMonth + 1);
    }

    function loadCalendarAvailability(year, month) {
        if (!state.selectedAgentIds.length || !fpInstance) return;
        var cacheKey = year + '-' + (month < 10 ? '0' + month : month);
        var selectedProductId = getSelectedProductId();
        var requestId = ++calendarAvailabilityRequestId;

        if (calendarCache[cacheKey] !== undefined) {
            applyCalendarAvailability(calendarCache[cacheKey]);
            setCalendarLoading(false);
            return;
        }

        setCalendarLoading(true);
        var url = raccBooking.restUrl + 'availability-calendar'
            + '?agent_ids=' + state.selectedAgentIds.join(',')
            + '&year='  + year
            + '&month=' + month;

        if (selectedProductId > 0) {
            url += '&woo_product_id=' + selectedProductId;
        }

        fetch(url, { headers: { 'X-WP-Nonce': raccBooking.nonce } })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var dates = (data && Array.isArray(data.available_dates)) ? data.available_dates : [];
                calendarCache[cacheKey] = dates;
                if (
                    requestId !== calendarAvailabilityRequestId ||
                    selectedProductId !== getSelectedProductId()
                ) {
                    return;
                }
                applyCalendarAvailability(dates);
            })
            .catch(function () {
                calendarCache[cacheKey] = null; // mark failed — don't retry
            })
            .then(function () {
                if (requestId === calendarAvailabilityRequestId) {
                    setCalendarLoading(false);
                }
            });
    }

    function applyCalendarAvailability(dates) {
        if (!fpInstance || !dates) return;
        dates.forEach(function (d) { availableDatesSet[d] = true; });
        fpInstance.redraw();
    }

    function setCalendarLoading(loading) {
        var cal = fpInstance && fpInstance.calendarContainer
            ? fpInstance.calendarContainer
            : document.querySelector('.racc-date-picker-wrap .flatpickr-calendar');
        if (!cal) return;

        var panel = cal.querySelector('.racc-calendar-loading-panel');
        if (!panel) {
            panel = document.createElement('div');
            panel.className = 'racc-calendar-loading-panel';
            panel.setAttribute('role', 'status');
            panel.setAttribute('aria-live', 'polite');
            panel.innerHTML = '<span class="racc-calendar-loading-spinner" aria-hidden="true"></span><span>Loading availability...</span>';
            cal.appendChild(panel);
        }

        cal.classList.toggle('racc-calendar-loading', loading);
        cal.setAttribute('aria-busy', loading ? 'true' : 'false');
        panel.style.display = loading ? 'flex' : 'none';
    }

    // ═══════════════════════════════════════════════════════════════════════
    // STEP 4 — Timezone Dropdown
    // ═══════════════════════════════════════════════════════════════════════

    var TIMEZONES = [
        'Pacific/Midway', 'Pacific/Honolulu', 'America/Anchorage', 'America/Los_Angeles',
        'America/Denver', 'America/Chicago', 'America/New_York', 'America/Sao_Paulo',
        'Atlantic/Azores', 'Europe/London', 'Europe/Paris', 'Europe/Helsinki',
        'Asia/Dubai', 'Asia/Karachi', 'Asia/Kolkata', 'Asia/Dhaka', 'Asia/Bangkok',
        'Asia/Singapore', 'Asia/Shanghai', 'Asia/Tokyo', 'Asia/Seoul',
        'Australia/Perth', 'Australia/Darwin', 'Australia/Adelaide',
        'Australia/Sydney', 'Australia/Brisbane', 'Pacific/Auckland'
    ];

    function initTimezoneDropdown() {
        var select = document.getElementById('racc-timezone-select');
        if (!select) return;

        var browserTz = (
            (typeof Intl !== 'undefined' && Intl.DateTimeFormat().resolvedOptions().timeZone) ||
            raccBooking.timezone
        );
        state.selectedTimezone = browserTz;

        // Prepend browser timezone if not already in list
        if (TIMEZONES.indexOf(browserTz) === -1) {
            var opt = document.createElement('option');
            opt.value       = browserTz;
            opt.textContent = browserTz.replace(/_/g, ' ') + ' ' + raccBooking.i18n.yourTimezone;
            opt.selected    = true;
            select.appendChild(opt);
        }

        TIMEZONES.forEach(function (tz) {
            var opt = document.createElement('option');
            opt.value       = tz;
            opt.textContent = tz.replace(/_/g, ' ');
            if (tz === browserTz) opt.selected = true;
            select.appendChild(opt);
        });

        select.addEventListener('change', function () {
            state.selectedTimezone = this.value;
            state.availableSlots   = [];
            state.selectedSlot     = null;
            var slotsWrap = document.getElementById('racc-time-slots');
            var reviewCard = document.getElementById('racc-review-card');
            if (slotsWrap) slotsWrap.style.display = 'none';
            if (reviewCard) reviewCard.style.display = 'none';
            updateNavigation();
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Google Calendar Connectivity
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Check whether ALL selected agents have google_connected === false and, if so,
     * show the persistent alert banner immediately on step 3.
     * Called when agents are resolved and when step 3 is entered.
     */
    function checkGoogleConnectivity() {
        if (!state.selectedAgentIds.length || !state.agents.length) {
            hideGoogleAlert();
            return;
        }

        var selectedAgents = state.agents.filter(function (a) {
            return state.selectedAgentIds.indexOf(a.id) !== -1;
        });

        // Only show warning when we KNOW every selected agent has no Google connection.
        var allDisconnected = selectedAgents.length > 0 && selectedAgents.every(function (a) {
            return a.google_connected === false;
        });

        if (allDisconnected) {
            showGoogleAlert({ code: 'google_not_connected' });
            var checkBtn = document.getElementById('racc-check-availability');
            if (checkBtn) checkBtn.disabled = true;
        } else {
            hideGoogleAlert();
        }
    }

    function showGoogleAlert(err) {
        var alertEl = document.getElementById('racc-google-alert');
        if (!alertEl) return;

        var code = (err && err.code) || '';
        var msg  = (code === 'google_reconnect_required')
            ? (raccBooking.i18n.googleReconnectRequired || 'Calendar connection expired. Please contact support to reconnect.')
            : (raccBooking.i18n.googleNotConnected      || 'Consultant calendar is not connected yet. Please contact admin.');

        alertEl.innerHTML    = '<span class="racc-google-alert-icon">⚠️</span><span>' + msg + '</span>';
        alertEl.style.display = 'flex';
    }

    function hideGoogleAlert() {
        var alertEl = document.getElementById('racc-google-alert');
        if (alertEl) alertEl.style.display = 'none';
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Schedule — Availability & Time Slots
    // ═══════════════════════════════════════════════════════════════════════

    function checkAvailability() {
        if (!state.selectedAgentIds.length || !state.selectedDate) return;
        var selectedProductId = getSelectedProductId();
        var requestId = ++availabilityRequestId;
        var requestDate = state.selectedDate;
        var requestAgents = state.selectedAgentIds.join(',');

        var checkBtn  = document.getElementById('racc-check-availability');
        var slotsWrap = document.getElementById('racc-time-slots');
        var slotsGrid = document.getElementById('racc-slots-grid');

        if (!slotsWrap || !slotsGrid) return;

        if (checkBtn) {
            checkBtn.disabled    = true;
            checkBtn.textContent = raccBooking.i18n.loading;
        }
        slotsGrid.innerHTML  = '';

        hideGoogleAlert();

        state.selectedSlot = null;
        updateReview();

        // Fetch availability from all selected agents in parallel.
        // Use { __error: body } markers to surface connectivity errors instead of silently discarding them.
        var promises = state.selectedAgentIds.map(function (agentId) {
            var url = raccBooking.restUrl + 'availability?agent_id=' + agentId + '&date=' + state.selectedDate;
            if (selectedProductId > 0) {
                url += '&woo_product_id=' + selectedProductId;
            }
            return fetch(url, { headers: { 'X-WP-Nonce': raccBooking.nonce } })
                .then(function (res) {
                    return res.json().then(function (body) {
                        if (!res.ok) return { __error: body };
                        return (body || []).map(function (slot) {
                            return { start: slot.start, end: slot.end, agent_id: agentId };
                        });
                    });
                })
                .catch(function () { return []; });
        });

        Promise.all(promises).then(function (results) {
            if (
                requestId !== availabilityRequestId ||
                requestDate !== state.selectedDate ||
                requestAgents !== state.selectedAgentIds.join(',') ||
                selectedProductId !== getSelectedProductId()
            ) {
                return;
            }

            var errors = [];
            var merged = [];
            var seen   = {};

            results.forEach(function (result) {
                if (result && result.__error) {
                    errors.push(result.__error);
                } else {
                    (result || []).forEach(function (slot) {
                        var key = slot.start + '-' + slot.end;
                        if (!seen[key]) {
                            seen[key] = true;
                            merged.push(slot);
                        }
                    });
                }
            });

            merged.sort(function (a, b) { return a.start.localeCompare(b.start); });

            state.availableSlots = merged;

            if (checkBtn) {
                checkBtn.disabled    = false;
                checkBtn.textContent = raccBooking.i18n.checkAvail;
            }

            // If ALL agents returned a Google connectivity error, show the persistent banner.
            if (!merged.length && errors.length > 0 && errors.length === state.selectedAgentIds.length) {
                var errCode = (errors[0] && errors[0].code) || '';
                if (errCode === 'google_not_connected' || errCode === 'google_reconnect_required') {
                    showGoogleAlert(errors[0]);
                    slotsWrap.style.display = 'none';
                    updateNavigation();
                    return;
                }
            }

            renderSlots(merged);
            slotsWrap.style.display = 'block';
            updateNavigation();

            // If no slots on this date, find and suggest the nearest available date.
            if (!merged.length && state.selectedDate && state.selectedAgentIds.length) {
                fetchNearestAvailable();
            } else {
                hideNearestAvailableSuggestion();
            }
        });
    }

    function scheduleAvailabilityCheck() {
        clearTimeout(availabilityTimer);
        availabilityRequestId++;
        if (!state.selectedAgentIds.length || !state.selectedDate) return;
        availabilityTimer = setTimeout(checkAvailability, 250);
    }

    function fetchNearestAvailable() {
        // Search from the day after the selected date (selected date already has no slots).
        var fromStr   = addDaysToDateStr(state.selectedDate, 1);
        var slotsGrid = document.getElementById('racc-slots-grid');
        var selectedProductId = getSelectedProductId();

        if (slotsGrid) {
            var searching = document.createElement('div');
            searching.id        = 'racc-nearest-searching';
            searching.className = 'racc-nearest-searching';
            searching.textContent = raccBooking.i18n.searchingNearest || 'Searching for the nearest available date…';
            slotsGrid.appendChild(searching);
        }

        var url = raccBooking.restUrl + 'nearest-available'
            + '?agent_ids=' + state.selectedAgentIds.join(',')
            + '&from='      + fromStr;

        if (selectedProductId > 0) {
            url += '&woo_product_id=' + selectedProductId;
        }

        fetch(url, { headers: { 'X-WP-Nonce': raccBooking.nonce } })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var el = document.getElementById('racc-nearest-searching');
                if (el) el.parentNode.removeChild(el);
                showNearestAvailableSuggestion(data);
            })
            .catch(function () {
                var el = document.getElementById('racc-nearest-searching');
                if (el) el.parentNode.removeChild(el);
            });
    }

    function showNearestAvailableSuggestion(result) {
        hideNearestAvailableSuggestion();

        var slotsGrid = document.getElementById('racc-slots-grid');
        if (!slotsGrid) return;

        if (!result || !result.date) {
            var noResult = document.createElement('div');
            noResult.id        = 'racc-nearest-suggestion';
            noResult.className = 'racc-nearest-suggestion racc-nearest-none';
            noResult.textContent = raccBooking.i18n.noAvailableDates || 'No available dates found in the next 60 days.';
            slotsGrid.appendChild(noResult);
            return;
        }

        var wrap = document.createElement('div');
        wrap.id        = 'racc-nearest-suggestion';
        wrap.className = 'racc-nearest-suggestion';

        var icon = document.createElement('span');
        icon.className   = 'racc-nearest-icon';
        icon.textContent = '\uD83D\uDCC5'; // 📅

        var textWrap = document.createElement('div');
        textWrap.className = 'racc-nearest-text';

        var label = document.createElement('span');
        label.className   = 'racc-nearest-label';
        label.textContent = (raccBooking.i18n.nearestAvailable || 'Next available:') + ' ';

        var dateText = document.createElement('strong');
        dateText.textContent = result.formatted || formatDateFriendly(result.date);

        textWrap.appendChild(label);
        textWrap.appendChild(dateText);

        var jumpBtn = document.createElement('button');
        jumpBtn.type        = 'button';
        jumpBtn.className   = 'racc-nearest-jump-btn';
        jumpBtn.textContent = raccBooking.i18n.jumpThere || 'Jump there \u2192';
        jumpBtn.addEventListener('click', function () {
            if (fpInstance) {
                fpInstance.setDate(result.date, true); // true triggers onChange
                fpInstance.jumpToDate(result.date);
            }
        });

        wrap.appendChild(icon);
        wrap.appendChild(textWrap);
        wrap.appendChild(jumpBtn);
        slotsGrid.appendChild(wrap);
    }

    function hideNearestAvailableSuggestion() {
        var s = document.getElementById('racc-nearest-searching');
        if (s) s.parentNode.removeChild(s);
        var e = document.getElementById('racc-nearest-suggestion');
        if (e) e.parentNode.removeChild(e);
    }

    function renderSlots(slots) {
        var grid = document.getElementById('racc-slots-grid');
        grid.innerHTML = '';

        if (!slots || !slots.length) {
            grid.innerHTML = '<div class="racc-no-slots">' + raccBooking.i18n.noSlots + '</div>';
            return;
        }

        slots.forEach(function (slot) {
            var btn       = document.createElement('button');
            btn.type      = 'button';
            btn.className = 'racc-slot-btn';
            btn.textContent = slot.start + ' — ' + slot.end;

            btn.addEventListener('click', function () {
                grid.querySelectorAll('.racc-slot-btn').forEach(function (b) { b.classList.remove('selected'); });
                btn.classList.add('selected');

                // agent_id is stored internally — NOT shown to the user
                state.selectedSlot = { start: slot.start, end: slot.end, agent_id: slot.agent_id };
                updateReview();
                updateNavigation();
            });

            grid.appendChild(btn);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Schedule Review
    // ═══════════════════════════════════════════════════════════════════════

    function updateReview() {
        var reviewCard = document.getElementById('racc-review-card');
        var el;
        el = document.getElementById('racc-review-service');
        if (el) el.textContent = state.selectedService || '—';

        el = document.getElementById('racc-review-date');
        if (el) el.textContent = state.selectedDate ? formatDate(state.selectedDate) : '—';

        el = document.getElementById('racc-review-time');
        if (el) el.textContent = state.selectedSlot ? (state.selectedSlot.start + ' — ' + state.selectedSlot.end) : '—';

        el = document.getElementById('racc-review-timezone');
        if (el) el.textContent = (state.selectedTimezone || '—').replace(/_/g, ' ');

        if (reviewCard) {
            reviewCard.style.display = state.selectedSlot ? 'block' : 'none';
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Step 4 — Booking Summary (sidebar on details step)
    // ═══════════════════════════════════════════════════════════════════════

    function updateSummary() {
        var summaryDiv = document.getElementById('racc-booking-summary');
        if (!summaryDiv) return;

        if (state.selectedService && state.selectedDate && state.selectedSlot) {
            var el;
            el = document.getElementById('racc-summary-service');
            if (el) el.textContent = state.selectedService;

            el = document.getElementById('racc-summary-datetime');
            if (el) el.textContent = formatDate(state.selectedDate) + ' at ' + state.selectedSlot.start + ' — ' + state.selectedSlot.end;

            el = document.getElementById('racc-summary-timezone');
            if (el) el.textContent = (state.selectedTimezone || '').replace(/_/g, ' ');

            summaryDiv.style.display = 'block';
        }

        updateNavigation();
    }

    function hideBookingFlow() {
        $app.querySelectorAll('.racc-booking-step-content').forEach(function (content) {
            content.style.display = 'none';
        });

        var nav = $app.querySelector('.racc-booking-nav');
        var steps = $app.querySelector('.racc-booking-steps');

        if (nav) nav.style.display = 'none';
        if (steps) steps.style.display = 'none';
    }

    function showFinalBookingSummary(response) {
        var summary = document.getElementById('racc-final-summary');
        if (!summary) return;

        var paymentLink = document.getElementById('racc-final-payment-link');
        var actions = document.getElementById('racc-final-actions');
        var notesWrap = document.getElementById('racc-final-notes-wrap');
        var notesEl = document.getElementById('racc-final-notes');
        var statusText = response.checkout_url ? 'Awaiting Payment' : 'Booked';
        var timezoneText = (response.agent_timezone || state.selectedTimezone || 'UTC').replace(/_/g, ' ');
        var scheduleText = '';

        if (response.booking_date) {
            scheduleText = formatDate(response.booking_date);
        }

        if (response.time_start && response.time_end) {
            scheduleText += (scheduleText ? ' at ' : '') + response.time_start + ' — ' + response.time_end;
        }

        setText('racc-final-booking-id', response.booking_id ? ('#' + response.booking_id) : '—');
        setText('racc-final-booking-status', statusText);
        setText('racc-final-service', response.service_type || state.selectedService || '—');
        setText('racc-final-agent', response.agent_name || '—');
        setText('racc-final-schedule', scheduleText || '—');
        setText('racc-final-timezone', timezoneText);
        setText('racc-final-client-name', response.client_name || '—');
        setText('racc-final-client-email', response.client_email || '—');
        setText('racc-final-client-phone', response.client_phone || '—');

        if (notesWrap && notesEl) {
            if (response.notes) {
                notesEl.textContent = response.notes;
                notesWrap.style.display = 'block';
            } else {
                notesEl.textContent = '';
                notesWrap.style.display = 'none';
            }
        }

        if (paymentLink && actions) {
            if (response.checkout_url) {
                paymentLink.href = response.checkout_url;
                paymentLink.style.display = 'inline-flex';
                actions.style.display = 'flex';
            } else {
                paymentLink.href = '#';
                paymentLink.style.display = 'none';
                actions.style.display = 'none';
            }
        }

        summary.style.display = 'block';
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Step Navigation
    // ═══════════════════════════════════════════════════════════════════════

    function goToStep(step) {
        if (step < 1 || step > state.totalSteps) return;

        // Step 2 (Consultant) is always skipped — auto-resolved by service mapping
        if (step === 2) {
            step = (state.currentStep < 2) ? 3 : 1;
        }

        // Side-effects when entering a step
        if (step === 3) {
            updateReview();
            checkGoogleConnectivity();
            if (fpInstance && state.selectedAgentIds.length) {
                loadCalendarAvailability(fpInstance.currentYear, fpInstance.currentMonth + 1);
            }
            if (state.selectedDate && !state.selectedSlot) {
                scheduleAvailabilityCheck();
            }
        }

        state.currentStep = step;

        // Update step indicator badges (visible order: 1 -> 3 -> 4)
        $steps.forEach(function (s) {
            var stepValue = parseInt(s.dataset.step, 10);
            var currentOrder = (step === 4) ? 3 : ((step === 3) ? 2 : 1);
            var badgeOrder   = (stepValue === 4) ? 3 : ((stepValue === 3) ? 2 : 1);

            s.classList.remove('active', 'completed');
            if      (badgeOrder === currentOrder) s.classList.add('active');
            else if (badgeOrder < currentOrder)   s.classList.add('completed');
        });

        // Show / hide step panels
        $stepContents.forEach(function (panel) {
            panel.style.display = (parseInt(panel.dataset.step) === step) ? 'block' : 'none';
        });

        // Nav buttons
        $prevBtn.style.display = (step > 1) ? 'inline-flex' : 'none';

        if (step === state.totalSteps) {
            $nextBtn.style.display   = 'none';
            $submitBtn.style.display = 'inline-flex';
            updateSummary();
        } else {
            $nextBtn.style.display   = 'inline-flex';
            $submitBtn.style.display = 'none';
        }

        updateNavigation();
    }

    function nextStep() {
        if (canProceed()) goToStep(state.currentStep + 1);
    }

    function prevStep() {
        goToStep(state.currentStep - 1);
    }

    function canProceed() {
        switch (state.currentStep) {
            case 1: return !!state.selectedService;
            // case 2 is skipped — consultants are auto-selected
            case 3: return !!state.selectedDate && !!state.selectedTimezone && !!state.selectedSlot;
            case 4: {
                var nameEl  = document.getElementById('racc-client-name');
                var emailEl = document.getElementById('racc-client-email');
                var phoneEl = document.getElementById('racc-client-phone');
                var name    = nameEl  ? nameEl.value.trim()  : '';
                var email   = emailEl ? emailEl.value.trim() : '';
                var phone   = phoneEl ? phoneEl.value.trim() : '';
                return name !== '' && email !== '' && phone !== '' && isValidEmail(email);
            }
            default: return false;
        }
    }

    function updateNavigation() {
        if ($nextBtn)   $nextBtn.disabled   = !canProceed();
        if ($submitBtn && state.currentStep === state.totalSteps) {
            $submitBtn.disabled = !canProceed();
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Events
    // ═══════════════════════════════════════════════════════════════════════

    function initReferralParam() {
        if (typeof window.URLSearchParams === 'undefined') return;
        var urlParams = new URLSearchParams(window.location.search);
        var refParam = urlParams.get('ref');
        
        if (refParam) {
            var mapping = (raccBooking && raccBooking.referralMapping) ? raccBooking.referralMapping : {};
            var paramNormalized = refParam.toLowerCase().trim();
            var dropdownValue = '';
            
            // Check mapped values first
            if (mapping.hasOwnProperty(paramNormalized)) {
                dropdownValue = mapping[paramNormalized];
            } else {
                // Fallback: check exact dropdown options
                var $options = document.querySelectorAll('#racc-client-referral option');
                for (var i = 0; i < $options.length; i++) {
                    if ($options[i].value.toLowerCase() === paramNormalized) {
                        dropdownValue = $options[i].value;
                        break;
                    }
                }
            }
            
            if (dropdownValue) {
                var $refDropdown = document.getElementById('racc-client-referral');
                if ($refDropdown) {
                    $refDropdown.value = dropdownValue;
                    $refDropdown.disabled = true;
                    // Styling for disabled field to look read-only
                    $refDropdown.style.backgroundColor = '#f0f0f1';
                    $refDropdown.style.cursor = 'not-allowed';
                    $refDropdown.style.opacity = '1';
                }
            }
        }
    }

    function bindNavEvents() {
        var checkBtn = document.getElementById('racc-check-availability');
        if (checkBtn) checkBtn.addEventListener('click', function () {
            clearTimeout(availabilityTimer);
            checkAvailability();
        });

        if ($prevBtn)   $prevBtn.addEventListener('click',   prevStep);
        if ($nextBtn)   $nextBtn.addEventListener('click',   nextStep);
        if ($submitBtn) $submitBtn.addEventListener('click', submitBooking);

        // Re-validate when user types in the primary step 4 fields.
        ['racc-client-name', 'racc-client-email', 'racc-client-phone'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('input', updateNavigation);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Submit Booking
    // ═══════════════════════════════════════════════════════════════════════

    function submitBooking() {
        if (!canProceed()) return;

        var clientName             = document.getElementById('racc-client-name').value.trim();
        var clientEmail            = document.getElementById('racc-client-email').value.trim();
        var clientPhone            = getClientPhoneValue();
        var clientPhoneIso         = getClientPhoneIso();
        var clientPhoneNational    = getClientPhoneNational();
        var clientNationality      = document.getElementById('racc-client-nationality').value.trim();
        var clientDob              = document.getElementById('racc-client-dob').value;
        var clientUniversity       = document.getElementById('racc-client-university').value.trim();
        var clientCourseLevel      = document.getElementById('racc-client-course-level').value;
        var clientCourseMajor      = document.getElementById('racc-client-course-major').value.trim();
        var clientCourseCompletion = document.getElementById('racc-client-course-completion').value;
        var clientVisaType         = document.getElementById('racc-client-visa-type').value;
        var clientVisaExpiry       = document.getElementById('racc-client-visa-expiry').value;
        var clientDomicileCountry  = document.getElementById('racc-client-country').value.trim();
        var clientState            = document.getElementById('racc-client-state').value.trim();
        var isAustralianDomicile   = isAustralia(clientDomicileCountry);
        var clientCountry          = clientDomicileCountry;
        var clientStateValue       = isAustralianDomicile ? clientState : '';
        var isOffshore             = isOffshoreVisa(clientVisaType);
        var clientOccupation       = document.getElementById('racc-client-occupation').value.trim();
        var clientContactLink      = document.getElementById('racc-client-contact-link').value.trim();
        var clientReferral         = document.getElementById('racc-client-referral').value;
        var notes                  = document.getElementById('racc-notes').value.trim();

        if (!clientName || !clientEmail || !clientPhone || !clientNationality || !clientDob ||
            !clientUniversity || !clientCourseLevel || !clientCourseMajor || !clientCourseCompletion ||
            !clientVisaType || (!isOffshore && !clientVisaExpiry) || !clientDomicileCountry ||
            (isAustralianDomicile && !clientState) || !clientOccupation ||
            !clientReferral || !notes) {
            showMessage('error', raccBooking.i18n.fillAllFields);
            return;
        }

        if (!isClientPhoneValid()) {
            showMessage('error', raccBooking.i18n.invalidPhone || 'Please enter a valid phone number with country code.');
            return;
        }

        $submitBtn.disabled    = true;
        $submitBtn.textContent = raccBooking.i18n.loading;

        // agent_id is resolved from the selected slot — never shown to the user
        var data = {
            agent_id:                 state.selectedSlot.agent_id,
            client_name:              clientName,
            client_email:             clientEmail,
            client_phone:             clientPhone,
            client_phone_iso:         clientPhoneIso,
            client_phone_national:    clientPhoneNational,
            client_nationality:       clientNationality,
            client_dob:               clientDob,
            client_university:        clientUniversity,
            client_course_level:      clientCourseLevel,
            client_course_major:      clientCourseMajor,
            client_course_completion: clientCourseCompletion,
            client_visa_type:         clientVisaType,
            client_visa_expiry:       clientVisaExpiry,
            client_country:           clientCountry,
            client_state:             clientStateValue,
            client_occupation:        clientOccupation,
            client_contact_link:      clientContactLink,
            client_referral_source:   clientReferral,
            service_type:             state.selectedService,
            woo_product_id:           getSelectedProductId(),
            booking_date:             state.selectedDate,
            booking_time_start:       state.selectedSlot.start,
            booking_time_end:         state.selectedSlot.end,
            notes:                    notes
        };

        var supportsAbortController = (typeof AbortController !== 'undefined');
        var controller = supportsAbortController ? new AbortController() : null;
        var timeoutId = setTimeout(function () {
            if (controller) {
                controller.abort();
            }
        }, 60000); // 60s safety timeout

        fetch(raccBooking.restUrl + 'bookings', {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce':   raccBooking.nonce
            },
            signal: controller ? controller.signal : undefined,
            body: JSON.stringify(data)
        })
        .then(function (res) {
            return res.json().then(function (body) {
                if (!res.ok) throw body;
                return body;
            });
        })
        .then(function (response) {
            clearTimeout(timeoutId);
            if (response.success) {
                if (response.free_booking_confirmed) {
                    showMessage('success', response.message || raccBooking.i18n.bookingSuccess);
                    hideBookingFlow();
                    showFinalBookingSummary(response);
                    showAddToCalendarButton(response);
                    return;
                }

                if (response.checkout_url) {
                    showMessage('success', response.message || raccBooking.i18n.bookingSuccess);
                    hideBookingFlow();
                    showFinalBookingSummary(response);
                    return;
                }

                // If Woo bridge is active but checkout_url is missing, keep form visible.
                if (typeof raccWooBridge !== 'undefined') {
                    showMessage('error', response.message || 'Booking created, but checkout URL is missing. Please contact admin.');
                    $submitBtn.disabled    = false;
                    $submitBtn.textContent = raccBooking.i18n.bookNow;
                    return;
                }

                showMessage('success', response.message || raccBooking.i18n.bookingSuccess);
                hideBookingFlow();
                showFinalBookingSummary(response);
                showAddToCalendarButton(response);
            } else {
                showMessage('error', response.message || raccBooking.i18n.bookingError);
                $submitBtn.disabled    = false;
                $submitBtn.textContent = raccBooking.i18n.bookNow;
            }
        })
        .catch(function (err) {
            clearTimeout(timeoutId);
            if (err && err.name === 'AbortError') {
                showMessage('error', 'Request timeout. If your booking was already created, submitting again will reopen the existing booking.');
            } else {
                showMessage('error', getApiErrorMessage(err, raccBooking.i18n.bookingError));
            }
            $submitBtn.disabled    = false;
            $submitBtn.textContent = raccBooking.i18n.bookNow;
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════════

    function showMessage(type, text) {
        $message.className   = 'racc-booking-message racc-msg-' + type;
        $message.textContent = text;
        $message.style.display = 'block';
        $message.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function formatDate(dateStr) {
        return formatDateFriendly(dateStr);
    }

    function formatDateFriendly(dateStr) {
        var locale = (raccBooking.locale || 'id-ID').replace('_', '-');
        var d = new Date(dateStr + 'T00:00:00');
        try {
            return new Intl.DateTimeFormat(locale, {
                weekday: 'long',
                year:    'numeric',
                month:   'long',
                day:     'numeric'
            }).format(d);
        } catch (e) {
            return d.toLocaleDateString('id-ID', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        }
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    function getApiErrorMessage(err, fallback) {
        var code    = (err && err.code)    || '';
        var message = (err && err.message) || fallback;
        if (code === 'google_reconnect_required') return raccBooking.i18n.googleReconnectRequired || 'The consultant\'s Google Calendar connection needs to be reconnected. Please contact support.';
        if (code === 'google_not_connected') return raccBooking.i18n.googleNotConnected  || message;
        if (code === 'google_api_error')     return raccBooking.i18n.googleApiError      || message;
        if (code === 'calendar_sync_failed') return raccBooking.i18n.calendarSyncFailed  || message;
        if (code === 'slot_taken' || code === 'slot_locked') return raccBooking.i18n.slotUnavailable || message;
        return message || fallback;
    }

    function getSelectedProductId() {
        if (
            typeof raccWooBridge !== 'undefined' &&
            raccWooBridge.serviceMap &&
            state.selectedService &&
            raccWooBridge.serviceMap[state.selectedService] &&
            raccWooBridge.serviceMap[state.selectedService].product_id
        ) {
            var pid = parseInt(raccWooBridge.serviceMap[state.selectedService].product_id, 10);
            return isNaN(pid) ? 0 : pid;
        }
        return 0;
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function setText(id, value) {
        var el = document.getElementById(id);
        if (el) {
            el.textContent = value;
        }
    }

    // ─── Google Calendar ─────────────────────────────────────────────────────

    /**
     * Build a "Add to Google Calendar" URL from booking response data.
     * Uses the floating local-time format (no Z suffix) with ctz parameter.
     */
    function buildGoogleCalendarUrl(response) {
        var date      = (response.booking_date || '').replace(/-/g, '');
        var startTime = (response.time_start || '00:00').replace(':', '') + '00';
        var endTime   = (response.time_end   || '01:00').replace(':', '') + '00';
        var tz        = response.agent_timezone || 'UTC';
        var title     = '[RACC] ' + (response.service_type || 'Appointment') + ' - ' + (response.client_name || '');

        var details = '';
        details += '=== CLIENT INFORMATION ===' + '\n';
        details += 'Name: '              + (response.client_name        || '') + '\n';
        details += 'Email: '             + (response.client_email       || '') + '\n';
        details += 'Phone: '             + (response.client_phone       || '') + '\n';
        details += 'Nationality: '       + (response.client_nationality  || '') + '\n';
        details += 'Date of Birth: '     + (response.client_dob         || '') + '\n';
        details += 'Country: '           + (response.client_country     || '') + '\n';
        details += '\n=== EDUCATION ===' + '\n';
        details += 'University/School: ' + (response.client_university      || '') + '\n';
        details += 'Course Level: '      + (response.client_course_level    || '') + '\n';
        details += 'Course Major: '      + (response.client_course_major    || '') + '\n';
        details += 'Course Completion: ' + (response.client_course_completion || '') + '\n';
        details += '\n=== VISA & IMMIGRATION ===' + '\n';
        details += 'Current Visa: '      + (response.client_visa_type   || '') + '\n';
        details += 'Visa Expiry: '       + (response.client_visa_expiry || '') + '\n';
        details += '\n=== ADDITIONAL INFO ===' + '\n';
        details += 'Occupation: '        + (response.client_occupation      || '') + '\n';
        details += 'Contact Link: '      + (response.client_contact_link    || '') + '\n';
        details += 'Referral Source: '   + (response.client_referral_source || '') + '\n';
        details += '\n=== SERVICE ===' + '\n';
        details += 'Service Type: '      + (response.service_type  || '') + '\n';
        details += 'Consultant: '        + (response.agent_name    || '') + '\n';
        details += 'Booking ID: #'       + (response.booking_id    || '') + '\n';
        if (response.notes) {
            details += '\n=== ENQUIRY ===' + '\n';
            details += response.notes + '\n';
        }

        var params = [
            'action=TEMPLATE',
            'text='    + encodeURIComponent(title),
            'dates='   + date + 'T' + startTime + '/' + date + 'T' + endTime,
            'details=' + encodeURIComponent(details),
            'ctz='     + encodeURIComponent(tz)
        ];

        return 'https://calendar.google.com/calendar/render?' + params.join('&');
    }

    /**
     * Inject the "Add to Google Calendar" button after the success message.
     */
    function showAddToCalendarButton(response) {
        // Remove any existing calendar widget
        var existing = $app.querySelector('.racc-gcal-widget');
        if (existing) existing.parentNode.removeChild(existing);

        var url = buildGoogleCalendarUrl(response);

        var widget = document.createElement('div');
        widget.className = 'racc-gcal-widget';

        var link = document.createElement('a');
        link.href      = url;
        link.target    = '_blank';
        link.rel       = 'noopener noreferrer';
        link.className = 'racc-gcal-btn';
        link.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="20" height="20" style="flex-shrink:0;">' +
            '<rect x="6" y="6" width="36" height="36" rx="4" fill="#fff"/>' +
            '<path d="M34 6H14L6 14v20l8 8h20l8-8V14z" fill="none"/>' +
            '<rect x="6" y="6" width="36" height="36" rx="4" fill="none" stroke="#dadce0" stroke-width="2"/>' +
            '<path d="M14 6v8H6" fill="none" stroke="#dadce0" stroke-width="2"/>' +
            '<text x="24" y="32" text-anchor="middle" font-family="Arial,sans-serif" font-size="16" font-weight="bold" fill="#1a73e8">31</text>' +
            '</svg>' +
            (raccBooking.i18n.addToGoogleCalendar || 'Add to Google Calendar');

        widget.appendChild(link);

        var actions = document.getElementById('racc-final-actions');
        if (actions) {
            actions.style.display = 'flex';
            actions.appendChild(widget);
        } else {
            // Insert after the message div
            if ($message.nextSibling) {
                $message.parentNode.insertBefore(widget, $message.nextSibling);
            } else {
                $message.parentNode.appendChild(widget);
            }
        }
    }

})();
