<?php
/**
 * Plugin Name: RACC Booking - Consultant Booking System
 * Plugin URI:  https://racc.net.au
 * Description: A consultant booking system integrated with Google Calendar. Supports multi-agent scheduling, real-time availability checks, and email notifications.
 * Version:     1.2.5
 * Author:      RACC
 * Author URI:  https://racc.net.au
 * License:     GPL-2.0+
 * Text Domain: racc-booking
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'RACC_BOOKING_VERSION', '1.2.4' );
define( 'RACC_BOOKING_FILE', __FILE__ );
define( 'RACC_BOOKING_PATH', plugin_dir_path( __FILE__ ) );
define( 'RACC_BOOKING_URL', plugin_dir_url( __FILE__ ) );
define( 'RACC_BOOKING_BASENAME', plugin_basename( __FILE__ ) );

// Autoload classes
spl_autoload_register( function ( $class ) {
    $prefix    = 'RACC_Booking\\';
    $base_dir  = RACC_BOOKING_PATH . 'includes/';

    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, $len );
    $file = $base_dir . 'class-' . strtolower( str_replace( [ '\\', '_' ], [ '/', '-' ], $relative_class ) ) . '.php';

    if ( file_exists( $file ) ) {
        require $file;
    }
});

/**
 * Activation hook — create database tables and set defaults.
 */
function racc_booking_activate() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    // Agents table
    $table_agents = $wpdb->prefix . 'racc_agents';
    $sql_agents = "CREATE TABLE {$table_agents} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        email varchar(255) NOT NULL,
        phone varchar(50) DEFAULT '',
        nationality varchar(100) DEFAULT '',
        domicile varchar(100) DEFAULT '',
        services text DEFAULT '',
        calendar_id varchar(255) DEFAULT '',
        google_access_token text DEFAULT '',
        google_refresh_token text DEFAULT '',
        google_token_expires bigint(20) DEFAULT 0,
        slot_duration int(11) DEFAULT 60,
        working_hours_start varchar(10) DEFAULT '09:00',
        working_hours_end varchar(10) DEFAULT '17:00',
        working_days varchar(50) DEFAULT '1,2,3,4,5',
        timezone varchar(100) DEFAULT 'Australia/Sydney',
        status varchar(20) DEFAULT 'active',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    // Bookings table
    $table_bookings = $wpdb->prefix . 'racc_bookings';
    $sql_bookings = "CREATE TABLE {$table_bookings} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        agent_id bigint(20) unsigned NOT NULL,
        client_name varchar(255) NOT NULL,
        client_email varchar(255) NOT NULL,
        client_phone varchar(50) DEFAULT '',
        client_phone_iso varchar(5) DEFAULT '',
        client_phone_national varchar(50) DEFAULT '',
        client_nationality varchar(100) DEFAULT '',
        client_dob date DEFAULT NULL,
        client_university varchar(255) DEFAULT '',
        client_course_level varchar(100) DEFAULT '',
        client_course_major varchar(255) DEFAULT '',
        client_course_completion date DEFAULT NULL,
        client_visa_type varchar(100) DEFAULT '',
        client_visa_expiry date DEFAULT NULL,
        client_country varchar(100) DEFAULT '',
        client_state varchar(100) DEFAULT '',
        client_occupation varchar(255) DEFAULT '',
        client_contact_link varchar(255) DEFAULT '',
        client_referral_source varchar(255) DEFAULT '',
        service_type varchar(255) NOT NULL,
        booking_date date NOT NULL,
        booking_time_start time NOT NULL,
        booking_time_end time NOT NULL,
        google_event_id varchar(255) DEFAULT '',
        notes text DEFAULT '',
        status varchar(20) DEFAULT 'confirmed',
        agentcis_contact_id varchar(255) DEFAULT '' COMMENT 'AgentCIS Contact ID',
        agentcis_sync_status varchar(20) DEFAULT 'pending' COMMENT 'pending|synced|failed',
        agentcis_sync_at datetime DEFAULT NULL,
        agentcis_sync_error text DEFAULT '' COMMENT 'Last sync error message',
        location_mode varchar(30) DEFAULT 'client_place',
        location_id bigint(20) unsigned DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY agent_id (agent_id),
        KEY booking_date (booking_date),
        KEY status (status),
        KEY location_id (location_id)
    ) $charset_collate;";

    // Locations table
    $table_locations = $wpdb->prefix . 'racc_locations';
    $sql_locations = "CREATE TABLE {$table_locations} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        country_region varchar(120) DEFAULT '',
        city varchar(120) DEFAULT '',
        postal_code varchar(30) DEFAULT '',
        street_name varchar(255) DEFAULT '',
        house_number varchar(50) DEFAULT '',
        apartment_suite varchar(255) DEFAULT '',
        address_description text DEFAULT '',
        use_default_contact tinyint(1) DEFAULT 0,
        location_contact_name varchar(255) DEFAULT '',
        location_contact_phone varchar(50) DEFAULT '',
        location_contact_email varchar(255) DEFAULT '',
        status varchar(20) DEFAULT 'active',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY status (status)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql_agents );
    dbDelta( $sql_bookings );
    dbDelta( $sql_locations );

    // Set default options
    if ( ! get_option( 'racc_booking_settings' ) ) {
        update_option( 'racc_booking_settings', [
            'google_client_id'     => '',
            'google_client_secret' => '',
            'google_redirect_uri'  => admin_url( 'admin.php?page=racc-booking-settings&racc_oauth_callback=1' ),
            'slot_duration'        => 60,
            'timezone'             => 'Australia/Sydney',
            'notification_email'   => get_option( 'admin_email' ),
            'default_contact_name'  => '',
            'default_contact_phone' => '',
            'default_contact_email' => get_option( 'admin_email' ),
            'visa_categories'       => \RACC_Booking\Visa_Categories::get_default_options(),
        ]);
    }

    // Store DB version
    update_option( 'racc_booking_db_version', '1.3.0' );

    // Flush rewrite rules
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'racc_booking_activate' );

