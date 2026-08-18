<?php
/**
 * Google Calendar API integration.
 *
 * Handles OAuth 2.0 flow, token management, free/busy queries,
 * and event CRUD via Google Calendar API v3 using wp_remote_*.
 *
 * @package RACC_Booking
 */

namespace RACC_Booking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Google_Calendar {

    const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
    const AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
    const CALENDAR_URL = 'https://www.googleapis.com/calendar/v3';
    const SCOPES       = 'https://www.googleapis.com/auth/calendar';
    const CONNECTION_STATE_OPTION = 'racc_booking_google_connection_state';

    /**
     * Resolve Google Calendar sendUpdates mode.
     *
     * Allowed values: all, externalOnly, none.
     *
     * @param string $context create|update|delete
     * @return string
     */
    private function get_send_updates_mode( $context = 'create' ) {
        $mode = apply_filters( 'racc_booking_google_send_updates', 'none', (string) $context );
        $mode = is_string( $mode ) ? trim( $mode ) : 'none';

        if ( ! in_array( $mode, [ 'all', 'externalOnly', 'none' ], true ) ) {
            $mode = 'none';
        }

        return $mode;
    }

    /**
     * Resolve Google event reminders payload.
     *
     * @param array $booking_data Booking payload.
     * @return array
     */
    private function get_event_reminders_payload( $booking_data = [] ) {
        $default = [
            'useDefault' => false,
            'overrides'  => [
                [ 'method' => 'popup', 'minutes' => 30 ],
            ],
        ];

        $reminders = apply_filters( 'racc_booking_google_event_reminders', $default, $booking_data );

        return is_array( $reminders ) ? $reminders : $default;
    }

    /**
     * Get plugin settings.
     */
    private function get_settings() {
        return get_option( 'racc_booking_settings', [] );
    }

    /**
     * Get saved connection state map.
     *
     * @return array
     */
    private function get_connection_state_map() {
        $map = get_option( self::CONNECTION_STATE_OPTION, [] );
        return is_array( $map ) ? $map : [];
    }

    /**
     * Mark an agent as requiring reconnect.
     *
     * @param int    $agent_id Agent ID.
     * @param string $reason   Error reason.
     * @return void
     */
    private function mark_reconnect_required( $agent_id, $reason = 'invalid_grant' ) {
        $agent_id = absint( $agent_id );
        if ( $agent_id <= 0 ) {
            return;
        }

        $map = $this->get_connection_state_map();
        $map[ $agent_id ] = [
            'required'   => true,
            'reason'     => sanitize_text_field( (string) $reason ),
            'updated_at' => time(),
        ];

        update_option( self::CONNECTION_STATE_OPTION, $map, false );
    }

    /**
     * Clear reconnect-required flag for an agent.
     *
     * @param int $agent_id Agent ID.
     * @return void
     */
    private function clear_reconnect_required( $agent_id ) {
        $agent_id = absint( $agent_id );
        if ( $agent_id <= 0 ) {
            return;
        }

        $map = $this->get_connection_state_map();
        if ( isset( $map[ $agent_id ] ) ) {
            unset( $map[ $agent_id ] );
            update_option( self::CONNECTION_STATE_OPTION, $map, false );
        }
    }

    /**
     * Whether an agent currently requires Google reconnect.
     *
     * @param int $agent_id Agent ID.
     * @return bool
     */
    public function needs_reconnect( $agent_id ) {
        $agent_id = absint( $agent_id );
        if ( $agent_id <= 0 ) {
            return false;
        }

        $map = $this->get_connection_state_map();
        return ! empty( $map[ $agent_id ]['required'] );
    }

    /**
     * Build the OAuth authorization URL for an agent.
     *
     * @param int $agent_id Agent ID to authorize.
     * @return string Authorization URL.
     */
    public function get_auth_url( $agent_id ) {
        $settings = $this->get_settings();
        $state    = wp_create_nonce( 'racc_oauth_' . $agent_id ) . '|' . $agent_id;

        $params = [
            'client_id'     => $settings['google_client_id'] ?? '',
            'redirect_uri'  => admin_url( 'admin.php?page=racc-booking-settings&racc_oauth_callback=1' ),
            'response_type' => 'code',
            'scope'         => self::SCOPES,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ];

        return self::AUTH_URL . '?' . http_build_query( $params );
    }

    /**
     * Handle the OAuth callback — exchange code for tokens.
     *
     * @param string $code  Authorization code from Google.
     * @param string $state State parameter with nonce and agent_id.
     * @return bool|string True on success, error message on failure.
     */
    public function handle_oauth_callback( $code, $state ) {
        $parts    = explode( '|', $state );
        $nonce    = $parts[0] ?? '';
        $agent_id = intval( $parts[1] ?? 0 );

        if ( ! wp_verify_nonce( $nonce, 'racc_oauth_' . $agent_id ) ) {
            return __( 'Invalid security token. Please try again.', 'racc-booking' );
        }

        $settings = $this->get_settings();

        $response = wp_remote_post( self::TOKEN_URL, [
            'body' => [
                'code'          => $code,
                'client_id'     => $settings['google_client_id'] ?? '',
                'client_secret' => $settings['google_client_secret'] ?? '',
                'redirect_uri'  => admin_url( 'admin.php?page=racc-booking-settings&racc_oauth_callback=1' ),
                'grant_type'    => 'authorization_code',
            ],
            'timeout' => 30,
        ]);

        if ( is_wp_error( $response ) ) {
            return $response->get_error_message();
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            return $body['error_description'] ?? $body['error'];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'racc_agents';

        // Preserve existing refresh token when Google does not return a new one.
        $existing_refresh_token = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT google_refresh_token FROM {$table} WHERE id = %d",
            $agent_id
        ) );

