<?php
/**
 * Admin functionality — menus, settings, agents, bookings management.
 *
 * @package RACC_Booking
 */

namespace RACC_Booking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menus' ] );
        add_action( 'admin_init', [ $this, 'handle_actions' ] );
        add_action( 'admin_init', [ $this, 'handle_oauth_callback' ] );
    }

    /**
     * Register admin menu pages.
     */
    public function register_menus() {
        // Main menu - Bookings
        add_menu_page(
            __( 'RACC Booking', 'racc-booking' ),
            __( 'RACC Booking', 'racc-booking' ),
            'manage_options',
            'racc-booking',
            [ $this, 'render_bookings_page' ],
            'dashicons-calendar-alt',
            30
        );

        // Submenu: All Bookings
        add_submenu_page(
            'racc-booking',
            __( 'All Bookings', 'racc-booking' ),
            __( 'All Bookings', 'racc-booking' ),
            'manage_options',
            'racc-booking',
            [ $this, 'render_bookings_page' ]
        );

        // Submenu: Agents
        add_submenu_page(
            'racc-booking',
            __( 'Consultants', 'racc-booking' ),
            __( 'Consultants', 'racc-booking' ),
            'manage_options',
            'racc-booking-agents',
            [ $this, 'render_agents_page' ]
        );

        // Submenu: Settings
        add_submenu_page(
            'racc-booking',
            __( 'Settings', 'racc-booking' ),
            __( 'Settings', 'racc-booking' ),
            'manage_options',
            'racc-booking-settings',
            [ $this, 'render_settings_page' ]
        );

        // Submenu: Master Locations
        add_submenu_page(
            'racc-booking',
            __( 'Master Lokasi', 'racc-booking' ),
            __( 'Master Lokasi', 'racc-booking' ),
            'manage_options',
            'racc-booking-locations',
            [ $this, 'render_locations_page' ]
        );

        // Submenu: Calendar View (Google Calendar iframe)
        add_submenu_page(
            'racc-booking',
            __( 'Calendar View', 'racc-booking' ),
            __( 'Calendar View', 'racc-booking' ),
            'manage_options',
            'racc-booking-calendar',
            [ $this, 'render_calendar_page' ]
        );

        // Hidden: Reschedule page
        add_submenu_page(
            null,
            __( 'Reschedule Booking', 'racc-booking' ),
            __( 'Reschedule', 'racc-booking' ),
            'manage_options',
            'racc-booking-reschedule',
            [ $this, 'render_reschedule_page' ]
        );

        // Hidden: Change consultant / reassign page
        add_submenu_page(
            null,
            __( 'Change Consultant', 'racc-booking' ),
            __( 'Change Consultant', 'racc-booking' ),
            'manage_options',
            'racc-booking-reassign',
            [ $this, 'render_reassign_page' ]
        );

        add_submenu_page(
            'racc-booking',
            __( 'Help & Manual', 'racc-booking' ),
            __( 'Help / Manual', 'racc-booking' ),
            'manage_options',
            'racc-booking-help',
            [ $this, 'render_help_page' ]
        );
    }

    /**
     * Handle OAuth callback from Google.
     */
    public function handle_oauth_callback() {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'racc-booking-settings' ) {
            return;
        }
        if ( ! isset( $_GET['racc_oauth_callback'] ) || ! isset( $_GET['code'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $code  = sanitize_text_field( wp_unslash( $_GET['code'] ) );
        $state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );

        $gcal   = new Google_Calendar();
        $result = $gcal->handle_oauth_callback( $code, $state );

        if ( $result === true ) {
            $parts    = explode( '|', $state );
            $agent_id = intval( $parts[1] ?? 0 );
            wp_redirect( admin_url( 'admin.php?page=racc-booking-agents&message=google_connected&agent_id=' . $agent_id ) );
            exit;
        } else {
            wp_redirect( admin_url( 'admin.php?page=racc-booking-settings&error=' . urlencode( $result ) ) );
            exit;
        }
    }

    /**
     * Handle form submissions (add/edit/delete agents, update settings).
     */
    public function handle_actions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Export bookings CSV from list page, respecting active filters.
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'export_bookings_csv' ) {
            if ( wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'racc_export_bookings_csv' ) ) {
                $this->export_bookings_csv();
            }
        }

        // Save settings
        if ( isset( $_POST['racc_save_settings'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'racc_save_settings' ) ) {
            $this->save_settings();
        }

        // Add/Edit agent
        if ( isset( $_POST['racc_save_agent'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'racc_save_agent' ) ) {
            $this->save_agent();
        }

        // Add/Edit location
        if ( isset( $_POST['racc_save_location'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'racc_save_location' ) ) {
            $this->save_location();
        }

        // Delete agent
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete_agent' && isset( $_GET['agent_id'] ) ) {
            if ( wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'racc_delete_agent' ) ) {
                $this->delete_agent( absint( $_GET['agent_id'] ) );
            }
        }

        // Delete location
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete_location' && isset( $_GET['location_id'] ) ) {
            if ( wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'racc_delete_location' ) ) {
                $this->delete_location( absint( $_GET['location_id'] ) );
            }
        }

        // Disconnect Google Calendar
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'disconnect_google' && isset( $_GET['agent_id'] ) ) {
            if ( wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'racc_disconnect_google' ) ) {
                $gcal = new Google_Calendar();
                $gcal->disconnect( absint( $_GET['agent_id'] ) );
                wp_redirect( admin_url( 'admin.php?page=racc-booking-agents&message=google_disconnected' ) );
                exit;
            }
        }

        // Sync AgentCIS Users
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'sync_agentcis_users' ) {
            if ( wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'racc_sync_agentcis_users' ) ) {
                $agentcis = new Agentcis();
                $result   = $agentcis->sync_agentcis_users();
                $msg      = $result ? 'users_synced' : 'users_sync_failed';
                wp_redirect( admin_url( 'admin.php?page=racc-booking-settings&message=' . $msg ) );
                exit;
            }
        }

        // Reconnect Google Calendar (trigger OAuth flow)
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'reconnect_google' && isset( $_GET['agent_id'] ) ) {
            if ( wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'racc_reconnect_google' ) ) {
                $agent_id = absint( $_GET['agent_id'] );
                $gcal = new Google_Calendar();
                wp_redirect( $gcal->get_auth_url( $agent_id ) );
                exit;
            }
        }

        // Cancel booking
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'cancel_booking' && isset( $_GET['booking_id'] ) ) {
            if ( wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'racc_cancel_booking' ) ) {
                $this->cancel_booking( absint( $_GET['booking_id'] ) );
            }
        }

        // Delete single booking
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete_booking' && isset( $_GET['booking_id'] ) ) {
            if ( wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'racc_delete_booking' ) ) {
                $this->delete_booking( absint( $_GET['booking_id'] ) );
            }
        }

        // Save consultant reassignment for one or more bookings.
        if ( isset( $_POST['racc_reassign_bookings'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'racc_reassign_bookings' ) ) {
            $booking_ids = isset( $_POST['booking_ids'] ) && is_array( $_POST['booking_ids'] )
                ? array_map( 'absint', wp_unslash( $_POST['booking_ids'] ) )
                : [];
            $agent_id = absint( $_POST['agent_id'] ?? 0 );

            $this->reassign_bookings( $booking_ids, $agent_id );
        }

        // Bulk delete bookings from list page.
        $bulk_action = '';
        if ( isset( $_POST['racc_bulk_action'] ) ) {
            $bulk_action = sanitize_text_field( wp_unslash( $_POST['racc_bulk_action'] ) );
        }
        if ( '-1' === $bulk_action && isset( $_POST['racc_bulk_action_bottom'] ) ) {
            $bulk_action = sanitize_text_field( wp_unslash( $_POST['racc_bulk_action_bottom'] ) );
        }

        if ( $bulk_action === 'delete' ) {
            if ( wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'racc_bulk_booking_action' ) ) {
                $booking_ids = isset( $_POST['booking_ids'] ) && is_array( $_POST['booking_ids'] )
                    ? array_map( 'absint', $_POST['booking_ids'] )
                    : [];

                $this->bulk_delete_bookings( $booking_ids );
            }
        } elseif ( $bulk_action === 'reassign' ) {
            if ( wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'racc_bulk_booking_action' ) ) {
                $booking_ids = isset( $_POST['booking_ids'] ) && is_array( $_POST['booking_ids'] )
                    ? array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['booking_ids'] ) ) ) )
                    : [];

                if ( empty( $booking_ids ) ) {
                    wp_redirect( admin_url( 'admin.php?page=racc-booking&message=no_bookings_selected' ) );
                    exit;
                }

                $batch_key = wp_generate_password( 20, false, false );
                set_transient( 'racc_reassign_batch_' . get_current_user_id() . '_' . $batch_key, $booking_ids, 10 * MINUTE_IN_SECONDS );

                wp_redirect( admin_url( 'admin.php?page=racc-booking-reassign&batch=' . rawurlencode( $batch_key ) ) );
                exit;
            }
        }
    }

    /**
     * Save plugin settings.
     */
    private function save_settings() {
        $existing_settings = get_option( 'racc_booking_settings', [] );
        if ( ! is_array( $existing_settings ) ) {
            $existing_settings = [];
        }

        $settings = [
            'google_client_id'     => sanitize_text_field( $_POST['google_client_id'] ?? '' ),
            'google_client_secret' => sanitize_text_field( $_POST['google_client_secret'] ?? '' ),
            'google_redirect_uri'  => admin_url( 'admin.php?page=racc-booking-settings&racc_oauth_callback=1' ),
            'slot_duration'        => absint( $_POST['slot_duration'] ?? 60 ),
            'timezone'             => sanitize_text_field( $_POST['timezone'] ?? 'Australia/Sydney' ),
            'notification_email'   => sanitize_email( $_POST['notification_email'] ?? '' ),
            'default_contact_name'  => sanitize_text_field( $_POST['default_contact_name'] ?? '' ),
            'default_contact_phone' => sanitize_text_field( $_POST['default_contact_phone'] ?? '' ),
            'default_contact_email' => sanitize_email( $_POST['default_contact_email'] ?? '' ),
            'visa_categories'       => Visa_Categories::sanitize_options( $_POST['visa_categories'] ?? '' ),
        ];

        update_option( 'racc_booking_settings', array_merge( $existing_settings, $settings ) );
        wp_redirect( admin_url( 'admin.php?page=racc-booking-settings&message=settings_saved' ) );
        exit;
    }

    /**
     * Save an agent (create or update).
     */
    private function save_agent() {
        global $wpdb;
        $table = $wpdb->prefix . 'racc_agents';

        $agent_id = absint( $_POST['agent_id'] ?? 0 );

        $data = [
            'name'               => sanitize_text_field( $_POST['agent_name'] ?? '' ),
            'email'              => sanitize_email( $_POST['agent_email'] ?? '' ),
            'zoom_link'          => esc_url_raw( $_POST['agent_zoom_link'] ?? '' ),
            'phone'              => sanitize_text_field( $_POST['agent_phone'] ?? '' ),
            'nationality'        => sanitize_text_field( $_POST['agent_nationality'] ?? '' ),
            'domicile'           => sanitize_text_field( $_POST['agent_domicile'] ?? '' ),
            'nation_coverage'    => wp_json_encode( array_map( 'sanitize_text_field', (array) ( $_POST['agent_nation_coverage'] ?? [] ) ) ),
            // Service-to-consultant mapping is managed by Woo product assignment
            // in the racc-booking-woo bridge plugin.
            'services'           => wp_json_encode( [] ),
            'working_hours_start'=> sanitize_text_field( $_POST['working_hours_start'] ?? '09:00' ),
            'working_hours_end'  => sanitize_text_field( $_POST['working_hours_end'] ?? '17:00' ),
            'working_days'       => sanitize_text_field( implode( ',', array_map( 'absint', $_POST['working_days'] ?? [ 1, 2, 3, 4, 5 ] ) ) ),
            'timezone'           => sanitize_text_field( $_POST['agent_timezone'] ?? 'Australia/Sydney' ),
            'status'             => sanitize_text_field( $_POST['agent_status'] ?? 'active' ),
            'agentcis_assignee_id'=> absint( $_POST['agentcis_assignee_id'] ?? 0 ),
        ];

        if ( $agent_id > 0 ) {
            $wpdb->update( $table, $data, [ 'id' => $agent_id ] );
            $message = 'agent_updated';
        } else {
            $wpdb->insert( $table, $data );
            $agent_id = $wpdb->insert_id;
            $message  = 'agent_added';
        }

        wp_redirect( admin_url( 'admin.php?page=racc-booking-agents&message=' . $message . '&agent_id=' . $agent_id ) );
        exit;
    }

    /**
     * Delete an agent.
     */
    private function delete_agent( $agent_id ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'racc_agents', [ 'id' => $agent_id ], [ '%d' ] );
        wp_redirect( admin_url( 'admin.php?page=racc-booking-agents&message=agent_deleted' ) );
        exit;
    }

    /**
     * Save a location (create or update).
     */
    private function save_location() {
        global $wpdb;

        $table       = $wpdb->prefix . 'racc_locations';
        $location_id = absint( $_POST['location_id'] ?? 0 );

        $data = [
            'name'                    => sanitize_text_field( $_POST['location_name'] ?? '' ),
            'country_region'          => sanitize_text_field( $_POST['country_region'] ?? '' ),
            'city'                    => sanitize_text_field( $_POST['city'] ?? '' ),
            'postal_code'             => sanitize_text_field( $_POST['postal_code'] ?? '' ),
            'street_name'             => sanitize_text_field( $_POST['street_name'] ?? '' ),
            'house_number'            => sanitize_text_field( $_POST['house_number'] ?? '' ),
            'apartment_suite'         => sanitize_text_field( $_POST['apartment_suite'] ?? '' ),
            'address_description'     => sanitize_textarea_field( $_POST['address_description'] ?? '' ),
            'use_default_contact'     => ! empty( $_POST['use_default_contact'] ) ? 1 : 0,
            'location_contact_name'   => sanitize_text_field( $_POST['location_contact_name'] ?? '' ),
            'location_contact_phone'  => sanitize_text_field( $_POST['location_contact_phone'] ?? '' ),
            'location_contact_email'  => sanitize_email( $_POST['location_contact_email'] ?? '' ),
            'status'                  => sanitize_text_field( $_POST['location_status'] ?? 'active' ),
        ];

        if ( empty( $data['name'] ) || empty( $data['country_region'] ) || empty( $data['city'] ) ) {
            wp_redirect( admin_url( 'admin.php?page=racc-booking-locations&error=location_required_fields' ) );
            exit;
        }

        if ( ! in_array( $data['status'], [ 'active', 'inactive' ], true ) ) {
            $data['status'] = 'active';
        }

        if ( $location_id > 0 ) {
            $wpdb->update( $table, $data, [ 'id' => $location_id ] );
            $message = 'location_updated';
        } else {
            $wpdb->insert( $table, $data );
            $location_id = $wpdb->insert_id;
            $message     = 'location_added';
        }

        wp_redirect( admin_url( 'admin.php?page=racc-booking-locations&message=' . $message . '&location_id=' . $location_id ) );
        exit;
    }

    /**
     * Delete a location and unlink bookings using it.
     */
    private function delete_location( $location_id ) {
        global $wpdb;

        $table_locations = $wpdb->prefix . 'racc_locations';
        $table_bookings  = $wpdb->prefix . 'racc_bookings';

        $wpdb->delete( $table_locations, [ 'id' => $location_id ], [ '%d' ] );
        $wpdb->update(
            $table_bookings,
            [
                'location_mode' => 'client_place',
                'location_id'   => 0,
            ],
            [ 'location_id' => $location_id ],
            [ '%s', '%d' ],
            [ '%d' ]
        );

        wp_redirect( admin_url( 'admin.php?page=racc-booking-locations&message=location_deleted' ) );
        exit;
    }

    /**
     * Cancel a booking via admin action link.
     */
    private function cancel_booking( $booking_id ) {
        global $wpdb;
        $table   = $wpdb->prefix . 'racc_bookings';
        $booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $booking_id ) );

        if ( $booking ) {
            if ( $booking->google_event_id ) {
                $gcal = new Google_Calendar();
                $gcal->delete_event( $booking->agent_id, $booking->google_event_id );
            }

            $wpdb->update( $table, [ 'status' => 'cancelled' ], [ 'id' => $booking_id ] );

            $email = new Email_Notifier();
            $email->send_cancellation_notification( $booking_id );
        }

        wp_redirect( admin_url( 'admin.php?page=racc-booking&message=booking_cancelled' ) );
        exit;
    }

    /**
     * Permanently delete a single booking.
     */
    private function delete_booking( $booking_id ) {
        $this->delete_booking_record( $booking_id );

        wp_redirect( admin_url( 'admin.php?page=racc-booking&message=booking_deleted' ) );
        exit;
    }

    /**
     * Bulk-delete bookings by IDs.
     *
     * @param array<int> $booking_ids
     */
    private function bulk_delete_bookings( array $booking_ids ) {
        $deleted = 0;
        foreach ( $booking_ids as $booking_id ) {
            if ( $booking_id > 0 && $this->delete_booking_record( $booking_id ) ) {
                $deleted++;
            }
        }

        wp_redirect( admin_url( 'admin.php?page=racc-booking&message=bookings_deleted&count=' . $deleted ) );
        exit;
    }

    /**
     * Reassign selected bookings to another consultant without changing schedule.
     *
     * @param array<int> $booking_ids
     * @param int        $agent_id
     */
    private function reassign_bookings( array $booking_ids, $agent_id ) {
        global $wpdb;

        $agent = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}racc_agents WHERE id = %d AND status = 'active'",
            $agent_id
        ) );

        if ( ! $agent ) {
            wp_redirect( admin_url( 'admin.php?page=racc-booking&message=reassign_invalid_agent' ) );
            exit;
        }

        $updated = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ( array_unique( array_filter( $booking_ids ) ) as $booking_id ) {
            $result = $this->reassign_booking_record( (int) $booking_id, (int) $agent_id );

            if ( 'updated' === $result ) {
                $updated++;
            } elseif ( 'skipped' === $result ) {
                $skipped++;
            } else {
                $failed++;
            }
        }

        wp_redirect( admin_url(
            'admin.php?page=racc-booking&message=bookings_reassigned&count=' . $updated .
            '&skipped=' . $skipped .
            '&failed=' . $failed
        ) );
        exit;
    }

    /**
     * Reassign one booking to another consultant and move its Google Calendar event.
     *
     * @param int $booking_id
     * @param int $new_agent_id
     * @return string updated|skipped|failed
     */
    private function reassign_booking_record( $booking_id, $new_agent_id ) {
        global $wpdb;

        $table   = $wpdb->prefix . 'racc_bookings';
        $booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $booking_id ) );

        if ( ! $booking ) {
            return 'failed';
        }

        if ( (int) $booking->agent_id === (int) $new_agent_id ) {
            return 'skipped';
        }

        if ( in_array( (string) $booking->status, [ 'confirmed', 'rescheduled' ], true ) ) {
            $conflict = $wpdb->get_var( $wpdb->prepare(
                "SELECT id
                 FROM {$table}
                 WHERE agent_id = %d
                   AND booking_date = %s
                   AND status IN ('confirmed', 'rescheduled')
                   AND id != %d
                   AND booking_time_start < %s
                   AND booking_time_end > %s
                 LIMIT 1",
                $new_agent_id,
                $booking->booking_date,
                $booking_id,
                $booking->booking_time_end,
                $booking->booking_time_start
            ) );

            if ( $conflict ) {
                return 'failed';
            }
        }

        $gcal            = new Google_Calendar();
        $google_event_id = '';
        $created_new     = false;

        if ( ! empty( $booking->google_event_id ) ) {
            $gcal->delete_event( (int) $booking->agent_id, (string) $booking->google_event_id );
        }

        $new_agent = $wpdb->get_row( $wpdb->prepare(
            "SELECT google_refresh_token FROM {$wpdb->prefix}racc_agents WHERE id = %d",
            $new_agent_id
        ) );

        if ( ! empty( $new_agent->google_refresh_token ) ) {
            $google_event_id = $gcal->create_event( $new_agent_id, $this->build_calendar_payload_from_booking( $booking ) );
            if ( ! $google_event_id ) {
                return 'failed';
            }
            $created_new = true;
        }

        $result = $wpdb->update(
            $table,
            [
                'agent_id'           => $new_agent_id,
                'google_event_id'    => $google_event_id,
                'changed_by_user_id' => get_current_user_id() ?: null,
            ],
            [ 'id' => $booking_id ],
            [ '%d', '%s', '%d' ],
            [ '%d' ]
        );

        if ( false === $result ) {
            if ( $created_new && $google_event_id ) {
                $gcal->delete_event( $new_agent_id, $google_event_id );
            }

            return 'failed';
        }

        do_action( 'racc_booking_reassigned', $booking_id, $new_agent_id, (int) $booking->agent_id );

        $email = new Email_Notifier();
        $email->send_reassign_notification( $booking_id );

        return 'updated';
    }

    /**
     * Build a Google Calendar payload from a booking row.
     *
     * @param object $booking
     * @return array<string,string>
     */
    private function build_calendar_payload_from_booking( $booking ) {
        return [
            'client_name'              => (string) ( $booking->client_name ?? '' ),
            'client_email'             => (string) ( $booking->client_email ?? '' ),
            'client_phone'             => (string) ( $booking->client_phone ?? '' ),
            'client_nationality'       => (string) ( $booking->client_nationality ?? '' ),
            'client_dob'               => (string) ( $booking->client_dob ?? '' ),
            'client_country'           => (string) ( $booking->client_country ?? '' ),
            'client_state'             => (string) ( $booking->client_state ?? '' ),
            'client_university'        => (string) ( $booking->client_university ?? '' ),
            'client_course_level'      => (string) ( $booking->client_course_level ?? '' ),
            'client_course_major'      => (string) ( $booking->client_course_major ?? '' ),
            'client_course_completion' => (string) ( $booking->client_course_completion ?? '' ),
            'client_visa_type'         => (string) ( $booking->client_visa_type ?? '' ),
            'client_visa_expiry'       => (string) ( $booking->client_visa_expiry ?? '' ),
            'client_occupation'        => (string) ( $booking->client_occupation ?? '' ),
            'client_contact_link'      => (string) ( $booking->client_contact_link ?? '' ),
            'client_referral_source'   => (string) ( $booking->client_referral_source ?? '' ),
            'service_type'             => (string) ( $booking->service_type ?? '' ),
            'booking_date'             => (string) ( $booking->booking_date ?? '' ),
            'booking_time_start'       => substr( (string) ( $booking->booking_time_start ?? '' ), 0, 5 ),
            'booking_time_end'         => substr( (string) ( $booking->booking_time_end ?? '' ), 0, 5 ),
            'notes'                    => (string) ( $booking->notes ?? '' ),
        ];
    }

    /**
     * Delete booking row and clean linked Google Calendar event if present.
     *
     * @param int $booking_id
     * @return bool
     */
    private function delete_booking_record( $booking_id ) {
        global $wpdb;

        $table   = $wpdb->prefix . 'racc_bookings';
        $booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $booking_id ) );

        if ( ! $booking ) {
            return false;
        }

        if ( ! empty( $booking->google_event_id ) ) {
            $gcal = new Google_Calendar();
            $gcal->delete_event( (int) $booking->agent_id, (string) $booking->google_event_id );
        }

        $result = $wpdb->delete( $table, [ 'id' => (int) $booking_id ], [ '%d' ] );

        return (bool) $result;
    }

    /**
     * Export bookings as CSV using the same filters as the bookings list.
     */
    private function export_bookings_csv() {
        global $wpdb;

        $filter_status = sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) );
        $filter_agent  = absint( $_GET['filter_agent'] ?? 0 );
        $filter_date_start = sanitize_text_field( wp_unslash( $_GET['filter_date_start'] ?? '' ) );
        $filter_date_end   = sanitize_text_field( wp_unslash( $_GET['filter_date_end'] ?? '' ) );
        $filter_nationality = sanitize_text_field( wp_unslash( $_GET['filter_nationality'] ?? '' ) );
        $filter_domicile = sanitize_text_field( wp_unslash( $_GET['filter_domicile'] ?? '' ) );
        $filter_agentcis_status = sanitize_text_field( wp_unslash( $_GET['filter_agentcis_status'] ?? '' ) );
        $search_query  = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );

        $where  = [ '1=1' ];
        $params = [];

        if ( $filter_status ) {
            $where[]  = 'b.status = %s';
            $params[] = $filter_status;
        }

        if ( $filter_agent ) {
            $where[]  = 'b.agent_id = %d';
            $params[] = $filter_agent;
        }

        if ( $filter_date_start ) {
            $where[]  = 'b.booking_date >= %s';
            $params[] = $filter_date_start;
        }

        if ( $filter_date_end ) {
            $where[]  = 'b.booking_date <= %s';
            $params[] = $filter_date_end;
        }

        if ( $filter_nationality ) {
            $where[]  = 'b.client_nationality = %s';
            $params[] = $filter_nationality;
        }

        if ( $filter_domicile ) {
            $where[]  = 'b.client_country = %s';
            $params[] = $filter_domicile;
        }

        if ( $filter_agentcis_status ) {
            $where[]  = 'b.agentcis_sync_status = %s';
            $params[] = $filter_agentcis_status;
        }

        if ( $search_query ) {
            if ( preg_match( '/^#?\d+$/', $search_query ) ) {
                $where[]  = 'b.id = %d';
                $params[] = absint( ltrim( $search_query, '#' ) );
            } else {
                $like = '%' . $wpdb->esc_like( $search_query ) . '%';
                $where[]  = '( b.client_name LIKE %s OR b.client_email LIKE %s OR b.client_phone LIKE %s OR b.service_type LIKE %s OR a.name LIKE %s OR p.post_title LIKE %s )';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
        }

        $where_clause = implode( ' AND ', $where );
        $sql          = "SELECT b.*, a.name AS agent_name, a.email AS agent_email, p.post_title AS woo_product_name, u.display_name AS changed_by_display_name
            FROM {$wpdb->prefix}racc_bookings b
            LEFT JOIN {$wpdb->prefix}racc_agents a ON b.agent_id = a.id
            LEFT JOIN {$wpdb->posts} p ON p.ID = b.woo_product_id AND p.post_type = 'product'
            LEFT JOIN {$wpdb->users} u ON u.ID = b.changed_by_user_id
            WHERE {$where_clause}
            ORDER BY b.booking_date DESC, b.booking_time_start DESC";

        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, ...$params );
        }

        $bookings = $wpdb->get_results( $sql );
        $filename = 'racc-bookings-' . gmdate( 'Y-m-d-His' ) . '.csv';

        while ( ob_get_level() ) {
            ob_end_clean();
        }

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        $this->write_csv_row( $output, [
            'Booking ID',
            'Status',
            'Client Name',
            'Client Email',
            'Client Phone',
            'Nationality',
            'Date of Birth',
            'Country',
            'State',
            'University',
            'Course Level',
            'Course Major',
            'Course Completion',
            'Visa Type',
            'Visa Expiry',
            'Occupation',
            'Contact Link',
            'Where did you hear about us?',
            'Consultant',
            'Consultant Email',
            'Service',
            'Woo Product ID',
            'Woo Product Name',
            'Woo Order ID',
            'Booking Date',
            'Start Time',
            'End Time',
            'Location Mode',
            'Location ID',
            'Notes',
            'Google Event ID',
            'AgentCIS Contact ID',
            'AgentCIS Sync Status',
            'AgentCIS Sync Error',
            'Created At',
            'Updated At',
            'Last Changed By',
        ] );

        foreach ( $bookings as $booking ) {
            $this->write_csv_row( $output, [
                $booking->id ?? '',
                ucwords( str_replace( '_', ' ', (string) ( $booking->status ?? '' ) ) ),
                $booking->client_name ?? '',
                $booking->client_email ?? '',
                $booking->client_phone ?? '',
                $booking->client_nationality ?? '',
                $booking->client_dob ?? '',
                $booking->client_country ?? '',
                $booking->client_state ?? '',
                $booking->client_university ?? '',
                $booking->client_course_level ?? '',
                $booking->client_course_major ?? '',
                $booking->client_course_completion ?? '',
                $booking->client_visa_type ?? '',
                $booking->client_visa_expiry ?? '',
                $booking->client_occupation ?? '',
                $booking->client_contact_link ?? '',
                $booking->client_referral_source ?? '',
                $booking->agent_name ?? '',
                $booking->agent_email ?? '',
                $booking->service_type ?? '',
                $booking->woo_product_id ?? '',
                $booking->woo_product_name ?? '',
                $booking->woo_order_id ?? '',
                $booking->booking_date ?? '',
                $booking->booking_time_start ?? '',
                $booking->booking_time_end ?? '',
                $booking->location_mode ?? '',
                $booking->location_id ?? '',
                $booking->notes ?? '',
                $booking->google_event_id ?? '',
                $booking->agentcis_contact_id ?? '',
                $booking->agentcis_sync_status ?? '',
                $booking->agentcis_sync_error ?? '',
                $booking->created_at ?? '',
                $booking->updated_at ?? '',
                $booking->changed_by_display_name ?? '',
            ] );
        }

        fclose( $output );
        exit;
    }

    /**
     * Write a CSV row with explicit formatting for PHP 8.4+ compatibility.
     *
     * @param resource $output
     * @param array<int,string|int|null> $row
     */
    private function write_csv_row( $output, array $row ) {
        fputcsv( $output, $row, ',', '"', '' );
    }

    /**
     * Render the Bookings list page.
     */
    public function render_bookings_page() {
        include RACC_BOOKING_PATH . 'admin/views/bookings-page.php';
    }

    /**
     * Render the Agents management page.
     */
    public function render_agents_page() {
        include RACC_BOOKING_PATH . 'admin/views/agents-page.php';
    }

    /**
     * Render the Settings page.
     */
    public function render_settings_page() {
        include RACC_BOOKING_PATH . 'admin/views/settings-page.php';
    }

    public function render_help_page() {
        include RACC_BOOKING_PATH . 'admin/views/help-page.php';
    }

    /**
     * Render the Locations management page.
     */
    public function render_locations_page() {
        include RACC_BOOKING_PATH . 'admin/views/locations-page.php';
    }

    /**
     * Render the Google Calendar iframe page.
     */
    public function render_calendar_page() {
        include RACC_BOOKING_PATH . 'admin/views/calendar-page.php';
    }

    /**
     * Render the Reschedule page.
     */
    public function render_reschedule_page() {
        include RACC_BOOKING_PATH . 'admin/views/reschedule-page.php';
    }

    /**
     * Render the consultant reassignment page.
     */
    public function render_reassign_page() {
        include RACC_BOOKING_PATH . 'admin/views/reassign-page.php';
    }

    /**
     * Get available services list.
     *
     * @return array
     */
    public static function get_services() {
        return [
            'migration_consultation' => __( 'Migration Consultation', 'racc-booking' ),
            'pr_pathway'             => __( 'PR Pathway Consultation', 'racc-booking' ),
            'course_student_visa'    => __( 'Apply Course & Student Visa', 'racc-booking' ),
            'tourist_visa'           => __( 'Tourist Visa', 'racc-booking' ),
            'student_visa_extension' => __( 'Student Visa Extension', 'racc-booking' ),
        ];
    }

    /**
     * Get Google Calendar iframe accounts list from connected consultants.
     *
     * @return array<int,array<string,string>>
     */
    public static function get_google_calendar_embed_accounts() {
        global $wpdb;

        $table = $wpdb->prefix . 'racc_agents';
        $rows  = $wpdb->get_results(
            "SELECT id, name, calendar_id, timezone FROM {$table}
             WHERE status = 'active' AND calendar_id != ''
             ORDER BY name ASC"
        );

        $accounts = [];

        if ( ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                $timezone = ! empty( $row->timezone ) ? $row->timezone : wp_timezone_string();
                $query    = [
                    'src'           => $row->calendar_id,
                    'ctz'           => $timezone,
                    'showTitle'     => '0',
                    'showPrint'     => '0',
                    'showTabs'      => '0',
                    'showCalendars' => '0',
                    'showTz'        => '0',
                ];

                $accounts[] = [
                    'id'        => 'agent_' . absint( $row->id ),
                    'label'     => sanitize_text_field( $row->name ),
                    'embed_url' => add_query_arg( $query, 'https://calendar.google.com/calendar/embed' ),
                ];
            }
        }

        return apply_filters( 'racc_booking_google_calendar_accounts', $accounts );
    }

    /**
     * Get active consultants list for admin calendar DB mode.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_calendar_consultants() {
        global $wpdb;

        $table = $wpdb->prefix . 'racc_agents';
        $rows  = $wpdb->get_results(
            "SELECT id, name
             FROM {$table}
             WHERE status = 'active'
             ORDER BY name ASC"
        );

        $consultants = [];

        if ( ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                $consultants[] = [
                    'id'    => absint( $row->id ),
                    'label' => sanitize_text_field( $row->name ),
                ];
            }
        }

        return apply_filters( 'racc_booking_calendar_consultants', $consultants );
    }

    /**
     * Get active locations for booking location selector.
     *
     * @return array<int,object>
     */
    public static function get_active_locations() {
        global $wpdb;

        $table = $wpdb->prefix . 'racc_locations';

        return $wpdb->get_results(
            "SELECT *
             FROM {$table}
             WHERE status = 'active'
             ORDER BY name ASC"
        );
    }
}