/**
 * Upgrade database schema when plugin updates.
 */
function racc_booking_maybe_upgrade_database() {
    global $wpdb;

    $db_version = get_option( 'racc_booking_db_version', '1.0.0' );

    if ( version_compare( $db_version, '1.6.0', '>=' ) ) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $table_agents    = $wpdb->prefix . 'racc_agents';
    $table_bookings  = $wpdb->prefix . 'racc_bookings';
    $table_locations = $wpdb->prefix . 'racc_locations';

    $sql_agents = "CREATE TABLE {$table_agents} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        email varchar(255) NOT NULL,
        phone varchar(50) DEFAULT '',
        nationality varchar(100) DEFAULT '',
        domicile varchar(100) DEFAULT '',
        services text DEFAULT '',
        nation_coverage text DEFAULT NULL,
        calendar_id varchar(255) DEFAULT '',
        google_access_token text DEFAULT '',
        google_refresh_token text DEFAULT '',
        google_token_expires bigint(20) DEFAULT 0,
        slot_duration int(11) DEFAULT 60,
        working_hours_start varchar(10) DEFAULT '09:00',
        working_hours_end varchar(10) DEFAULT '17:00',
        working_days varchar(50) DEFAULT '1,2,3,4,5',
        timezone varchar(100) DEFAULT 'Australia/Sydney',
        status varchar(20) DEFAULT 'active',
        agentcis_assignee_id bigint(20) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    $sql_bookings = "CREATE TABLE {$table_bookings} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        agent_id bigint(20) unsigned NOT NULL,
        client_name varchar(255) NOT NULL,
        client_email varchar(255) NOT NULL,
        client_phone varchar(50) DEFAULT '',
        client_phone_iso varchar(5) DEFAULT '',
        client_phone_national varchar(50) DEFAULT '',
        client_nationality varchar(100) DEFAULT '',
        client_dob date DEFAULT NULL,
        client_university varchar(255) DEFAULT '',
        client_course_level varchar(100) DEFAULT '',
        client_course_major varchar(255) DEFAULT '',
        client_course_completion date DEFAULT NULL,
        client_visa_type varchar(100) DEFAULT '',
        client_visa_expiry date DEFAULT NULL,
        client_country varchar(100) DEFAULT '',
        client_state varchar(100) DEFAULT '',
        client_occupation varchar(255) DEFAULT '',
        client_contact_link varchar(255) DEFAULT '',
        client_referral_source varchar(255) DEFAULT '',
        service_type varchar(255) NOT NULL,
        booking_date date NOT NULL,
        booking_time_start time NOT NULL,
        booking_time_end time NOT NULL,
        google_event_id varchar(255) DEFAULT '',
        notes text DEFAULT '',
        status varchar(20) DEFAULT 'confirmed',
        agentcis_contact_id varchar(255) DEFAULT '' COMMENT 'AgentCIS Contact ID',
        agentcis_sync_status varchar(20) DEFAULT 'pending' COMMENT 'pending|synced|failed',
        agentcis_sync_at datetime DEFAULT NULL,
        agentcis_sync_error text DEFAULT '' COMMENT 'Last sync error message',
        location_mode varchar(30) DEFAULT 'client_place',
        location_id bigint(20) unsigned DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY agent_id (agent_id),
        KEY booking_date (booking_date),
        KEY status (status),
        KEY location_id (location_id)
    ) $charset_collate;";

    $sql_locations = "CREATE TABLE {$table_locations} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        country_region varchar(120) DEFAULT '',
        city varchar(120) DEFAULT '',
        postal_code varchar(30) DEFAULT '',
        street_name varchar(255) DEFAULT '',
        house_number varchar(50) DEFAULT '',
        apartment_suite varchar(255) DEFAULT '',
        address_description text DEFAULT '',
        use_default_contact tinyint(1) DEFAULT 0,
        location_contact_name varchar(255) DEFAULT '',
        location_contact_phone varchar(50) DEFAULT '',
        location_contact_email varchar(255) DEFAULT '',
        status varchar(20) DEFAULT 'active',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY status (status)
    ) $charset_collate;";

    dbDelta( $sql_agents );
    dbDelta( $sql_bookings );
    dbDelta( $sql_locations );

    $settings = get_option( 'racc_booking_settings', [] );
    if ( ! is_array( $settings ) ) {
        $settings = [];
    }

    $settings = wp_parse_args( $settings, [
        'default_contact_name'  => '',
        'default_contact_phone' => '',
        'default_contact_email' => get_option( 'admin_email' ),
        'visa_categories'       => \RACC_Booking\Visa_Categories::get_default_options(),
    ] );
    update_option( 'racc_booking_settings', $settings );

    update_option( 'racc_booking_db_version', '1.6.0' );
}
add_action( 'plugins_loaded', 'racc_booking_maybe_upgrade_database', 5 );

