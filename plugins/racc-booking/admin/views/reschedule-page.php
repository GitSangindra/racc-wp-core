<?php
/**
 * Admin view: Edit / Reschedule Booking page.
 *
 * @package RACC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$booking_id = absint( $_GET['booking_id'] ?? 0 );
$gcal       = new \RACC_Booking\Google_Calendar();

if ( ! $booking_id ) {
    echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Invalid booking ID.', 'racc-booking' ) . '</p></div></div>';
    return;
}

$booking = $wpdb->get_row( $wpdb->prepare(
    "SELECT b.*, a.name as agent_name, a.email as agent_email, a.timezone as agent_timezone, a.google_refresh_token as agent_google_refresh_token
     FROM {$wpdb->prefix}racc_bookings b
     LEFT JOIN {$wpdb->prefix}racc_agents a ON b.agent_id = a.id
     WHERE b.id = %d",
    $booking_id
) );

if ( ! $booking ) {
    echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Booking not found.', 'racc-booking' ) . '</p></div></div>';
    return;
}

// Load all active consultants for the selector
$all_agents = $wpdb->get_results(
    "SELECT id, name, email, google_refresh_token, agentcis_assignee_id
     FROM {$wpdb->prefix}racc_agents
     WHERE status = 'active'
     ORDER BY name ASC"
);

$agentcis_users = get_option( 'racc_agentcis_users_list', [] );
$agentcis_map   = wp_list_pluck( $agentcis_users, 'name', 'id' );

$all_locations = \RACC_Booking\Admin::get_active_locations();
$settings      = get_option( 'racc_booking_settings', [] );

$services             = \RACC_Booking\Admin::get_services();
$is_google_connected  = $gcal->is_connected( (int) $booking->agent_id );

// Fetch booking services from WooCommerce products (bridge integration).
$woo_service_products = [];
if ( function_exists( 'wc_get_products' ) ) {
    $woo_bridge_settings   = get_option( 'racc_woo_bridge_settings', [] );
    $woo_category_slug     = $woo_bridge_settings['category_slug'] ?? 'booking-services';
    $woo_service_products  = wc_get_products( [
        'category' => [ $woo_category_slug ],
        'status'   => 'publish',
        'limit'    => -1,
        'orderby'  => 'title',
        'order'    => 'ASC',
    ] );
}
$agentcis_sync_status = $booking->agentcis_sync_status ?? 'pending';
$agentcis_contact_id  = $booking->agentcis_contact_id  ?? null;
$agentcis_sync_error  = $booking->agentcis_sync_error  ?? null;
$agentcis_configured  = ! empty( get_option( 'racc_agentcis_api_key' ) );
$agentcis_nonce       = wp_create_nonce( 'racc_agentcis_nonce' );
$current_visa_options = \RACC_Booking\Visa_Categories::get_options();

if ( ! empty( $booking->client_visa_type ) && ! in_array( $booking->client_visa_type, $current_visa_options, true ) ) {
    array_unshift( $current_visa_options, $booking->client_visa_type );
}

$australian_state_options = [
    'Australian Capital Territory',
    'New South Wales',
    'Northern Territory',
    'Queensland',
    'South Australia',
    'Tasmania',
    'Victoria',
    'Western Australia',
];
$current_residence_state = in_array( $booking->client_country, $australian_state_options, true ) ? $booking->client_country : $booking->client_state;
$current_residence_country = $current_residence_state ? 'Australia' : $booking->client_country;

// Original booking data exposed to JS for prefill & diff tracking
$booking_js_data = [
    'id'                       => (int) $booking->id,
    'agent_id'                 => (int) $booking->agent_id,
    'agent_name'               => $booking->agent_name ?? '',
    'google_connected'         => $is_google_connected,
    'client_name'              => $booking->client_name ?? '',
    'client_email'             => $booking->client_email ?? '',
    'client_phone'             => $booking->client_phone ?? '',
    'client_nationality'       => $booking->client_nationality ?? '',
    'client_dob'               => $booking->client_dob ?? '',
    'client_university'        => $booking->client_university ?? '',
    'client_course_level'      => $booking->client_course_level ?? '',
    'client_course_major'      => $booking->client_course_major ?? '',
    'client_course_completion' => $booking->client_course_completion ?? '',
    'client_visa_type'         => $booking->client_visa_type ?? '',
    'client_visa_expiry'       => $booking->client_visa_expiry ?? '',
    'client_country'           => $booking->client_country ?? '',
    'client_occupation'        => $booking->client_occupation ?? '',
    'client_contact_link'      => $booking->client_contact_link ?? '',
    'client_referral_source'   => $booking->client_referral_source ?? '',
    'service_type'             => $booking->service_type ?? '',
    'woo_product_id'           => (int) ( $booking->woo_product_id ?? 0 ),
    'booking_date'             => $booking->booking_date ?? '',
    'booking_time_start'       => substr( $booking->booking_time_start ?? '', 0, 5 ),
    'booking_time_end'         => substr( $booking->booking_time_end ?? '', 0, 5 ),
    'status'                   => $booking->status ?? 'confirmed',
    'notes'                    => $booking->notes ?? '',
    'location_mode'            => $booking->location_mode ?? 'client_place',
    'location_id'              => (int) ( $booking->location_id ?? 0 ),
    'agentcis_contact_id'      => $booking->agentcis_contact_id ?? '',
];
?>
<div class="wrap racc-admin-wrap">
    <h1 class="racc-admin-title">
        <span class="dashicons dashicons-edit"></span>
        <?php esc_html_e( 'Edit Booking', 'racc-booking' ); ?> <span style="color:#787c82;">#<?php echo esc_html( $booking_id ); ?></span>
    </h1>

    <a href="<?php echo esc_url( admin_url( 'admin.php?page=racc-booking' ) ); ?>" class="page-title-action">
        ← <?php esc_html_e( 'Back to Bookings', 'racc-booking' ); ?>
    </a>

    <?php if ( $agentcis_configured && $agentcis_sync_status === 'failed' ) : ?>
        <div class="notice notice-error" style="margin-top:15px;">
            <p>
                <strong>⚠️ <?php esc_html_e( 'AgentCIS Sync Failed:', 'racc-booking' ); ?></strong>
                <?php echo esc_html( $agentcis_sync_error ?: __( 'Unknown error', 'racc-booking' ) ); ?>
                <button type="button"
                        id="racc-retry-agentcis-sync"
                        data-booking-id="<?php echo esc_attr( $booking_id ); ?>"
                        class="button button-small" style="margin-left:10px;">
                    🔄 <?php esc_html_e( 'Retry Sync', 'racc-booking' ); ?>
                </button>
            </p>
        </div>
    <?php endif; ?>

    <div id="racc-edit-booking-message" style="display:none;margin-top:15px;"></div>

    <div class="racc-edit-booking-layout">

        <!-- ═══ LEFT: Edit Form ═══ -->
        <div class="racc-edit-booking-main">

            <!-- ── Section: Status & Service ── -->
            <div class="racc-edit-section">
                <div class="racc-edit-section-header open" data-target="racc-section-status">
                    <span class="dashicons dashicons-tag"></span>
                    <h3><?php esc_html_e( 'Booking Status & Service', 'racc-booking' ); ?></h3>
                    <span class="racc-toggle-icon dashicons dashicons-arrow-up-alt2"></span>
                </div>
                <div id="racc-section-status" class="racc-edit-section-body">
                    <div class="racc-edit-form-row">
                        <div class="racc-edit-form-group">
                            <label for="racc-edit-status"><?php esc_html_e( 'Status', 'racc-booking' ); ?></label>
                            <select id="racc-edit-status" class="regular-text racc-change-track">
                                <?php foreach ( [
                                    'pending_payment' => 'Pending Payment',
                                    'pending'         => 'Pending',
                                    'confirmed'       => 'Confirmed',
                                    'rescheduled'     => 'Rescheduled',
                                    'cancelled'       => 'Cancelled',
                                    'completed'       => 'Completed',
                                ] as $val => $lbl ) : ?>
                                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $booking->status, $val ); ?>>
                                        <?php echo esc_html( $lbl ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="racc-edit-form-group">
                            <label for="racc-edit-service"><?php esc_html_e( 'Service Type', 'racc-booking' ); ?></label>
                            <?php if ( ! empty( $woo_service_products ) ) : ?>
                            <select id="racc-edit-service" class="regular-text racc-change-track">
                                <?php foreach ( $woo_service_products as $woo_product ) :
                                    $product_name = $woo_product->get_name();
                                    $product_id   = $woo_product->get_id();
                                    $price_html   = wp_strip_all_tags( $woo_product->get_price_html() );
                                    $option_label = $product_name . ( $price_html ? ' (' . $price_html . ')' : '' );
                                ?>
                                    <option value="<?php echo esc_attr( $product_name ); ?>"
                                            data-product-id="<?php echo esc_attr( $product_id ); ?>"
                                            <?php selected( $booking->service_type, $product_name ); ?>>
                                        <?php echo esc_html( $option_label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description" style="margin-top:4px;font-size:11px;">
                                <?php esc_html_e( 'Services are pulled from WooCommerce products.', 'racc-booking' ); ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=racc-booking-woo-settings' ) ); ?>" target="_blank"><?php esc_html_e( 'Bridge Settings →', 'racc-booking' ); ?></a>
                            </p>
                            <?php else : ?>
                            <select id="racc-edit-service" class="regular-text racc-change-track">
                                <?php foreach ( $services as $key => $label ) : ?>
                                    <option value="<?php echo esc_attr( $label ); ?>" <?php selected( $booking->service_type, $label ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Section: Consultant ── -->
            <div class="racc-edit-section">
                <div class="racc-edit-section-header open" data-target="racc-section-consultant">
                    <span class="dashicons dashicons-businessman"></span>
                    <h3><?php esc_html_e( 'Consultant', 'racc-booking' ); ?></h3>
                    <span class="racc-toggle-icon dashicons dashicons-arrow-up-alt2"></span>
                </div>
                <div id="racc-section-consultant" class="racc-edit-section-body">
                    <div class="racc-consultant-grid">
                        <?php foreach ( $all_agents as $agent ) :
                            $connection_status = $gcal->get_connection_status( (int) $agent->id, true );
                            $agent_status      = $connection_status['status'] ?? 'not_connected';
                            $agent_google_ok   = ( $agent_status === 'connected' );
                            $needs_reconnect   = ( $agent_status === 'reconnect_required' );
                        ?>
                            <label class="racc-consultant-card <?php echo ( (int) $agent->id === (int) $booking->agent_id ) ? 'selected' : ''; ?><?php echo $needs_reconnect ? ' racc-consultant-card--disabled' : ''; ?>"
                                   data-agent-id="<?php echo esc_attr( $agent->id ); ?>"
                                   data-google-connected="<?php echo $agent_google_ok ? '1' : '0'; ?>"
                                   data-needs-reconnect="<?php echo $needs_reconnect ? '1' : '0'; ?>">
                                <input type="radio" name="racc_edit_agent"
                                       value="<?php echo esc_attr( $agent->id ); ?>"
                                       <?php checked( (int) $agent->id, (int) $booking->agent_id ); ?>
                                       <?php disabled( $needs_reconnect ); ?> />
                                <div class="racc-consultant-card-inner">
                                    <div class="racc-consultant-avatar">
                                        <?php echo esc_html( strtoupper( mb_substr( $agent->name, 0, 1 ) ) ); ?>
                                    </div>
                                    <div class="racc-consultant-info">
                                        <strong><?php echo esc_html( $agent->name ); ?></strong>
                                        <small><?php echo esc_html( $agent->email ); ?></small>
                                        <?php if ( $agent_google_ok ) : ?>
                                            <span class="racc-calendar-badge racc-calendar-badge--on">✓ Calendar connected</span>
                                        <?php elseif ( $needs_reconnect ) : ?>
                                            <span class="racc-calendar-badge racc-calendar-badge--warning">⚠️ Reconnect required</span>
                                        <?php else : ?>
                                            <span class="racc-calendar-badge racc-calendar-badge--off">⚠ Calendar offline</span>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $agent->agentcis_assignee_id ) && isset( $agentcis_map[ $agent->agentcis_assignee_id ] ) ) : ?>
                                            <span style="font-size:10px;color:#047857;margin-top:4px;display:inline-block;padding:2px 4px;background:#d1fae5;border-radius:4px;border:1px solid #a7f3d0;">
                                                AgentCIS: <?php echo esc_html( $agentcis_map[ $agent->agentcis_assignee_id ] ); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p id="racc-consultant-warning" class="racc-field-warning" style="display:none;">
                        ⚠️ <?php esc_html_e( 'Selected consultant\'s Google Calendar is not connected. Availability check will be skipped.', 'racc-booking' ); ?>
                    </p>
                    <p id="racc-consultant-reconnect-warning" class="racc-field-warning" style="display:none;color:#dc2626;">
                        ⚠️ <strong><?php esc_html_e( 'Selected consultant\'s Google Calendar connection needs to be reconnected before booking!', 'racc-booking' ); ?></strong>
                    </p>
                </div>
            </div>

            <!-- ── Section: Schedule ── -->
            <div class="racc-edit-section">
                <div class="racc-edit-section-header open" data-target="racc-section-schedule">
                    <span class="dashicons dashicons-calendar-alt"></span>
                    <h3><?php esc_html_e( 'Date & Time', 'racc-booking' ); ?></h3>
                    <span class="racc-toggle-icon dashicons dashicons-arrow-up-alt2"></span>
                </div>
                <div id="racc-section-schedule" class="racc-edit-section-body">

                    <div class="racc-schedule-keep-row">
                        <label class="racc-checkbox-label">
                            <input type="checkbox" id="racc-keep-schedule" checked />
                            <?php esc_html_e( 'Keep current schedule:', 'racc-booking' ); ?>
                            <strong>
                                <?php echo esc_html(
                                    date_i18n( 'l, j F Y', strtotime( $booking->booking_date ) ) . ' · ' .
                                    date_i18n( 'g:i A', strtotime( $booking->booking_time_start ) ) . ' – ' .
                                    date_i18n( 'g:i A', strtotime( $booking->booking_time_end ) )
                                ); ?>
                            </strong>
                        </label>
                    </div>

                    <div id="racc-schedule-picker-wrap" style="display:none;">
                        <div class="racc-edit-form-row" style="align-items:flex-end;">
                            <div class="racc-edit-form-group">
                                <label for="racc-edit-date">
                                    <span class="dashicons dashicons-calendar-alt"></span>
                                    <?php esc_html_e( 'New Date', 'racc-booking' ); ?>
                                </label>
                                <input type="text"
                                       id="racc-edit-date"
                                       class="regular-text racc-datepicker"
                                       placeholder="<?php esc_attr_e( 'Click to pick a date…', 'racc-booking' ); ?>"
                                       autocomplete="off" />
                            </div>
                            <div class="racc-edit-form-group">
                                <button type="button" id="racc-edit-check-availability" class="button button-secondary" disabled>
                                    <span class="dashicons dashicons-search" style="margin-top:3px;"></span>
                                    <?php esc_html_e( 'Check Availability', 'racc-booking' ); ?>
                                </button>
                            </div>
                        </div>

                        <div id="racc-edit-slots" class="racc-time-slots" style="display:none;margin-top:15px;">
                            <label style="font-weight:600;margin-bottom:10px;display:block;">
                                <?php esc_html_e( 'Available Time Slots:', 'racc-booking' ); ?>
                            </label>
                            <div id="racc-edit-slots-grid" class="racc-slots-grid"></div>
                        </div>

                        <div id="racc-edit-slot-selected" class="racc-slot-selected-notice" style="display:none;">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <span id="racc-edit-slot-selected-text"></span>
                        </div>
                    </div>

                    <!-- Hidden: carry current values when keeping schedule -->
                    <input type="hidden" id="racc-edit-date-final"       value="<?php echo esc_attr( $booking->booking_date ); ?>" />
                    <input type="hidden" id="racc-edit-time-start-final" value="<?php echo esc_attr( substr( $booking->booking_time_start, 0, 5 ) ); ?>" />
                    <input type="hidden" id="racc-edit-time-end-final"   value="<?php echo esc_attr( substr( $booking->booking_time_end, 0, 5 ) ); ?>" />
                </div>
            </div>

            <!-- ── Section: Client Information (collapsed by default) ── -->
            <div class="racc-edit-section">
                <div class="racc-edit-section-header" data-target="racc-section-client">
                    <span class="dashicons dashicons-id"></span>
                    <h3><?php esc_html_e( 'Client Information', 'racc-booking' ); ?></h3>
                    <span class="racc-toggle-icon dashicons dashicons-arrow-down-alt2"></span>
                </div>
                <div id="racc-section-client" class="racc-edit-section-body" style="display:none;">
                    <div class="racc-edit-form-row">
                        <div class="racc-edit-form-group">
                            <label for="racc-edit-client-name"><?php esc_html_e( 'Full Name', 'racc-booking' ); ?> <span class="required">*</span></label>
                            <input type="text" id="racc-edit-client-name" class="regular-text racc-change-track"
                                   value="<?php echo esc_attr( $booking->client_name ); ?>" required />
                        </div>
                        <div class="racc-edit-form-group">
                            <label for="racc-edit-client-email"><?php esc_html_e( 'Email Address', 'racc-booking' ); ?> <span class="required">*</span></label>
                            <input type="email" id="racc-edit-client-email" class="regular-text racc-change-track"
                                   value="<?php echo esc_attr( $booking->client_email ); ?>" required />
                        </div>
                    </div>
                    <div class="racc-edit-form-row">
                        <div class="racc-edit-form-group">
                            <label for="racc-edit-client-phone"><?php esc_html_e( 'Phone Number', 'racc-booking' ); ?></label>
                            <input type="tel" id="racc-edit-client-phone" class="regular-text racc-change-track"
                                   value="<?php echo esc_attr( $booking->client_phone ); ?>" />
                        </div>
                        <div class="racc-edit-form-group">
                            <label for="racc-edit-client-nationality"><?php esc_html_e( 'Nationality', 'racc-booking' ); ?></label>
                            <select id="racc-edit-client-nationality" class="regular-text racc-change-track racc-searchable-select" data-racc-searchable-select="1" data-search-placeholder="<?php esc_attr_e( 'Search nationality...', 'racc-booking' ); ?>">
                                <option value=""><?php esc_html_e( '— Select Nationality —', 'racc-booking' ); ?></option>
                                <?php foreach ( \RACC_Booking\Country_Helper::get_country_list() as $code => $name ) : ?>
                                    <option value="<?php echo esc_attr( $name ); ?>" <?php selected( $booking->client_nationality, $name ); ?>>
                                        <?php echo esc_html( $name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="racc-edit-form-row">
                        <div class="racc-edit-form-group">
                            <label for="racc-edit-client-dob"><?php esc_html_e( 'Date of Birth', 'racc-booking' ); ?></label>
                            <input type="date" id="racc-edit-client-dob" class="regular-text racc-change-track"
                                   value="<?php echo esc_attr( $booking->client_dob ); ?>" />
                        </div>
                        <div class="racc-edit-form-group">
                            <label for="racc-edit-client-country"><?php esc_html_e( 'Where did you live?', 'racc-booking' ); ?></label>
                            <select id="racc-edit-client-country" class="regular-text racc-change-track racc-searchable-select" data-racc-searchable-select="1" data-search-placeholder="<?php esc_attr_e( 'Search country...', 'racc-booking' ); ?>">
                                <option value=""><?php esc_html_e( '— Select Country —', 'racc-booking' ); ?></option>
                                <option value="Offshore" <?php selected( $current_residence_country, 'Offshore' ); ?>><?php esc_html_e( 'Offshore', 'racc-booking' ); ?></option>
                                <?php foreach ( \RACC_Booking\Country_Helper::get_country_list() as $code => $name ) : ?>
                                    <option value="<?php echo esc_attr( $name ); ?>" <?php selected( $current_residence_country, $name ); ?>>
                                        <?php echo esc_html( $name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="racc-edit-client-state-group" class="racc-edit-form-group" style="<?php echo $current_residence_state ? '' : 'display:none;'; ?>">
                            <label for="racc-edit-client-state"><?php esc_html_e( 'State', 'racc-booking' ); ?></label>
                            <select id="racc-edit-client-state" class="regular-text racc-change-track">
                                <option value=""><?php esc_html_e( 'Select...', 'racc-booking' ); ?></option>
                                <option value="Offshore" hidden><?php esc_html_e( 'Offshore', 'racc-booking' ); ?></option>
                                <?php foreach ( $australian_state_options as $state_option ) : ?>
                                    <option value="<?php echo esc_attr( $state_option ); ?>" <?php selected( $current_residence_state, $state_option ); ?>>
                                        <?php echo esc_html( $state_option ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Section: Education & Visa (collapsed) ── -->
            <div class="racc-edit-section">
                <div class="racc-edit-section-header" data-target="racc-section-education">
                    <span class="dashicons dashicons-welcome-learn-more"></span>
                    <h3><?php esc_html_e( 'Education & Visa', 'racc-booking' ); ?></h3>
                    <span class="racc-toggle-icon dashicons dashicons-arrow-down-alt2"></span>
                </div>
                <div id="racc-section-education" class="racc-edit-section-body" style="display:none;">
                    <div class="racc-edit-form-row">
                        <div class="racc-edit-form-group">
                            <label for="racc-edit-university"><?php esc_html_e( 'University / School', 'racc-booking' ); ?></label>
                            <input type="text" id="racc-edit-university" class="regular-text racc-change-track"
                                   value="<?php echo esc_attr( $booking->client_university ); ?>" />
                        </div>
                        <div class="racc-edit-form-group">
                            <label for="racc-edit-course-level"><?php esc_html_e( 'Course Level', 'racc-booking' ); ?></label>
                            <select id="racc-edit-course-level" class="regular-text racc-change-track">
                                <?php foreach ( ['High School','Certificate','Diploma','Advanced Diploma','Bachelor','Graduate Certificate','Graduate Diploma','Master','Doctorate/PhD'] as $lv ) : ?>
                                    <option value="<?php echo esc_attr( $lv ); ?>" <?php selected( $booking->client_course_level, $lv ); ?>>
                                        <?php echo esc_html( $lv ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="racc-edit-form-row">
                        <div class="racc-edit-form-group">
                            <label for="racc-edit-course-major"><?php esc_html_e( 'Course Major', 'racc-booking' ); ?></label>
                            <input type="text" id="racc-edit-course-major" class="regular-text racc-change-track"
                                   value="<?php echo esc_attr( $booking->client_course_major ); ?>" />
                        </div>
                        <div class="racc-edit-form-group">
                            <label for="racc-edit-course-completion"><?php esc_html_e( 'Course Completion Date', 'racc-booking' ); ?></label>
                            <input type="date" id="racc-edit-course-completion" class="regular-text racc-change-track"
                                   value="<?php echo esc_attr( $booking->client_course_completion ); ?>" />
                        </div>
                    </div>
                    <div class="racc-edit-form-row">
                        <div class="racc-edit-form-group">
                            <label for="racc-edit-visa-type"><?php esc_html_e( 'Current Visa', 'racc-booking' ); ?></label>
                            <select id="racc-edit-visa-type" class="regular-text racc-change-track">
                                <?php foreach ( $current_visa_options as $v ) : ?>
                                    <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $booking->client_visa_type, $v ); ?>>
                                        <?php echo esc_html( $v ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="racc-edit-form-group">
                            <label for="racc-edit-visa-expiry"><?php esc_html_e( 'Visa Expiry Date', 'racc-booking' ); ?></label>
                            <input type="date" id="racc-edit-visa-expiry" class="regular-text racc-change-track"
                                   value="<?php echo esc_attr( $booking->client_visa_expiry ); ?>" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Section: Additional Information (collapsed) ── -->
            <div class="racc-edit-section">
                <div class="racc-edit-section-header" data-target="racc-section-location">
                    <span class="dashicons dashicons-location"></span>
                    <h3><?php esc_html_e( 'Lokasi Booking', 'racc-booking' ); ?></h3>
                    <span class="racc-toggle-icon dashicons dashicons-arrow-down-alt2"></span>
                </div>
                <div id="racc-section-location" class="racc-edit-section-body" style="display:none;">
                    <div class="racc-edit-form-group">
                        <label for="racc-edit-location-mode"><?php esc_html_e( 'Opsi Lokasi', 'racc-booking' ); ?></label>
                        <?php $current_location_mode = $booking->location_mode ?: 'client_place'; ?>
                        <select id="racc-edit-location-mode" class="regular-text racc-change-track">
                            <option value="client_place" <?php selected( $current_location_mode, 'client_place' ); ?>>
                                <?php esc_html_e( 'Di tempat klien', 'racc-booking' ); ?>
                            </option>
                            <option value="master_location" <?php selected( $current_location_mode, 'master_location' ); ?>>
                                <?php esc_html_e( 'Pilih dari Master Lokasi', 'racc-booking' ); ?>
                            </option>
                            <option value="default_contact" <?php selected( $current_location_mode, 'default_contact' ); ?>>
                                <?php esc_html_e( 'Gunakan info kontak yang sama seperti lokasi default Anda', 'racc-booking' ); ?>
                            </option>
                        </select>
                    </div>

                    <div class="racc-edit-form-group" id="racc-edit-master-location-wrap" style="margin-top:12px;<?php echo ( 'master_location' === $current_location_mode ) ? '' : 'display:none;'; ?>">
                        <label for="racc-edit-location-id"><?php esc_html_e( 'Master Lokasi', 'racc-booking' ); ?></label>
                        <select id="racc-edit-location-id" class="regular-text racc-change-track">
                            <option value=""><?php esc_html_e( 'Pilih lokasi...', 'racc-booking' ); ?></option>
                            <?php foreach ( $all_locations as $location ) : ?>
                                <option value="<?php echo esc_attr( $location->id ); ?>" <?php selected( (int) $booking->location_id, (int) $location->id ); ?>>
                                    <?php
                                    $label_parts = array_filter( [
                                        $location->name,
                                        $location->city,
                                        $location->country_region,
                                    ] );
                                    echo esc_html( implode( ' — ', $label_parts ) );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <p class="description" id="racc-location-default-contact-note" style="margin-top:10px;<?php echo ( 'default_contact' === $current_location_mode ) ? '' : 'display:none;'; ?>">
                        <?php
                        $default_contact_preview = trim( implode( ' · ', array_filter( [
                            $settings['default_contact_name'] ?? '',
                            $settings['default_contact_phone'] ?? '',
                            $settings['default_contact_email'] ?? '',
                        ] ) ) );
                        echo esc_html( $default_contact_preview ? $default_contact_preview : __( 'Default contact belum diatur di Settings.', 'racc-booking' ) );
                        ?>
                    </p>
                </div>
            </div>

            <div class="racc-edit-section">
                <div class="racc-edit-section-header" data-target="racc-section-additional">
                    <span class="dashicons dashicons-info-outline"></span>
                    <h3><?php esc_html_e( 'Additional Information', 'racc-booking' ); ?></h3>
                    <span class="racc-toggle-icon dashicons dashicons-arrow-down-alt2"></span>
                </div>
                <div id="racc-section-additional" class="racc-edit-section-body" style="display:none;">
                    <div class="racc-edit-form-row">
                        <div class="racc-edit-form-group">
                            <label for="racc-edit-occupation"><?php esc_html_e( 'Current Occupation', 'racc-booking' ); ?></label>
                            <input type="text" id="racc-edit-occupation" class="regular-text racc-change-track"
                                   value="<?php echo esc_attr( $booking->client_occupation ); ?>" />
                        </div>
                        <div class="racc-edit-form-group">
                            <label for="racc-edit-contact-link"><?php esc_html_e( 'WhatsApp / Viber / Messenger', 'racc-booking' ); ?></label>
                            <input type="text" id="racc-edit-contact-link" class="regular-text racc-change-track"
                                   value="<?php echo esc_attr( $booking->client_contact_link ); ?>" />
                        </div>
                    </div>
                    <div class="racc-edit-form-row">
                        <div class="racc-edit-form-group">
                            <label for="racc-edit-referral"><?php esc_html_e( 'Referral Source', 'racc-booking' ); ?></label>
                            <select id="racc-edit-referral" class="regular-text racc-change-track">
                                <?php foreach ( ['B2B Client','Chat GPT','Direct to Website','Email','Event','Facebook','Facebook Event Ad','Facebook Lead Ad','Google','Instagram','Instagram Event Ad','Instagram Lead Ad','Linkedin','Other','Previous Client','Promotional Code','RACC Indonesia','Referral (Family)','Referral (Friend)','Telemarketing','Tiktok','University Club','Voucher/Flyer'] as $r ) : ?>
                                    <option value="<?php echo esc_attr( $r ); ?>" <?php selected( $booking->client_referral_source, $r ); ?>>
                                        <?php echo esc_html( $r ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="racc-edit-form-group">
                        <label for="racc-edit-notes"><?php esc_html_e( 'Consultation Enquiry / Notes', 'racc-booking' ); ?></label>
                        <textarea id="racc-edit-notes" class="regular-text racc-change-track" rows="5"
                                  style="width:100%;resize:vertical;"><?php echo esc_textarea( $booking->notes ); ?></textarea>
                    </div>
                </div>
            </div>

        </div><!-- /.racc-edit-booking-main -->

        <!-- ═══ RIGHT: Sidebar ═══ -->
        <div class="racc-edit-booking-sidebar">

            <!-- Save Button -->
            <div class="racc-admin-card" style="text-align:center;">
                <button type="button" id="racc-edit-save-btn"
                        class="button button-primary button-hero"
                        style="width:100%;margin-bottom:10px;padding:10px 0;font-size:15px;">
                    <span class="dashicons dashicons-saved" style="margin-top:3px;"></span>
                    <?php esc_html_e( 'Save All Changes', 'racc-booking' ); ?>
                </button>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=racc-booking' ) ); ?>"
                   class="button" style="width:100%;box-sizing:border-box;text-align:center;">
                    <?php esc_html_e( 'Cancel', 'racc-booking' ); ?>
                </a>
                <p class="description" style="font-size:11px;margin-top:10px;color:#787c82;">
                    <?php esc_html_e( 'Client receives an email if date or time changes.', 'racc-booking' ); ?>
                </p>
            </div>

            <!-- Changes Summary -->
            <div class="racc-admin-card">
                <h3 style="margin-top:0;font-size:14px;display:flex;align-items:center;gap:6px;">
                    <span class="dashicons dashicons-visibility" style="color:#2271b1;font-size:16px;width:16px;height:16px;"></span>
                    <?php esc_html_e( 'Changes Summary', 'racc-booking' ); ?>
                </h3>
                <div id="racc-changes-summary">
                    <p style="color:#787c82;font-size:12px;margin:0;"><?php esc_html_e( 'No changes yet — form values match the original booking.', 'racc-booking' ); ?></p>
                </div>
            </div>

            <!-- AgentCIS Sync -->
            <?php if ( $agentcis_configured ) : ?>
            <div class="racc-admin-card">
                <h3 style="margin-top:0;font-size:14px;">AgentCIS Sync</h3>
                <div id="racc-agentcis-sync-cell">
                    <?php if ( $agentcis_sync_status === 'synced' ) : ?>
                        <span class="racc-status-badge" style="background:#dcfce7;color:#166534;border:1px solid #86efac;">✅ Synced</span>
                        <?php if ( ! empty( $agentcis_contact_id ) ) : ?>
                            <br><small style="color:#6b7280;margin-top:4px;display:block;">
                                <a href="https://racccrm.agentcisapp.com/app#/contacts/u/<?php echo esc_attr( $agentcis_contact_id ); ?>/activities"
                                   target="_blank" rel="noopener" style="margin-left:4px;">🔗 View on AgentCIS</a>
                            </small>
                        <?php else : ?>
                            <small style="color:#6b7280;display:block;margin-top:4px;">
                                <?php esc_html_e( 'Client synced to AgentCIS successfully.', 'racc-booking' ); ?>
                            </small>
                        <?php endif; ?>
                    <?php elseif ( $agentcis_sync_status === 'failed' ) : ?>
                        <span class="racc-status-badge" style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;">❌ Sync Failed</span>
                        <small style="color:#991b1b;display:block;margin-top:4px;"><?php echo esc_html( $agentcis_sync_error ?: 'Unknown error' ); ?></small>
                        <div style="margin-top:6px;">
                            <button type="button" id="racc-retry-agentcis-sync-inline"
                                    data-booking-id="<?php echo esc_attr( $booking_id ); ?>"
                                    class="button button-small">🔄 Retry</button>
                            <?php if ( strpos( strtolower( $agentcis_sync_error ), 'already been taken' ) !== false ) : ?>
                                <button type="button" class="button button-small racc-inline-edit-contact-btn" style="margin-left:6px;">🔑 Input Client ID</button>
                            <?php endif; ?>
                        </div>
                    <?php else : ?>
                        <span class="racc-status-badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;">⏳ Pending</span>
                        <button type="button" id="racc-manual-agentcis-sync"
                                data-booking-id="<?php echo esc_attr( $booking_id ); ?>"
                                class="button button-small" style="margin-left:6px;">🔄 Sync Now</button>
                    <?php endif; ?>
                </div>
                
                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #e5e7eb;">
                    <label for="agentcis_contact_id" style="font-size: 13px; display: block; font-weight: 600; margin-bottom: 5px;">
                        AgentCIS Contact ID Override
                        <a href="#" id="racc-toggle-agentcis-help" style="margin-left: 4px; text-decoration: none;" title="How to get Contact ID?">
                            <span class="dashicons dashicons-editor-help" style="font-size: 16px; color: #2271b1; vertical-align: middle;"></span>
                        </a>
                    </label>
                    <div id="racc-agentcis-help-image" style="display: none; margin-bottom: 10px; border: 1px solid #c3c4c7; padding: 5px; background: #fff; max-width: 500px;">
                        <img src="<?php echo esc_url( RACC_BOOKING_URL . 'assets/images/agentcis-contact-id-help.png' ); ?>" alt="How to get AgentCIS Contact ID" class="racc-zoomable-image" style="max-width: 100%; height: auto; display: block; cursor: zoom-in;" title="Click to enlarge" />
                    </div>
                    <div style="display:flex; gap: 8px; align-items:center;">
                        <input type="text" name="agentcis_contact_id" id="agentcis_contact_id" value="<?php echo esc_attr( $agentcis_contact_id ); ?>" class="regular-text" style="width: 100%; max-width: 250px; padding: 4px 8px; font-size: 13px; background: #f0f0f1;" placeholder="Leave empty to auto-detect" readonly />
                        <button type="button" id="racc-unlock-contact-id" class="button button-secondary">✏️ Edit</button>
                        <button type="button" id="racc-save-contact-id" class="button button-primary" data-booking-id="<?php echo esc_attr( $booking_id ); ?>" style="display:none;">💾 Save & Sync</button>
                    </div>
                    <small style="color:#991b1b;display:block;margin-top:4px;font-style:italic;">
                        ⚠️ <strong>Warning:</strong> Modifying this ID incorrectly will permanently sync data to the wrong client. Only change this if the sync failed due to duplicate email.
                    </small>
                </div>
            </div>
            <?php endif; ?>

            <!-- Original Booking (reference) -->
            <div class="racc-admin-card">
                <h3 style="margin-top:0;font-size:14px;color:#787c82;display:flex;align-items:center;gap:6px;">
                    <span class="dashicons dashicons-backup" style="font-size:16px;width:16px;height:16px;"></span>
                    <?php esc_html_e( 'Original Booking', 'racc-booking' ); ?>
                </h3>
                <table class="racc-mini-table">
                    <tr><th><?php esc_html_e( 'Client', 'racc-booking' ); ?></th>
                        <td><?php echo esc_html( $booking->client_name ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Consultant', 'racc-booking' ); ?></th>
                        <td><?php echo esc_html( $booking->agent_name ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Service', 'racc-booking' ); ?></th>
                        <td><?php echo esc_html( $booking->service_type ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Date', 'racc-booking' ); ?></th>
                        <td><?php echo esc_html( date_i18n( 'j M Y', strtotime( $booking->booking_date ) ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Time', 'racc-booking' ); ?></th>
                        <td><?php echo esc_html(
                            date_i18n( 'g:i A', strtotime( $booking->booking_time_start ) ) . '–' .
                            date_i18n( 'g:i A', strtotime( $booking->booking_time_end ) )
                        ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Status', 'racc-booking' ); ?></th>
                        <td><span class="racc-status-badge racc-status-<?php echo esc_attr( $booking->status ); ?>">
                            <?php echo esc_html( ucfirst( $booking->status ) ); ?>
                        </span></td></tr>
                </table>
            </div>

        </div><!-- /.racc-edit-booking-sidebar -->
    </div><!-- /.racc-edit-booking-layout -->

    <!-- Hidden fields for JS -->
    <input type="hidden" id="racc-edit-booking-id"       value="<?php echo esc_attr( $booking_id ); ?>" />
    <input type="hidden" id="racc-agentcis-nonce"         value="<?php echo esc_attr( $agentcis_nonce ); ?>" />
    <input type="hidden" id="racc-agentcis-configured"    value="<?php echo esc_attr( $agentcis_configured ? '1' : '0' ); ?>" />

</div><!-- /.wrap -->

<!-- Flatpickr -->
<link rel="stylesheet" href="<?php echo esc_url( RACC_BOOKING_URL . 'assets/flatpickr/flatpickr.min.css' ); ?>?v=<?php echo RACC_BOOKING_VERSION; ?>">
<script src="<?php echo esc_url( RACC_BOOKING_URL . 'assets/flatpickr/flatpickr.min.js' ); ?>?v=<?php echo RACC_BOOKING_VERSION; ?>"></script>

<!-- Original booking data for JS (prefill + diff) -->
<script>
window.raccEditBookingData = <?php echo wp_json_encode( $booking_js_data ); ?>;
window.raccFlatpickrReady  = (typeof flatpickr !== 'undefined');
</script>
