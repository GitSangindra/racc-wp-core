<?php
/**
 * REST API endpoints for the booking system.
 *
 * @package RACC_Booking
 */

namespace RACC_Booking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rest_API {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
        // Map Google event IDs back to local booking IDs when the event originated from RACC.
        if ( ! empty( $events ) ) {
            $google_event_ids = [];
            foreach ( $events as $event ) {
                $event_id = sanitize_text_field( (string) ( $event['id'] ?? '' ) );
                if ( $event_id !== '' ) {
                    $google_event_ids[] = $event_id;
                }
            }

            $google_event_ids = array_values( array_unique( $google_event_ids ) );

            if ( ! empty( $google_event_ids ) ) {
                $bookings_table = $wpdb->prefix . 'racc_bookings';
                $placeholders   = implode( ',', array_fill( 0, count( $google_event_ids ), '%s' ) );
                $lookup_sql     = "SELECT id, google_event_id FROM {$bookings_table} WHERE google_event_id IN ({$placeholders})";
                $lookup_rows    = $wpdb->get_results( $wpdb->prepare( $lookup_sql, ...$google_event_ids ) );

                $booking_map = [];
                foreach ( (array) $lookup_rows as $row ) {
                    $booking_map[ (string) $row->google_event_id ] = (int) $row->id;
                }

                foreach ( $events as &$event ) {
                    $event_id = (string) ( $event['id'] ?? '' );
                    if ( isset( $booking_map[ $event_id ] ) ) {
                        $event['booking_id'] = $booking_map[ $event_id ];
                    }
                }
                unset( $event );
            }
        }
    }

    /**
     * Check if current request is authenticated (for admin endpoints).
     *
     * @return bool
     */
    public function is_admin_authenticated() {
        // Check if user is logged in and has manage_options capability
        return current_user_can( 'manage_options' );
    }

    /**
     * Register all REST routes.
     */
    public function register_routes() {
        $namespace = 'racc/v1';

        // Agents
        register_rest_route( $namespace, '/agents', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_agents' ],
            'permission_callback' => '__return_true',
        ]);

        // Services — unique list aggregated from all active agents
        register_rest_route( $namespace, '/services', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_services' ],
            'permission_callback' => '__return_true',
        ]);

        // Availability
        register_rest_route( $namespace, '/availability', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_availability' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'agent_id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'date' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => function ( $param ) {
                        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $param );
                    },
                ],
                'woo_product_id' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'service_type' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Nearest available date — used by the frontend when no slots found on a selected date.
        register_rest_route( $namespace, '/nearest-available', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_nearest_available' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'agent_ids' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'from' => [
                    'required'          => false,
                    'type'              => 'string',
                    'validate_callback' => function ( $param ) {
                        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $param );
                    },
                ],
                'woo_product_id' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'service_type' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Availability calendar — returns available dates for a given month.
        register_rest_route( $namespace, '/availability-calendar', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_availability_calendar' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'agent_ids' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'year' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'month' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'woo_product_id' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'service_type' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Create booking
        register_rest_route( $namespace, '/bookings', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'create_booking' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'agent_id' => [
                    'required' => true,
                    'type'     => 'integer',
                ],
                'client_name' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'client_email' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_email',
                    'validate_callback' => function ( $param ) {
                        return is_email( $param );
                    },
                ],
                'client_phone' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'client_nationality' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'client_dob' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => function ( $param ) {
                        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $param );
                    },
                ],
                'client_university' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'client_course_level' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'client_course_major' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'client_course_completion' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => function ( $param ) {
                        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $param );
                    },
                ],
                'client_visa_type' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'client_visa_expiry' => [
                    'required'          => false,
                    'type'              => 'string',
                    'validate_callback' => function ( $param ) {
                        $param = trim( (string) $param );
                        return '' === $param || (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $param );
                    },
                ],
                'client_country' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'client_occupation' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'client_contact_link' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                ],
                'client_referral_source' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'service_type' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'woo_product_id' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'booking_date' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => function ( $param ) {
                        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $param );
                    },
                ],
                'booking_time_start' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => function ( $param ) {
                        return (bool) preg_match( '/^\d{2}:\d{2}$/', $param );
                    },
                ],
                'booking_time_end' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => function ( $param ) {
                        return (bool) preg_match( '/^\d{2}:\d{2}$/', $param );
                    },
                ],
                'notes' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
                'location_mode' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'location_id' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Reschedule / full-edit booking (admin only)
        register_rest_route( $namespace, '/bookings/(?P<id>\d+)/reschedule', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'reschedule_booking' ],
            'permission_callback' => [ $this, 'is_admin_authenticated' ],
            'args' => [
                'id' => [ 'required' => true, 'type' => 'integer' ],
                // Schedule (optional — omit to keep unchanged)
                'booking_date' => [
                    'required'          => false,
                    'type'              => 'string',
                    'validate_callback' => function ( $p ) { return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $p ); },
                ],
                'booking_time_start' => [
                    'required'          => false,
                    'type'              => 'string',
                    'validate_callback' => function ( $p ) { return (bool) preg_match( '/^\d{2}:\d{2}$/', $p ); },
                ],
                'booking_time_end' => [
                    'required'          => false,
                    'type'              => 'string',
                    'validate_callback' => function ( $p ) { return (bool) preg_match( '/^\d{2}:\d{2}$/', $p ); },
                ],
                // Consultant
                'agent_id' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
                // Booking meta
                'service_type'             => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'woo_product_id'           => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
                'status'                   => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'notes'                    => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
                // Client fields
                'client_name'              => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'client_email'             => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ],
                'client_phone'             => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'client_nationality'       => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'client_dob'               => [ 'required' => false, 'type' => 'string' ],
                'client_university'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'client_course_level'      => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'client_course_major'      => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'client_course_completion' => [ 'required' => false, 'type' => 'string' ],
                'client_visa_type'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'client_visa_expiry'       => [ 'required' => false, 'type' => 'string' ],
                'client_country'           => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'client_state'             => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'client_occupation'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'client_contact_link'      => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ],
                'client_referral_source'   => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                // Location
                'location_mode'            => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'location_id'              => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
            ],
        ]);

        // Cancel booking (admin only)
        register_rest_route( $namespace, '/bookings/(?P<id>\d+)/cancel', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'cancel_booking' ],
            'permission_callback' => [ $this, 'is_admin_authenticated' ],
        ]);

        // Delete booking permanently (admin only)
        register_rest_route( $namespace, '/bookings/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_booking' ],
            'permission_callback' => [ $this, 'is_admin_authenticated' ],
        ]);

        // Get single booking (admin only)
        register_rest_route( $namespace, '/bookings/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_booking' ],
            'permission_callback' => [ $this, 'is_admin_authenticated' ],
        ]);

        // Admin: availability check for reschedule
        register_rest_route( $namespace, '/admin/availability', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_availability' ],
            'permission_callback' => [ $this, 'is_admin_authenticated' ],
            'args' => [
                'agent_id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'date' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => function ( $param ) {
                        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $param );
                    },
                ],
                'woo_product_id' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'service_type' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Admin: calendar events from DB (non-cancelled bookings)
        register_rest_route( $namespace, '/admin/calendar-events', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_admin_calendar_events' ],
            'permission_callback' => [ $this, 'is_admin_authenticated' ],
            'args'                => [
                'start_date' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => function ( $param ) {
                        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $param );
                    },
                ],
                'end_date' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => function ( $param ) {
                        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $param );
                    },
                ],
                'agent_id' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'agent_ids' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Admin: calendar events from Google Calendar API.
        register_rest_route( $namespace, '/admin/google-calendar-events', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_admin_google_calendar_events' ],
            'permission_callback' => [ $this, 'is_admin_authenticated' ],
            'args'                => [
                'start_date' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => function ( $param ) {
                        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $param );
                    },
                ],
                'end_date' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => function ( $param ) {
                        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $param );
                    },
                ],
                'agent_id' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'agent_ids' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * GET /racc/v1/admin/calendar-events — bookings calendar events (admin).
     */
    public function get_admin_calendar_events( \WP_REST_Request $request ) {
        global $wpdb;

        $start_date = sanitize_text_field( (string) $request->get_param( 'start_date' ) );
        $end_date   = sanitize_text_field( (string) $request->get_param( 'end_date' ) );
        $agent_id   = absint( $request->get_param( 'agent_id' ) );
        $agent_ids_raw = sanitize_text_field( (string) ( $request->get_param( 'agent_ids' ) ?? '' ) );

        // Parse comma-separated agent_ids; fall back to single agent_id for BC.
        $agent_ids = [];
        if ( $agent_ids_raw !== '' ) {
            foreach ( explode( ',', $agent_ids_raw ) as $raw_id ) {
                $id = absint( trim( $raw_id ) );
                if ( $id > 0 ) { $agent_ids[] = $id; }
            }
        } elseif ( $agent_id > 0 ) {
            $agent_ids = [ $agent_id ];
        }

        if ( $start_date > $end_date ) {
            return new \WP_Error( 'invalid_range', __( 'Invalid date range.', 'racc-booking' ), [ 'status' => 400 ] );
        }

        $bookings_table = $wpdb->prefix . 'racc_bookings';
        $agents_table   = $wpdb->prefix . 'racc_agents';

        $query = "SELECT b.id, b.agent_id, b.client_name, b.service_type, b.status,
                         b.booking_date, b.booking_time_start, b.booking_time_end,
                         a.name AS agent_name
                  FROM {$bookings_table} b
                  LEFT JOIN {$agents_table} a ON a.id = b.agent_id
                  WHERE b.booking_date BETWEEN %s AND %s
                    AND ( b.status IS NULL OR b.status != 'cancelled' )";

        $params = [ $start_date, $end_date ];

        if ( ! empty( $agent_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $agent_ids ), '%d' ) );
            $query       .= " AND b.agent_id IN ({$placeholders})";
            $params       = array_merge( $params, $agent_ids );
        }

        $query .= ' ORDER BY b.booking_date ASC, b.booking_time_start ASC';

        $rows = $wpdb->get_results( $wpdb->prepare( $query, $params ) );

        $events = [];
        foreach ( (array) $rows as $row ) {
            $start_time = substr( (string) $row->booking_time_start, 0, 5 );
            $end_time   = substr( (string) $row->booking_time_end, 0, 5 );

            $events[] = [
                'id'              => (int) $row->id,
                'consultant_id'   => (int) $row->agent_id,
                'consultant_name' => $row->agent_name ? (string) $row->agent_name : __( 'Unknown Consultant', 'racc-booking' ),
                'client_name'     => (string) $row->client_name,
                'service_type'    => (string) $row->service_type,
                'status'          => (string) $row->status,
                'booking_date'    => (string) $row->booking_date,
                'start_time'      => $start_time,
                'end_time'        => $end_time,
                'start'           => (string) $row->booking_date . 'T' . $start_time . ':00',
                'end'             => (string) $row->booking_date . 'T' . $end_time . ':00',
            ];
        }

        return rest_ensure_response( [
            'events'     => $events,
            'start_date' => $start_date,
            'end_date'   => $end_date,
        ] );
    }

    /**
     * GET /racc/v1/admin/google-calendar-events — Google Calendar events (admin).
     */
    public function get_admin_google_calendar_events( \WP_REST_Request $request ) {
        global $wpdb;

        $start_date    = sanitize_text_field( (string) $request->get_param( 'start_date' ) );
        $end_date      = sanitize_text_field( (string) $request->get_param( 'end_date' ) );
        $agent_id      = absint( $request->get_param( 'agent_id' ) );
        $agent_ids_raw = sanitize_text_field( (string) ( $request->get_param( 'agent_ids' ) ?? '' ) );

        // Parse comma-separated agent_ids; fall back to single agent_id for BC.
        $agent_ids = [];
        if ( $agent_ids_raw !== '' ) {
            foreach ( explode( ',', $agent_ids_raw ) as $raw_id ) {
                $id = absint( trim( $raw_id ) );
                if ( $id > 0 ) { $agent_ids[] = $id; }
            }
        } elseif ( $agent_id > 0 ) {
            $agent_ids = [ $agent_id ];
        }

        if ( $start_date > $end_date ) {
            return new \WP_Error( 'invalid_range', __( 'Invalid date range.', 'racc-booking' ), [ 'status' => 400 ] );
        }

        $agents_table = $wpdb->prefix . 'racc_agents';
        $sql          = "SELECT id, name
                         FROM {$agents_table}
                         WHERE status = 'active'
                           AND google_refresh_token != ''
                           AND calendar_id != ''";
        $params       = [];

        if ( ! empty( $agent_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $agent_ids ), '%d' ) );
            $sql         .= " AND id IN ({$placeholders})";
            $params       = $agent_ids;
        }

        $sql .= ' ORDER BY name ASC';

        $agents = empty( $params )
            ? $wpdb->get_results( $sql )
            : $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

        $gcal     = new Google_Calendar();
        $events    = [];
        $warnings  = [];
        $skipped   = [];

        foreach ( (array) $agents as $agent ) {
            $result = $gcal->list_events( (int) $agent->id, $start_date, $end_date );

            if ( is_wp_error( $result ) ) {
                $warnings[] = [
                    'consultant_id'   => (int) $agent->id,
                    'consultant_name' => sanitize_text_field( (string) $agent->name ),
                    'message'         => $result->get_error_message(),
                ];
                $skipped[] = (int) $agent->id;
                continue;
            }

            $events = array_merge( $events, $result );
        }

        usort(
            $events,
            function ( $left, $right ) {
                return strcmp( (string) ( $left['start'] ?? '' ), (string) ( $right['start'] ?? '' ) );
            }
        );

        // Map Google event IDs back to local booking IDs when the event originated from RACC.
        if ( ! empty( $events ) ) {
            $google_event_ids = [];
            foreach ( $events as $event ) {
                $event_id = sanitize_text_field( (string) ( $event['id'] ?? '' ) );
                if ( $event_id !== '' ) {
                    $google_event_ids[] = $event_id;
                }
            }

            $google_event_ids = array_values( array_unique( $google_event_ids ) );

            if ( ! empty( $google_event_ids ) ) {
                $bookings_table = $wpdb->prefix . 'racc_bookings';
                $placeholders   = implode( ',', array_fill( 0, count( $google_event_ids ), '%s' ) );
                $lookup_sql     = "SELECT id, google_event_id FROM {$bookings_table} WHERE google_event_id IN ({$placeholders})";
                $lookup_rows    = $wpdb->get_results( $wpdb->prepare( $lookup_sql, ...$google_event_ids ) );

                $booking_map = [];
                foreach ( (array) $lookup_rows as $row ) {
                    $booking_map[ (string) $row->google_event_id ] = (int) $row->id;
                }

                foreach ( $events as &$event ) {
                    $event_id = (string) ( $event['id'] ?? '' );
                    if ( isset( $booking_map[ $event_id ] ) ) {
                        $event['booking_id'] = $booking_map[ $event_id ];
                    }
                }
                unset( $event );
            }
        }

        return rest_ensure_response( [
            'events'              => $events,
            'warnings'            => $warnings,
            'skipped_consultants' => $skipped,
            'start_date'          => $start_date,
            'end_date'            => $end_date,
        ] );
    }

    /**
     * GET /racc/v1/agents — list all active agents.
     */
    public function get_agents( \WP_REST_Request $request ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'racc_agents';
        $agents = $wpdb->get_results( "SELECT id, name, email, nationality, domicile, services, nation_coverage, timezone, google_refresh_token FROM {$table} WHERE status = 'active' ORDER BY id ASC" );

        $data = [];
        foreach ( $agents as $agent ) {
            $data[] = [
                'id'               => (int) $agent->id,
                'name'             => $agent->name,
                'email'            => $agent->email,
                'nationality'      => $agent->nationality,
                'domicile'         => $agent->domicile,
                'nation_coverage'  => $agent->nation_coverage ? json_decode( $agent->nation_coverage, true ) : [],
                'services'         => $agent->services ? json_decode( $agent->services, true ) : [],
                'timezone'         => $agent->timezone,
                'google_connected' => ! empty( $agent->google_refresh_token ),
            ];
        }

        return rest_ensure_response( $data );
    }

    /**
     * GET /racc/v1/services — list all unique services across active agents.
     */
    public function get_services( \WP_REST_Request $request ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'racc_agents';
        $agents = $wpdb->get_results( "SELECT services FROM {$table} WHERE status = 'active'" );

        $services = [];
        foreach ( $agents as $agent ) {
            if ( $agent->services ) {
                $list = json_decode( $agent->services, true );
                if ( is_array( $list ) ) {
                    foreach ( $list as $service ) {
                        $service = trim( $service );
                        if ( $service && ! in_array( $service, $services, true ) ) {
                            $services[] = $service;
                        }
                    }
                }
            }
        }

        sort( $services );

        /**
         * Filter the services list.
         * The racc-booking-woo bridge replaces this with WooCommerce products.
         *
         * @param array $services
         */
        $services = apply_filters( 'racc_booking_services', $services );

        return rest_ensure_response( $services );
    }

    /**
     * GET /racc/v1/availability — get available time slots.
     */
    public function get_availability( \WP_REST_Request $request ) {
        $agent_id = (int) $request->get_param( 'agent_id' );
        $date     = $request->get_param( 'date' );
        $slot_duration = $this->resolve_slot_duration_from_request( $request, $agent_id );

        // Don't allow booking in the past
        $today = current_time( 'Y-m-d' );
        if ( $date < $today ) {
            return rest_ensure_response( [] );
        }

        $gcal  = new Google_Calendar();
        $slots = $gcal->get_available_slots( $agent_id, $date, true, $slot_duration );

        if ( is_wp_error( $slots ) ) {
            return $slots;
        }

        // If today, filter out past time slots
        if ( $date === $today ) {
            $now = current_time( 'H:i' );
            $slots = array_values( array_filter( $slots, function ( $slot ) use ( $now ) {
                return $slot['start'] > $now;
            }));
        }

        return rest_ensure_response( $slots );
    }

    /**
     * POST /racc/v1/bookings — create a new booking.
     */
    public function create_booking( \WP_REST_Request $request ) {
        global $wpdb;
        $table = $wpdb->prefix . 'racc_bookings';

        $agent_id                 = absint( $request->get_param( 'agent_id' ) );
        $client_name              = sanitize_text_field( $request->get_param( 'client_name' ) );
        $client_email             = sanitize_email( $request->get_param( 'client_email' ) );
        $client_phone             = sanitize_text_field( $request->get_param( 'client_phone' ) );
        $client_phone_iso         = sanitize_text_field( $request->get_param( 'client_phone_iso' ) );
        $client_phone_national    = sanitize_text_field( $request->get_param( 'client_phone_national' ) );
        $client_nationality       = sanitize_text_field( $request->get_param( 'client_nationality' ) );
        $client_dob               = $request->get_param( 'client_dob' );
        $client_university        = sanitize_text_field( $request->get_param( 'client_university' ) );
        $client_course_level      = sanitize_text_field( $request->get_param( 'client_course_level' ) );
        $client_course_major      = sanitize_text_field( $request->get_param( 'client_course_major' ) );
        $client_course_completion = $request->get_param( 'client_course_completion' );
        $client_visa_type         = sanitize_text_field( $request->get_param( 'client_visa_type' ) );
        $client_visa_expiry       = $this->normalize_nullable_ymd_date( $request->get_param( 'client_visa_expiry' ) );
        $client_country           = sanitize_text_field( $request->get_param( 'client_country' ) );
        $client_state             = $this->normalize_residence_state_value( $request->get_param( 'client_state' ) );
        $client_occupation        = sanitize_text_field( $request->get_param( 'client_occupation' ) );
        $client_contact_link      = esc_url_raw( $request->get_param( 'client_contact_link' ) );
        $client_referral_source   = sanitize_text_field( $request->get_param( 'client_referral_source' ) );
        $service_type             = sanitize_text_field( $request->get_param( 'service_type' ) );
        $woo_product_id           = absint( $request->get_param( 'woo_product_id' ) );
        if ( $woo_product_id <= 0 ) {
            $woo_product_id = $this->find_woo_product_id_by_service_name( $service_type );
        }
        $slot_duration            = $this->resolve_slot_duration( $woo_product_id, $agent_id );
        $booking_date             = $this->normalize_ymd_date( $request->get_param( 'booking_date' ) );
        $booking_time_start       = $this->normalize_hhmm_time( $request->get_param( 'booking_time_start' ) );
        $booking_time_end         = $this->normalize_hhmm_time( $request->get_param( 'booking_time_end' ) );
        $notes                    = sanitize_textarea_field( $request->get_param( 'notes' ) ?? '' );

        if ( '' === $booking_date ) {
            return new \WP_Error( 'invalid_booking_date', __( 'Invalid booking date format. Use YYYY-MM-DD.', 'racc-booking' ), [ 'status' => 400 ] );
        }

        if ( '' === $booking_time_start || '' === $booking_time_end ) {
            return new \WP_Error( 'invalid_booking_time', __( 'Invalid booking time format. Use HH:MM (24-hour).', 'racc-booking' ), [ 'status' => 400 ] );
        }

        if ( strtotime( $booking_time_start ) >= strtotime( $booking_time_end ) ) {
            return new \WP_Error( 'invalid_booking_time_range', __( 'Booking end time must be after start time.', 'racc-booking' ), [ 'status' => 400 ] );
        }

        // Default location for new booking: client place
        $location_mode = 'client_place';
        $location_id   = null;

        // Always run auto-assignment to find the most suitable consultant who has this exact slot available.
        // Priority: 1. Domicile match, 2. Nationality match, 3. Seniority (ID ASC).
        $fallback_agents = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}racc_agents WHERE status = 'active' ORDER BY id ASC"
        );

        usort( $fallback_agents, function( $a, $b ) use ( $client_country, $client_nationality ) {
            $score_a = 0;
            $score_b = 0;

            $cov_a = ! empty( $a->nation_coverage ) ? json_decode( $a->nation_coverage, true ) : [];
            $cov_b = ! empty( $b->nation_coverage ) ? json_decode( $b->nation_coverage, true ) : [];
            if ( ! is_array( $cov_a ) ) $cov_a = [];
            if ( ! is_array( $cov_b ) ) $cov_b = [];

            // Priority 1: Domicile Match
            if ( in_array( $client_country, $cov_a, true ) ) $score_a += 100;
            if ( in_array( $client_country, $cov_b, true ) ) $score_b += 100;

            // Priority 2: Nationality Match
            if ( in_array( $client_nationality, $cov_a, true ) ) $score_a += 50;
            if ( in_array( $client_nationality, $cov_b, true ) ) $score_b += 50;

            if ( $score_a !== $score_b ) {
                return $score_b <=> $score_a; // DESC
            }

            return (int) $a->id <=> (int) $b->id; // ASC
        } );

        $gcal_fallback = new Google_Calendar();
        $agent = null;
        foreach ( $fallback_agents as $candidate ) {
            $candidate_slots = $gcal_fallback->get_available_slots( $candidate->id, $booking_date, true, $slot_duration );
            foreach ( $candidate_slots as $cs ) {
                if ( $cs['start'] === $booking_time_start && $cs['end'] === $booking_time_end ) {
                    $agent    = $candidate;
                    $agent_id = (int) $candidate->id;
                    break 2;
                }
            }
        }
        
        if ( ! $agent ) {
            return new \WP_Error( 'invalid_agent', __( 'Selected consultant is not available at this time.', 'racc-booking' ), [ 'status' => 400 ] );
        }

        $existing_booking = $this->find_existing_booking_for_retry( [
            'agent_id'           => $agent_id,
            'client_email'       => $client_email,
            'booking_date'       => $booking_date,
            'booking_time_start' => $booking_time_start,
            'booking_time_end'   => $booking_time_end,
            'service_type'       => $service_type,
        ] );

        if ( $existing_booking ) {
            return rest_ensure_response(
                $this->build_created_booking_response(
                    $existing_booking,
                    $agent,
                    __( 'Your previous booking request was already received. We restored the existing booking.', 'racc-booking' )
                )
            );
        }

        // Check if agent can take booking (hard block if reconnect required)
        $gcal            = new Google_Calendar();
        $booking_allowed = $gcal->can_take_booking( $agent_id );

        if ( ! $booking_allowed['allowed'] ) {
            if ( 'reconnect_required' === $booking_allowed['status'] ) {
                return new \WP_Error(
                    'google_reconnect_required',
                    __( 'The selected consultant\'s Google Calendar connection needs to be reconnected. Please contact support.', 'racc-booking' ),
                    [ 'status' => 403 ]
                );
            }
            return new \WP_Error( 'booking_not_allowed', __( 'Booking not allowed for this consultant.', 'racc-booking' ), [ 'status' => 403 ] );
        }

        // Double-check availability (race condition protection)
        $available = $gcal->get_available_slots( $agent_id, $booking_date, true, $slot_duration );

        if ( is_wp_error( $available ) ) {
            return new \WP_Error( 'availability_error', $available->get_error_message(), [ 'status' => 500 ] );
        }

        $slot_available = false;
        foreach ( $available as $slot ) {
            if ( $slot['start'] === $booking_time_start && $slot['end'] === $booking_time_end ) {
                $slot_available = true;
                break;
            }
        }

        if ( ! $slot_available ) {
            return new \WP_Error( 'slot_taken', __( 'This time slot is no longer available. Please select another.', 'racc-booking' ), [ 'status' => 409 ] );
        }

        // Temporary lock via transient
        $lock_key = "racc_slot_lock_{$agent_id}_{$booking_date}_{$booking_time_start}";
        if ( get_transient( $lock_key ) ) {
            return new \WP_Error( 'slot_locked', __( 'This slot is being booked by another user. Please select another.', 'racc-booking' ), [ 'status' => 409 ] );
        }
        set_transient( $lock_key, true, 300 ); // 5-minute lock

        // Create Google Calendar event
        $booking_data = [
            'client_name'              => $client_name,
            'client_email'             => $client_email,
            'client_phone'             => $client_phone,
            'client_phone_iso'         => $client_phone_iso,
            'client_phone_national'    => $client_phone_national,
            'client_nationality'       => $client_nationality,
            'client_dob'               => $client_dob,
            'client_university'        => $client_university,
            'client_course_level'      => $client_course_level,
            'client_course_major'      => $client_course_major,
            'client_course_completion' => $client_course_completion,
            'client_visa_type'         => $client_visa_type,
            'client_visa_expiry'       => $client_visa_expiry,
            'client_country'           => $client_country,
            'client_state'             => $client_state,
            'client_occupation'        => $client_occupation,
            'client_contact_link'      => $client_contact_link,
            'client_referral_source'   => $client_referral_source,
            'service_type'             => $service_type,
            'booking_date'             => $booking_date,
            'booking_time_start'       => $booking_time_start,
            'booking_time_end'         => $booking_time_end,
            'notes'                    => $notes,
        ];

        $google_event_id = $gcal->create_event( $agent_id, $booking_data );
        if ( ! $google_event_id ) {
            delete_transient( $lock_key );
            return new \WP_Error( 'calendar_sync_failed', __( 'Failed to create Google Calendar event. Please try again.', 'racc-booking' ), [ 'status' => 502 ] );
        }

        // Insert into DB
        $insert_data = [
            'agent_id'                 => $agent_id,
            'client_name'              => $client_name,
            'client_email'             => $client_email,
            'client_phone'             => $client_phone,
            'client_phone_iso'         => $client_phone_iso,
            'client_phone_national'    => $client_phone_national,
            'client_nationality'       => $client_nationality,
            'client_dob'               => $client_dob,
            'client_university'        => $client_university,
            'client_course_level'      => $client_course_level,
            'client_course_major'      => $client_course_major,
            'client_course_completion' => $client_course_completion,
            'client_visa_type'         => $client_visa_type,
            'client_visa_expiry'       => $client_visa_expiry,
            'client_country'           => $client_country,
            'client_state'             => $client_state,
            'client_occupation'        => $client_occupation,
            'client_contact_link'      => $client_contact_link,
            'client_referral_source'   => $client_referral_source,
            'service_type'             => $service_type,
            'booking_date'             => $booking_date,
            'booking_time_start'       => $booking_time_start . ':00',
            'booking_time_end'         => $booking_time_end . ':00',
            'google_event_id'          => $google_event_id ?: '',
            'notes'                    => $notes,
            'status'                   => apply_filters( 'racc_booking_initial_status', 'confirmed' ),
            'location_mode'            => $location_mode,
            'location_id'              => $location_id,
        ];

        // Optional Woo product reference (bridge integration).
        $has_woo_product_column = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            'woo_product_id'
        ) );
        if ( $has_woo_product_column && $woo_product_id > 0 ) {
            $insert_data['woo_product_id'] = $woo_product_id;
        }

        $insert_format = [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ];
        if ( isset( $insert_data['woo_product_id'] ) ) {
            $insert_format[] = '%d';
        }

        $result = $wpdb->insert( $table, $insert_data, $insert_format );

        // Release lock
        delete_transient( $lock_key );

        if ( ! $result ) {
            // Roll back Google event if DB insert fails
            $gcal->delete_event( $agent_id, $google_event_id );
            return new \WP_Error( 'booking_failed', __( 'Failed to create booking. Please try again.', 'racc-booking' ), [ 'status' => 500 ] );
        }

        $booking_id = $wpdb->insert_id;

        // Fire booking created hook (e.g. AgentCIS sync)
        do_action( 'racc_booking_created', $booking_id );

        // Send confirmation emails (can be suppressed by payment bridge plugins).
        if ( apply_filters( 'racc_booking_send_confirmation_email', true, $booking_id ) ) {
            $email_notifier = new Email_Notifier();
            $email_notifier->send_booking_confirmation( $booking_id );
        }

        return rest_ensure_response( $this->build_created_booking_response( $insert_data, $agent, '', $booking_id ) );
    }

    /**
     * POST /racc/v1/bookings/{id}/reschedule — full-edit a booking (admin).
     *
     * Accepts any subset of booking fields. Schedule fields (booking_date,
     * booking_time_start, booking_time_end) are optional; if omitted the
     * existing values are kept and no availability check is performed.
     * If the consultant (agent_id) changes the old Google Calendar event is
     * deleted and a new one is created on the new consultant's calendar.
     */
    public function reschedule_booking( \WP_REST_Request $request ) {
        global $wpdb;
        $table      = $wpdb->prefix . 'racc_bookings';
        $booking_id = absint( $request->get_param( 'id' ) );

        // ── Load existing booking ────────────────────────────────────────
        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, a.google_refresh_token AS agent_google_token
             FROM {$table} b
             LEFT JOIN {$wpdb->prefix}racc_agents a ON b.agent_id = a.id
             WHERE b.id = %d",
            $booking_id
        ) );

        if ( ! $booking ) {
            return new \WP_Error( 'not_found', __( 'Booking not found.', 'racc-booking' ), [ 'status' => 404 ] );
        }

        // ── Resolve new values (use existing when param not sent) ────────
        $p = function ( $key, $fallback ) use ( $request ) {
            $val = $request->get_param( $key );
            return ( $val !== null ) ? $val : $fallback;
        };

        $new_agent_id        = absint( $p( 'agent_id', $booking->agent_id ) );
        $new_date            = $request->get_param( 'booking_date' );       // null = keep
        $new_time_start      = $request->get_param( 'booking_time_start' ); // null = keep
        $new_time_end        = $request->get_param( 'booking_time_end' );   // null = keep
        $date_input_changed  = $request->has_param( 'booking_date' );
        $start_input_changed = $request->has_param( 'booking_time_start' );
        $end_input_changed   = $request->has_param( 'booking_time_end' );

        $agent_changed          = ( $new_agent_id !== (int) $booking->agent_id );
        $schedule_input_changed = ( $date_input_changed || $start_input_changed || $end_input_changed );

        $current_date  = $this->normalize_ymd_date( $booking->booking_date );
        $current_start = $this->normalize_hhmm_time( $booking->booking_time_start );
        $current_end   = $this->normalize_hhmm_time( $booking->booking_time_end );

        if ( '' === $current_date || '' === $current_start || '' === $current_end ) {
            return new \WP_Error( 'booking_data_invalid', __( 'Existing booking schedule is invalid. Please contact administrator.', 'racc-booking' ), [ 'status' => 500 ] );
        }

        // Final date/time values (partial schedule edits are supported).
        $final_date  = $date_input_changed ? $this->normalize_ymd_date( $new_date ) : $current_date;
        $final_start = $start_input_changed ? $this->normalize_hhmm_time( $new_time_start ) : $current_start;
        $final_end   = $end_input_changed ? $this->normalize_hhmm_time( $new_time_end ) : $current_end;

        if ( '' === $final_date ) {
            return new \WP_Error( 'invalid_booking_date', __( 'Invalid reschedule date format. Use YYYY-MM-DD.', 'racc-booking' ), [ 'status' => 400 ] );
        }

        if ( '' === $final_start || '' === $final_end ) {
            return new \WP_Error( 'invalid_booking_time', __( 'Invalid reschedule time format. Use HH:MM (24-hour).', 'racc-booking' ), [ 'status' => 400 ] );
        }

        if ( strtotime( $final_start ) >= strtotime( $final_end ) ) {
            return new \WP_Error( 'invalid_booking_time_range', __( 'Reschedule end time must be after start time.', 'racc-booking' ), [ 'status' => 400 ] );
        }

        $schedule_effective_changed = (
            $final_date  !== $current_date ||
            $final_start !== $current_start ||
            $final_end   !== $current_end
        );

        $requested_service_type = sanitize_text_field( (string) $p( 'service_type', $booking->service_type ) );
        $requested_woo_product_id = absint( $p( 'woo_product_id', $booking->woo_product_id ?? 0 ) );
        
        $new_agentcis_contact_id = null;
        if ( $request->has_param( 'agentcis_contact_id' ) ) {
            $new_agentcis_contact_id = sanitize_text_field( $request->get_param( 'agentcis_contact_id' ) );
        }
        if ( $requested_woo_product_id <= 0 ) {
            $requested_woo_product_id = $this->find_woo_product_id_by_service_name( $requested_service_type );
        }
        $slot_duration_for_check = $this->resolve_slot_duration( $requested_woo_product_id, $new_agent_id );

        // ── Validate: if schedule or agent changed, check slot ───────────
        $needs_availability_check = $schedule_input_changed || $agent_changed;

        // Skip when same agent + same date/time (slot already belongs to this booking)
        $truly_same_slot = (
            ! $agent_changed &&
            $final_date  === $current_date &&
            $final_start === $current_start &&
            $final_end   === $current_end
        );

        if ( $needs_availability_check && ! $truly_same_slot ) {
            // Fetch new agent record
            $target_agent = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}racc_agents WHERE id = %d AND status = 'active'",
                $new_agent_id
            ) );

            if ( ! $target_agent ) {
                return new \WP_Error( 'invalid_agent', __( 'Selected consultant not found or inactive.', 'racc-booking' ), [ 'status' => 400 ] );
            }

            if ( ! empty( $target_agent->google_refresh_token ) ) {
                $gcal      = new Google_Calendar();
                $available = $gcal->get_available_slots( $new_agent_id, $final_date, true, $slot_duration_for_check );

                if ( is_wp_error( $available ) ) {
                    return new \WP_Error( 'availability_error', $available->get_error_message(), [ 'status' => 500 ] );
                }

                $slot_ok = false;
                foreach ( $available as $slot ) {
                    if ( $slot['start'] === $final_start && $slot['end'] === $final_end ) {
                        $slot_ok = true;
                        break;
                    }
                }

                if ( ! $slot_ok ) {
                    return new \WP_Error( 'slot_taken', __( 'This time slot is not available for the selected consultant.', 'racc-booking' ), [ 'status' => 409 ] );
                }
            }
        }

        // ── Collect all updated client/meta fields ───────────────────────
        $new_client_name              = sanitize_text_field( $p( 'client_name', $booking->client_name ) );
        $new_client_email             = sanitize_email( $p( 'client_email', $booking->client_email ) );
        $new_client_phone             = sanitize_text_field( $p( 'client_phone', $booking->client_phone ) );
        $new_client_nationality       = sanitize_text_field( $p( 'client_nationality', $booking->client_nationality ) );
        $new_client_dob               = $p( 'client_dob', $booking->client_dob );
        $new_client_university        = sanitize_text_field( $p( 'client_university', $booking->client_university ) );
        $new_client_course_level      = sanitize_text_field( $p( 'client_course_level', $booking->client_course_level ) );
        $new_client_course_major      = sanitize_text_field( $p( 'client_course_major', $booking->client_course_major ) );
        $new_client_course_completion = $p( 'client_course_completion', $booking->client_course_completion );
        $new_client_visa_type         = sanitize_text_field( $p( 'client_visa_type', $booking->client_visa_type ) );
        $new_client_visa_expiry       = $this->normalize_nullable_ymd_date( $p( 'client_visa_expiry', $booking->client_visa_expiry ) );
        $new_client_country           = sanitize_text_field( $p( 'client_country', $booking->client_country ) );
        $new_client_state             = $this->normalize_residence_state_value( $p( 'client_state', $booking->client_state ?? '' ) );
        $new_client_occupation        = sanitize_text_field( $p( 'client_occupation', $booking->client_occupation ) );
        $new_client_contact_link      = esc_url_raw( $p( 'client_contact_link', $booking->client_contact_link ) );
        $new_client_referral_source   = sanitize_text_field( $p( 'client_referral_source', $booking->client_referral_source ) );
        $new_service_type             = sanitize_text_field( $p( 'service_type', $booking->service_type ) );
        $new_notes                    = sanitize_textarea_field( $p( 'notes', $booking->notes ) );

        // ── Resolve woo_product_id ──────────────────────────────────────
        $new_woo_product_id = $requested_woo_product_id > 0
            ? $requested_woo_product_id
            : ( isset( $booking->woo_product_id ) ? (int) $booking->woo_product_id : 0 );

        if ( $new_woo_product_id <= 0 || $new_service_type !== $booking->service_type ) {
            $resolved_product_id = $this->find_woo_product_id_by_service_name( $new_service_type );
            if ( $resolved_product_id > 0 ) {
                $new_woo_product_id = $resolved_product_id;
            }
        }
        $new_status = sanitize_text_field( $p( 'status', $booking->status ) );
        $allowed_statuses = [ 'pending_payment', 'pending', 'confirmed', 'rescheduled', 'cancelled', 'completed' ];
        if ( ! in_array( $new_status, $allowed_statuses, true ) ) {
            $new_status = (string) $booking->status;
        }
        $new_location_mode            = sanitize_text_field( $p( 'location_mode', $booking->location_mode ?: 'client_place' ) );
        $new_location_id              = absint( $p( 'location_id', $booking->location_id ?? 0 ) );

        if ( ! in_array( $new_location_mode, [ 'client_place', 'master_location', 'default_contact' ], true ) ) {
            $new_location_mode = 'client_place';
        }

        if ( 'master_location' !== $new_location_mode ) {
            $new_location_id = 0;
        } else {
            $location_exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}racc_locations WHERE id = %d AND status = 'active'",
                $new_location_id
            ) );

            if ( ! $location_exists ) {
                return new \WP_Error( 'invalid_location', __( 'Selected location is invalid or inactive.', 'racc-booking' ), [ 'status' => 400 ] );
            }
        }

        // ── Handle Google Calendar ───────────────────────────────────────
        $gcal            = new Google_Calendar();
        $google_event_id = $booking->google_event_id;
        $created_new     = false;

        $cal_payload = [
            'client_name'        => $new_client_name,
            'client_email'       => $new_client_email,
            'client_phone'       => $new_client_phone,
            'service_type'       => $new_service_type,
            'booking_date'       => $final_date,
            'booking_time_start' => $final_start,
            'booking_time_end'   => $final_end,
            'notes'              => $new_notes,
        ];

        if ( $agent_changed ) {
            // Delete old event from old consultant's calendar
            if ( $google_event_id ) {
                $gcal->delete_event( (int) $booking->agent_id, $google_event_id );
            }
            // Create new event on new consultant's calendar (if connected)
            $new_agent_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT google_refresh_token FROM {$wpdb->prefix}racc_agents WHERE id = %d",
                $new_agent_id
            ) );
            if ( ! empty( $new_agent_row->google_refresh_token ) ) {
                $google_event_id = $gcal->create_event( $new_agent_id, $cal_payload );
                if ( ! $google_event_id ) {
                    return new \WP_Error( 'calendar_sync_failed', __( 'Failed to create Google Calendar event for the new consultant.', 'racc-booking' ), [ 'status' => 502 ] );
                }
                $created_new = true;
            } else {
                $google_event_id = '';
            }
        } elseif ( $google_event_id ) {
            // Same consultant — update existing event
            $updated_cal = $gcal->update_event( $new_agent_id, $google_event_id, $cal_payload );
            if ( ! $updated_cal ) {
                // Fallback: create a fresh event
                $google_event_id = $gcal->create_event( $new_agent_id, $cal_payload ) ?: $google_event_id;
                if ( $google_event_id ) $created_new = true;
            }
        } elseif ( $schedule_effective_changed ) {
            // No existing event — create one if consultant is connected
            $same_agent_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT google_refresh_token FROM {$wpdb->prefix}racc_agents WHERE id = %d",
                $new_agent_id
            ) );
            if ( ! empty( $same_agent_row->google_refresh_token ) ) {
                $new_id = $gcal->create_event( $new_agent_id, $cal_payload );
                if ( $new_id ) { $google_event_id = $new_id; $created_new = true; }
            }
        }

        // ── Persist to database ──────────────────────────────────────────
        $result = $wpdb->update(
            $table,
            [
                'agent_id'                 => $new_agent_id,
                'client_name'              => $new_client_name,
                'client_email'             => $new_client_email,
                'client_phone'             => $new_client_phone,
                'client_nationality'       => $new_client_nationality,
                'client_dob'               => $new_client_dob,
                'client_university'        => $new_client_university,
                'client_course_level'      => $new_client_course_level,
                'client_course_major'      => $new_client_course_major,
                'client_course_completion' => $new_client_course_completion,
                'client_visa_type'         => $new_client_visa_type,
                'client_visa_expiry'       => $new_client_visa_expiry,
                'client_country'           => $new_client_country,
                'client_state'             => $new_client_state,
                'client_occupation'        => $new_client_occupation,
                'client_contact_link'      => $new_client_contact_link,
                'client_referral_source'   => $new_client_referral_source,
                'service_type'             => $new_service_type,
                'woo_product_id'           => $new_woo_product_id ?: null,
                'booking_date'             => $final_date,
                'booking_time_start'       => $final_start . ':00',
                'booking_time_end'         => $final_end . ':00',
                'google_event_id'          => $google_event_id,
                'status'                   => $new_status,
                'notes'                    => $new_notes,
                'location_mode'            => $new_location_mode,
                'location_id'              => $new_location_id ? $new_location_id : null,
                'changed_by_user_id'       => get_current_user_id() ?: null,
            ],
            [ 'id' => $booking_id ]
        );

        if ( $new_agentcis_contact_id !== null && $new_agentcis_contact_id !== $booking->agentcis_contact_id ) {
            $wpdb->update(
                $table,
                [ 'agentcis_contact_id' => $new_agentcis_contact_id ],
                [ 'id' => $booking_id ]
            );
        }

        if ( false === $result ) {
            // Rollback: delete the freshly created calendar event
            if ( $created_new && $google_event_id ) {
                $gcal->delete_event( $new_agent_id, $google_event_id );
            }
            return new \WP_Error( 'update_failed', __( 'Failed to save booking changes to the database.', 'racc-booking' ), [ 'status' => 500 ] );
        }

        // ── Notifications ────────────────────────────────────────────────
        if ( $schedule_effective_changed ) {
            $email = new Email_Notifier();
            $email->send_reschedule_notification(
                $booking_id,
                $current_date,
                $current_start,
                $current_end,
                $schedule_effective_changed,
                $final_date,
                $final_start,
                $final_end
            );
        }

        if ( $schedule_effective_changed ) {
            do_action( 'racc_booking_rescheduled', $booking_id );
        } elseif ( $agent_changed ) {
            do_action( 'racc_booking_reassigned', $booking_id, $new_agent_id, (int) $booking->agent_id );
            
            $email = new Email_Notifier();
            $email->send_reassign_notification( $booking_id );
        }

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Booking updated successfully.', 'racc-booking' ),
        ] );
    }

    /**
     * POST /racc/v1/bookings/{id}/cancel — cancel a booking (admin).
     */
    public function cancel_booking( \WP_REST_Request $request ) {
        global $wpdb;
        $table      = $wpdb->prefix . 'racc_bookings';
        $booking_id = absint( $request->get_param( 'id' ) );

        $booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $booking_id ) );

        if ( ! $booking ) {
            return new \WP_Error( 'not_found', __( 'Booking not found.', 'racc-booking' ), [ 'status' => 404 ] );
        }

        // Delete from Google Calendar
        if ( $booking->google_event_id ) {
            $gcal = new Google_Calendar();
            $gcal->delete_event( $booking->agent_id, $booking->google_event_id );
        }

        // Update status
        $wpdb->update(
            $table,
            [ 'status' => 'cancelled' ],
            [ 'id' => $booking_id ],
            [ '%s' ],
            [ '%d' ]
        );

        // Send cancellation email
        $email_notifier = new Email_Notifier();
        $email_notifier->send_cancellation_notification( $booking_id );

        // Fire booking cancelled hook (e.g. AgentCIS sync)
        do_action( 'racc_booking_cancelled', $booking_id );

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Booking cancelled successfully.', 'racc-booking' ),
        ] );
    }

    /**
     * DELETE /racc/v1/bookings/{id} — delete a booking permanently (admin).
     */
    public function delete_booking( \WP_REST_Request $request ) {
        global $wpdb;
        $table      = $wpdb->prefix . 'racc_bookings';
        $booking_id = absint( $request->get_param( 'id' ) );

        $booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $booking_id ) );

        if ( ! $booking ) {
            return new \WP_Error( 'not_found', __( 'Booking not found.', 'racc-booking' ), [ 'status' => 404 ] );
        }

        if ( ! empty( $booking->google_event_id ) ) {
            $gcal = new Google_Calendar();
            $gcal->delete_event( (int) $booking->agent_id, (string) $booking->google_event_id );
        }

        $wpdb->delete( $table, [ 'id' => $booking_id ], [ '%d' ] );

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Booking deleted successfully.', 'racc-booking' ),
        ] );
    }

    /**
     * GET /racc/v1/bookings/{id} — get a single booking (admin).
     */
    public function get_booking( \WP_REST_Request $request ) {
        global $wpdb;
        $booking_id = absint( $request->get_param( 'id' ) );

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, a.name as agent_name, a.email as agent_email, a.timezone as agent_timezone, a.agentcis_assignee_id,
                    l.name as location_name, l.country_region, l.city, l.postal_code, l.street_name, l.house_number,
                    l.apartment_suite, l.address_description, l.use_default_contact,
                    l.location_contact_name, l.location_contact_phone, l.location_contact_email
             FROM {$wpdb->prefix}racc_bookings b
             LEFT JOIN {$wpdb->prefix}racc_agents a ON b.agent_id = a.id
             LEFT JOIN {$wpdb->prefix}racc_locations l ON b.location_id = l.id
             WHERE b.id = %d",
            $booking_id
        ) );

        if ( ! $booking ) {
            return new \WP_Error( 'not_found', __( 'Booking not found.', 'racc-booking' ), [ 'status' => 404 ] );
        }

        if ( ! empty( $booking->agent_name ) && ! empty( $booking->agentcis_assignee_id ) ) {
            $mapped_users = get_option( 'racc_agentcis_users_list', [] );
            if ( is_array( $mapped_users ) ) {
                foreach ( $mapped_users as $u ) {
                    if ( (int) $u['id'] === (int) $booking->agentcis_assignee_id ) {
                        $booking->agent_name .= ' (' . $u['name'] . ')';
                        break;
                    }
                }
            }
        }

        $booking->status_label = ucwords( str_replace( '_', ' ', (string) $booking->status ) );
        if ( ! empty( $booking->created_at ) ) {
            $booking->created_at = date_i18n( 'j M Y, g:i A', strtotime( $booking->created_at ) );
        }

        if ( ! empty( $booking->woo_product_id ) ) {
            $booking->woo_product_name      = get_the_title( (int) $booking->woo_product_id );
            $booking->woo_product_edit_link = admin_url( 'post.php?post=' . absint( $booking->woo_product_id ) . '&action=edit' );
        } else {
            $booking->woo_product_name      = '';
            $booking->woo_product_edit_link = '';
        }

        if ( ! empty( $booking->woo_order_id ) ) {
            $order_edit_link = get_edit_post_link( (int) $booking->woo_order_id, 'raw' );
            if ( ! $order_edit_link ) {
                $order_edit_link = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . absint( $booking->woo_order_id ) );
            }

            $booking->woo_order_edit_link = $order_edit_link;
        } else {
            $booking->woo_order_edit_link = '';
        }

        return rest_ensure_response( $booking );
    }

    /**
     * Find an already-created booking for the same customer + slot.
     *
     * This is used to recover gracefully when the frontend request timed out
     * after the booking and WooCommerce order were already created.
     *
     * @param array $criteria
     * @return object|null
     */
    private function find_existing_booking_for_retry( array $criteria ) {
        global $wpdb;

        $agent_id           = absint( $criteria['agent_id'] ?? 0 );
        $client_email       = sanitize_email( (string) ( $criteria['client_email'] ?? '' ) );
        $booking_date       = sanitize_text_field( (string) ( $criteria['booking_date'] ?? '' ) );
        $booking_time_start = sanitize_text_field( (string) ( $criteria['booking_time_start'] ?? '' ) );
        $booking_time_end   = sanitize_text_field( (string) ( $criteria['booking_time_end'] ?? '' ) );
        $service_type       = sanitize_text_field( (string) ( $criteria['service_type'] ?? '' ) );

        if ( $agent_id <= 0 || '' === $client_email || '' === $booking_date || '' === $booking_time_start || '' === $booking_time_end ) {
            return null;
        }

        $table      = $wpdb->prefix . 'racc_bookings';
        $start_with_seconds = 5 === strlen( $booking_time_start ) ? $booking_time_start . ':00' : $booking_time_start;
        $end_with_seconds   = 5 === strlen( $booking_time_end ) ? $booking_time_end . ':00' : $booking_time_end;

        $sql = $wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE agent_id = %d
               AND LOWER(client_email) = LOWER(%s)
               AND booking_date = %s
               AND booking_time_start IN (%s, %s)
               AND booking_time_end IN (%s, %s)
               AND status <> 'cancelled'
               AND created_at >= ( UTC_TIMESTAMP() - INTERVAL 1 DAY )
             ORDER BY id DESC
             LIMIT 1",
            $agent_id,
            $client_email,
            $booking_date,
            $booking_time_start,
            $start_with_seconds,
            $booking_time_end,
            $end_with_seconds
        );

        $booking = $wpdb->get_row( $sql );
        if ( ! $booking ) {
            return null;
        }

        if ( '' !== $service_type && ! empty( $booking->service_type ) && 0 !== strcasecmp( (string) $booking->service_type, $service_type ) ) {
            return null;
        }

        return $booking;
    }

    /**
     * Build the booking-created response shape used by the frontend.
     *
     * @param array|object $booking_or_insert_data
     * @param object       $agent
     * @param string       $message
     * @param int|null     $booking_id
     * @return array
     */
    private function build_created_booking_response( $booking_or_insert_data, $agent, $message = '', $booking_id = null ) {
        if ( is_object( $booking_or_insert_data ) ) {
            $booking_id              = (int) ( $booking_or_insert_data->id ?? $booking_id );
            $booking_date            = (string) ( $booking_or_insert_data->booking_date ?? '' );
            $time_start              = substr( (string) ( $booking_or_insert_data->booking_time_start ?? '' ), 0, 5 );
            $time_end                = substr( (string) ( $booking_or_insert_data->booking_time_end ?? '' ), 0, 5 );
            $service_type            = (string) ( $booking_or_insert_data->service_type ?? '' );
            $client_name             = (string) ( $booking_or_insert_data->client_name ?? '' );
            $client_email            = (string) ( $booking_or_insert_data->client_email ?? '' );
            $client_phone            = (string) ( $booking_or_insert_data->client_phone ?? '' );
            $client_nationality      = (string) ( $booking_or_insert_data->client_nationality ?? '' );
            $client_dob              = (string) ( $booking_or_insert_data->client_dob ?? '' );
            $client_country          = (string) ( $booking_or_insert_data->client_country ?? '' );
            $client_state            = (string) ( $booking_or_insert_data->client_state ?? '' );
            $client_university       = (string) ( $booking_or_insert_data->client_university ?? '' );
            $client_course_level     = (string) ( $booking_or_insert_data->client_course_level ?? '' );
            $client_course_major     = (string) ( $booking_or_insert_data->client_course_major ?? '' );
            $client_course_completion = (string) ( $booking_or_insert_data->client_course_completion ?? '' );
            $client_visa_type        = (string) ( $booking_or_insert_data->client_visa_type ?? '' );
            $client_visa_expiry      = (string) ( $booking_or_insert_data->client_visa_expiry ?? '' );
            $client_occupation       = (string) ( $booking_or_insert_data->client_occupation ?? '' );
            $client_contact_link     = (string) ( $booking_or_insert_data->client_contact_link ?? '' );
            $client_referral_source  = (string) ( $booking_or_insert_data->client_referral_source ?? '' );
            $notes                   = (string) ( $booking_or_insert_data->notes ?? '' );
        } else {
            $booking_id              = (int) $booking_id;
            $booking_date            = (string) ( $booking_or_insert_data['booking_date'] ?? '' );
            $time_start              = substr( (string) ( $booking_or_insert_data['booking_time_start'] ?? '' ), 0, 5 );
            $time_end                = substr( (string) ( $booking_or_insert_data['booking_time_end'] ?? '' ), 0, 5 );
            $service_type            = (string) ( $booking_or_insert_data['service_type'] ?? '' );
            $client_name             = (string) ( $booking_or_insert_data['client_name'] ?? '' );
            $client_email            = (string) ( $booking_or_insert_data['client_email'] ?? '' );
            $client_phone            = (string) ( $booking_or_insert_data['client_phone'] ?? '' );
            $client_nationality      = (string) ( $booking_or_insert_data['client_nationality'] ?? '' );
            $client_dob              = (string) ( $booking_or_insert_data['client_dob'] ?? '' );
            $client_country          = (string) ( $booking_or_insert_data['client_country'] ?? '' );
            $client_state            = (string) ( $booking_or_insert_data['client_state'] ?? '' );
            $client_university       = (string) ( $booking_or_insert_data['client_university'] ?? '' );
            $client_course_level     = (string) ( $booking_or_insert_data['client_course_level'] ?? '' );
            $client_course_major     = (string) ( $booking_or_insert_data['client_course_major'] ?? '' );
            $client_course_completion = (string) ( $booking_or_insert_data['client_course_completion'] ?? '' );
            $client_visa_type        = (string) ( $booking_or_insert_data['client_visa_type'] ?? '' );
            $client_visa_expiry      = (string) ( $booking_or_insert_data['client_visa_expiry'] ?? '' );
            $client_occupation       = (string) ( $booking_or_insert_data['client_occupation'] ?? '' );
            $client_contact_link     = (string) ( $booking_or_insert_data['client_contact_link'] ?? '' );
            $client_referral_source  = (string) ( $booking_or_insert_data['client_referral_source'] ?? '' );
            $notes                   = (string) ( $booking_or_insert_data['notes'] ?? '' );
        }

        $response_data = [
            'success'                  => true,
            'booking_id'               => $booking_id,
            'message'                  => '' !== $message ? $message : __( 'Your appointment has been booked successfully! A confirmation email has been sent.', 'racc-booking' ),
            'booking_date'             => $booking_date,
            'time_start'               => $time_start,
            'time_end'                 => $time_end,
            'service_type'             => $service_type,
            'agent_name'               => $agent->name ?? '',
            'agent_timezone'           => ! empty( $agent->timezone ) ? $agent->timezone : 'UTC',
            'client_name'              => $client_name,
            'client_email'             => $client_email,
            'client_phone'             => $client_phone,
            'client_nationality'       => $client_nationality,
            'client_dob'               => $client_dob,
            'client_country'           => $client_country,
            'client_state'             => $client_state,
            'client_university'        => $client_university,
            'client_course_level'      => $client_course_level,
            'client_course_major'      => $client_course_major,
            'client_course_completion' => $client_course_completion,
            'client_visa_type'         => $client_visa_type,
            'client_visa_expiry'       => $client_visa_expiry,
            'client_occupation'        => $client_occupation,
            'client_contact_link'      => $client_contact_link,
            'client_referral_source'   => $client_referral_source,
            'notes'                    => $notes,
        ];

        /**
         * Filter the REST response after a booking is created.
         * The racc-booking-woo bridge injects a checkout_url here.
         *
         * @param array $response_data
         * @param int   $booking_id
         */
        return apply_filters( 'racc_booking_created_response', $response_data, $booking_id );
    }

    /**
     * Normalize date into YYYY-MM-DD.
     *
     * Returns empty string when invalid.
     *
     * @param mixed $date Raw date value.
     * @return string
     */
    private function normalize_ymd_date( $date ) {
        $date = trim( (string) $date );
        if ( '' === $date ) {
            return '';
        }

        if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
            $year  = (int) $m[1];
            $month = (int) $m[2];
            $day   = (int) $m[3];

            if ( $year < 1900 || ! checkdate( $month, $day, $year ) ) {
                return '';
            }

            return sprintf( '%04d-%02d-%02d', $year, $month, $day );
        }

        return '';
    }

    /**
     * Normalize an optional date into YYYY-MM-DD, or null when omitted/invalid.
     *
     * @param mixed $date Raw date value.
     * @return string|null
     */
    private function normalize_nullable_ymd_date( $date ) {
        $date = $this->normalize_ymd_date( $date );
        return '' === $date ? null : $date;
    }

    /**
     * Normalize time into HH:MM (24-hour).
     *
     * Supports HH:MM, HH:MM:SS, and h:mm AM/PM.
     * Returns empty string when invalid.
     *
     * @param mixed $time Raw time value.
     * @return string
     */
    private function normalize_hhmm_time( $time ) {
        $time = trim( (string) $time );
        if ( '' === $time ) {
            return '';
        }

        if ( preg_match( '/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $m ) ) {
            $hour   = (int) $m[1];
            $minute = (int) $m[2];

            if ( $hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 ) {
                return '';
            }

            return sprintf( '%02d:%02d', $hour, $minute );
        }

        if ( preg_match( '/^(\d{1,2}):(\d{2})\s*([AaPp][Mm])$/', $time, $m ) ) {
            $hour   = (int) $m[1];
            $minute = (int) $m[2];
            $ampm   = strtoupper( $m[3] );

            if ( $hour < 1 || $hour > 12 || $minute < 0 || $minute > 59 ) {
                return '';
            }

            if ( 'PM' === $ampm && $hour < 12 ) {
                $hour += 12;
            }
            if ( 'AM' === $ampm && 12 === $hour ) {
                $hour = 0;
            }

            return sprintf( '%02d:%02d', $hour, $minute );
        }

        return '';
    }

    /**
     * Resolve slot duration from request context.
     *
     * @param \WP_REST_Request $request
     * @param int              $agent_id
     * @return int
     */
    private function resolve_slot_duration_from_request( \WP_REST_Request $request, $agent_id = 0 ) {
        $woo_product_id = absint( $request->get_param( 'woo_product_id' ) );

        if ( $woo_product_id <= 0 ) {
            $service_type = sanitize_text_field( (string) ( $request->get_param( 'service_type' ) ?? '' ) );
            if ( '' !== $service_type ) {
                $woo_product_id = $this->find_woo_product_id_by_service_name( $service_type );
            }
        }

        return $this->resolve_slot_duration( $woo_product_id, (int) $agent_id );
    }

    /**
     * Resolve slot duration from Woo product with consultant/default fallback.
     *
     * @param int $woo_product_id
     * @param int $agent_id
     * @return int
     */
    private function resolve_slot_duration( $woo_product_id, $agent_id = 0 ) {
        $duration = 0;

        if ( $woo_product_id > 0 ) {
            $duration = absint( get_post_meta( $woo_product_id, '_racc_booking_slot_duration', true ) );
        }

        if ( $duration <= 0 && $agent_id > 0 ) {
            global $wpdb;
            $duration = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT slot_duration FROM {$wpdb->prefix}racc_agents WHERE id = %d",
                $agent_id
            ) );
        }

        if ( $duration <= 0 ) {
            $settings = get_option( 'racc_booking_settings', [] );
            $duration = absint( $settings['slot_duration'] ?? 60 );
        }

        return $duration > 0 ? $duration : 60;
    }

    /**
     * Find WooCommerce booking product ID by service name.
     *
     * @param string $service_name
     * @return int
     */
    private function find_woo_product_id_by_service_name( $service_name ) {
        $service_name = trim( (string) $service_name );
        if ( '' === $service_name || ! function_exists( 'wc_get_products' ) ) {
            return 0;
        }

        $woo_bridge_settings = get_option( 'racc_woo_bridge_settings', [] );
        $woo_category_slug   = $woo_bridge_settings['category_slug'] ?? 'booking-services';
        $products            = wc_get_products( [
            'category' => [ $woo_category_slug ],
            'status'   => 'publish',
            'limit'    => -1,
        ] );

        foreach ( $products as $product ) {
            if ( strtolower( (string) $product->get_name() ) === strtolower( $service_name ) ) {
                return (int) $product->get_id();
            }
        }

        return 0;
    }

    /**
     * GET /racc/v1/nearest-available
     *
     * Find the nearest date (on or after `from`) that has available slots
     * for any of the given agents.
     *
     * Query params:
     *   agent_ids  string  Comma-separated agent IDs (required).
     *   from       string  Y-m-d start date (optional; defaults to today).
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_nearest_available( \WP_REST_Request $request ) {
        $agent_ids_raw = sanitize_text_field( (string) ( $request->get_param( 'agent_ids' ) ?? '' ) );
        $from          = sanitize_text_field( (string) ( $request->get_param( 'from' ) ?: current_time( 'Y-m-d' ) ) );
        $slot_duration = $this->resolve_slot_duration_from_request( $request, 0 );

        $agent_ids = [];
        foreach ( explode( ',', $agent_ids_raw ) as $raw_id ) {
            $id = absint( trim( $raw_id ) );
            if ( $id > 0 ) {
                $agent_ids[] = $id;
            }
        }

        if ( empty( $agent_ids ) ) {
            return new \WP_Error( 'no_agents', __( 'No agents specified.', 'racc-booking' ), [ 'status' => 400 ] );
        }

        // Never search in the past.
        $today = current_time( 'Y-m-d' );
        if ( $from < $today ) {
            $from = $today;
        }

        $gcal = new Google_Calendar();
        $date = $gcal->find_nearest_available_date( $agent_ids, $from, 60, $slot_duration );

        return rest_ensure_response( [
            'date'      => $date,
            'formatted' => $date ? $this->format_date_friendly( $date ) : null,
        ] );
    }

    /**
     * GET /racc/v1/availability-calendar
     *
     * Returns the list of dates in a given month that have at least one
     * available slot for any of the given agents.
     *
     * Query params:
     *   agent_ids  string   Comma-separated agent IDs (required).
     *   year       integer  Four-digit year (required).
     *   month      integer  Month 1–12 (required).
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_availability_calendar( \WP_REST_Request $request ) {
        $agent_ids_raw = sanitize_text_field( (string) ( $request->get_param( 'agent_ids' ) ?? '' ) );
        $year          = absint( $request->get_param( 'year' ) );
        $month         = absint( $request->get_param( 'month' ) );
        $slot_duration = $this->resolve_slot_duration_from_request( $request, 0 );

        if ( $year < 2020 || $year > 2100 || $month < 1 || $month > 12 ) {
            return new \WP_Error( 'invalid_params', __( 'Invalid year or month.', 'racc-booking' ), [ 'status' => 400 ] );
        }

        $agent_ids = [];
        foreach ( explode( ',', $agent_ids_raw ) as $raw_id ) {
            $id = absint( trim( $raw_id ) );
            if ( $id > 0 ) {
                $agent_ids[] = $id;
            }
        }

        if ( empty( $agent_ids ) ) {
            return new \WP_Error( 'no_agents', __( 'No agents specified.', 'racc-booking' ), [ 'status' => 400 ] );
        }

        $start_date = sprintf( '%04d-%02d-01', $year, $month );
        $end_date   = date( 'Y-m-t', mktime( 0, 0, 0, $month, 1, $year ) ); // last day of month

        $gcal          = new Google_Calendar();
        $all_available = [];

        foreach ( $agent_ids as $agent_id ) {
            $dates = $gcal->get_available_dates_in_range( $agent_id, $start_date, $end_date, $slot_duration );
            foreach ( $dates as $d ) {
                $all_available[ $d ] = true;
            }
        }

        ksort( $all_available );

        return rest_ensure_response( [
            'year'            => $year,
            'month'           => $month,
            'available_dates' => array_keys( $all_available ),
        ] );
    }

    /**
     * Format a Y-m-d date in a friendly, locale-aware human-readable form.
     *
     * Uses IntlDateFormatter when available (yields full locale-native strings
     * such as "Senin, 15 Juni 2026" for id_ID), with a WP date_i18n() fallback.
     *
     * @param string $date Y-m-d date string.
     * @return string
     */
    private function format_date_friendly( $date ) {
        $timestamp = strtotime( $date );

        if ( class_exists( 'IntlDateFormatter' ) ) {
            try {
                $fmt = new \IntlDateFormatter(
                    get_locale(),
                    \IntlDateFormatter::FULL,
                    \IntlDateFormatter::NONE,
                    wp_timezone()
                );
                if ( $fmt ) {
                    return $fmt->format( $timestamp );
                }
            } catch ( \Exception $e ) {
                // fall through to WP fallback
            }
        }

        // WP fallback — uses the site's translation strings for month/day names.
        return date_i18n( 'l, j F Y', $timestamp );
    }

    /**
     * Store the residence field as an Australian state, or Offshore for all other countries.
     *
     * @param mixed $value Submitted value.
     * @return string
     */
    private function normalize_residence_state_value( $value ) {
        $value = sanitize_text_field( (string) $value );

        $allowed_states = [
            'Australian Capital Territory',
            'New South Wales',
            'Northern Territory',
            'Queensland',
            'South Australia',
            'Tasmania',
            'Victoria',
            'Western Australia',
        ];

        if ( in_array( $value, $allowed_states, true ) ) {
            return $value;
        }

        return '';
    }
}