/**
 * Ensure audit columns (changed_by_user_id) exist in racc_bookings.
 * Safe to run on every page load — only ALTERs if column is missing.
 */
function racc_booking_ensure_audit_columns() {
    global $wpdb;
    $table = $wpdb->prefix . 'racc_bookings';

    $col = $wpdb->get_row( $wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'changed_by_user_id'",
        DB_NAME,
        $table
    ) );

    if ( ! $col ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN changed_by_user_id bigint(20) unsigned DEFAULT NULL AFTER updated_at" );
    }
}
add_action( 'plugins_loaded', 'racc_booking_ensure_audit_columns', 6 );

/**
 * Deactivation hook — cleanup scheduled events.
 */
function racc_booking_deactivate() {
    wp_clear_scheduled_hook( 'racc_booking_token_refresh' );
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'racc_booking_deactivate' );

/**
 * Initialize the plugin.
 */
function racc_booking_init() {
    // Load classes
    $admin      = new RACC_Booking\Admin();
    $rest_api   = new RACC_Booking\Rest_API();
    $booking    = new RACC_Booking\Booking();
    $email      = new RACC_Booking\Email_Notifier();
    $agentcis   = new RACC_Booking\Agentcis();

    // Register AgentCIS settings
    add_action( 'admin_init', function() {
        register_setting( 'racc_agentcis_settings', 'racc_agentcis_api_key', [
            'sanitize_callback' => function( $value ) {
                return sanitize_text_field( trim( $value ) );
            },
        ]);
        register_setting( 'racc_agentcis_settings', 'racc_agentcis_api_base', [
            'sanitize_callback' => function( $value ) {
                return untrailingslashit( esc_url_raw( trim( $value ) ) );
            },
        ]);
        register_setting( 'racc_agentcis_settings', 'racc_agentcis_default_assignee_id', [
            'sanitize_callback' => 'absint',
        ] );

        // Removed racc_agentcis_referral_mapping (migrated to new UI)
        register_setting( 'racc_referral_settings', 'racc_referral_mapping_advanced' );

        // Custom field UUID mappings (Academic History + Contact)
        $cf_options = [
            'racc_agentcis_cf_course',
            'racc_agentcis_cf_release_letter',
            'racc_agentcis_cf_university',
            'racc_agentcis_cf_interested_in',
            'racc_agentcis_cf_cold_caller_id',
            'racc_agentcis_cf_cold_caller_date',
            'racc_agentcis_cf_consultant_date',
            'racc_agentcis_cf_state',
        ];
        foreach ( $cf_options as $option_name ) {
            register_setting( 'racc_agentcis_settings', $option_name, [
                'sanitize_callback' => function( $value ) {
                    return sanitize_text_field( trim( $value ) );
                },
            ]);
        }
    });

    // Submenus
    add_action( 'admin_menu', function() {
        // AgentCIS Settings
        add_submenu_page(
            'racc-booking',
            __( 'AgentCIS Integration', 'racc-booking' ),
            __( 'AgentCIS', 'racc-booking' ),
            'manage_options',
            'racc-booking-agentcis',
            function() {
                include RACC_BOOKING_PATH . 'admin/views/settings-agentcis.php';
            }
        );

        // Referral Mapping
        add_submenu_page(
            'racc-booking',
            __( 'Referral Mapping', 'racc-booking' ),
            __( 'Referral Mapping', 'racc-booking' ),
            'manage_options',
            'racc-booking-referral',
            function() {
                include RACC_BOOKING_PATH . 'admin/views/settings-referral.php';
            }
        );
    });

    // AJAX: clear logs
    add_action( 'wp_ajax_racc_clear_agentcis_logs', function() {
        check_ajax_referer( 'racc_agentcis_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        $log_file = WP_CONTENT_DIR . '/logs/racc-agentcis.log';
        if ( file_exists( $log_file ) ) {
            file_put_contents( $log_file, '' );
        }
        wp_send_json_success();
    });

    // Register shortcode
    add_shortcode( 'racc_booking_form', [ $booking, 'render_booking_form' ] );

    // Schedule cron events
    if ( ! wp_next_scheduled( 'racc_booking_token_refresh' ) ) {
        wp_schedule_event( time(), 'hourly', 'racc_booking_token_refresh' );
    }

    // Token refresh cron handler
    add_action( 'racc_booking_token_refresh', function () {
        $gcal = new RACC_Booking\Google_Calendar();
        $gcal->refresh_all_tokens();
    });
}
add_action( 'plugins_loaded', 'racc_booking_init' );

/**
 * Enqueue frontend assets.
 */
function racc_booking_enqueue_frontend() {
    // Only load on pages with our shortcode
    global $post;
    if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'racc_booking_form' ) ) {
        // Flatpickr (local files)
        wp_enqueue_style( 'flatpickr', RACC_BOOKING_URL . 'assets/flatpickr/flatpickr.min.css', [], '4.6.13' );
        wp_enqueue_script( 'flatpickr', RACC_BOOKING_URL . 'assets/flatpickr/flatpickr.min.js', [], '4.6.13', true );

        // International phone input with country-code detection.
        wp_enqueue_style( 'intl-tel-input', 'https://cdn.jsdelivr.net/npm/intl-tel-input@18.5.3/build/css/intlTelInput.min.css', [], '18.5.3' );
        wp_enqueue_script( 'intl-tel-input', 'https://cdn.jsdelivr.net/npm/intl-tel-input@18.5.3/build/js/intlTelInput.min.js', [], '18.5.3', true );


        // Plugin styles and scripts
        wp_enqueue_style( 'racc-booking-form', RACC_BOOKING_URL . 'public/css/booking-form.css', [], RACC_BOOKING_VERSION );
        wp_enqueue_script( 'racc-booking-form', RACC_BOOKING_URL . 'public/js/booking-form.js', [ 'flatpickr', 'intl-tel-input' ], RACC_BOOKING_VERSION, true );

        $advanced_mapping = get_option( 'racc_referral_mapping_advanced', [] );
        if ( ! is_array( $advanced_mapping ) ) {
            $advanced_mapping = [];
        }
        
        $flattened_mapping = [];
        foreach ( $advanced_mapping as $tag_name => $csv_values ) {
            $values = array_map( 'trim', explode( ',', $csv_values ) );
            foreach ( $values as $val ) {
                if ( '' !== $val ) {
                    $flattened_mapping[ strtolower( $val ) ] = $tag_name;
                }
            }
        }

        wp_localize_script( 'racc-booking-form', 'raccBooking', [
            'referralMapping' => $flattened_mapping,
            'restUrl'  => esc_url_raw( rest_url( 'racc/v1/' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'timezone' => wp_timezone_string(),
            'locale'   => get_locale(),
            'phone'    => [
                'defaultCountry' => 'AU',
                'utilsScript'    => esc_url_raw( 'https://cdn.jsdelivr.net/npm/intl-tel-input@18.5.3/build/js/utils.js' ),
            ],
            'i18n'     => [
                // Navigation / generic
                'loading'             => __( 'Loading...', 'racc-booking' ),
                'checkAvail'          => __( 'Check Availability', 'racc-booking' ),
                'bookNow'             => __( 'Book Appointment', 'racc-booking' ),
                'fillAllFields'       => __( 'Please fill in all required fields.', 'racc-booking' ),
                // Step 1 — Services
                'loadServicesError'   => __( 'Failed to load services. Please refresh the page.', 'racc-booking' ),
                'noServices'          => __( 'No services available at the moment.', 'racc-booking' ),
                // Step 2 — Consultants
                'loadAgentsError'     => __( 'Failed to load consultants. Please refresh the page.', 'racc-booking' ),
                'noAgents'            => __( 'No consultants available for this service.', 'racc-booking' ),
                'selectAll'           => __( 'Select All Consultants', 'racc-booking' ),
                // Step 4 — Timezone
                'yourTimezone'        => __( '(Your timezone)', 'racc-booking' ),
                // Step 5 — Availability
                'noSlots'             => __( 'No available slots for this date.', 'racc-booking' ),
                'searchingNearest'    => __( 'Searching for the nearest available date…', 'racc-booking' ),
                'nearestAvailable'    => __( 'Next available:', 'racc-booking' ),
                'jumpThere'           => __( 'Jump there →', 'racc-booking' ),
                'noAvailableDates'    => __( 'No available dates found in the next 60 days.', 'racc-booking' ),
                'availabilityError'   => __( 'Failed to check availability. Please try again.', 'racc-booking' ),
                // Submit
                'bookingSuccess'      => __( 'Your appointment has been booked successfully!', 'racc-booking' ),
                'addToGoogleCalendar' => __( 'Add to Google Calendar', 'racc-booking' ),
                'bookingError'        => __( 'There was an error booking your appointment. Please try again.', 'racc-booking' ),
                'invalidPhone'        => __( 'Please enter a valid phone number with country code.', 'racc-booking' ),
                // API error codes
                'googleNotConnected'   => __( 'Consultant calendar is not connected yet. Please contact admin.', 'racc-booking' ),
                'googleReconnectRequired' => __( 'Consultant calendar connection expired. Please contact support to reconnect.', 'racc-booking' ),
                'googleApiError'       => __( 'Google Calendar is temporarily unavailable. Please try again in a moment.', 'racc-booking' ),
                'calendarSyncFailed'   => __( 'Could not sync with Google Calendar. Please try again.', 'racc-booking' ),
                'slotUnavailable'      => __( 'This time slot is no longer available. Please pick another slot.', 'racc-booking' ),
            ],
        ]);
    }
}
add_action( 'wp_enqueue_scripts', 'racc_booking_enqueue_frontend' );

/**
 * Enqueue admin assets.
 */
function racc_booking_enqueue_admin( $hook ) {
    $screens = [
        'toplevel_page_racc-booking',
        'racc-booking_page_racc-booking-agents',
        'racc-booking_page_racc-booking-settings',
        'racc-booking_page_racc-booking-locations',
        'racc-booking_page_racc-booking-calendar',
        'racc-booking_page_racc-booking-reschedule', // legacy, kept for safety
        'admin_page_racc-booking-reschedule',        // correct hook for null-parent hidden page
        'admin_page_racc-booking-reassign',          // hidden page for change consultant
        'racc-booking_page_racc-booking-agentcis',
    ];

    if ( ! in_array( $hook, $screens, true ) ) {
        return;
    }

    // Flatpickr for reschedule page (local files)
    wp_enqueue_style( 'flatpickr', RACC_BOOKING_URL . 'assets/flatpickr/flatpickr.min.css', [], '4.6.13' );
    wp_enqueue_script( 'flatpickr', RACC_BOOKING_URL . 'assets/flatpickr/flatpickr.min.js', [], '4.6.13', true );


    wp_enqueue_style( 'racc-admin-style', RACC_BOOKING_URL . 'admin/css/admin-style.css', [], RACC_BOOKING_VERSION );
    wp_enqueue_script( 'racc-admin-script', RACC_BOOKING_URL . 'admin/js/admin-script.js', [ 'jquery', 'flatpickr' ], RACC_BOOKING_VERSION, true );

    wp_localize_script( 'racc-admin-script', 'raccAdmin', [
        'restUrl'        => esc_url_raw( rest_url( 'racc/v1/' ) ),
        'nonce'          => wp_create_nonce( 'wp_rest' ),
        'restNonce'      => wp_create_nonce( 'wp_rest' ),
        'adminUrl'       => admin_url(),
        'reassignNonce'  => wp_create_nonce( 'racc_reassign_booking_link' ),
        'agentcisNonce'  => wp_create_nonce( 'racc_agentcis_nonce' ),
        'agentcisActive' => ! empty( get_option( 'racc_agentcis_api_key' ) ) ? '1' : '0',
        'calendar' => [
            'accounts'      => \RACC_Booking\Admin::get_google_calendar_embed_accounts(),
            'consultants'   => \RACC_Booking\Admin::get_calendar_consultants(),
            'defaultView'   => 'WEEK',
            'defaultMode'   => 'GOOGLE',
            'defaultGoogleView' => 'IFRAME',
        ],
        'i18n'     => [
            'confirmDelete'    => __( 'Are you sure you want to delete this?', 'racc-booking' ),
            'confirmCancel'    => __( 'Are you sure you want to cancel this booking?', 'racc-booking' ),
            'rescheduleSuccess' => __( 'Booking rescheduled successfully.', 'racc-booking' ),
            'bookingCancelled' => __( 'Booking cancelled successfully.', 'racc-booking' ),
            'bookingDeleted'   => __( 'Booking deleted successfully.', 'racc-booking' ),
            'googleNotConnected' => __( 'Consultant calendar is not connected yet.', 'racc-booking' ),
            'googleApiError'  => __( 'Google Calendar is temporarily unavailable. Please try again.', 'racc-booking' ),
            'calendarSyncFailed' => __( 'Could not sync changes to Google Calendar. Please try again.', 'racc-booking' ),
            'slotUnavailable' => __( 'This time slot is no longer available. Please choose another slot.', 'racc-booking' ),
            'noCalendarAccounts' => __( 'No connected consultant calendar found. Connect Google Calendar on the Consultants page first.', 'racc-booking' ),
            'dbCalendarEmpty' => __( 'No bookings found in this period.', 'racc-booking' ),
            'dbCalendarError' => __( 'Failed to load bookings calendar data.', 'racc-booking' ),
            'allConsultants'  => __( 'All Consultants', 'racc-booking' ),
            'modeBookingDb'   => __( 'Booking DB', 'racc-booking' ),
            'modeGoogle'      => __( 'Google Calendar', 'racc-booking' ),
            'googleViewCustom' => __( 'Custom', 'racc-booking' ),
            'googleViewIframe' => __( 'Iframe', 'racc-booking' ),
            'iframeActionNote' => __( 'Iframe view is read-only. Use Custom view for View Details, Reschedule, Cancel, and Delete actions.', 'racc-booking' ),
            'viewDetails'     => __( 'View Details', 'racc-booking' ),
            'reschedule'      => __( 'Reschedule', 'racc-booking' ),
            'cancel'          => __( 'Cancel', 'racc-booking' ),
            'delete'          => __( 'Delete', 'racc-booking' ),
            'consultant'      => __( 'Consultant', 'racc-booking' ),
            'client'          => __( 'Client', 'racc-booking' ),
            'service'         => __( 'Service', 'racc-booking' ),
            'status'          => __( 'Status', 'racc-booking' ),
            'eventTitle'      => __( 'Event', 'racc-booking' ),
            'googleCalendarEmpty' => __( 'No Google Calendar events found in this period.', 'racc-booking' ),
            'googleCalendarError' => __( 'Failed to load Google Calendar events.', 'racc-booking' ),
            'googleWarningsTitle' => __( 'Some consultant calendars could not be loaded:', 'racc-booking' ),
            'allDay'          => __( 'All day', 'racc-booking' ),
        ],
    ]);
}
add_action( 'admin_enqueue_scripts', 'racc_booking_enqueue_admin' );

/**
 * Get Referral Tags from agentcis-tags.json
 *
 * @return array
 */
function racc_get_referral_tags() {
    $json_file = RACC_BOOKING_PATH . 'assets/agentcis-tags.json';
    if ( ! file_exists( $json_file ) ) {
        return [];
    }
    
    $json_content = file_get_contents( $json_file );
    $data = json_decode( $json_content, true );
    
    $tags = [];
    if ( ! empty( $data['data'] ) ) {
        foreach ( $data['data'] as $tag_item ) {
            $tags[] = trim( $tag_item['name'] );
        }
    }
    
    sort( $tags ); // alphabetical order
    return $tags;
}
