/**
 * RACC Booking — Admin JavaScript
 *
 * @package RACC_Booking
 */

(function($) {
    'use strict';

    function raccEscHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function isAustralia(value) {
        return String(value || '').trim().toLowerCase() === 'australia';
    }

    function initSearchableSelects(scope) {
        var root = scope || document;
        var selects = root.querySelectorAll('select[data-racc-searchable-select="1"]');

        Array.prototype.forEach.call(selects, function(select) {
            if (select.dataset.raccSearchInit === '1') return;
            select.dataset.raccSearchInit = '1';

            var placeholder = select.options[0] ? select.options[0].text : '— Select —';
            var searchPlaceholder = select.getAttribute('data-search-placeholder') || 'Search...';

            var allOptions = Array.prototype.slice.call(select.options).slice(1).map(function(option) {
                return { value: option.value, text: option.text };
            });

            select.style.display = 'none';

            var wrapper = document.createElement('div');
            wrapper.className = 'racc-cs';

            var trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'racc-cs-trigger';
            trigger.innerHTML = '<span class="racc-cs-value">' + raccEscHtml(placeholder) + '</span><span class="racc-cs-arrow">&#8964;</span>';

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
                var matched = allOptions.filter(function(o) {
                    return !q || o.text.toLowerCase().indexOf(q) !== -1;
                });

                if (!matched.length) {
                    var empty = document.createElement('li');
                    empty.className = 'racc-cs-empty';
                    empty.textContent = 'No results';
                    list.appendChild(empty);
                    return;
                }

                matched.forEach(function(o) {
                    var li = document.createElement('li');
                    li.className = 'racc-cs-option' + (select.value === o.value ? ' selected' : '');
                    li.setAttribute('data-value', o.value);
                    if (q) {
                        var idx = o.text.toLowerCase().indexOf(q);
                        li.innerHTML = raccEscHtml(o.text.substring(0, idx))
                            + '<mark>' + raccEscHtml(o.text.substring(idx, idx + q.length)) + '</mark>'
                            + raccEscHtml(o.text.substring(idx + q.length));
                    } else {
                        li.textContent = o.text;
                    }
                    li.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                        select.value = o.value;
                        $(select).trigger('change');
                        trigger.querySelector('.racc-cs-value').textContent = o.text;
                        trigger.classList.add('racc-cs-has-value');
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

            trigger.addEventListener('click', function() {
                panel.style.display === 'none' ? openPanel() : closePanel();
            });

            search.addEventListener('input', function() {
                renderList(search.value);
            });

            document.addEventListener('click', function(e) {
                if (!wrapper.contains(e.target)) closePanel();
            });

            if (select.value) {
                var found = allOptions.filter(function(o) { return o.value === select.value; })[0];
                if (found) {
                    trigger.querySelector('.racc-cs-value').textContent = found.text;
                    trigger.classList.add('racc-cs-has-value');
                }
            }
        });
    }

    function initSearchableMultiSelects(scope) {
        var root = scope || document;
        var selects = root.querySelectorAll('select[multiple][data-racc-searchable-multi-select="1"]');

        Array.prototype.forEach.call(selects, function(select) {
            if (select.dataset.raccSearchInit === '1') return;
            select.dataset.raccSearchInit = '1';

            var searchPlaceholder = select.getAttribute('data-search-placeholder') || 'Search...';
            var allOptions = Array.prototype.slice.call(select.options).map(function(option) {
                return { value: option.value, text: option.text };
            });

            select.style.display = 'none';

            var wrapper = document.createElement('div');
            wrapper.className = 'racc-cs-multi';

            var tagsContainer = document.createElement('div');
            tagsContainer.className = 'racc-cs-tags';

            var search = document.createElement('input');
            search.type = 'text';
            search.className = 'racc-cs-multi-input';
            search.placeholder = searchPlaceholder;
            search.setAttribute('autocomplete', 'off');

            tagsContainer.appendChild(search);

            var panel = document.createElement('div');
            panel.className = 'racc-cs-panel';
            panel.style.display = 'none';

            var list = document.createElement('ul');
            list.className = 'racc-cs-list';
            panel.appendChild(list);

            wrapper.appendChild(tagsContainer);
            wrapper.appendChild(panel);
            select.parentNode.insertBefore(wrapper, select);
            wrapper.appendChild(select);

            function getSelectedValues() {
                var vals = [];
                for (var i = 0; i < select.options.length; i++) {
                    if (select.options[i].selected && select.options[i].value) {
                        vals.push(select.options[i].value);
                    }
                }
                return vals;
            }

            function renderTags() {
                var selected = getSelectedValues();
                var children = Array.prototype.slice.call(tagsContainer.childNodes);
                children.forEach(function(child) {
                    if (child !== search) tagsContainer.removeChild(child);
                });

                selected.forEach(function(val) {
                    var opt = allOptions.filter(function(o) { return o.value === val; })[0];
                    if (!opt) return;

                    var tag = document.createElement('span');
                    tag.className = 'racc-cs-tag';
                    tag.textContent = opt.text;

                    var remove = document.createElement('span');
                    remove.className = 'racc-cs-remove';
                    remove.innerHTML = '&times;';
                    remove.addEventListener('click', function(e) {
                        e.stopPropagation();
                        for (var i = 0; i < select.options.length; i++) {
                            if (select.options[i].value === val) select.options[i].selected = false;
                        }
                        $(select).trigger('change');
                        renderTags();
                    });

                    tag.appendChild(remove);
                    tagsContainer.insertBefore(tag, search);
                });
            }

            function openPanel() {
                panel.style.display = 'block';
                renderList(search.value);
            }

            function closePanel() {
                panel.style.display = 'none';
                search.value = '';
            }

            function renderList(query) {
                list.innerHTML = '';
                var q = (query || '').toLowerCase().trim();
                var selected = getSelectedValues();
                var matched = allOptions.filter(function(o) {
                    if (selected.indexOf(o.value) !== -1 || !o.value) return false;
                    return !q || o.text.toLowerCase().indexOf(q) !== -1;
                });

                if (!matched.length) {
                    var empty = document.createElement('li');
                    empty.className = 'racc-cs-empty';
                    empty.textContent = 'No results';
                    list.appendChild(empty);
                    return;
                }

                matched.forEach(function(o) {
                    var li = document.createElement('li');
                    li.className = 'racc-cs-option';
                    li.setAttribute('data-value', o.value);
                    if (q) {
                        var idx = o.text.toLowerCase().indexOf(q);
                        li.innerHTML = raccEscHtml(o.text.substring(0, idx))
                            + '<mark>' + raccEscHtml(o.text.substring(idx, idx + q.length)) + '</mark>'
                            + raccEscHtml(o.text.substring(idx + q.length));
                    } else {
                        li.textContent = o.text;
                    }
                    li.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                        for (var i = 0; i < select.options.length; i++) {
                            if (select.options[i].value === o.value) select.options[i].selected = true;
                        }
                        $(select).trigger('change');
                        renderTags();
                        closePanel();
                        search.focus();
                    });
                    list.appendChild(li);
                });
            }

            tagsContainer.addEventListener('click', function() {
                search.focus();
            });

            search.addEventListener('focus', openPanel);
            search.addEventListener('input', function() {
                openPanel();
            });

            document.addEventListener('click', function(e) {
                if (!wrapper.contains(e.target)) closePanel();
            });

            renderTags();
        });
    }

    initSearchableSelects(document);
    initSearchableMultiSelects(document);

    function adminUrl(path) {
        try {
            return new URL(path, (window.raccAdmin && raccAdmin.adminUrl) ? raccAdmin.adminUrl : window.location.href).toString();
        } catch (e) {
            return ((window.raccAdmin && raccAdmin.adminUrl) ? raccAdmin.adminUrl : '') + String(path || '').replace(/^\//, '');
        }
    }

    function renderBookingDetailsHtml(booking) {
        var html = '';

        // Booking Info
        html += '<div class="racc-detail-section">';
        html += '<h3>📋 Booking Information</h3>';
        html += '<div class="racc-detail-row"><span class="racc-detail-label">Booking ID:</span><span class="racc-detail-value">#' + booking.id + '</span></div>';
        if (booking.created_at) {
            html += '<div class="racc-detail-row"><span class="racc-detail-label">Created At:</span><span class="racc-detail-value">' + booking.created_at + '</span></div>';
        }
        html += '<div class="racc-detail-row"><span class="racc-detail-label">Status:</span><span class="racc-detail-value"><span class="racc-status-badge racc-status-' + booking.status + '">' + (booking.status_label || booking.status) + '</span></span></div>';
        html += '<div class="racc-detail-row"><span class="racc-detail-label">Consultant:</span><span class="racc-detail-value">' + (booking.agent_name || 'Unknown') + '</span></div>';
        html += '<div class="racc-detail-row"><span class="racc-detail-label">Service Type:</span><span class="racc-detail-value">' + booking.service_type + '</span></div>';
        if (booking.woo_product_id) {
            html += '<div class="racc-detail-row"><span class="racc-detail-label">Woo Product:</span><span class="racc-detail-value"><a href="' + booking.woo_product_edit_link + '">#' + booking.woo_product_id + ' — ' + (booking.woo_product_name || booking.service_type) + '</a></span></div>';
        }
        if (booking.woo_order_id) {
            html += '<div class="racc-detail-row"><span class="racc-detail-label">Woo Order:</span><span class="racc-detail-value"><a href="' + booking.woo_order_edit_link + '">#' + booking.woo_order_id + '</a></span></div>';
        }
        if (booking.google_event_id) {
            html += '<div class="racc-detail-row"><span class="racc-detail-label">Google Event:</span><span class="racc-detail-value"><a href="https://calendar.google.com/calendar/u/0/r/search?q=' + booking.google_event_id + '" target="_blank">View Event (' + booking.google_event_id + ')</a></span></div>';
        }
        html += '<div class="racc-detail-row"><span class="racc-detail-label">Date & Time:</span><span class="racc-detail-value">' + formatDateTime(booking.booking_date, booking.booking_time_start, booking.booking_time_end) + '</span></div>';
        html += '</div>';

        html += '<div class="racc-event-actions" style="margin: 8px 0 18px;">';
        html += '<a class="button button-primary" href="' + adminUrl('admin.php?page=racc-booking-reschedule&booking_id=' + booking.id) + '">' + 'Edit/Reschedule' + '</a>';
        var reassignUrl = adminUrl('admin.php?page=racc-booking-reassign&booking_ids[]=' + booking.id + '&_wpnonce=' + (raccAdmin.reassignNonce || ''));
        html += '<a class="button" style="margin-left:8px;" href="' + reassignUrl + '">' + 'Change Consultant' + '</a>';
        html += '<button type="button" class="button racc-event-cancel" data-booking-id="' + booking.id + '">' + (raccAdmin.i18n.cancel || 'Cancel') + '</button>';
        html += '<button type="button" class="button button-link-delete racc-event-delete" data-booking-id="' + booking.id + '">' + (raccAdmin.i18n.delete || 'Delete') + '</button>';
        html += '</div>';

        // Location Information
        var locationMode = booking.location_mode || 'client_place';
        var locationModeLabel = 'Client Place';
        if (locationMode === 'master_location') {
            locationModeLabel = 'Master Location';
        } else if (locationMode === 'default_contact') {
            locationModeLabel = 'Default Contact';
        }

        var locationAddressParts = [
            booking.house_number,
            booking.street_name,
            booking.apartment_suite,
            booking.city,
            booking.postal_code,
            booking.country_region
        ].filter(function(v) { return v && String(v).trim() !== ''; });

        var locationAddress = locationAddressParts.length ? locationAddressParts.join(', ') : '<span class="empty">Not provided</span>';
        var locationName = booking.location_name || '<span class="empty">Not provided</span>';
        var locationContact = [
            booking.location_contact_name,
            booking.location_contact_phone,
            booking.location_contact_email
        ].filter(function(v) { return v && String(v).trim() !== ''; }).join(' · ');
        var useDefaultContact = booking.use_default_contact === 1 || booking.use_default_contact === '1';

        html += '<div class="racc-detail-section">';
        html += '<h3>📍 Location Information</h3>';
        html += '<div class="racc-detail-row"><span class="racc-detail-label">Location Mode:</span><span class="racc-detail-value">' + locationModeLabel + '</span></div>';

        if (locationMode === 'master_location') {
            html += '<div class="racc-detail-row"><span class="racc-detail-label">Location Name:</span><span class="racc-detail-value">' + locationName + '</span></div>';
            html += '<div class="racc-detail-row"><span class="racc-detail-label">Address:</span><span class="racc-detail-value">' + locationAddress + '</span></div>';
            html += '<div class="racc-detail-row"><span class="racc-detail-label">Address Note:</span><span class="racc-detail-value">' + (booking.address_description || '<span class="empty">Not provided</span>') + '</span></div>';
            html += '<div class="racc-detail-row"><span class="racc-detail-label">Contact:</span><span class="racc-detail-value">' + (useDefaultContact ? 'Use default contact information from settings' : (locationContact || '<span class="empty">Not provided</span>')) + '</span></div>';
        } else if (locationMode === 'default_contact') {
            html += '<div class="racc-detail-row"><span class="racc-detail-label">Location:</span><span class="racc-detail-value">Use default contact information from settings</span></div>';
        } else {
            html += '<div class="racc-detail-row"><span class="racc-detail-label">Location:</span><span class="racc-detail-value">At client place</span></div>';
        }

        html += '</div>';

        // Personal Information
        html += '<div class="racc-detail-section">';
        html += '<h3>👤 Personal Information</h3>';
        html += '<div class="racc-detail-row"><span class="racc-detail-label">Full Name:</span><span class="racc-detail-value">' + booking.client_name + '</span></div>';
        html += '<div class="racc-detail-row"><span class="racc-detail-label">Email:</span><span class="racc-detail-value">' + booking.client_email + '</span></div>';
        html += '<div class="racc-detail-row"><span class="racc-detail-label">Phone:</span><span class="racc-detail-value">' + (booking.client_phone || '<span class="empty">Not provided</span>') + '</span></div>';
        html += '<div class="racc-detail-row"><span class="racc-detail-label">Nationality:</span><span class="racc-detail-value">' + (booking.client_nationality || '<span class="empty">Not provided</span>') + '</span></div>';
        html += '<div class="racc-detail-row"><span class="racc-detail-label">Date of Birth:</span><span class="racc-detail-value">' + (booking.client_dob || '<span class="empty">Not provided</span>') + '</span></div>';
        var oldStates = ['Australian Capital Territory', 'New South Wales', 'Northern Territory', 'Queensland', 'South Australia', 'Tasmania', 'Victoria', 'Western Australia'];
        var isOldState = oldStates.indexOf(booking.client_country) !== -1;
        var displayCountry = isOldState ? 'Australia' : (booking.client_country || '<span class="empty">Not provided</span>');
        var displayState = isOldState ? booking.client_country : booking.client_state;

        html += '<div class="racc-detail-row"><span class="racc-detail-label">Country of Residence:</span><span class="racc-detail-value">' + displayCountry + '</span></div>';
        if (displayState) {
            html += '<div class="racc-detail-row"><span class="racc-detail-label">State / Province:</span><span class="racc-detail-value">' + displayState + '</span></div>';
        }
        html += '</div>';

        // Education Background
        html += '<div class="racc-detail-section">';
        html += '<h3>🎓 Education Background</h3>';
        html += '<div class="racc-detail-row"><span class="racc-detail-label">University/School:</span><span class="racc-detail-value">' + (booking.client_university || '<span class="empty">Not provided</span>') + '</span></div>';
        html += '<div class="racc-detail-row"><span class="racc-detail-label">Course Level:</span><span class="racc-detail-value">' + (booking.client_course_level || '<span class="empty">Not provided</span>') + '</span></div>';
        html += '<div class="racc-detail-row"><span class="racc-detail-label">Course Major:</span><span class="racc-detail-value">' + (booking.client_course_major || '<span class="empty">Not provided</span>') + '</span></div>';
        html += '<div class="racc-detail-row"><span class="racc-detail-label">Course Completion:</span><span class="racc-detail-value">' + (booking.client_course_completion || '<span class="empty">Not provided</span>') + '</span></div>';
        html += '</div>';

        // Visa & Immigration
        html += '<div class="racc-detail-section">';
        html += '<h3>🛂 Visa & Immigration</h3>';
        html += '<div class="racc-detail-row"><span class="racc-detail-label">Current Visa:</span><span class="racc-detail-value">' + (booking.client_visa_type || '<span class="empty">Not provided</span>') + '</span></div>';
        html += '<div class="racc-detail-row"><span class="racc-detail-label">Visa Expiry:</span><span class="racc-detail-value">' + (booking.client_visa_expiry || '<span class="empty">Not provided</span>') + '</span></div>';
        html += '</div>';

        // Additional Information
        html += '<div class="racc-detail-section">';
        html += '<h3>ℹ️ Additional Information</h3>';
        html += '<div class="racc-detail-row"><span class="racc-detail-label">Occupation:</span><span class="racc-detail-value">' + (booking.client_occupation || '<span class="empty">Not provided</span>') + '</span></div>';
        html += '<div class="racc-detail-row"><span class="racc-detail-label">Contact Link:</span><span class="racc-detail-value">' + (booking.client_contact_link ? '<a href="' + booking.client_contact_link + '" target="_blank">' + booking.client_contact_link + '</a>' : '<span class="empty">Not provided</span>') + '</span></div>';
        html += '<div class="racc-detail-row"><span class="racc-detail-label">Referral Source:</span><span class="racc-detail-value">' + (booking.client_referral_source || '<span class="empty">Not provided</span>') + '</span></div>';
        html += '</div>';

        // Enquiry
        if (booking.notes) {
            html += '<div class="racc-detail-section">';
            html += '<h3>💬 Consultation Enquiry</h3>';
            html += '<div style="padding: 15px; background: #f9f9f9; border-radius: 4px; font-size: 13px; line-height: 1.6; white-space: pre-wrap;">' + booking.notes + '</div>';
            html += '</div>';
        }

        // AgentCIS Status
        html += '<div class="racc-detail-section">';
        html += '<h3>🔄 AgentCIS Status</h3>';
        
        var syncStatus = booking.agentcis_sync_status || 'pending';
        var statusColor = syncStatus === 'synced' ? 'green' : (syncStatus === 'failed' ? 'red' : 'orange');
        html += '<div class="racc-detail-row"><span class="racc-detail-label">Sync Status:</span><span class="racc-detail-value" style="color: ' + statusColor + '; font-weight: 500; text-transform: capitalize;">' + syncStatus + '</span></div>';
        
        if (booking.agentcis_sync_at) {
            html += '<div class="racc-detail-row"><span class="racc-detail-label">Sync At:</span><span class="racc-detail-value">' + booking.agentcis_sync_at.substring(0, 16) + '</span></div>';
        }

        if (booking.agentcis_contact_id) {
            html += '<div class="racc-detail-row"><span class="racc-detail-label">Contact ID:</span><span class="racc-detail-value">' + booking.agentcis_contact_id + '</span></div>';
        }
        
        if (syncStatus === 'synced') {
            html += '<div class="racc-detail-row"><span class="racc-detail-label">AgentCIS Response:</span><span class="racc-detail-value" style="color: green;">200 OK (Success)</span></div>';
            if (booking.agentcis_sync_error) {
                html += '<div class="racc-detail-row"><span class="racc-detail-label">Warnings:</span><span class="racc-detail-value" style="color: #d97706; white-space: pre-wrap; font-size: 12px; line-height: 1.4;">' + booking.agentcis_sync_error + '</span></div>';
            }
        } else if (booking.agentcis_sync_error) {
            html += '<div class="racc-detail-row"><span class="racc-detail-label">AgentCIS Response:</span><span class="racc-detail-value" style="color: red; white-space: pre-wrap;">' + booking.agentcis_sync_error + '</span></div>';
        }
        
        html += '<div style="margin-top: 15px;">';
        html += '<button type="button" class="button button-secondary racc-resync-agentcis" data-booking-id="' + booking.id + '">Resync to AgentCIS</button>';
        html += '</div>';

        html += '</div>';

        return html;
    }

    // ─────────────────────────────────────
    // Customer Details Modal
    // ─────────────────────────────────────
    $(document).on('click', '.racc-view-details', function(e) {
        e.preventDefault();

        var bookingId = $(this).data('booking-id');
        var $modal = $('#racc-details-modal');
        var $content = $('#racc-details-content');

        if (!$modal.length || !$content.length) {
            var href = $(this).attr('href');
            if (href && href !== '#') {
                window.location.href = href;
            }
            return;
        }
        
        $modal.show();
        $content.html('<div class="racc-loading"><div class="racc-spinner"></div><p>Loading customer details...</p></div>');
        
        // Fetch booking details via REST API
        $.ajax({
            url: raccAdmin.restUrl + 'bookings/' + bookingId,
            method: 'GET',
            xhrFields: {
                withCredentials: true
            },
            headers: {
                'X-WP-Nonce': raccAdmin.nonce || raccAdmin.restNonce
            },
            beforeSend: function(xhr) {
                // Ensure nonce is sent
                if (raccAdmin.nonce) {
                    xhr.setRequestHeader('X-WP-Nonce', raccAdmin.nonce);
                }
            },
            success: function(booking) {
                $content.html(renderBookingDetailsHtml(booking));
            },
            error: function() {
                $content.html('<div class="notice notice-error"><p>Failed to load customer details.</p></div>');
            }
        });
    });

    // ─────────────────────────────────────
    // Resync AgentCIS
    // ─────────────────────────────────────
    $(document).on('click', '.racc-resync-agentcis', function() {
        var $btn = $(this);
        var bookingId = $btn.data('booking-id');
        
        if (!confirm('Are you sure you want to push this data to AgentCIS?')) {
            return;
        }
        
        $btn.prop('disabled', true).text('Syncing...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'racc_agentcis_manual_sync',
                nonce: raccAdmin.agentcisNonce,
                booking_id: bookingId
            },
            success: function(response) {
                if (response.success) {
                    alert('Successfully synced to AgentCIS!');
                    location.reload();
                } else {
                    alert('Sync failed: ' + (response.data || 'Unknown error'));
                    $btn.prop('disabled', false).text('Resync to AgentCIS');
                }
            },
            error: function() {
                alert('An error occurred while communicating with the server.');
                $btn.prop('disabled', false).text('Resync to AgentCIS');
            }
        });
    });

    function openBookingDetailsFromQuery() {
        var params = new URLSearchParams(window.location.search);
        var bookingId = params.get('booking_id');

        if (!bookingId) {
            return;
        }

        var $trigger = $('.racc-view-details[data-booking-id="' + bookingId + '"]').first();
        if ($trigger.length) {
            $trigger.trigger('click');
            return;
        }

        var $modal = $('#racc-details-modal');
        var $content = $('#racc-details-content');

        $modal.show();
        $content.html('<div class="racc-loading"><div class="racc-spinner"></div><p>Loading customer details...</p></div>');

        $.ajax({
            url: raccAdmin.restUrl + 'bookings/' + bookingId,
            method: 'GET',
            xhrFields: { withCredentials: true },
            headers: {
                'X-WP-Nonce': raccAdmin.nonce || raccAdmin.restNonce
            },
            success: function(booking) {
                $content.html(renderBookingDetailsHtml(booking));
            },
            error: function() {
                $content.html('<div class="notice notice-error"><p>Failed to load customer details.</p></div>');
            }
        });
    }
    
    function formatDateTime(date, timeStart, timeEnd) {
        var d = new Date(date);
        var dateStr = d.toLocaleDateString('en-AU', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
        var startTime = timeStart.substring(0, 5);
        var endTime = timeEnd.substring(0, 5);
        return dateStr + ' at ' + startTime + ' — ' + endTime;
    }

    // ─────────────────────────────────────
    // Notes Modal
    // ─────────────────────────────────────
    $(document).on('click', '.racc-view-notes', function() {
        var notes = $(this).data('notes');
        $('#racc-notes-content').text(notes);
        $('#racc-notes-modal').show();
    });

    $(document).on('click', '.racc-modal-close', function() {
        $(this).closest('.racc-modal').hide();
    });

    $(document).on('click', '.racc-modal', function(e) {
        if (e.target === this) {
            $(this).hide();
        }
    });

    // ─────────────────────────────────────
    // Edit / Reschedule Booking Page
    // ─────────────────────────────────────

    if ( $('#racc-edit-booking-id').length ) {
        var orig        = window.raccEditBookingData || {};
        var editState   = {
            agent_id:    orig.agent_id,
            agentName:   orig.agent_name,
            date:        null,   // null = keep original
            timeStart:   null,
            timeEnd:     null,
            keepSchedule: true
        };
        var editDatePicker = null;
        var editAvailabilityTimer = null;
        var editAvailabilityRequest = null;
        var editAvailabilityRequestId = 0;
        var editCalendarCache = {};
        var editAvailableDatesSet = {};
        var editNearestRequestId = 0;

        function syncEditDomicileStateVisibility() {
            var $country = $('#racc-edit-client-country');
            var $stateGroup = $('#racc-edit-client-state-group');
            var $state = $('#racc-edit-client-state');

            if (!$country.length || !$stateGroup.length || !$state.length) return;

            if (isAustralia($country.val())) {
                $stateGroup.show();
                if ($state.val() === 'Offshore') {
                    $state.val('');
                }
            } else {
                $state.val('Offshore');
                $stateGroup.hide();
            }
        }

        function getEditResidenceStateValue() {
            var country = $('#racc-edit-client-country').val();
            return isAustralia(country)
                ? $('#racc-edit-client-state').val()
                : country;
        }

        function getSelectedWooProductIdFromEditForm() {
            var $service = $('#racc-edit-service');
            if (!$service.length) return 0;
            var pid = parseInt($service.find(':selected').data('product-id') || 0, 10);
            return isNaN(pid) ? 0 : pid;
        }

        function editDateObjToYMD(d) {
            var y = d.getFullYear();
            var m = d.getMonth() + 1;
            var day = d.getDate();
            return y + '-' + (m < 10 ? '0' + m : m) + '-' + (day < 10 ? '0' + day : day);
        }

        function invalidateEditAvailabilityCalendar() {
            editCalendarCache = {};
            editAvailableDatesSet = {};
            if (editDatePicker) editDatePicker.redraw();
        }

        function cancelEditAvailabilityLookup() {
            clearTimeout(editAvailabilityTimer);
            editAvailabilityRequestId++;
            if (editAvailabilityRequest && editAvailabilityRequest.readyState !== 4) {
                editAvailabilityRequest.abort();
            }
        }

        function getEditCalendarCacheKey(year, month) {
            return year + '-' + (month < 10 ? '0' + month : month);
        }

        function setEditCalendarLoading(loading) {
            $('.flatpickr-calendar').toggleClass('racc-calendar-loading', !!loading);
        }

        function applyEditCalendarAvailability(dates) {
            if (!editDatePicker || !dates) return;
            dates.forEach(function (d) {
                editAvailableDatesSet[d] = true;
            });
            editDatePicker.redraw();
        }

        function loadEditCalendarAvailability(year, month) {
            if (!editDatePicker || !editState.agent_id) return;

            var cacheKey = getEditCalendarCacheKey(year, month);
            if (editCalendarCache[cacheKey] !== undefined) {
                applyEditCalendarAvailability(editCalendarCache[cacheKey]);
                return;
            }

            setEditCalendarLoading(true);
            $.ajax({
                url: raccAdmin.restUrl + 'availability-calendar',
                method: 'GET',
                xhrFields: {
                    withCredentials: true
                },
                data: {
                    agent_ids: editState.agent_id,
                    year: year,
                    month: month,
                    woo_product_id: getSelectedWooProductIdFromEditForm()
                },
                beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', raccAdmin.nonce); },
                success: function (data) {
                    var dates = (data && Array.isArray(data.available_dates)) ? data.available_dates : [];
                    editCalendarCache[cacheKey] = dates;
                    applyEditCalendarAvailability(dates);
                },
                error: function () {
                    editCalendarCache[cacheKey] = null;
                },
                complete: function () {
                    setEditCalendarLoading(false);
                }
            });
        }

        function loadVisibleEditCalendarAvailability() {
            if (!editDatePicker || !editState.agent_id) return;
            loadEditCalendarAvailability(editDatePicker.currentYear, editDatePicker.currentMonth + 1);
        }

        function showEditNearestMessage(message, tone) {
            var $grid = $('#racc-edit-slots-grid');
            if (!$grid.length) return;
            $('#racc-edit-slots').show();
            $grid.html(
                '<div class="racc-nearest-suggestion ' + (tone === 'error' ? 'racc-nearest-none' : '') + '">'
                + raccEscHtml(message)
                + '</div>'
            );
        }

        function loadNearestEditAvailableDate() {
            if (!editDatePicker || !editState.agent_id || editState.keepSchedule) return;

            var requestId = ++editNearestRequestId;
            $('#racc-edit-slots').show();
            $('#racc-edit-slots-grid').html(
                '<div class="racc-nearest-searching">Searching nearest available date...</div>'
            );

            $.ajax({
                url: raccAdmin.restUrl + 'nearest-available',
                method: 'GET',
                xhrFields: {
                    withCredentials: true
                },
                data: {
                    agent_ids: editState.agent_id,
                    from: editDateObjToYMD(new Date()),
                    woo_product_id: getSelectedWooProductIdFromEditForm()
                },
                beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', raccAdmin.nonce); },
                success: function (data) {
                    if (requestId !== editNearestRequestId || editState.keepSchedule) return;

                    if (data && data.date) {
                        editDatePicker.setDate(data.date, true);
                        editDatePicker.jumpToDate(data.date);
                        loadVisibleEditCalendarAvailability();
                        return;
                    }

                    showEditNearestMessage('No available dates found in the next 60 days.', 'error');
                },
                error: function () {
                    if (requestId !== editNearestRequestId || editState.keepSchedule) return;
                    showEditNearestMessage('Failed to find the nearest available date.', 'error');
                }
            });
        }

        function toggleLocationFields() {
            var mode = $('#racc-edit-location-mode').val() || 'client_place';
            $('#racc-edit-master-location-wrap').toggle(mode === 'master_location');
            $('#racc-location-default-contact-note').toggle(mode === 'default_contact');
            if (mode !== 'master_location') {
                $('#racc-edit-location-id').val('');
            }
        }

        // ── Section toggle (collapsible) ──────────────────────────────
        $(document).on('click', '.racc-edit-section-header', function () {
            var $hdr  = $(this);
            var $body = $('#' + $hdr.data('target'));
            var $icon = $hdr.find('.racc-toggle-icon');
            if ($body.is(':visible')) {
                $body.slideUp(180);
                $icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
                $hdr.removeClass('open');
            } else {
                $body.slideDown(180);
                $icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
                $hdr.addClass('open');
            }
        });

        // ── Init Flatpickr date picker ────────────────────────────────
        function initEditDatePicker() {
            var $field = $('#racc-edit-date');
            if (!$field.length || typeof flatpickr === 'undefined') {
                if ($field.length) setTimeout(initEditDatePicker, 300);
                return;
            }
            if ($field.get(0)._flatpickr) return;
            editDatePicker = flatpickr('#racc-edit-date', {
                dateFormat:    'Y-m-d',
                minDate:       'today',
                disableMobile: true,
                clickOpens:    true,
                allowInput:    false,
                appendTo:      document.body,
                onOpen: function () {
                    loadVisibleEditCalendarAvailability();
                },
                onMonthChange: function (selectedDates, dateStr, fp) {
                    loadEditCalendarAvailability(fp.currentYear, fp.currentMonth + 1);
                },
                onYearChange: function (selectedDates, dateStr, fp) {
                    loadEditCalendarAvailability(fp.currentYear, fp.currentMonth + 1);
                },
                onDayCreate: function (dObj, dStr, fp, dayElem) {
                    if (!dayElem.dateObj) return;
                    var d = editDateObjToYMD(dayElem.dateObj);
                    if (editAvailableDatesSet[d]) {
                        dayElem.classList.add('racc-day-available');
                    }
                },
                onChange: function (selectedDates, dateStr) {
                    editState.date = dateStr;
                    editState.timeStart = null;
                    editState.timeEnd   = null;
                    $('#racc-edit-slots').hide();
                    $('#racc-edit-slot-selected').hide();
                    $('#racc-edit-check-availability').prop('disabled', false);
                    updateChangesSummary();
                    scheduleEditAvailabilityCheck();
                }
            });
            $field.on('click focus', function () {
                if (editDatePicker && !editDatePicker.isOpen) editDatePicker.open();
            });
        }

        if (typeof flatpickr !== 'undefined') {
            initEditDatePicker();
        } else {
            setTimeout(initEditDatePicker, 600);
        }

        // ── Keep Schedule toggle ──────────────────────────────────────
        $('#racc-keep-schedule').on('change', function () {
            editState.keepSchedule = this.checked;
            if (this.checked) {
                cancelEditAvailabilityLookup();
                $('#racc-schedule-picker-wrap').slideUp(200);
                editState.date      = null;
                editState.timeStart = null;
                editState.timeEnd   = null;
                // Restore hidden finals to original
                $('#racc-edit-date-final').val(orig.booking_date);
                $('#racc-edit-time-start-final').val(orig.booking_time_start);
                $('#racc-edit-time-end-final').val(orig.booking_time_end);
            } else {
                $('#racc-schedule-picker-wrap').slideDown(200);
                setTimeout(function () {
                    if (!$('#racc-edit-date').get(0)._flatpickr) initEditDatePicker();
                    loadVisibleEditCalendarAvailability();
                    loadNearestEditAvailableDate();
                }, 100);
            }
            updateChangesSummary();
        });

        // ── Consultant card selection ─────────────────────────────────
        $(document).on('click', '.racc-consultant-card', function () {
            var $card = $(this);
            $('.racc-consultant-card').removeClass('selected');
            $card.addClass('selected');
            $card.find('input[type="radio"]').prop('checked', true);

            editState.agent_id  = parseInt($card.data('agent-id'), 10);
            editState.agentName = $card.find('strong').text().trim();

            var googleOk = $card.data('google-connected') === 1 || $card.data('google-connected') === '1';
            $('#racc-consultant-warning').toggle(!googleOk);

            // Reset slot selection when consultant changes
            if (!editState.keepSchedule) {
                cancelEditAvailabilityLookup();
                editState.timeStart = null;
                editState.timeEnd   = null;
                editState.date      = null;
                $('#racc-edit-date').val('');
                $('#racc-edit-date-final').val('');
                $('#racc-edit-time-start-final').val('');
                $('#racc-edit-time-end-final').val('');
                $('#racc-edit-slots').hide();
                $('#racc-edit-slot-selected').hide();
                $('#racc-edit-check-availability').prop('disabled', true);
                invalidateEditAvailabilityCalendar();
                loadVisibleEditCalendarAvailability();
                loadNearestEditAvailableDate();
            }
            updateChangesSummary();
        });

        // ── Check Availability ────────────────────────────────────────
        function resetEditAvailabilityButton() {
            $('#racc-edit-check-availability').prop('disabled', false).html(
                '<span class="dashicons dashicons-search" style="margin-top:3px;"></span> Check Availability'
            );
        }

        function scheduleEditAvailabilityCheck() {
            clearTimeout(editAvailabilityTimer);
            editAvailabilityRequestId++;
            editAvailabilityTimer = setTimeout(function () {
                checkEditAvailability();
            }, 250);
        }

        function checkEditAvailability() {
            var $btn = $('#racc-edit-check-availability');
            if (!editState.date || !editState.agent_id) {
                showEditMessage('error', 'Please select a date first.');
                return;
            }
            var requestId = ++editAvailabilityRequestId;
            $btn.prop('disabled', true).html(
                '<span class="dashicons dashicons-update racc-sync-spinning" style="margin-top:3px;"></span> Checking…'
            );
            $('#racc-edit-slots').hide();
            $('#racc-edit-slot-selected').hide();
            editState.timeStart = null;
            editState.timeEnd   = null;

            if (editAvailabilityRequest && editAvailabilityRequest.readyState !== 4) {
                editAvailabilityRequest.abort();
            }

            editAvailabilityRequest = $.ajax({
                url: raccAdmin.restUrl + 'admin/availability',
                method: 'GET',
                xhrFields: {
                    withCredentials: true
                },
                data: {
                    agent_id: editState.agent_id,
                    date: editState.date,
                    woo_product_id: getSelectedWooProductIdFromEditForm()
                },
                beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', raccAdmin.nonce); },
                success: function (slots) {
                    if (requestId !== editAvailabilityRequestId) return;
                    resetEditAvailabilityButton();
                    renderEditSlots(slots);
                },
                error: function (xhr) {
                    if (xhr && xhr.statusText === 'abort') return;
                    if (requestId !== editAvailabilityRequestId) return;
                    resetEditAvailabilityButton();
                    showEditMessage('error', getAdminApiErrorMessage(xhr && xhr.responseJSON, 'Failed to check availability.'));
                }
            });
        }

        $('#racc-edit-check-availability').on('click', function () {
            clearTimeout(editAvailabilityTimer);
            checkEditAvailability();
        });

        $('#racc-edit-service').on('change', function () {
            invalidateEditAvailabilityCalendar();
            if (!editState.keepSchedule) {
                cancelEditAvailabilityLookup();
                editState.timeStart = null;
                editState.timeEnd   = null;
                editState.date      = null;
                $('#racc-edit-date').val('');
                $('#racc-edit-date-final').val('');
                $('#racc-edit-time-start-final').val('');
                $('#racc-edit-time-end-final').val('');
                $('#racc-edit-slots').hide();
                $('#racc-edit-slot-selected').hide();
                $('#racc-edit-check-availability').prop('disabled', true);
                loadVisibleEditCalendarAvailability();
                loadNearestEditAvailableDate();
            }
        });

        function renderEditSlots(slots) {
            var $grid = $('#racc-edit-slots-grid').empty();
            if (!slots || !slots.length) {
                $grid.html('<p style="color:#787c82;">No available slots for this date. Try another day.</p>');
                $('#racc-edit-slots').show();
                return;
            }
            slots.forEach(function (slot) {
                $('<button type="button" class="racc-slot-btn"></button>')
                    .text(slot.start + ' — ' + slot.end)
                    .data('start', slot.start)
                    .data('end', slot.end)
                    .on('click', function () {
                        $('.racc-slot-btn').removeClass('selected');
                        $(this).addClass('selected');
                        editState.timeStart = $(this).data('start');
                        editState.timeEnd   = $(this).data('end');
                        // Update hidden finals
                        $('#racc-edit-date-final').val(editState.date);
                        $('#racc-edit-time-start-final').val(editState.timeStart);
                        $('#racc-edit-time-end-final').val(editState.timeEnd);
                        $('#racc-edit-slot-selected-text').text(
                            editState.date + ' · ' + editState.timeStart + ' – ' + editState.timeEnd
                        );
                        $('#racc-edit-slot-selected').show();
                        updateChangesSummary();
                    })
                    .appendTo($grid);
            });
            $('#racc-edit-slots').show();
        }

        // ── Track field changes & build summary ───────────────────────
        $(document).on('change input', '.racc-change-track', function () {
            updateChangesSummary();
        });

        $(document).on('change', '#racc-edit-location-mode', function () {
            toggleLocationFields();
            updateChangesSummary();
        });

        toggleLocationFields();
        syncEditDomicileStateVisibility();

        $(document).on('change', '#racc-edit-client-country', function () {
            syncEditDomicileStateVisibility();
            updateChangesSummary();
        });

        function readForm() {
            return {
                agent_id:                 editState.agent_id,
                agent_name:               editState.agentName,
                status:                   $('#racc-edit-status').val(),
                service_type:             $('#racc-edit-service').val(),
                woo_product_id:           getSelectedWooProductIdFromEditForm(),
                booking_date:             $('#racc-edit-date-final').val(),
                booking_time_start:       $('#racc-edit-time-start-final').val(),
                booking_time_end:         $('#racc-edit-time-end-final').val(),
                client_name:              $('#racc-edit-client-name').val(),
                client_email:             $('#racc-edit-client-email').val(),
                client_phone:             $('#racc-edit-client-phone').val(),
                client_nationality:       $('#racc-edit-client-nationality').val(),
                client_dob:               $('#racc-edit-client-dob').val(),
                client_university:        $('#racc-edit-university').val(),
                client_course_level:      $('#racc-edit-course-level').val(),
                client_course_major:      $('#racc-edit-course-major').val(),
                client_course_completion: $('#racc-edit-course-completion').val(),
                client_visa_type:         $('#racc-edit-visa-type').val(),
                client_visa_expiry:       $('#racc-edit-visa-expiry').val(),
                client_country:           getEditResidenceStateValue(),
                client_occupation:        $('#racc-edit-occupation').val(),
                client_contact_link:      $('#racc-edit-contact-link').val(),
                client_referral_source:   $('#racc-edit-referral').val(),
                notes:                    $('#racc-edit-notes').val(),
                location_mode:            $('#racc-edit-location-mode').val() || 'client_place',
                location_id:              parseInt($('#racc-edit-location-id').val() || '0', 10),
                agentcis_contact_id:      $('#agentcis_contact_id').val()
            };
        }

        var labelMap = {
            agent_name:               'Consultant',
            status:                   'Status',
            service_type:             'Service',
            booking_date:             'Date',
            booking_time_start:       'Start Time',
            booking_time_end:         'End Time',
            client_name:              'Full Name',
            client_email:             'Email',
            client_phone:             'Phone',
            client_nationality:       'Nationality',
            client_dob:               'Date of Birth',
            client_university:        'University',
            client_course_level:      'Course Level',
            client_course_major:      'Course Major',
            client_course_completion: 'Completion',
            client_visa_type:         'Visa Type',
            client_visa_expiry:       'Visa Expiry',
            client_country:           'Country of Residence',
            client_state:             'State / Province',
            client_occupation:        'Occupation',
            client_contact_link:      'Contact Link',
            client_referral_source:   'Referral',
            notes:                    'Notes',
            location_mode:            'Lokasi',
            location_id:              'Master Lokasi'
        };

        function updateChangesSummary() {
            var current   = readForm();
            var origFlat  = {
                agent_name:               orig.agent_name,
                status:                   orig.status,
                service_type:             orig.service_type,
                booking_date:             orig.booking_date,
                booking_time_start:       orig.booking_time_start,
                booking_time_end:         orig.booking_time_end,
                client_name:              orig.client_name,
                client_email:             orig.client_email,
                client_phone:             orig.client_phone,
                client_nationality:       orig.client_nationality,
                client_dob:               orig.client_dob,
                client_university:        orig.client_university,
                client_course_level:      orig.client_course_level,
                client_course_major:      orig.client_course_major,
                client_course_completion: orig.client_course_completion,
                client_visa_type:         orig.client_visa_type,
                client_visa_expiry:       orig.client_visa_expiry,
                client_country:           orig.client_country,
                client_occupation:        orig.client_occupation,
                client_contact_link:      orig.client_contact_link,
                client_referral_source:   orig.client_referral_source,
                notes:                    orig.notes,
                location_mode:            orig.location_mode || 'client_place',
                location_id:              parseInt(orig.location_id || 0, 10)
            };

            var diffs = [];
            $.each(labelMap, function (key, label) {
                var oldVal = (origFlat[key] || '').toString().trim();
                var newVal = (current[key]  || '').toString().trim();
                if (oldVal !== newVal) {
                    diffs.push({ label: label, oldVal: oldVal || '—', newVal: newVal || '—' });
                }
            });

            var $box = $('#racc-changes-summary');
            if (!diffs.length) {
                $box.html('<p style="color:#787c82;font-size:12px;margin:0;">No changes yet — form values match the original booking.</p>');
                return;
            }

            var html = '<div style="font-size:12px;">';
            diffs.forEach(function (d) {
                html += '<div class="racc-change-row">'
                    + '<span class="racc-change-label">' + d.label + '</span>'
                    + '<span class="racc-change-old">' + d.oldVal + '</span>'
                    + '<span class="racc-change-new">' + d.newVal + '</span>'
                    + '</div>';
            });
            html += '</div>';
            $box.html(html);
        }

        // ── Validation ────────────────────────────────────────────────
        function validateEditForm() {
            var name  = $('#racc-edit-client-name').val().trim();
            var email = $('#racc-edit-client-email').val().trim();
            if (!name)  { showEditMessage('error', 'Client name is required.'); return false; }
            if (!email) { showEditMessage('error', 'Client email is required.'); return false; }
            if (!editState.keepSchedule) {
                if (!$('#racc-edit-date-final').val()) { showEditMessage('error', 'Please select a new date.'); return false; }
                if (!editState.timeStart) { showEditMessage('error', 'Please select a time slot.'); return false; }
            }

            if (($('#racc-edit-location-mode').val() || 'client_place') === 'master_location' && !$('#racc-edit-location-id').val()) {
                showEditMessage('error', 'Please select a master location.');
                return false;
            }

            return true;
        }

        // ── Submit ────────────────────────────────────────────────────
        $('#racc-edit-save-btn').on('click', function () {
            if (!validateEditForm()) return;

            var $btn      = $(this);
            var bookingId = $('#racc-edit-booking-id').val();
            var form      = readForm();

            if ((form.agentcis_contact_id || '') !== (orig.agentcis_contact_id || '')) {
                if (!confirm("Are you sure you want to override the AgentCIS Contact ID?\n\nWARNING: Modifying this incorrectly will permanently sync data for this email to the wrong client.\n\nOnly proceed if you are resolving a duplicate email error.")) {
                    return;
                }
            }

            var payload = {
                agent_id:                 form.agent_id,
                service_type:             form.service_type,
                client_name:              form.client_name,
                client_email:             form.client_email,
                client_phone:             form.client_phone,
                client_nationality:       form.client_nationality,
                client_dob:               form.client_dob,
                client_university:        form.client_university,
                client_course_level:      form.client_course_level,
                client_course_major:      form.client_course_major,
                client_course_completion: form.client_course_completion,
                client_visa_type:         form.client_visa_type,
                client_visa_expiry:       form.client_visa_expiry,
                client_country:           form.client_country,
                client_occupation:        form.client_occupation,
                client_contact_link:      form.client_contact_link,
                client_referral_source:   form.client_referral_source,
                notes:                    form.notes,
                location_mode:            form.location_mode,
                location_id:              form.location_mode === 'master_location' ? form.location_id : 0,
                agentcis_contact_id:      form.agentcis_contact_id
            };

            // Status is an explicit edit. Do not resend it for consultant-only
            // changes, because stale/default select values can alter booking state.
            if ((form.status || '') !== (orig.status || '')) {
                payload.status = form.status;
            }

            // Include schedule only when changed
            if (!editState.keepSchedule && editState.timeStart) {
                payload.booking_date       = form.booking_date;
                payload.booking_time_start = form.booking_time_start;
                payload.booking_time_end   = form.booking_time_end;
            }

            $btn.prop('disabled', true).html(
                '<span class="dashicons dashicons-update racc-sync-spinning" style="margin-top:3px;"></span> Saving…'
            );
            $('#racc-edit-booking-message').hide();

            $.ajax({
                url: raccAdmin.restUrl + 'bookings/' + bookingId + '/reschedule',
                method: 'POST',
                contentType: 'application/json',
                xhrFields: {
                    withCredentials: true
                },
                data: JSON.stringify(payload),
                beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', raccAdmin.nonce); },
                success: function (response) {
                    if (response && response.success) {
                        showEditMessage('success', response.message || 'Booking updated successfully!');
                        setTimeout(function () {
                            window.location.href = raccAdmin.adminUrl + 'admin.php?page=racc-booking&message=booking_updated';
                        }, 1400);
                    } else {
                        $btn.prop('disabled', false).html(
                            '<span class="dashicons dashicons-saved" style="margin-top:3px;"></span> Save All Changes'
                        );
                        showEditMessage('error', (response && response.message) || 'Failed to save changes.');
                    }
                },
                error: function (xhr) {
                    $btn.prop('disabled', false).html(
                        '<span class="dashicons dashicons-saved" style="margin-top:3px;"></span> Save All Changes'
                    );
                    showEditMessage('error', getAdminApiErrorMessage(xhr && xhr.responseJSON, 'Failed to save changes. Please try again.'));
                }
            });
        });

        function showEditMessage(type, text) {
            var $msg = $('#racc-edit-booking-message');
            var bg   = type === 'success' ? '#dcfce7' : '#fee2e2';
            var col  = type === 'success' ? '#166534' : '#991b1b';
            var brd  = type === 'success' ? '#86efac' : '#fecaca';
            $msg.css({ background: bg, color: col, border: '1px solid ' + brd,
                        padding: '12px 16px', borderRadius: '6px', fontSize: '14px' })
                .text(text).show();
            $('html, body').animate({ scrollTop: $msg.offset().top - 40 }, 300);
        }

        // Build initial summary
        updateChangesSummary();
    }

    // ─────────────────────────────────────
    // AgentCIS Manual Sync & Retry
    // ─────────────────────────────────────
    function raccShowAgentcisNotice(type, message) {
        $('.racc-agentcis-runtime-notice').remove();

        $('<div class="notice notice-' + type + ' is-dismissible racc-agentcis-runtime-notice" style="margin-top:12px;"><p>'
            + message + '</p></div>')
            .insertAfter('.racc-admin-title');
    }

    function raccStartAgentcisCooldown($btn, seconds, originalHtml) {
        var remaining = parseInt(seconds, 10) || 0;

        if (remaining <= 0) {
            $btn.prop('disabled', false).html(originalHtml);
            return;
        }

        $btn.prop('disabled', true);

        var tick = function() {
            if (remaining <= 0) {
                $btn.prop('disabled', false).html(originalHtml);
                return;
            }

            $btn.html('<span class="dashicons dashicons-clock" style="margin-top:3px;"></span> Retry in ' + remaining + 's');
            remaining -= 1;
            window.setTimeout(tick, 1000);
        };

        tick();
    }

    function raccAgentcisSyncRequest(action, bookingId, $btn, extraData) {
        var originalHtml = $btn.html();
        $btn.prop('disabled', true)
            .html('<span class="racc-sync-spinning dashicons dashicons-update"></span> Syncing...');

        var nonce = $('#racc-agentcis-nonce').val()
            || (window.raccAdmin && raccAdmin.agentcisNonce)
            || '';

        var ajaxData = {
            action: action,
            booking_id: bookingId,
            nonce: nonce
        };
        if (extraData) {
            $.extend(ajaxData, extraData);
        }

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            xhrFields: {
                withCredentials: true
            },
            data: ajaxData,
            success: function(response) {
                if (response.success) {
                    var contactId = response.data.contact_id || '';
                    var html = '<span style="background:#dcfce7;color:#166534;padding:4px 10px;border-radius:4px;font-size:13px;">✅ Synced</span>';
                    if (contactId) {
                        html += '<br><small style="color:#6b7280;margin-top:4px;display:block;">'
                            + '<a href="https://racccrm.agentcisapp.com/app#/contacts/u/' + contactId + '/activities" target="_blank" rel="noopener">🔗 View in AgentCIS</a>'
                            + '</small>';
                    }
                    $('#racc-agentcis-sync-cell').html(html);

                    // Also dismiss the top notice if visible
                    $('.notice-error #racc-retry-agentcis-sync').closest('.notice').slideUp();

                    $('<div class="notice notice-success is-dismissible" style="margin-top:12px;"><p>'
                        + response.data.message + '</p></div>')
                        .insertAfter('.racc-admin-title')
                        .delay(4000).fadeOut();
                } else {
                    var message = (response.data && response.data.message ? response.data.message : 'Sync failed.');
                    var retryAfter = response.data && response.data.retry_after ? parseInt(response.data.retry_after, 10) : 0;

                    if (response.data && response.data.code === 'agentcis_rate_limited' && retryAfter > 0) {
                        raccShowAgentcisNotice('warning', '⚠️ ' + message);
                        raccStartAgentcisCooldown($btn, retryAfter, originalHtml);
                        return;
                    }

                    $btn.prop('disabled', false).html(originalHtml);
                    raccShowAgentcisNotice('error', '❌ ' + message);
                }
            },
            error: function() {
                $btn.prop('disabled', false).html(originalHtml);
                raccShowAgentcisNotice('error', '❌ Connection error. Please try again.');
            }
        });
    }

    // Sync Now (pending)
    $(document).on('click', '#racc-manual-agentcis-sync', function() {
        var bookingId = $(this).data('booking-id');
        raccAgentcisSyncRequest('racc_agentcis_manual_sync', bookingId, $(this));
    });

    // Retry Sync (failed) — inline in table cell
    $(document).on('click', '#racc-retry-agentcis-sync-inline', function() {
        var bookingId = $(this).data('booking-id');
        raccAgentcisSyncRequest('racc_agentcis_retry_sync', bookingId, $(this));
    });

    $(document).on('click', '#racc-unlock-contact-id', function() {
        $('#agentcis_contact_id').removeAttr('readonly').css('background', '#fff').focus();
        $(this).hide();
        $('#racc-save-contact-id').show();
    });

    $(document).on('click', '#racc-toggle-agentcis-help', function(e) {
        e.preventDefault();
        $('#racc-agentcis-help-image').slideToggle('fast');
    });

    $(document).on('click', '.racc-zoomable-image', function() {
        var src = $(this).attr('src');
        var $overlay = $('<div id="racc-lightbox-overlay" style="position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.8); z-index:999999; display:flex; align-items:center; justify-content:center; cursor:zoom-out;">' +
                            '<img src="' + src + '" style="max-width:90%; max-height:90%; object-fit:contain; box-shadow: 0 4px 20px rgba(0,0,0,0.5); border-radius: 4px;" />' +
                         '</div>');
        
        $overlay.appendTo('body').hide().fadeIn('fast');
        
        $overlay.on('click', function() {
            $(this).fadeOut('fast', function() {
                $(this).remove();
            });
        });
    });

    $(document).on('click', '.racc-inline-edit-contact-btn', function(e) {
        e.preventDefault();
        
        var manualId = prompt("Please enter the correct AgentCIS Contact ID:");
        if (manualId !== null) {
            manualId = manualId.trim();
            if (manualId !== "") {
                $('#agentcis_contact_id').val(manualId);
                var bookingId = $(this).data('booking-id');
                raccAgentcisSyncRequest('racc_agentcis_retry_sync', bookingId, $(this), { agentcis_contact_id: manualId });
            }
        }
    });

    $(document).on('click', '#racc-save-contact-id', function() {
        var bookingId = $(this).data('booking-id');
        var manualId = $('#agentcis_contact_id').val();
        raccAgentcisSyncRequest('racc_agentcis_retry_sync', bookingId, $(this), { agentcis_contact_id: manualId });
    });

    // Retry Sync — in top notice banner
    $(document).on('click', '#racc-retry-agentcis-sync', function() {
        var bookingId = $(this).data('booking-id');
        raccAgentcisSyncRequest('racc_agentcis_retry_sync', bookingId, $(this));
    });

    function getAdminApiErrorMessage(payload, fallback) {
        payload = payload || {};

        var code = payload.code || '';
        var message = payload.message || fallback;
        var i18n = (window.raccAdmin && raccAdmin.i18n) ? raccAdmin.i18n : {};

        if (code === 'google_not_connected') {
            return i18n.googleNotConnected || message;
        }

        if (code === 'google_api_error') {
            return i18n.googleApiError || message;
        }

        if (code === 'calendar_sync_failed') {
            return i18n.calendarSyncFailed || message;
        }

        if (code === 'slot_taken' || code === 'slot_locked') {
            return i18n.slotUnavailable || message;
        }

        return message || fallback;
    }

    // ─────────────────────────────────────
    // Calendar page (DB mode + Google custom/iframe)
    // ─────────────────────────────────────
    (function initAdminCalendar() {
        var $modeSelect = $('#racc-calendar-mode');
        var $googleViewWrap = $('#racc-google-view-wrap');
        var $googleViewSelect = $('#racc-google-view');
        var $msWrap = $('#racc-calendar-account-wrap');
        var $msTrigger = $('#racc-multiselect-trigger');
        var $msLabel = $('#racc-multiselect-label');
        var $msDropdown = $('#racc-multiselect-dropdown');
        var $msOptions = $('#racc-multiselect-options');
        var $msAll = $msDropdown.find('.racc-multiselect-all input');
        var $iframe = $('#racc-calendar-iframe');
        var $googleWrap = $('#racc-calendar-google-wrap');
        var $dbWrap = $('#racc-calendar-db');
        var $message = $('#racc-calendar-message');
        var $toolbar = $('#racc-calendar-toolbar');
        var $rangeLabel = $('#racc-calendar-range');
        var $legend = $('#racc-calendar-legend');
        var $googleNote = $('#racc-google-note');
        var $actionNote = $('#racc-calendar-action-note');

        if (!$modeSelect.length || !$msTrigger.length) {
            return;
        }

        var calendarData = (window.raccAdmin && raccAdmin.calendar) ? raccAdmin.calendar : {};
        var i18n = (window.raccAdmin && raccAdmin.i18n) ? raccAdmin.i18n : {};
        var googleAccounts = Array.isArray(calendarData.accounts) ? calendarData.accounts : [];
        var consultants = Array.isArray(calendarData.consultants) ? calendarData.consultants : [];
        var GOOGLE_ALL_ID = 'all_consultants_google';
        var DB_ALL_ID = 'all_consultants';

        var currentView = String(calendarData.defaultView || 'WEEK').toUpperCase();
        var currentMode = String(calendarData.defaultMode || 'DB').toUpperCase();
        var currentGoogleView = String(calendarData.defaultGoogleView || 'IFRAME').toUpperCase();
        var currentOffset = 0;

        function formatDateISO(dateObj) {
            var year = dateObj.getFullYear();
            var month = String(dateObj.getMonth() + 1).padStart(2, '0');
            var day = String(dateObj.getDate()).padStart(2, '0');
            return year + '-' + month + '-' + day;
        }

        function yyyymmdd(dateObj) {
            var year = dateObj.getFullYear();
            var month = String(dateObj.getMonth() + 1).padStart(2, '0');
            var day = String(dateObj.getDate()).padStart(2, '0');
            return '' + year + month + day;
        }

        function addDays(dateObj, days) {
            var next = new Date(dateObj.getTime());
            next.setDate(next.getDate() + days);
            return next;
        }

        function parseDateKey(dateKey) {
            return new Date(dateKey + 'T00:00:00');
        }

        function getStartOfWeek(dateObj) {
            var d = new Date(dateObj.getTime());
            var day = d.getDay();
            var diffToMonday = day === 0 ? -6 : (1 - day);
            d.setDate(d.getDate() + diffToMonday);
            d.setHours(0, 0, 0, 0);
            return d;
        }

        function getRange() {
            var now = new Date();
            now.setHours(0, 0, 0, 0);

            if (currentView === 'DAY') {
                var dayDate = addDays(now, currentOffset);
                return { start: dayDate, end: dayDate };
            }

            var base = addDays(now, currentOffset * 7);
            var weekStart = getStartOfWeek(base);
            var weekEnd = addDays(weekStart, 6);
            return { start: weekStart, end: weekEnd };
        }

        function buildDayKeys(range) {
            var dayKeys = [];
            var cursor = new Date(range.start.getTime());

            while (cursor <= range.end) {
                dayKeys.push(formatDateISO(cursor));
                cursor = addDays(cursor, 1);
            }

            return dayKeys;
        }

        function setActiveViewButton(view) {
            $('[data-racc-view]').each(function() {
                var $btn = $(this);
                $btn.toggleClass('button-primary', String($btn.data('racc-view')).toUpperCase() === view);
            });
        }

        function updateRangeLabel(range) {
            var startText = range.start.toLocaleDateString('en-AU', { day: 'numeric', month: 'short', year: 'numeric' });
            var endText = range.end.toLocaleDateString('en-AU', { day: 'numeric', month: 'short', year: 'numeric' });
            $rangeLabel.text(startText === endText ? startText : (startText + ' — ' + endText));
        }

        function getConsultantColor(consultantId) {
            var id = parseInt(consultantId, 10) || 0;
            var hue = (id * 67) % 360;
            return 'hsl(' + hue + ' 62% 45%)';
        }

        function extractConsultantId(value) {
            var raw = String(value || '0');
            var match = raw.match(/(\d+)/);
            return match ? parseInt(match[1], 10) : 0;
        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function buildGoogleUrl(baseUrl, view, range) {
            try {
                var url = new URL(baseUrl, window.location.origin);

                if (view === 'DAY') {
                    var day = yyyymmdd(range.start);
                    url.searchParams.set('mode', 'AGENDA');
                    url.searchParams.set('dates', day + '/' + day);
                } else {
                    var start = yyyymmdd(range.start);
                    var end = yyyymmdd(addDays(range.end, 1));
                    url.searchParams.set('mode', 'WEEK');
                    url.searchParams.set('dates', start + '/' + end);
                }

                return url.toString();
            } catch (e) {
                return baseUrl;
            }
        }

        // ── Multi-select helpers ──────────────────────────────────────
        function getSelectedIds() {
            // Returns array of selected IDs (strings), or ['__all__'] if all selected.
            if ($msAll.is(':checked')) { return ['__all__']; }
            var ids = [];
            $msOptions.find('input[type=checkbox]:checked').each(function() {
                ids.push($(this).val());
            });
            return ids.length ? ids : ['__all__'];
        }

        function updateMsLabel() {
            var ids = getSelectedIds();
            if (ids[0] === '__all__') {
                $msLabel.text(i18n.allConsultants || 'All Consultants');
                return;
            }
            if (ids.length === 1) {
                var label = $msOptions.find('input[value="' + ids[0] + '"]').closest('label').text().trim();
                $msLabel.text(label || ids[0]);
            } else {
                $msLabel.text(ids.length + ' ' + (i18n.consultant || 'Consultants'));
            }
        }

        function fillAccountOptions(mode) {
            $msOptions.empty();
            $msAll.prop('checked', true);
            var items = (mode === 'DB') ? consultants : googleAccounts;

            items.forEach(function(item) {
                var val = String(item.id);
                var color = getConsultantColor(extractConsultantId(val));
                var $label = $('<label class="racc-multiselect-item"></label>');
                var $cb = $('<input type="checkbox">');
                $cb.val(val);
                var $dot = $('<span class="racc-multiselect-dot"></span>').css('background', color);
                $label.append($cb).append($dot).append(document.createTextNode(' ' + escapeHtml(item.label)));
                $msOptions.append($label);
            });

            updateMsLabel();
        }

        // Toggle dropdown
        $msTrigger.on('click', function(e) {
            e.stopPropagation();
            $msDropdown.toggle();
        });

        $(document).on('click', function(e) {
            if (!$msWrap.is(e.target) && $msWrap.has(e.target).length === 0) {
                $msDropdown.hide();
            }
        });

        // "All" checkbox logic
        $msAll.on('change', function() {
            if ($(this).is(':checked')) {
                $msOptions.find('input[type=checkbox]').prop('checked', false);
            }
            updateMsLabel();
            renderCurrentMode();
        });

        // Individual checkbox logic
        $msOptions.on('change', 'input[type=checkbox]', function() {
            var anyChecked = $msOptions.find('input[type=checkbox]:checked').length > 0;
            $msAll.prop('checked', !anyChecked);
            updateMsLabel();
            renderCurrentMode();
        });

        function buildGoogleAllConsultantsUrl(view, range, accountsList) {
            var list = accountsList || googleAccounts;
            if (!list.length) { return ''; }

            try {
                var firstUrl = new URL(list[0].embed_url, window.location.origin);
                var baseUrl = new URL('https://calendar.google.com/calendar/embed');

                ['ctz', 'showTitle', 'showPrint', 'showTabs', 'showCalendars', 'showTz'].forEach(function(key) {
                    var val = firstUrl.searchParams.get(key);
                    if (val !== null && val !== '') {
                        baseUrl.searchParams.set(key, val);
                    }
                });

                list.forEach(function(account) {
                    try {
                        var accountUrl = new URL(account.embed_url, window.location.origin);
                        accountUrl.searchParams.getAll('src').forEach(function(src) {
                            if (src) { baseUrl.searchParams.append('src', src); }
                        });
                    } catch (e) {}
                });

                return buildGoogleUrl(baseUrl.toString(), view, range);
            } catch (e) {
                return '';
            }
        }

        function renderLegend(events) {
            if (!events || !events.length) {
                $legend.hide().empty();
                return;
            }

            var map = {};
            events.forEach(function(evt) {
                var cid = String(evt.consultant_id || '0');
                if (!map[cid]) {
                    map[cid] = {
                        id: cid,
                        label: evt.consultant_name || (i18n.consultant || 'Consultant'),
                        color: getConsultantColor(cid)
                    };
                }
            });

            var html = '<strong>' + escapeHtml(i18n.consultant || 'Consultant') + ':</strong>';
            Object.keys(map).sort().forEach(function(key) {
                var item = map[key];
                html += '<span class="racc-calendar-legend-item">'
                    + '<span class="racc-calendar-legend-dot" style="background:' + item.color + ';"></span>'
                    + '<span style="color:' + item.color + '; font-weight:600;">' + escapeHtml(item.label) + '</span>'
                    + '</span>';
            });

            $legend.html(html).show();
        }

        function renderConsultantLegendFromAccounts(accountsList) {
            if (!accountsList || !accountsList.length) {
                $legend.hide().empty();
                return;
            }

            var html = '<strong>' + escapeHtml(i18n.consultant || 'Consultant') + ':</strong>';

            accountsList.forEach(function(item) {
                var cid = extractConsultantId(item && item.id ? item.id : '0');
                var color = getConsultantColor(cid);
                var label = item && item.label ? item.label : (i18n.consultant || 'Consultant');

                html += '<span class="racc-calendar-legend-item">'
                    + '<span class="racc-calendar-legend-dot" style="background:' + color + ';"></span>'
                    + '<span style="color:' + color + '; font-weight:600;">' + escapeHtml(label) + '</span>'
                    + '</span>';
            });

            $legend.html(html).show();
        }

        function hexAlpha(hslColor, alpha) {
            // Convert HSL string to rgba for background tint
            var m = hslColor.match(/hsl\((\d+),\s*(\d+)%,\s*(\d+)%\)/);
            if (!m) { return 'rgba(100,100,200,' + alpha + ')'; }
            return 'hsla(' + m[1] + ',' + m[2] + '%,' + m[3] + '%,' + alpha + ')';
        }

        function bookingIdForEvent(evt) {
            if (!evt) {
                return 0;
            }

            if (evt.source === 'google') {
                return parseInt(evt.booking_id || 0, 10) || 0;
            }

            return parseInt(evt.booking_id || evt.id || 0, 10) || 0;
        }

        function buildAdminUrl(path) {
            try {
                return new URL(path, raccAdmin.adminUrl || window.location.href).toString();
            } catch (e) {
                return (raccAdmin.adminUrl || '') + path.replace(/^\//, '');
            }
        }

        function renderBookingActions(evt) {
            var bookingId = bookingIdForEvent(evt);
            if (!bookingId) {
                return '';
            }

            var detailsUrl = buildAdminUrl('admin.php?page=racc-booking&booking_id=' + bookingId);
            var rescheduleUrl = buildAdminUrl('admin.php?page=racc-booking-reschedule&booking_id=' + bookingId);

            var reassignUrl = buildAdminUrl('admin.php?page=racc-booking-reassign&booking_ids[]=' + bookingId + '&_wpnonce=' + (raccAdmin.reassignNonce || ''));
            return '<div class="racc-event-actions">'
                + '<a class="button button-small racc-view-details" href="' + escapeHtml(detailsUrl) + '" data-booking-id="' + bookingId + '">' + escapeHtml(i18n.viewDetails || 'View Details') + '</a>'
                + '<a class="button button-small" href="' + escapeHtml(rescheduleUrl) + '">' + 'Edit/Reschedule' + '</a>'
                + '<a class="button button-small" href="' + escapeHtml(reassignUrl) + '">' + 'Change Consultant' + '</a>'
                + '<button type="button" class="button button-small racc-event-cancel" data-booking-id="' + bookingId + '">' + escapeHtml(i18n.cancel || 'Cancel') + '</button>'
                + '<button type="button" class="button button-small button-link-delete racc-event-delete" data-booking-id="' + bookingId + '">' + escapeHtml(i18n.delete || 'Delete') + '</button>'
                + '</div>';
        }

        function sendBookingAction(url, method, fallbackMessage) {
            return $.ajax({
                url: url,
                method: method,
                xhrFields: { withCredentials: true },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', raccAdmin.nonce || raccAdmin.restNonce);
                },
                success: function(resp) {
                    var message = (resp && resp.message) ? resp.message : fallbackMessage;
                    if (message) {
                        window.alert(message);
                    }
                    window.location.reload();
                },
                error: function(xhr) {
                    var message = fallbackMessage || 'Action failed.';
                    if (xhr && xhr.responseJSON) {
                        message = xhr.responseJSON.message || (xhr.responseJSON.data && xhr.responseJSON.data.message) || message;
                    }
                    window.alert(message);
                }
            });
        }

        $(document).on('click', '.racc-event-cancel', function() {
            var bookingId = parseInt($(this).data('booking-id'), 10) || 0;
            if (!bookingId) {
                return;
            }

            if (!window.confirm(raccAdmin.i18n.confirmCancel || 'Are you sure you want to cancel this booking?')) {
                return;
            }

            sendBookingAction(raccAdmin.restUrl + 'bookings/' + bookingId + '/cancel', 'POST', raccAdmin.i18n.bookingCancelled || 'Booking cancelled successfully.');
        });

        $(document).on('click', '.racc-event-delete', function() {
            var bookingId = parseInt($(this).data('booking-id'), 10) || 0;
            if (!bookingId) {
                return;
            }

            if (!window.confirm(raccAdmin.i18n.confirmDelete || 'Are you sure you want to delete this?')) {
                return;
            }

            sendBookingAction(raccAdmin.restUrl + 'bookings/' + bookingId, 'DELETE', raccAdmin.i18n.bookingDeleted || 'Booking deleted successfully.');
        });

        function renderEventCard(evt, options) {
            var color = getConsultantColor(evt.consultant_id);
            var isGoogle = (evt.source === 'google');
            var isAllDay = options && options.isAllDay;

            if (isGoogle) {
                // ── Compact Google-style block ──────────────────────
                if (isAllDay) {
                    return '<div class="racc-cal-event racc-cal-event--allday" style="background:' + color + ';" title="' + escapeHtml(evt.title || '') + '">'
                        + '<span class="racc-cal-event-title">' + escapeHtml(evt.title || (i18n.allDay || 'All day')) + '</span>'
                        + '<span class="racc-cal-event-who">' + escapeHtml(evt.consultant_name || '') + '</span>'
                        + '</div>';
                }
                return '<div class="racc-cal-event" style="background:' + hexAlpha(color, 0.12) + '; border-left:4px solid ' + color + ';" title="' + escapeHtml(evt.title || '') + '">'
                    + (evt.start_time ? '<div class="racc-cal-event-time" style="color:' + color + ';">' + escapeHtml(evt.start_time) + (evt.end_time ? ' – ' + escapeHtml(evt.end_time) : '') + '</div>' : '')
                    + '<div class="racc-cal-event-title">' + escapeHtml(evt.title || '') + '</div>'
                    + '<div class="racc-cal-event-who">'
                        + '<span class="racc-consultant-chip" style="background:' + color + ';"></span>'
                        + '<span style="color:' + color + ';">' + escapeHtml(evt.consultant_name || '') + '</span>'
                    + '</div>'
                    + renderBookingActions(evt)
                    + '</div>';
            }

            // ── Detailed DB booking card ──────────────────────────
            var html = '<div class="racc-db-event" style="border-left-color:' + color + ';">';

            if (evt.start_time || evt.end_time) {
                html += '<div class="racc-db-event-time">' + escapeHtml(evt.start_time || '') + (evt.end_time ? ' – ' + escapeHtml(evt.end_time) : '') + '</div>';
            }

            if (evt.client_name) {
                html += '<div><strong>' + escapeHtml(i18n.client || 'Client') + ':</strong> ' + escapeHtml(evt.client_name) + '</div>';
            }

            if (evt.title) {
                html += '<div><strong>' + escapeHtml(i18n.eventTitle || 'Event') + ':</strong> ' + escapeHtml(evt.title) + '</div>';
            }

            html += '<div><strong>' + escapeHtml(i18n.consultant || 'Consultant') + ':</strong> '
                + '<span class="racc-consultant-chip" style="background:' + color + ';"></span>'
                + '<span style="color:' + color + '; font-weight:600;">' + escapeHtml(evt.consultant_name || '') + '</span></div>';

            if (evt.service_type) {
                html += '<div><strong>' + escapeHtml(i18n.service || 'Service') + ':</strong> ' + escapeHtml(evt.service_type) + '</div>';
            }

            if (evt.status) {
                html += '<div><strong>' + escapeHtml(i18n.status || 'Status') + ':</strong> ' + escapeHtml(evt.status) + '</div>';
            }

            html += renderBookingActions(evt);

            html += '</div>';
            return html;
        }

        function renderGridFromDays(dayKeys, grouped, emptyMessage, warningsHtml) {
            var html = warningsHtml || '';
            html += '<div class="racc-db-grid">';

            dayKeys.forEach(function(dayKey) {
                var list = grouped[dayKey] || [];
                var dateObj = parseDateKey(dayKey);
                var dayLabel = dateObj.toLocaleDateString('en-AU', {
                    weekday: 'short',
                    day: 'numeric',
                    month: 'short'
                });

                html += '<div class="racc-db-day">';
                html += '<div class="racc-db-day-header">' + escapeHtml(dayLabel) + '</div>';
                html += '<div class="racc-db-day-body">';

                if (!list.length) {
                    html += '<div class="racc-db-empty">—</div>';
                } else {
                    list.forEach(function(entry) {
                        html += renderEventCard(entry.event, { isAllDay: entry.isAllDay });
                    });
                }

                html += '</div></div>';
            });

            html += '</div>';

            if (!Object.keys(grouped).some(function(key) { return (grouped[key] || []).length; })) {
                html += '<p class="racc-db-empty-note">' + escapeHtml(emptyMessage) + '</p>';
            }

            $dbWrap.html(html).show();
        }

        function renderDbEvents(events, range) {
            var dayKeys = buildDayKeys(range);
            var grouped = {};
            dayKeys.forEach(function(key) { grouped[key] = []; });

            (events || []).forEach(function(evt) {
                var key = evt.booking_date;
                if (grouped[key]) {
                    grouped[key].push({ event: evt, isAllDay: false });
                }
            });

            renderGridFromDays(dayKeys, grouped, i18n.dbCalendarEmpty || 'No bookings found in this period.', '');
            renderLegend(events || []);
        }

        function buildWarningsHtml(warnings) {
            if (!warnings || !warnings.length) {
                return '';
            }

            var html = '<div class="racc-calendar-warning-list">';
            html += '<strong>' + escapeHtml(i18n.googleWarningsTitle || 'Some consultant calendars could not be loaded:') + '</strong>';
            html += '<ul>';

            warnings.forEach(function(item) {
                html += '<li>' + escapeHtml(item.consultant_name || '') + ': ' + escapeHtml(item.message || '') + '</li>';
            });

            html += '</ul></div>';
            return html;
        }

        function expandGoogleEventsForRange(events, range) {
            var grouped = {};
            var dayKeys = buildDayKeys(range);

            dayKeys.forEach(function(key) {
                grouped[key] = [];
            });

            (events || []).forEach(function(evt) {
                if (evt.is_all_day) {
                    var startDate = evt.start_date;
                    var endExclusive = evt.end_date;
                    var cursor = parseDateKey(startDate);
                    var endObj = parseDateKey(endExclusive);

                    while (cursor < endObj) {
                        var cursorKey = formatDateISO(cursor);
                        if (grouped[cursorKey]) {
                            grouped[cursorKey].push({ event: evt, isAllDay: true });
                        }
                        cursor = addDays(cursor, 1);
                    }
                    return;
                }

                var key = evt.start_date || evt.booking_date;
                if (grouped[key]) {
                    grouped[key].push({ event: evt, isAllDay: false });
                }
            });

            return {
                dayKeys: dayKeys,
                grouped: grouped
            };
        }

        function renderGoogleCustomEvents(events, range, warnings) {
            var expanded = expandGoogleEventsForRange(events, range);
            var warningsHtml = buildWarningsHtml(warnings);

            renderGridFromDays(
                expanded.dayKeys,
                expanded.grouped,
                i18n.googleCalendarEmpty || 'No Google Calendar events found in this period.',
                warningsHtml
            );
            renderLegend(events || []);
        }

        function selectedNumericIds() {
            var ids = getSelectedIds();
            if (ids[0] === '__all__') { return null; }
            var nums = ids.map(function(v) { return extractConsultantId(v); }).filter(function(n) { return n > 0; });
            return nums.length ? nums.join(',') : null;
        }

        function renderDbMode() {
            var range = getRange();
            updateRangeLabel(range);

            var reqData = {
                start_date: formatDateISO(range.start),
                end_date: formatDateISO(range.end)
            };

            var agentIds = selectedNumericIds();
            if (agentIds) { reqData.agent_ids = agentIds; }

            $message.hide();
            $dbWrap.html('<div class="racc-loading"><div class="racc-spinner"></div></div>').show();

            $.ajax({
                url: raccAdmin.restUrl + 'admin/calendar-events',
                method: 'GET',
                data: reqData,
                xhrFields: { withCredentials: true },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', raccAdmin.nonce || raccAdmin.restNonce);
                },
                success: function(resp) {
                    var events = (resp && Array.isArray(resp.events)) ? resp.events : [];
                    renderDbEvents(events, range);
                },
                error: function() {
                    $dbWrap.hide();
                    $legend.hide().empty();
                    $message.text(i18n.dbCalendarError || 'Failed to load bookings calendar data.').show();
                }
            });
        }

        function renderGoogleIframeMode() {
            var range = getRange();
            updateRangeLabel(range);

            if (!googleAccounts.length) {
                $toolbar.addClass('racc-is-empty');
                $googleWrap.hide();
                $legend.hide().empty();
                $message.text(i18n.noCalendarAccounts || 'No connected consultant calendar found.').show();
                return;
            }

            var selectedIds = getSelectedIds();
            var isAll = selectedIds[0] === '__all__';

            // Determine which accounts to show
            var activeAccounts = isAll
                ? googleAccounts
                : googleAccounts.filter(function(a) { return selectedIds.indexOf(String(a.id)) !== -1; });

            if (!activeAccounts.length) { activeAccounts = googleAccounts; }

            $toolbar.removeClass('racc-is-empty');
            $message.hide();

            if (activeAccounts.length === 1) {
                var account = activeAccounts[0];
                $googleWrap.show();
                $iframe.attr('src', buildGoogleUrl(account.embed_url, currentView, range));
                renderConsultantLegendFromAccounts(activeAccounts);
                return;
            }

            // Multiple — merge src params
            var mergedUrl = buildGoogleAllConsultantsUrl(currentView, range, activeAccounts);
            if (!mergedUrl) {
                $googleWrap.hide();
                $legend.hide().empty();
                $message.text(i18n.noCalendarAccounts || 'No connected consultant calendar found.').show();
                return;
            }

            $googleWrap.show();
            $iframe.attr('src', mergedUrl);
            renderConsultantLegendFromAccounts(activeAccounts);
        }

        function renderGoogleCustomMode() {
            var range = getRange();
            updateRangeLabel(range);

            if (!googleAccounts.length) {
                $toolbar.addClass('racc-is-empty');
                $dbWrap.hide();
                $legend.hide().empty();
                $message.text(i18n.noCalendarAccounts || 'No connected consultant calendar found.').show();
                return;
            }

            var reqData = {
                start_date: formatDateISO(range.start),
                end_date: formatDateISO(range.end)
            };

            var agentIds = selectedNumericIds();
            if (agentIds) { reqData.agent_ids = agentIds; }

            $toolbar.removeClass('racc-is-empty');
            $message.hide();
            $dbWrap.html('<div class="racc-loading"><div class="racc-spinner"></div></div>').show();

            $.ajax({
                url: raccAdmin.restUrl + 'admin/google-calendar-events',
                method: 'GET',
                data: reqData,
                xhrFields: { withCredentials: true },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', raccAdmin.nonce || raccAdmin.restNonce);
                },
                success: function(resp) {
                    var events = (resp && Array.isArray(resp.events)) ? resp.events : [];
                    var warnings = (resp && Array.isArray(resp.warnings)) ? resp.warnings : [];
                    renderGoogleCustomEvents(events, range, warnings);
                },
                error: function() {
                    $dbWrap.hide();
                    $legend.hide().empty();
                    $message.text(i18n.googleCalendarError || 'Failed to load Google Calendar events.').show();
                }
            });
        }

        function syncModeUi() {
            var isGoogle = currentMode === 'GOOGLE';
            var isIframe = isGoogle && currentGoogleView === 'IFRAME';

            $googleViewWrap.toggle(isGoogle);
            $googleWrap.toggle(isIframe);
            $dbWrap.toggle(!isIframe);
            $googleNote.toggle(isIframe);
            $actionNote.toggle(isIframe);

            if (isIframe) {
                $actionNote.text(i18n.iframeActionNote || 'Iframe view is read-only. Use Custom view for actions.');
            }
        }

        function renderCurrentMode() {
            setActiveViewButton(currentView);
            syncModeUi();

            if (currentMode === 'DB') {
                renderDbMode();
                return;
            }

            if (currentGoogleView === 'IFRAME') {
                renderGoogleIframeMode();
                return;
            }

            renderGoogleCustomMode();
        }

        function init() {
            fillAccountOptions(currentMode);
            $modeSelect.val(currentMode);
            $googleViewSelect.val(currentGoogleView);
            renderCurrentMode();
        }

        $modeSelect.on('change', function() {
            currentMode = String($(this).val() || 'DB').toUpperCase();
            currentOffset = 0;
            fillAccountOptions(currentMode);
            renderCurrentMode();
        });

        $googleViewSelect.on('change', function() {
            currentGoogleView = String($(this).val() || 'IFRAME').toUpperCase();
            renderCurrentMode();
        });

        $('[data-racc-view]').on('click', function() {
            currentView = String($(this).data('racc-view') || 'WEEK').toUpperCase();
            currentOffset = 0;
            renderCurrentMode();
        });

        $('[data-racc-nav]').on('click', function() {
            var dir = String($(this).data('racc-nav') || 'next').toLowerCase();
            currentOffset += (dir === 'prev' ? -1 : 1);
            renderCurrentMode();
        });

        init();
        openBookingDetailsFromQuery();
    })();

})(jQuery);