        $new_refresh_token = ! empty( $body['refresh_token'] )
            ? (string) $body['refresh_token']
            : $existing_refresh_token;

        $wpdb->update(
            $table,
            [
                'google_access_token'  => $body['access_token'] ?? '',
                'google_refresh_token' => $new_refresh_token,
                'google_token_expires' => time() + ( intval( $body['expires_in'] ?? 3600 ) ),
            ],
            [ 'id' => $agent_id ],
            [ '%s', '%s', '%d' ],
            [ '%d' ]
        );

        // OAuth success clears reconnect-required state.
        $this->clear_reconnect_required( $agent_id );

        // Also fetch and store the primary calendar ID
        $this->store_primary_calendar_id( $agent_id, $body['access_token'] );

        return true;
    }

    /**
     * Fetch and store the primary calendar ID for an agent.
     */
    private function store_primary_calendar_id( $agent_id, $access_token ) {
        $response = wp_remote_get( self::CALENDAR_URL . '/calendars/primary', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
            ],
            'timeout' => 15,
        ]);

        if ( ! is_wp_error( $response ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $body['id'] ) ) {
                global $wpdb;
                $wpdb->update(
                    $wpdb->prefix . 'racc_agents',
                    [ 'calendar_id' => $body['id'] ],
                    [ 'id' => $agent_id ],
                    [ '%s' ],
                    [ '%d' ]
                );
            }
        }
    }

    /**
     * Get a valid access token for an agent, refreshing if needed.
     *
     * @param int $agent_id Agent ID.
     * @return string|false Access token or false on failure.
     */
    public function get_access_token( $agent_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'racc_agents';
        $agent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $agent_id ) );

        if ( ! $agent || empty( $agent->google_refresh_token ) ) {
            return false;
        }

        // Token still valid (with 5 min buffer)
        if ( $agent->google_token_expires > ( time() + 300 ) ) {
            $this->clear_reconnect_required( $agent_id );
            return $agent->google_access_token;
        }

        // Refresh the token
        return $this->refresh_token( $agent_id, $agent->google_refresh_token );
    }

    /**
     * Refresh an agent's access token.
     *
     * @param int    $agent_id      Agent ID.
     * @param string $refresh_token Refresh token.
     * @return string|false New access token or false.
     */
    private function refresh_token( $agent_id, $refresh_token ) {
        $settings = $this->get_settings();

        $response = wp_remote_post( self::TOKEN_URL, [
            'body' => [
                'client_id'     => $settings['google_client_id'] ?? '',
                'client_secret' => $settings['google_client_secret'] ?? '',
                'refresh_token' => $refresh_token,
                'grant_type'    => 'refresh_token',
            ],
            'timeout' => 30,
        ]);

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            $oauth_error = (string) $body['error'];

            // Token is no longer refreshable: require reconnect.
            if ( in_array( $oauth_error, [ 'invalid_grant', 'invalid_client', 'unauthorized_client', 'invalid_request' ], true ) ) {
                global $wpdb;
                $wpdb->update(
                    $wpdb->prefix . 'racc_agents',
                    [
                        'google_access_token'  => '',
                        'google_refresh_token' => '',
                        'google_token_expires' => 0,
                    ],
                    [ 'id' => absint( $agent_id ) ],
                    [ '%s', '%s', '%d' ],
                    [ '%d' ]
                );
                $this->mark_reconnect_required( $agent_id, $oauth_error );
            }
            return false;
        }

        global $wpdb;
        $update_data = [
            'google_access_token'  => $body['access_token'],
            'google_token_expires' => time() + intval( $body['expires_in'] ?? 3600 ),
        ];

        // Google sometimes issues a new refresh token
        if ( ! empty( $body['refresh_token'] ) ) {
            $update_data['google_refresh_token'] = $body['refresh_token'];
        }

        $wpdb->update(
            $wpdb->prefix . 'racc_agents',
            $update_data,
            [ 'id' => $agent_id ]
        );

        $this->clear_reconnect_required( $agent_id );

        return $body['access_token'];
    }

    /**
     * Get connection status with token validity check and auto-refresh.
     *
     * @param int  $agent_id        Agent ID.
     * @param bool $attempt_refresh Attempt refresh when needed.
     * @return array<string,mixed>
     */
    public function get_connection_status( $agent_id, $attempt_refresh = true ) {
        global $wpdb;
        $agent_id = absint( $agent_id );
        $table    = $wpdb->prefix . 'racc_agents';
        $agent    = $wpdb->get_row( $wpdb->prepare( "SELECT google_access_token, google_refresh_token, google_token_expires FROM {$table} WHERE id = %d", $agent_id ) );

        if ( ! $agent ) {
            return [ 'status' => 'not_found' ];
        }

        if ( empty( $agent->google_refresh_token ) ) {
            if ( $this->needs_reconnect( $agent_id ) ) {
                return [ 'status' => 'reconnect_required' ];
            }
            return [ 'status' => 'not_connected' ];
        }

        // Access token still valid.
        if ( ! empty( $agent->google_access_token ) && intval( $agent->google_token_expires ) > ( time() + 300 ) ) {
            $this->clear_reconnect_required( $agent_id );
            return [ 'status' => 'connected' ];
        }

        if ( ! $attempt_refresh ) {
            return [ 'status' => 'token_expired' ];
        }

        $new_token = $this->refresh_token( $agent_id, $agent->google_refresh_token );
        if ( $new_token ) {
            return [ 'status' => 'connected', 'refreshed' => true ];
        }

        if ( $this->needs_reconnect( $agent_id ) ) {
            return [ 'status' => 'reconnect_required' ];
        }

        return [ 'status' => 'token_check_failed' ];
    }

    /**
     * Refresh tokens for all agents (cron job).
     */
    public function refresh_all_tokens() {
        global $wpdb;
        $table  = $wpdb->prefix . 'racc_agents';
        $agents = $wpdb->get_results( "SELECT id, google_refresh_token FROM {$table} WHERE google_refresh_token != '' AND status = 'active'" );

        foreach ( $agents as $agent ) {
            $this->refresh_token( $agent->id, $agent->google_refresh_token );
        }
    }

    /**
     * Get available time slots for an agent on a specific date.
     *
     * Queries Google Calendar's freebusy API and returns available slots.
     *
        * @param int    $agent_id       Agent ID.
        * @param string $date           Date in Y-m-d format.
          * @param bool   $require_google Whether Google Calendar must be available.
          * @param int    $slot_duration_override Optional slot duration override in minutes.
     * @return array|WP_Error Array of available slots or WP_Error.
     */
          public function get_available_slots( $agent_id, $date, $require_google = false, $slot_duration_override = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'racc_agents';
        $agent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $agent_id ) );

        if ( ! $agent ) {
            return new \WP_Error( 'agent_not_found', __( 'Agent not found.', 'racc-booking' ) );
        }

        $access_token = $this->get_access_token( $agent_id );
        $calendar_id  = $agent->calendar_id ?: 'primary';
        $timezone     = $agent->timezone ?: 'Australia/Sydney';
        $duration     = intval( $slot_duration_override > 0 ? $slot_duration_override : ( $agent->slot_duration ?: 60 ) );
        $working_days = array_map( 'intval', explode( ',', $agent->working_days ?: '1,2,3,4,5' ) );

        // Check if the date falls on a working day
        $date_obj = new \DateTime( $date, new \DateTimeZone( $timezone ) );
        $day_of_week = intval( $date_obj->format( 'N' ) ); // 1=Monday, 7=Sunday

        if ( ! in_array( $day_of_week, $working_days, true ) ) {
            return []; // Not a working day
        }

        $start_time = $agent->working_hours_start ?: '09:00';
        $end_time   = $agent->working_hours_end ?: '17:00';

        // Generate all possible slots
        $slots = [];
        $slot_start = new \DateTime( $date . ' ' . $start_time, new \DateTimeZone( $timezone ) );
        $work_end   = new \DateTime( $date . ' ' . $end_time, new \DateTimeZone( $timezone ) );

        while ( $slot_start < $work_end ) {
            $slot_end = clone $slot_start;
            $slot_end->modify( "+{$duration} minutes" );

            if ( $slot_end <= $work_end ) {
                $slots[] = [
                    'start' => $slot_start->format( 'H:i' ),
                    'end'   => $slot_end->format( 'H:i' ),
                ];
            }
            $slot_start = $slot_end;
        }

        // If no Google token, either fail (strict mode) or fallback to DB checks.
        if ( ! $access_token ) {
            if ( $require_google ) {
                return new \WP_Error( 'google_not_connected', __( 'Consultant Google Calendar is not connected.', 'racc-booking' ) );
            }
            return $this->filter_slots_by_db( $agent_id, $date, $slots );
        }

        // Query Google Calendar freebusy
        $time_min = new \DateTime( $date . ' ' . $start_time, new \DateTimeZone( $timezone ) );
        $time_max = new \DateTime( $date . ' ' . $end_time, new \DateTimeZone( $timezone ) );

        $body = [
            'timeMin'  => $time_min->format( 'c' ),
            'timeMax'  => $time_max->format( 'c' ),
            'timeZone' => $timezone,
            'items'    => [
                [ 'id' => $calendar_id ],
            ],
        ];

        $response = wp_remote_post( self::CALENDAR_URL . '/freeBusy', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 15,
        ]);

        if ( is_wp_error( $response ) ) {
            if ( $require_google ) {
                return new \WP_Error( 'google_api_error', __( 'Could not reach Google Calendar. Please try again.', 'racc-booking' ) );
            }
            // Fallback to DB-only check
            return $this->filter_slots_by_db( $agent_id, $date, $slots );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code < 200 || $status_code >= 300 ) {
            if ( $require_google ) {
                return new \WP_Error( 'google_api_error', __( 'Google Calendar returned an error while checking availability.', 'racc-booking' ) );
            }
            return $this->filter_slots_by_db( $agent_id, $date, $slots );
        }

        $result = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $result ) || ! isset( $result['calendars'] ) ) {
            if ( $require_google ) {
                return new \WP_Error( 'google_api_error', __( 'Invalid response from Google Calendar.', 'racc-booking' ) );
            }
            return $this->filter_slots_by_db( $agent_id, $date, $slots );
        }
        $busy   = $result['calendars'][ $calendar_id ]['busy'] ?? [];

        // Filter out busy slots
        $available = [];
        foreach ( $slots as $slot ) {
            $s_start = new \DateTime( $date . ' ' . $slot['start'], new \DateTimeZone( $timezone ) );
            $s_end   = new \DateTime( $date . ' ' . $slot['end'], new \DateTimeZone( $timezone ) );
            $is_busy = false;

            foreach ( $busy as $b ) {
                $b_start = new \DateTime( $b['start'] );
                $b_end   = new \DateTime( $b['end'] );

                // Overlap check
                if ( $s_start < $b_end && $s_end > $b_start ) {
                    $is_busy = true;
                    break;
                }
            }

            if ( ! $is_busy ) {
                $available[] = $slot;
            }
        }

        // Also filter by DB bookings (in case of recent bookings not yet synced)
        return $this->filter_slots_by_db( $agent_id, $date, $available );
    }

    /**
     * Filter slots by existing database bookings.
     *
     * @param int    $agent_id Agent ID.
     * @param string $date     Date in Y-m-d format.
     * @param array  $slots    Available slots.
     * @return array Filtered slots.
     */
    private function filter_slots_by_db( $agent_id, $date, $slots ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'racc_bookings';
        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT booking_time_start, booking_time_end FROM {$table}
             WHERE agent_id = %d AND booking_date = %s AND status IN ('confirmed', 'rescheduled')",
            $agent_id,
            $date
        ) );

        if ( empty( $bookings ) ) {
            return $slots;
        }

        $available = [];
        foreach ( $slots as $slot ) {
            $is_booked = false;
            foreach ( $bookings as $booking ) {
                $b_start = substr( $booking->booking_time_start, 0, 5 );
                $b_end   = substr( $booking->booking_time_end, 0, 5 );

                if ( $slot['start'] < $b_end && $slot['end'] > $b_start ) {
                    $is_booked = true;
                    break;
                }
            }
            if ( ! $is_booked ) {
                $available[] = $slot;
            }
        }

        return $available;
    }

    /**
     * Get dates with available slots within a date range using a single batch FreeBusy query per agent.
     *
     * Makes ONE Google FreeBusy API call covering the full range, then evaluates each
     * working day locally against the busy periods and DB bookings.
     *
     * @param int    $agent_id   Agent ID.
     * @param string $start_date Start date in Y-m-d format.
     * @param string $end_date   End date in Y-m-d format (inclusive).
    * @param int    $slot_duration_override Optional slot duration override in minutes.
     * @return string[] Y-m-d dates that have at least one available slot.
     */
    public function get_available_dates_in_range( $agent_id, $start_date, $end_date, $slot_duration_override = 0 ) {
        global $wpdb;

        $table = $wpdb->prefix . 'racc_agents';
        $agent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $agent_id ) );

        if ( ! $agent ) {
            return [];
        }

        $timezone     = $agent->timezone ?: 'Australia/Sydney';
        $duration     = intval( $slot_duration_override > 0 ? $slot_duration_override : ( $agent->slot_duration ?: 60 ) );
        $working_days = array_map( 'intval', explode( ',', $agent->working_days ?: '1,2,3,4,5' ) );
        $start_time   = $agent->working_hours_start ?: '09:00';
        $end_time     = $agent->working_hours_end ?: '17:00';
        $calendar_id  = $agent->calendar_id ?: 'primary';
        $today        = current_time( 'Y-m-d' );
        $now          = current_time( 'H:i' );

        // Enumerate only working days in the range (cheap local check first).
        $period_start  = new \DateTime( $start_date, new \DateTimeZone( $timezone ) );
        $period_end    = new \DateTime( $end_date,   new \DateTimeZone( $timezone ) );
        $working_dates = [];
        $cursor        = clone $period_start;

        while ( $cursor <= $period_end ) {
            $d = $cursor->format( 'Y-m-d' );
            if ( $d >= $today && in_array( intval( $cursor->format( 'N' ) ), $working_days, true ) ) {
                $working_dates[] = $d;
            }
            $cursor->modify( '+1 day' );
        }

        if ( empty( $working_dates ) ) {
            return [];
        }

        // Require Google Calendar connection (consistent with get_available_slots require_google=true).
        $access_token = $this->get_access_token( $agent_id );
        if ( ! $access_token ) {
            return []; // Cannot verify availability without Google Calendar connection.
        }

        // Single FreeBusy query covering the full range.
        $busy_by_date = [];

        $range_min = new \DateTime( $working_dates[0]            . ' 00:00:00', new \DateTimeZone( $timezone ) );
        $range_max = new \DateTime( end( $working_dates ) . ' 23:59:59', new \DateTimeZone( $timezone ) );

        $body = [
            'timeMin'  => $range_min->format( 'c' ),
            'timeMax'  => $range_max->format( 'c' ),
            'timeZone' => $timezone,
            'items'    => [ [ 'id' => $calendar_id ] ],
        ];

        $response = wp_remote_post( self::CALENDAR_URL . '/freeBusy', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 20,
        ]);

        if ( ! is_wp_error( $response ) ) {
            $status = wp_remote_retrieve_response_code( $response );
            if ( $status >= 200 && $status < 300 ) {
                $result = json_decode( wp_remote_retrieve_body( $response ), true );
                $busy   = ( is_array( $result ) && isset( $result['calendars'][ $calendar_id ]['busy'] ) )
                          ? $result['calendars'][ $calendar_id ]['busy']
                          : [];

                foreach ( $busy as $b ) {
                    $tz_obj  = new \DateTimeZone( $timezone );
                    $b_start = ( new \DateTime( $b['start'] ) )->setTimezone( $tz_obj );
                    $b_end   = ( new \DateTime( $b['end']   ) )->setTimezone( $tz_obj );

                    $date_key = $b_start->format( 'Y-m-d' );
                    $busy_by_date[ $date_key ][] = $b;

                    $end_key = $b_end->format( 'Y-m-d' );
                    if ( $end_key !== $date_key ) {
                        $busy_by_date[ $end_key ][] = $b;
                    }
                }
            }
        }

        // Single DB query for all working days in the range.
        $bookings_table  = $wpdb->prefix . 'racc_bookings';
        $placeholders    = implode( ',', array_fill( 0, count( $working_dates ), '%s' ) );
        $db_bookings_raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT booking_date, booking_time_start, booking_time_end
             FROM {$bookings_table}
             WHERE agent_id = %d AND booking_date IN ({$placeholders})
               AND status IN ('confirmed', 'rescheduled')",
            array_merge( [ $agent_id ], $working_dates )
        ) );

        $db_by_date = [];
        foreach ( (array) $db_bookings_raw as $row ) {
            $db_by_date[ $row->booking_date ][] = $row;
        }

        // Evaluate each working day.
        $available_dates = [];

        foreach ( $working_dates as $date ) {
            $slot_start = new \DateTime( $date . ' ' . $start_time, new \DateTimeZone( $timezone ) );
            $work_end   = new \DateTime( $date . ' ' . $end_time,   new \DateTimeZone( $timezone ) );
            $slots      = [];

            while ( $slot_start < $work_end ) {
                $slot_end = clone $slot_start;
                $slot_end->modify( "+{$duration} minutes" );
                if ( $slot_end <= $work_end ) {
                    $slots[] = [ 'start' => $slot_start->format( 'H:i' ), 'end' => $slot_end->format( 'H:i' ) ];
                }
                $slot_start = $slot_end;
            }

            if ( empty( $slots ) ) {
                continue;
            }

            // Today: filter out already-past time slots.
            if ( $date === $today ) {
                $slots = array_values( array_filter( $slots, function ( $s ) use ( $now ) {
                    return $s['start'] > $now;
                }));
                if ( empty( $slots ) ) {
                    continue;
                }
            }

            // Filter by Google busy periods.
            if ( ! empty( $busy_by_date[ $date ] ) ) {
                $filtered = [];
                foreach ( $slots as $slot ) {
                    $s_start = new \DateTime( $date . ' ' . $slot['start'], new \DateTimeZone( $timezone ) );
                    $s_end   = new \DateTime( $date . ' ' . $slot['end'],   new \DateTimeZone( $timezone ) );
                    $is_busy = false;
                    foreach ( $busy_by_date[ $date ] as $b ) {
                        $b_start = new \DateTime( $b['start'] );
                        $b_end   = new \DateTime( $b['end']   );
                        if ( $s_start < $b_end && $s_end > $b_start ) {
                            $is_busy = true;
                            break;
                        }
                    }
                    if ( ! $is_busy ) {
                        $filtered[] = $slot;
                    }
                }
                $slots = $filtered;
                if ( empty( $slots ) ) {
                    continue;
                }
            }

            // Filter by DB bookings.
            if ( ! empty( $db_by_date[ $date ] ) ) {
                $filtered = [];
                foreach ( $slots as $slot ) {
                    $is_booked = false;
                    foreach ( $db_by_date[ $date ] as $booking ) {
                        $b_start = substr( $booking->booking_time_start, 0, 5 );
                        $b_end   = substr( $booking->booking_time_end,   0, 5 );
                        if ( $slot['start'] < $b_end && $slot['end'] > $b_start ) {
                            $is_booked = true;
                            break;
                        }
                    }
                    if ( ! $is_booked ) {
                        $filtered[] = $slot;
                    }
                }
                $slots = $filtered;
            }

            if ( ! empty( $slots ) ) {
                $available_dates[] = $date;
            }
        }

        return $available_dates;
    }

    /**
     * Find the nearest date (on or after $from_date) that has available slots
     * across any of the given agents.
     *
     * Uses get_available_dates_in_range() in a $max_days window — a single
     * FreeBusy API call per agent instead of one call per day.
     *
     * @param int[]  $agent_ids Array of agent IDs.
     * @param string $from_date Start date Y-m-d (inclusive).
     * @param int    $max_days  Maximum days to look ahead. Default 60.
    * @param int    $slot_duration_override Optional slot duration override in minutes.
     * @return string|null Y-m-d of nearest available date, or null.
     */
    public function find_nearest_available_date( $agent_ids, $from_date, $max_days = 60, $slot_duration_override = 0 ) {
        if ( empty( $agent_ids ) ) {
            return null;
        }

        $end_date = ( new \DateTime( $from_date ) )
            ->modify( "+{$max_days} days" )
            ->format( 'Y-m-d' );

        $all_available = [];
        foreach ( (array) $agent_ids as $agent_id ) {
            $dates = $this->get_available_dates_in_range( (int) $agent_id, $from_date, $end_date, $slot_duration_override );
            foreach ( $dates as $d ) {
                $all_available[ $d ] = true;
            }
        }

        if ( empty( $all_available ) ) {
            return null;
        }

        ksort( $all_available );
        $keys = array_keys( $all_available );
        return $keys[0];
    }

    /**
     * Normalize a Google event date node into a DateTimeImmutable instance.
     *
     * @param array<string,string> $node     Google event date node.
     * @param string               $timezone Fallback timezone.
     * @return \DateTimeImmutable|null
     */
    private function normalize_event_datetime( $node, $timezone ) {
        if ( ! is_array( $node ) ) {
            return null;
        }

        try {
            if ( ! empty( $node['dateTime'] ) ) {
                return new \DateTimeImmutable( $node['dateTime'] );
            }

            if ( ! empty( $node['date'] ) ) {
                return new \DateTimeImmutable( $node['date'] . ' 00:00:00', new \DateTimeZone( $timezone ) );
            }
        } catch ( \Exception $e ) {
            return null;
        }

        return null;
    }

    /**
     * List Google Calendar events for a consultant within a date range.
     *
     * @param int    $agent_id    Agent ID.
     * @param string $start_date  Start date in Y-m-d format.
     * @param string $end_date    End date in Y-m-d format.
     * @return array<int,array<string,mixed>>|\WP_Error
     */
    public function list_events( $agent_id, $start_date, $end_date ) {
        global $wpdb;

        $agent = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name, calendar_id, timezone
             FROM {$wpdb->prefix}racc_agents
             WHERE id = %d",
            $agent_id
        ) );

        if ( ! $agent ) {
            return new \WP_Error( 'agent_not_found', __( 'Consultant not found.', 'racc-booking' ) );
        }

        $access_token = $this->get_access_token( $agent_id );
        if ( ! $access_token ) {
            return new \WP_Error( 'google_not_connected', __( 'Consultant Google Calendar is not connected.', 'racc-booking' ) );
        }

        $calendar_id = $agent->calendar_id ?: 'primary';

        // Resolve a valid IANA timezone string.
        $tz_string = (string) ( $agent->timezone ?: wp_timezone_string() );
        try {
            $tz = new \DateTimeZone( $tz_string );
        } catch ( \Exception $e ) {
            $tz = new \DateTimeZone( 'UTC' );
        }

        try {
            $range_start = new \DateTimeImmutable( $start_date . ' 00:00:00', $tz );
            $range_end   = new \DateTimeImmutable( $end_date . ' 23:59:59', $tz );
        } catch ( \Exception $e ) {
            return new \WP_Error( 'invalid_range', __( 'Invalid date range.', 'racc-booking' ) );
        }

        // Use UTC Z-suffix format to avoid encoding issues with timezone offset symbols.
        $utc         = new \DateTimeZone( 'UTC' );
        $time_min    = $range_start->setTimezone( $utc )->format( 'Y-m-d\TH:i:s\Z' );
        $time_max    = $range_end->setTimezone( $utc )->format( 'Y-m-d\TH:i:s\Z' );

        // Validate timezone is IANA-compatible (Google rejects offsets like "+07:00").
        $iana_tz     = ( strlen( $tz_string ) > 3 && strpos( $tz_string, ':' ) === false )
            ? $tz_string
            : 'UTC';

        $base_url    = self::CALENDAR_URL . '/calendars/' . rawurlencode( $calendar_id ) . '/events';
        $request_url = $base_url . '?' . http_build_query(
            [
                'timeMin'      => $time_min,
                'timeMax'      => $time_max,
                'singleEvents' => 'true',
                'orderBy'      => 'startTime',
                'timeZone'     => $iana_tz,
                'maxResults'   => 2500,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $response = wp_remote_get(
            $request_url,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type'  => 'application/json',
                ],
                'timeout' => 20,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return new \WP_Error( 'google_api_error', __( 'Could not reach Google Calendar.', 'racc-booking' ) );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code < 200 || $status_code >= 300 ) {
            $error_body    = json_decode( wp_remote_retrieve_body( $response ), true );
            $google_reason = '';
            if ( is_array( $error_body ) ) {
                $google_reason = (string) ( $error_body['error']['message']
                    ?? $error_body['error']['status']
                    ?? $error_body['error']
                    ?? '' );
            }
            $error_msg = __( 'Google Calendar returned an error while loading events.', 'racc-booking' );
            if ( $google_reason ) {
                $error_msg .= ' (' . $google_reason . ')';
            }
            return new \WP_Error( 'google_api_error', $error_msg, [ 'status_code' => $status_code ] );
        }

        $response_body = json_decode( wp_remote_retrieve_body( $response ), true );
        $items         = ( is_array( $response_body ) && ! empty( $response_body['items'] ) && is_array( $response_body['items'] ) )
            ? $response_body['items']
            : [];

        $events = [];

        foreach ( $items as $item ) {
            if ( ! empty( $item['status'] ) && 'cancelled' === $item['status'] ) {
                continue;
            }

            $start = $this->normalize_event_datetime( $item['start'] ?? [], $tz_string );
            $end   = $this->normalize_event_datetime( $item['end'] ?? [], $tz_string );

            if ( ! $start || ! $end ) {
                continue;
            }

            $local_start = $start->setTimezone( $tz );
            $local_end   = $end->setTimezone( $tz );
            $is_all_day  = ! empty( $item['start']['date'] ) && empty( $item['start']['dateTime'] );

            $events[] = [
                'id'              => sanitize_text_field( (string) ( $item['id'] ?? '' ) ),
                'consultant_id'   => (int) $agent->id,
                'consultant_name' => sanitize_text_field( (string) $agent->name ),
                'title'           => sanitize_text_field( (string) ( $item['summary'] ?? __( 'Untitled Event', 'racc-booking' ) ) ),
                'description'     => sanitize_textarea_field( (string) ( $item['description'] ?? '' ) ),
                'status'          => sanitize_text_field( (string) ( $item['status'] ?? 'confirmed' ) ),
                'start'           => $start->format( 'c' ),
                'end'             => $end->format( 'c' ),
                'start_date'      => $local_start->format( 'Y-m-d' ),
                'end_date'        => $local_end->format( 'Y-m-d' ),
                'booking_date'    => $local_start->format( 'Y-m-d' ),
                'start_time'      => $is_all_day ? __( 'All day', 'racc-booking' ) : $local_start->format( 'H:i' ),
                'end_time'        => $is_all_day ? __( 'All day', 'racc-booking' ) : $local_end->format( 'H:i' ),
                'is_all_day'      => $is_all_day,
                'source'          => 'google',
            ];
        }

        return $events;
    }

    /**
     * Build the standard Google Calendar event description from booking data.
     *
     * @param array  $booking_data Booking data.
     * @param string $suffix       Optional trailing text.
     * @return string
     */
    private function build_event_description( $booking_data, $suffix = '' ) {
        $description = sprintf(
            "=== CLIENT INFORMATION ===\n" .
            "Name: %s\n" .
            "Email: %s\n" .
            "Phone: %s\n" .
            "Nationality: %s\n" .
            "Date of Birth: %s\n" .
            "Country: %s\n" .
            "\n=== EDUCATION ===\n" .
            "University/School: %s\n" .
            "Course Level: %s\n" .
            "Course Major: %s\n" .
            "Course Completion: %s\n" .
            "\n=== VISA & IMMIGRATION ===\n" .
            "Current Visa: %s\n" .
            "Visa Expiry: %s\n" .
            "\n=== ADDITIONAL INFO ===\n" .
            "Occupation: %s\n" .
            "Contact Link: %s\n" .
            "Referral Source: %s\n" .
            "\n=== SERVICE ===\n" .
            "Service Type: %s\n" .
            "\n=== ENQUIRY ===\n" .
            "%s",
            $booking_data['client_name'] ?? '',
            $booking_data['client_email'] ?? '',
            $booking_data['client_phone'] ?? '',
            $booking_data['client_nationality'] ?? '',
            $booking_data['client_dob'] ?? '',
            $booking_data['client_country'] ?? '',
            $booking_data['client_university'] ?? '',
            $booking_data['client_course_level'] ?? '',
            $booking_data['client_course_major'] ?? '',
            $booking_data['client_course_completion'] ?? '',
            $booking_data['client_visa_type'] ?? '',
            $booking_data['client_visa_expiry'] ?? '',
            $booking_data['client_occupation'] ?? '',
            $booking_data['client_contact_link'] ?? '',
            $booking_data['client_referral_source'] ?? '',
            $booking_data['service_type'] ?? '',
            $booking_data['notes'] ?? ''
        );

        $suffix = trim( (string) $suffix );
        if ( '' !== $suffix ) {
            $description .= "\n\n" . $suffix;
        }

        return $description;
    }

    /**
     * Create an event on the agent's Google Calendar.
     *
     * @param int         $agent_id          Agent ID.
     * @param array       $booking_data      Booking data.
     * @param string|null $send_updates_mode Optional sendUpdates mode.
     * @return string|false Google event ID or false.
     */
    public function create_event( $agent_id, $booking_data, $send_updates_mode = null ) {
        $access_token = $this->get_access_token( $agent_id );
        if ( ! $access_token ) {
            return false;
        }

        global $wpdb;
        $agent = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}racc_agents WHERE id = %d",
            $agent_id
        ) );

        $calendar_id = $agent->calendar_id ?: 'primary';
        $timezone    = $agent->timezone ?: 'Australia/Sydney';

        $event = [
            'summary'     => sprintf(
                '[RACC] %s - %s',
                $booking_data['service_type'],
                $booking_data['client_name']
            ),
            'description' => $this->build_event_description( $booking_data ),
            'start' => [
                'dateTime' => $booking_data['booking_date'] . 'T' . $booking_data['booking_time_start'] . ':00',
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => $booking_data['booking_date'] . 'T' . $booking_data['booking_time_end'] . ':00',
                'timeZone' => $timezone,
            ],
            'attendees' => [
                [ 'email' => $booking_data['client_email'] ],
            ],
            'reminders' => $this->get_event_reminders_payload( $booking_data ),
        ];

        $send_updates_mode = is_string( $send_updates_mode ) && '' !== $send_updates_mode
            ? $send_updates_mode
            : $this->get_send_updates_mode( 'create' );

        $response = wp_remote_post(
            self::CALENDAR_URL . '/calendars/' . urlencode( $calendar_id ) . '/events?sendUpdates=' . rawurlencode( $send_updates_mode ),
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode( $event ),
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['id'] ?? false;
    }

    /**
     * Update an event on the agent's Google Calendar.
     *
     * @param int         $agent_id          Agent ID.
     * @param string      $event_id          Google event ID.
     * @param array       $booking_data      Updated booking data.
     * @param string|null $send_updates_mode Optional sendUpdates mode.
     * @return bool True on success.
     */
    public function update_event( $agent_id, $event_id, $booking_data, $send_updates_mode = null ) {
        $access_token = $this->get_access_token( $agent_id );
        if ( ! $access_token || ! $event_id ) {
            return false;
        }

        global $wpdb;
        $agent = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}racc_agents WHERE id = %d",
            $agent_id
        ) );

        $calendar_id = $agent->calendar_id ?: 'primary';
        $timezone    = $agent->timezone ?: 'Australia/Sydney';

        $event = [
            'summary'     => sprintf(
                '[RACC] %s - %s (Rescheduled)',
                $booking_data['service_type'],
                $booking_data['client_name']
            ),
            'description' => $this->build_event_description( $booking_data, '[RESCHEDULED]' ),
            'start' => [
                'dateTime' => $booking_data['booking_date'] . 'T' . $booking_data['booking_time_start'] . ':00',
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => $booking_data['booking_date'] . 'T' . $booking_data['booking_time_end'] . ':00',
                'timeZone' => $timezone,
            ],
        ];

        $send_updates_mode = is_string( $send_updates_mode ) && '' !== $send_updates_mode
            ? $send_updates_mode
            : $this->get_send_updates_mode( 'update' );

        $url = self::CALENDAR_URL . '/calendars/' . urlencode( $calendar_id ) . '/events/' . urlencode( $event_id ) . '?sendUpdates=' . rawurlencode( $send_updates_mode );

        $response = wp_remote_request( $url, [
            'method'  => 'PATCH',
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $event ),
            'timeout' => 15,
        ]);

        return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
    }

    /**
     * Delete an event from the agent's Google Calendar.
     *
     * @param int         $agent_id          Agent ID.
     * @param string      $event_id          Google event ID.
     * @param string|null $send_updates_mode Optional sendUpdates mode.
     * @return bool True on success.
     */
    public function delete_event( $agent_id, $event_id, $send_updates_mode = null ) {
        $access_token = $this->get_access_token( $agent_id );
        if ( ! $access_token || ! $event_id ) {
            return false;
        }

        global $wpdb;
        $agent = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}racc_agents WHERE id = %d",
            $agent_id
        ) );

        $calendar_id = $agent->calendar_id ?: 'primary';
        $send_updates_mode = is_string( $send_updates_mode ) && '' !== $send_updates_mode
            ? $send_updates_mode
            : $this->get_send_updates_mode( 'delete' );
        $url = self::CALENDAR_URL . '/calendars/' . urlencode( $calendar_id ) . '/events/' . urlencode( $event_id ) . '?sendUpdates=' . rawurlencode( $send_updates_mode );

        $response = wp_remote_request( $url, [
            'method'  => 'DELETE',
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
            ],
            'timeout' => 15,
        ]);

        return ! is_wp_error( $response ) && in_array( wp_remote_retrieve_response_code( $response ), [ 200, 204 ], true );
    }

    /**
     * Check if an agent has a valid Google Calendar connection.
     *
     * @param int $agent_id Agent ID.
     * @return bool
     */
    public function is_connected( $agent_id ) {
        $status = $this->get_connection_status( $agent_id, true );
        return isset( $status['status'] ) && $status['status'] === 'connected';
    }

    /**
     * Disconnect an agent's Google Calendar.
     *
     * @param int $agent_id Agent ID.
     * @return bool
     */
    public function disconnect( $agent_id ) {
        $this->clear_reconnect_required( $agent_id );

        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'racc_agents',
            [
                'google_access_token'  => '',
                'google_refresh_token' => '',
                'google_token_expires' => 0,
                'calendar_id'          => '',
            ],
            [ 'id' => $agent_id ]
        ) !== false;
    }

    /**
     * Ensure agent has valid Google access token (auto-refresh or mark reconnect_required).
     *
     * @param int $agent_id Agent ID.
     * @return bool|string True if valid, 'reconnect_required' if needs reconnect, false if not connected.
     */
    public function ensure_valid_access_token( $agent_id ) {
        $status_info = $this->get_connection_status( $agent_id, true );
        $status      = $status_info['status'] ?? 'not_connected';

        if ( $status === 'connected' ) {
            return true;
        } elseif ( $status === 'reconnect_required' ) {
            return 'reconnect_required';
        }

        return false;
    }

    /**
     * Can an agent take a booking? Check connection status without blocking if not connected.
     *
     * @param int $agent_id Agent ID.
     * @return array [ 'allowed' => bool, 'status' => string ]
     */
    public function can_take_booking( $agent_id ) {
        $status_info = $this->get_connection_status( $agent_id, true );
        $status      = $status_info['status'] ?? 'not_connected';

        // Connected or can fallback to consultant form settings
        if ( $status === 'connected' ) {
            return [ 'allowed' => true, 'status' => 'connected' ];
        }

        // Reconnect required: hard block
        if ( $status === 'reconnect_required' ) {
            return [ 'allowed' => false, 'status' => 'reconnect_required' ];
        }

        // Not connected: still allowed (falls back to consultant form + DB)
        return [ 'allowed' => true, 'status' => 'not_connected' ];
    }
}
