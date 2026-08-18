<?php
/**
 * AgentCIS Integration — sync bookings to AgentCIS CRM.
 *
 * @package RACC_Booking
 */

namespace RACC_Booking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Agentcis {

    /** @var string */
    private $api_key;

    /** @var string */
    private $api_base = '';

    /** @var string */
    private $log_file;

    /** @var int */
    private $last_http_code = 0;

    /** @var int */
    private $last_retry_after = 0;

    /** @var string */
    private $last_error_code = '';

    public function __construct() {
        $this->api_key  = $this->normalize_api_key( (string) get_option( 'racc_agentcis_api_key', '' ) );
        $this->api_base = untrailingslashit( (string) get_option( 'racc_agentcis_api_base', '' ) );
        $this->log_file = WP_CONTENT_DIR . '/logs/racc-agentcis.log';

        // Booking lifecycle hooks
        add_action( 'racc_booking_created',     [ $this, 'sync_booking' ],   10, 1 );
        add_action( 'racc_booking_rescheduled', [ $this, 'update_booking' ], 10, 1 );
        add_action( 'racc_booking_reassigned',  [ $this, 'update_booking' ], 10, 1 );
        add_action( 'racc_booking_cancelled',   [ $this, 'cancel_booking' ], 10, 1 );

        // AJAX handlers
        add_action( 'wp_ajax_racc_agentcis_manual_sync', [ $this, 'ajax_manual_sync' ] );
        add_action( 'wp_ajax_racc_agentcis_retry_sync',  [ $this, 'ajax_retry_sync' ] );
        add_action( 'wp_ajax_racc_get_agentcis_logs',    [ $this, 'ajax_get_logs' ] );
        add_action( 'wp_ajax_racc_agentcis_test_connection', [ $this, 'ajax_test_connection' ] );
    }

    // ─── Public helpers ──────────────────────────────────────────────────────────

    public function is_configured() {
        return ! empty( $this->api_key ) && ! empty( $this->api_base );
    }

    public function get_api_base() {
        return $this->api_base;
    }

    // ─── Booking lifecycle ───────────────────────────────────────────────────────

    /**
     * Sync a new booking to AgentCIS as a contact.
     * Called via `do_action( 'racc_booking_created', $booking_id )`.
     *
     * @param int $booking_id
     * @return bool
     */
    public function sync_booking( $booking_id ) {
        if ( ! $this->is_configured() ) {
            $this->log( "API key not configured — skipping sync for booking #$booking_id", 'warning' );
            return false;
        }

        $booking = $this->get_booking( $booking_id );
        if ( ! $booking ) {
            $this->log( "Booking #$booking_id not found", 'error' );
            return false;
        }

        if ( 'pending_payment' === (string) ( $booking->status ?? '' ) ) {
            $this->log( "Skipping AgentCIS sync for booking #$booking_id until payment is completed.", 'info' );
            return true;
        }

        $this->log( "Syncing booking #$booking_id ({$booking->client_name})" );

        $sync_warnings = [];
        $is_clients_mode = $this->is_clients_api_mode();
        $payload         = $is_clients_mode ? $this->build_clients_payload( $booking ) : $this->build_payload( $booking );

        $missing_required = $this->validate_required_fields( $payload, $is_clients_mode ? 'clients' : 'online_form' );
        if ( ! empty( $missing_required ) ) {
            $message = sprintf(
                /* translators: %s: comma separated field names */
                __( 'Missing required AgentCIS fields: %s', 'racc-booking' ),
                implode( ', ', $missing_required )
            );
            $this->update_sync_status( $booking_id, 'failed', null, $message );
            $this->log( "Sync blocked for #$booking_id: $message", 'error' );
            return false;
        }

        $submit_method   = 'POST';
        $submit_endpoint = $is_clients_mode ? $this->get_clients_create_endpoint() : '';
        $contact_id_hint = '';

        if ( $is_clients_mode ) {
            $local_contact_id = $this->resolve_existing_contact_id_for_booking( $booking_id, $booking );
            $contact_id_hint  = $local_contact_id;

            if ( '' !== $local_contact_id ) {
                $email = trim( (string) ( $booking->client_email ?? '' ) );
                if ( '' !== $email ) {
                    $api_contact_id = $this->find_contact_id_by_email_from_agentcis( $email );
                    if ( '' !== $api_contact_id && $api_contact_id !== $local_contact_id ) {
                        $this->log( "AgentCIS contact ID mismatch for booking #$booking_id (Local: $local_contact_id, API: $api_contact_id). Updating local DB.", 'warning' );
                        global $wpdb;
                        $wpdb->update( "{$wpdb->prefix}racc_bookings", [ 'agentcis_contact_id' => $api_contact_id ], [ 'id' => $booking_id ] );
                        $contact_id_hint = $api_contact_id;
                    }
                }
            }

            if ( '' !== $contact_id_hint ) {
                $submit_method   = 'PUT';
                $submit_endpoint = $this->get_clients_update_endpoint( $contact_id_hint );
                $payload         = $this->maybe_add_assignee_to_clients_update_payload( $payload, $booking );
                $this->log( "Existing AgentCIS contact detected for booking #$booking_id (Contact ID: $contact_id_hint). Sending update (PUT) so the phone can be overwritten.", 'info' );
            } else {
                $this->log( "Clients API mode for booking #$booking_id: sending a single create request (POST) with system + custom fields.", 'info' );
            }
        }

        $response = $this->request( $submit_method, $submit_endpoint, $payload );

        $duplicate_retry = $this->maybe_retry_duplicate_contact_as_update(
            $response,
            $booking_id,
            $booking,
            $payload,
            $submit_method,
            $submit_endpoint,
            $contact_id_hint
        );
        $response        = $duplicate_retry['response'];
        $payload         = $duplicate_retry['payload'];
        $submit_method   = $duplicate_retry['method'];
        $submit_endpoint = $duplicate_retry['endpoint'];
        $contact_id_hint = $duplicate_retry['contact_id'];
        if ( ! empty( $duplicate_retry['warnings'] ) ) {
            $sync_warnings = array_merge( $sync_warnings, $duplicate_retry['warnings'] );
        }

        if ( $is_clients_mode && is_wp_error( $response ) && $this->is_invalid_phone_number_error( $response->get_error_message() ) && ! empty( $payload['phone'] ) ) {
            $retry_payload = $payload;
            unset( $retry_payload['phone'] );

            $this->log(
                "AgentCIS rejected phone.number for booking #$booking_id. Retrying without phone so other client data can still be updated.",
                'warning'
            );
            $sync_warnings[] = 'Warning: AgentCIS rejected the phone number (Invalid Format). The contact was saved without the phone number.';

            $response = $this->request( $submit_method, $submit_endpoint, $retry_payload );
            $payload  = $retry_payload;

            $duplicate_retry = $this->maybe_retry_duplicate_contact_as_update(
                $response,
                $booking_id,
                $booking,
                $payload,
                $submit_method,
                $submit_endpoint,
                $contact_id_hint
            );
            $response        = $duplicate_retry['response'];
            $payload         = $duplicate_retry['payload'];
            $submit_method   = $duplicate_retry['method'];
            $submit_endpoint = $duplicate_retry['endpoint'];
            $contact_id_hint = $duplicate_retry['contact_id'];
            if ( ! empty( $duplicate_retry['warnings'] ) ) {
                $sync_warnings = array_merge( $sync_warnings, $duplicate_retry['warnings'] );
            }
        }

        if ( is_wp_error( $response ) ) {
            if ( $is_clients_mode && $this->is_assignee_required_error( $response->get_error_message() ) ) {
                $message = $this->get_assignee_required_message();
                $this->update_sync_status( $booking_id, 'failed', null, $message );
                $this->log( "Sync failed for #$booking_id: $message", 'error' );
                return false;
            }

            $this->update_sync_status( $booking_id, 'failed', null, $response->get_error_message() );
            $this->log( "Sync failed for #$booking_id: " . $response->get_error_message(), 'error' );
            return false;
        }

        $body       = json_decode( wp_remote_retrieve_body( $response ), true );
        $contact_id = $body['data']['id'] ?? ( $body['id'] ?? ( $body['submission_id'] ?? null ) );

        if ( ! $contact_id && '' !== $contact_id_hint ) {
            $contact_id = $contact_id_hint;
        }

        if ( $contact_id ) {
            $error_val = empty( $sync_warnings ) ? '' : implode( "\n", $sync_warnings );
            $this->update_sync_status( $booking_id, 'synced', (string) $contact_id, $error_val );
            $this->log( "✅ Booking #$booking_id synced. Contact ID: $contact_id" );

            // Attempt to sync education background for Clients API mode only
            if ( $this->is_clients_api_mode() ) {
                $this->sync_education_background( $booking_id, (string) $contact_id );
            }

            return true;
        }

        $this->update_sync_status( $booking_id, 'synced', '', '' );
        $this->log( "✅ Booking #$booking_id submitted to AgentCIS online form" );
        return true;
    }

    /**
     * Retry sync with up to $max attempts.
     *
     * @param int $booking_id
     * @param int $max
     * @return bool
     */
    public function sync_booking_with_retry( $booking_id, $max = 3 ) {
        for ( $i = 0; $i < $max; $i++ ) {
            if ( $this->sync_booking( $booking_id ) ) {
                return true;
            }

            // Do not spam retries on hard failures / throttling.
            if ( in_array( $this->last_http_code, [ 401, 403, 422, 429 ], true ) ) {
                if ( 429 === $this->last_http_code && $this->last_retry_after > 0 ) {
                    $this->log( "Rate limited for booking #$booking_id. Retry after {$this->last_retry_after}s.", 'warning' );
                }
                if ( 422 === $this->last_http_code ) {
                    $this->log( "Stop retry for booking #$booking_id: validation failed on AgentCIS (HTTP 422).", 'warning' );
                }
                break;
            }

            // Do not retry if all payload variants already failed with server error.
            if ( 'agentcis_form_mismatch' === $this->last_error_code ) {
                $this->log( "Stop retry for booking #$booking_id: payload/form mismatch likely on AgentCIS side.", 'warning' );
                break;
            }

            if ( $i < $max - 1 ) {
                sleep( 2 );
                $this->log( "Retry " . ( $i + 1 ) . "/$max for booking #$booking_id" );
            }
        }
        $this->log( "All retries exhausted for booking #$booking_id", 'error' );
        return false;
    }

    /**
     * Update AgentCIS contact after a booking is rescheduled.
     * Called via `do_action( 'racc_booking_rescheduled', $booking_id )`.
     *
     * @param int $booking_id
     * @return bool
     */
    public function update_booking( $booking_id ) {
        if ( ! $this->is_configured() ) {
            return false;
        }

        if ( ! $this->is_clients_api_mode() ) {
            $this->log( "ℹ️ Booking #$booking_id rescheduled locally. AgentCIS online-form endpoint is submit-only, so no update request was sent.", 'info' );
            return true;
        }

        $this->log( "Booking #$booking_id updated locally. Syncing latest client data to AgentCIS Clients API.", 'info' );

        return $this->sync_booking( $booking_id );
    }

    /**
     * Mark the AgentCIS contact as cancelled.
     * Called via `do_action( 'racc_booking_cancelled', $booking_id )`.
     *
     * @param int $booking_id
     * @return bool
     */
    public function cancel_booking( $booking_id ) {
        if ( ! $this->is_configured() ) {
            return false;
        }

        $this->log( "ℹ️ Booking #$booking_id cancelled locally. AgentCIS online-form endpoint is submit-only, so no cancel request was sent.", 'info' );
        return true;
    }

    // ─── AJAX handlers ───────────────────────────────────────────────────────────

    public function ajax_manual_sync() {
        check_ajax_referer( 'racc_agentcis_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ] );
        }

        $booking_id = absint( $_POST['booking_id'] ?? 0 );
        if ( ! $booking_id ) {
            wp_send_json_error( [ 'message' => 'Invalid booking ID' ] );
        }

        if ( $this->sync_booking_with_retry( $booking_id ) ) {
            global $wpdb;
            $contact_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT agentcis_contact_id FROM {$wpdb->prefix}racc_bookings WHERE id = %d",
                $booking_id
            ) );
            wp_send_json_success( [
                'message'    => '✅ Synced successfully!',
                'contact_id' => $contact_id,
            ] );
        } else {
            global $wpdb;
            $sync_error = $wpdb->get_var( $wpdb->prepare(
                "SELECT agentcis_sync_error FROM {$wpdb->prefix}racc_bookings WHERE id = %d",
                $booking_id
            ) );

            $payload = [
                'message' => ! empty( $sync_error ) ? $sync_error : '❌ Sync failed. Check logs for details.',
            ];

            if ( 429 === $this->last_http_code ) {
                $payload['code']        = 'agentcis_rate_limited';
                $payload['retry_after'] = $this->last_retry_after;
            }

            wp_send_json_error( $payload );
        }
    }

    public function ajax_retry_sync() {
        check_ajax_referer( 'racc_agentcis_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ] );
        }

        $booking_id = absint( $_POST['booking_id'] ?? 0 );
        if ( ! $booking_id ) {
            wp_send_json_error( [ 'message' => 'Invalid booking ID' ] );
        }

        if ( isset( $_POST['agentcis_contact_id'] ) ) {
            global $wpdb;
            $new_contact_id = sanitize_text_field( wp_unslash( $_POST['agentcis_contact_id'] ) );
            $wpdb->update( 
                "{$wpdb->prefix}racc_bookings", 
                [ 'agentcis_contact_id' => $new_contact_id ], 
                [ 'id' => $booking_id ] 
            );
        }

        // Reset status before retry
        $this->update_sync_status( $booking_id, 'pending', null, '' );

        if ( $this->sync_booking_with_retry( $booking_id ) ) {
            global $wpdb;
            $contact_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT agentcis_contact_id FROM {$wpdb->prefix}racc_bookings WHERE id = %d",
                $booking_id
            ) );
            wp_send_json_success( [
                'message'    => '✅ Retry successful!',
                'contact_id' => $contact_id,
            ] );
        } else {
            global $wpdb;
            $sync_error = $wpdb->get_var( $wpdb->prepare(
                "SELECT agentcis_sync_error FROM {$wpdb->prefix}racc_bookings WHERE id = %d",
                $booking_id
            ) );

            $payload = [
                'message' => ! empty( $sync_error ) ? $sync_error : '❌ Retry failed. Check API key & connection.',
            ];

            if ( 429 === $this->last_http_code ) {
                $payload['code']        = 'agentcis_rate_limited';
                $payload['retry_after'] = $this->last_retry_after;
            }

            wp_send_json_error( $payload );
        }
    }

    public function ajax_get_logs() {
        check_ajax_referer( 'racc_agentcis_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        $logs = 'No logs yet.';
        if ( file_exists( $this->log_file ) ) {
            $lines = file( $this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
            $lines = array_slice( $lines, -100 );
            $logs  = implode( "\n", array_reverse( $lines ) );
        }

        wp_send_json_success( [ 'logs' => $logs ] );
    }

    public function ajax_test_connection() {
        check_ajax_referer( 'racc_agentcis_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ] );
        }

        $validation = $this->validate_api_base();
        if ( is_wp_error( $validation ) ) {
            wp_send_json_error( [ 'message' => $validation->get_error_message() ] );
        }

        $is_clients_mode = $this->is_clients_api_mode();
        $url             = $is_clients_mode ? $this->get_clients_list_endpoint() : $this->api_base;

        if ( $is_clients_mode ) {
            $response = wp_remote_post( $url, [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Accept'        => 'application/json',
                ],
                'body'    => [
                    'page'     => 1,
                    'per_page' => 1,
                ],
            ] );
        } else {
            $response = wp_remote_get( $url, [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Accept'        => 'application/json',
                ],
            ] );
        }

        if ( is_wp_error( $response ) ) {
            $err = $response->get_error_message();

            // Local/dev fallback for SSL issues.
            if ( false !== stripos( $err, 'SSL' ) || false !== stripos( $err, 'certificate' ) ) {
                $retry = wp_remote_get( $url, [
                    'timeout'   => 15,
                    'sslverify' => false,
                    'headers'   => [
                        'Authorization' => 'Bearer ' . $this->api_key,
                        'Accept'        => 'application/json',
                    ],
                ] );

                if ( ! is_wp_error( $retry ) ) {
                    $status_code = wp_remote_retrieve_response_code( $retry );
                    $this->log( 'Connection test OK via sslverify=false: ' . $url . ' [' . $status_code . ']', 'warning' );
                    wp_send_json_success( [
                        'message'     => __( 'Endpoint reachable, but local SSL verification failed. This is common on local dev environments.', 'racc-booking' ),
                        'status_code' => $status_code,
                        'url'         => $url,
                        'preview'     => '',
                    ] );
                }
            }

            if ( false !== stripos( $err, 'cURL error 6' ) ) {
                $host = (string) wp_parse_url( $url, PHP_URL_HOST );
                $err  = sprintf(
                    /* translators: %s: host */
                    __( 'DNS lookup failed from PHP for host: %s. Check LocalWP/PHP network DNS settings.', 'racc-booking' ),
                    $host
                );
            }

            $this->log( 'Connection test failed: ' . $err, 'error' );
            wp_send_json_error( [ 'message' => $err ] );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $message     = sprintf(
            /* translators: %d: HTTP status code */
            __( 'Host reachable. HTTP %d received.', 'racc-booking' ),
            $status_code
        );

        if ( ! $is_clients_mode && 405 === $status_code ) {
            $message = __( 'Endpoint reachable. HTTP 405 received, which is expected when testing a POST-only online-form URL with GET.', 'racc-booking' );
        }

        $preview = '';
        if ( is_string( $body ) && $body !== '' ) {
            $preview = wp_strip_all_tags( wp_trim_words( $body, 30, '…' ) );
        }

        // Additional POST probe: verifies auth and payload contract.
        $probe_status = 0;
        $probe_body   = '';
        if ( $is_clients_mode ) {
            $probe_resp = wp_remote_post( $url, [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Accept'        => 'application/json',
                ],
                'body'    => [
                    'page'     => 1,
                    'per_page' => 1,
                ],
            ] );
        } else {
            $probe_resp = $this->perform_request_with_fallbacks(
                'POST',
                $url,
                [
                    'first_name'    => 'Connection',
                    'last_name'     => 'Probe',
                    'date_of_birth' => '1900-13-40',
                ]
            );
        }

        if ( is_wp_error( $probe_resp ) ) {
            wp_send_json_error( [
                'message' => __( 'GET check passed but POST probe failed: ', 'racc-booking' ) . $probe_resp->get_error_message(),
            ] );
        }

        $probe_status = wp_remote_retrieve_response_code( $probe_resp );
        $probe_body   = (string) wp_remote_retrieve_body( $probe_resp );

        if ( 401 === $probe_status || 403 === $probe_status ) {
            wp_send_json_error( [
                'message' => __( 'Endpoint reachable, but authentication failed for POST. Verify AgentCIS API key/token.', 'racc-booking' ),
                'url'     => $url,
                'preview' => $probe_body,
            ] );
        }

        if ( $probe_status >= 500 ) {
            wp_send_json_error( [
                'message' => __( 'Endpoint reachable, but AgentCIS returned server error on POST. Usually caused by invalid payload format or form-field mismatch.', 'racc-booking' ),
                'url'     => $url,
                'preview' => $probe_body,
            ] );
        }

        if ( 200 !== $probe_status && 201 !== $probe_status && 202 !== $probe_status && 422 !== $probe_status ) {
            wp_send_json_error( [
                'message' => sprintf( __( 'Endpoint reachable, but unexpected POST response: HTTP %d', 'racc-booking' ), $probe_status ),
                'url'     => $url,
                'preview' => $probe_body,
            ] );
        }

        $this->log( 'Connection test OK: ' . $url . ' [' . $status_code . ']', 'info' );

        wp_send_json_success( [
            'message'     => $message . ' ' . sprintf( __( 'POST probe returned HTTP %d.', 'racc-booking' ), $probe_status ),
            'status_code' => $status_code,
            'url'         => $url,
            'preview'     => $preview,
        ] );
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    private function get_booking( $booking_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}racc_bookings WHERE id = %d",
            $booking_id
        ) );
    }

    private function build_payload( $booking ) {
        $name_parts = explode( ' ', trim( $booking->client_name ?? '' ), 2 );

        $first_name = trim( (string) ( $name_parts[0] ?? '' ) );
        $last_name  = trim( (string) ( $name_parts[1] ?? '' ) );

        if ( '' === $first_name ) {
            $first_name = 'Client';
        }

        // AgentCIS online-form requires last_name.
        // For single-word names, send a safe placeholder to avoid hard failure.
        if ( '' === $last_name ) {
            $last_name = '-';
        }

        $dob = '';
        if ( ! empty( $booking->client_dob ) ) {
            $timestamp = strtotime( (string) $booking->client_dob );
            if ( $timestamp ) {
                $dob = gmdate( 'Y-m-d', $timestamp );
            }
        }

        $course_completion = '';
        if ( ! empty( $booking->client_course_completion ) ) {
            $timestamp = strtotime( (string) $booking->client_course_completion );
            if ( $timestamp ) {
                $course_completion = gmdate( 'Y-m-d', $timestamp );
            }
        }

        $visa_expiry = '';
        if ( ! empty( $booking->client_visa_expiry ) ) {
            $timestamp = strtotime( (string) $booking->client_visa_expiry );
            if ( $timestamp ) {
                $visa_expiry = gmdate( 'Y-m-d', $timestamp );
            }
        }

        $residence_country_value = $this->get_booking_residence_country_value( $booking );
        $passport_country_value  = $this->get_booking_passport_country_value( $booking );

        $referral_source = trim( (string) ( $booking->client_referral_source ?? '' ) );

        $payload = [
            'first_name'          => $first_name,
            'last_name'           => $last_name,
            'dob'                 => $dob,
            'phone'               => $booking->client_phone ?? '',
            'email'               => $booking->client_email ?? '',
            'secondary_email'     => $booking->client_email ?? '',
            'country'             => $this->resolve_country_id( $residence_country_value ),
            'state'               => $booking->client_state ?? '',
            'country_of_passport' => $this->resolve_country_id( $passport_country_value ),
            'nationality'         => $booking->client_nationality ?? '',
            'tags'                => $referral_source,
            'university'          => $booking->client_university ?? '',
            'course'              => $booking->client_course_major ?? '',
            'course_level'        => $booking->client_course_level ?? '',
            'course_major'        => $booking->client_course_major ?? '',
            'course_completed_date' => $course_completion,
            'course_completion'   => $course_completion,
            'visa_type'           => $booking->client_visa_type ?? '',
            'visa_expiry_date'    => $visa_expiry,
            'occupation'          => $booking->client_occupation ?? '',
            'remarks_visa_to_apply' => $booking->client_occupation ?? '',
            'contact_link'        => $booking->client_contact_link ?? '',
            'where_did_you_hear_us_from' => $referral_source,
            'referral_source'     => $referral_source,
            'service_type'        => $booking->service_type ?? '',
        ];

        // Remove empty values to reduce validation/API errors on strict forms.
        $payload = array_filter(
            $payload,
            static function( $value ) {
                return '' !== trim( (string) $value );
            }
        );

        $this->append_custom_fields( $payload, $booking );

        /**
         * Allow payload customization before submit.
         *
         * @param array  $payload
         * @param object $booking
         */
        return apply_filters( 'racc_agentcis_payload', $payload, $booking );
    }

    /**
     * Build payload for AgentCIS Clients API (/api/v2/clients).
     *
     * @param object $booking
     * @return array
     */
    private function build_clients_payload( $booking ) {
        $name_parts = explode( ' ', trim( $booking->client_name ?? '' ), 2 );

        $first_name = trim( (string) ( $name_parts[0] ?? '' ) );
        $last_name  = trim( (string) ( $name_parts[1] ?? '' ) );

        if ( '' === $first_name ) {
            $first_name = 'Client';
        }
        if ( '' === $last_name ) {
            $last_name = '-';
        }

        $dob = $this->normalize_date_for_agentcis( $booking->client_dob ?? '' );

        $visa_expiry = '';
        if ( ! empty( $booking->client_visa_expiry ) ) {
            $timestamp = strtotime( (string) $booking->client_visa_expiry );
            if ( $timestamp ) {
                $visa_expiry = gmdate( 'Y-m-d', $timestamp );
            }
        }

        $residence_country_value = $this->get_booking_residence_country_value( $booking );
        $passport_country_value  = $this->get_booking_passport_country_value( $booking );
        $referral_source         = trim( (string) ( $booking->client_referral_source ?? '' ) );

        $payload = [
            'first_name'             => $first_name,
            'last_name'              => $last_name,
            'dob'                    => $dob,
            'email'                  => $booking->client_email ?? '',
            'first_point_of_contact' => 'email',
            'visa_type'              => $booking->client_visa_type ?? '',
            'visa_expiry_date'       => $visa_expiry,
            'country'                => $this->resolve_country_id( $residence_country_value ),
            'state'                  => $booking->client_state ?? '',
            'country_of_passport'    => $this->resolve_country_id( $passport_country_value ),
            'added_from'             => $booking->client_referral_source ?? '',
            'source_title'           => $referral_source,
            'tag'                    => $referral_source,
            'tags'                   => $this->resolve_tag_ids( $booking->client_referral_source ?? '' ),
        ];

        $assignee_id = $this->resolve_assignee_id_for_booking( $booking );
        if ( $assignee_id > 0 ) {
            $payload['assignee'] = $assignee_id;
        }

        // `dob` is optional in Clients API. Send it only if it looks safely valid.
        if ( '' !== $dob && $this->is_plausible_dob( $dob ) ) {
            $payload['dob'] = $dob;
        }

        $secondary_email = trim( (string) ( $booking->client_secondary_email ?? '' ) );
        $primary_email   = trim( (string) ( $booking->client_email ?? '' ) );
        if ( '' !== $secondary_email && 0 !== strcasecmp( $secondary_email, $primary_email ) ) {
            $payload['secondary_email'] = $secondary_email;
        }

        $phone = trim( (string) ( $booking->client_phone ?? '' ) );
        if ( '' !== $phone ) {
            $db_phone_iso      = trim( (string) ( $booking->client_phone_iso ?? '' ) );
            $db_phone_national = trim( (string) ( $booking->client_phone_national ?? '' ) );

            if ( '' !== $db_phone_iso && '' !== $db_phone_national ) {
                $phone_country_code = $db_phone_iso;
                $phone_number       = $db_phone_national;
            } else {
                $phone_country_code = $this->resolve_phone_country_code_for_booking( $booking );
                $phone_number       = $this->normalize_phone_for_agentcis_payload( $phone, $phone_country_code );
            }

            $payload['phone'] = [
                'number'       => $phone_number,
                'country_code' => $phone_country_code,
            ];
        }

        $payload = array_filter(
            $payload,
            static function( $value ) {
                if ( is_array( $value ) ) {
                    return ! empty( $value );
                }
                return '' !== trim( (string) $value );
            }
        );

        $this->append_custom_fields( $payload, $booking );

        return apply_filters( 'racc_agentcis_clients_payload', $payload, $booking );
    }

    /**
     * Append AgentCIS custom fields to the payload.
     *
     * UUIDs are hardcoded from assets/Custom-Fields Mapping.json.
     * Text / date  → custom_fields[{uuid}]   = scalar
     * Dropdown     → custom_fields[{uuid}][] = option_id  (array notation)
     *
     * @param array  $payload  Payload array (passed by reference).
     * @param object $booking  Booking row from DB.
     */
    private function append_custom_fields( array &$payload, $booking ) {

        // ── Academic History ─────────────────────────────────────────────────────

        // "University"  →  client_university
        $value = trim( (string) $this->get_booking_field_value( $booking, [ 'client_university', 'university' ] ) );
        if ( '' !== $value ) {
            $this->set_custom_field_value( $payload, 'a9bc4c0d-e8b0-433a-9dd9-76fbea8b6d14', $value );
        }

        // "Course"  →  client_course_major
        $value = trim( (string) $this->get_booking_field_value( $booking, [ 'client_course_major', 'client_course_level' ] ) );
        if ( '' !== $value ) {
            $this->set_custom_field_value( $payload, '89811458-a5d5-40ea-b7dc-bf6c9c0d9ce8', $value );
        }

        // "Course Completed Date (Date Format)"  →  client_course_completion  (Y-m-d)
        $course_date = $this->normalize_date_for_agentcis(
            $this->get_booking_field_value( $booking, [ 'client_course_completion', 'course_completed_date' ] )
        );
        if ( '' !== $course_date ) {
            $this->set_custom_field_value( $payload, 'c2882499-a082-47e6-98ff-e1b197d4cd84', $course_date );
        }

        // "Release Letter (if Transfer of School)"  →  same course-completion date
        if ( '' !== $course_date ) {
            $this->set_custom_field_value( $payload, 'e639df3d-0ad3-4432-84bf-42436d95e2e9', $course_date );
        }

        // ── Visa & Immigration ───────────────────────────────────────────────────

        // "Remarks (Visa to Apply)"  →  client_visa_type
        $visa = trim( (string) $this->get_booking_field_value( $booking, [ 'client_visa_type', 'visa_type' ] ) );
        if ( '' !== $visa ) {
            $this->set_custom_field_value( $payload, '66ba61f4-266c-47b6-84df-a51db8809c35', $visa );
        }

        // "ONSHORE/OFFSHORE"  →  derived from client_visa_type
        if ( '' !== $visa ) {
            $location_status = ( 'Offshore' === $visa ) ? 'OFFSHORE' : 'ONSHORE';
            $this->set_custom_field_value( $payload, '681a1a39-0154-4246-acd5-005b93e26d89', $location_status );
        }

        // ── Additional Information ───────────────────────────────────────────────

        // "Occupation"  →  client_occupation
        $value = trim( (string) $this->get_booking_field_value( $booking, [ 'client_occupation', 'occupation' ] ) );
        if ( '' !== $value ) {
            $this->set_custom_field_value( $payload, 'c4776933-921a-4619-a445-e4e21dff76b8', $value );
        }

        // "If you are outside Australia, please provide your WhatsApp/Viber/Messenger link"
        //   →  client_contact_link
        $value = trim( (string) $this->get_booking_field_value( $booking, [ 'client_contact_link', 'contact_link' ] ) );
        if ( '' !== $value ) {
            $this->set_custom_field_value( $payload, 'e5012484-80fd-40d8-a95b-6ca9dbda8527', $value );
        }



        // "Where did you hear us from?"  →  client_referral_source
        $value = trim( (string) $this->get_booking_field_value( $booking, [ 'client_referral_source', 'referral_source' ] ) );
        if ( '' !== $value ) {
            $this->set_custom_field_value( $payload, '8a8f480f-c406-4eab-82e1-d48bfe77faf8', $value );
        }

        // ── Booking / Enquiry ────────────────────────────────────────────────────

        // "Service From Website"  →  service_type
        $value = trim( (string) $this->get_booking_field_value( $booking, [ 'service_type' ] ) );
        if ( '' !== $value ) {
            $this->set_custom_field_value( $payload, 'cf2be41e-b95e-4e93-bb70-7fb712f285b1', $value );
        }

        // "What is your enquiry for the consultation?"  →  notes
        $value = trim( (string) $this->get_booking_field_value( $booking, [ 'notes', 'enquiry' ] ) );
        if ( '' !== $value ) {
            $this->set_custom_field_value( $payload, 'fb0003e8-0f0f-4526-9978-b1290504d05b', $value );
        }



        // "Event and Consultation Date"  →  booking_date (Y-m-d)
        $booking_date = $this->normalize_date_for_agentcis( $booking->booking_date ?? '' );
        if ( '' !== $booking_date ) {
            $this->set_custom_field_value( $payload, '1fef0a22-0b9c-4d4c-af31-3efe0c64b0fc', $booking_date );
        }

        // ── Contact (Cold Caller / Consultant) ───────────────────────────────────
        // UUID is hardcoded from JSON; value for Cold Caller ID is the agent option-ID integer
        // (dropdown field in AgentCIS requires the [] array notation).

        // "Cold Caller ID"  →  agent_id  (dropdown option ID)
        $cf_cold_caller_id = trim( (string) get_option( 'racc_agentcis_cf_cold_caller_id', '1cf9fdae-ed01-4659-bd5f-3db647f43b05' ) );
        if ( '' !== $cf_cold_caller_id ) {
            $agent_id = (int) ( $booking->agent_id ?? 0 );
            if ( $agent_id > 0 ) {
                $this->set_custom_field_value( $payload, $cf_cold_caller_id, [ $agent_id ] );
            }
        }

        // "Cold Caller Last Contact Date"  →  booking_date
        $cf_cold_caller_date = trim( (string) get_option( 'racc_agentcis_cf_cold_caller_date', '2bc6bdb1-ba5f-4960-a2b1-3011283f18d9' ) );
        if ( '' !== $cf_cold_caller_date && '' !== $booking_date ) {
            $this->set_custom_field_value( $payload, $cf_cold_caller_date, $booking_date );
        }

        // "Consultant Latest Contact Date"  →  booking_date
        $cf_consultant_date = trim( (string) get_option( 'racc_agentcis_cf_consultant_date', '493b27f2-75ec-4439-9d17-8b1dff2e4039' ) );
        if ( '' !== $cf_consultant_date && '' !== $booking_date ) {
            $this->set_custom_field_value( $payload, $cf_consultant_date, $booking_date );
        }

        // "State / Province" -> client_state
        $cf_state = trim( (string) get_option( 'racc_agentcis_cf_state', '' ) );
        $client_state = trim( (string) $this->get_booking_field_value( $booking, [ 'client_state', 'state' ] ) );
        if ( '' !== $cf_state && '' !== $client_state ) {
            $this->set_custom_field_value( $payload, $cf_state, $client_state );
        }

        // Remove the top-level custom_fields key entirely if nothing was mapped.
        if ( isset( $payload['custom_fields'] ) && empty( $payload['custom_fields'] ) ) {
            unset( $payload['custom_fields'] );
        }
    }

    /**
     * Set AgentCIS custom field in both payload formats for compatibility.
     *
     * @param array        $payload Payload passed by reference.
     * @param string       $uuid    Custom field UUID.
     * @param string|array $value   Field value.
     * @return void
     */
    private function set_custom_field_value( array &$payload, $uuid, $value ) {
        $uuid = trim( (string) $uuid );
        if ( '' === $uuid ) {
            return;
        }

        if ( is_array( $value ) ) {
            if ( empty( $value ) ) {
                return;
            }
            $payload['custom_fields'][ $uuid ] = $value;
            $payload[ $uuid ]                  = $value;
            return;
        }

        $value = trim( (string) $value );
        if ( '' === $value ) {
            return;
        }

        $payload['custom_fields'][ $uuid ] = $value;
        $payload[ $uuid ]                  = $value;
    }

    /**
     * Get first non-empty booking field value from candidate keys.
     *
     * @param object|array $booking
     * @param array        $keys
     * @return string
     */
    private function get_booking_field_value( $booking, array $keys ) {
        foreach ( $keys as $key ) {
            $value = '';

            if ( is_object( $booking ) && isset( $booking->{$key} ) ) {
                $value = (string) $booking->{$key};
            } elseif ( is_array( $booking ) && isset( $booking[ $key ] ) ) {
                $value = (string) $booking[ $key ];
            }

            $value = trim( $value );
            if ( '' !== $value ) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Resolve booking residence country value with fallback keys.
     *
     * @param object|array $booking
     * @return string
     */
    private function get_booking_residence_country_value( $booking ) {
        return $this->get_booking_field_value( $booking, [ 'client_country', 'country', 'client_nationality' ] );
    }

    /**
     * Resolve booking passport-country value with fallback keys.
     *
     * Priority favors explicit passport field names if available,
     * then falls back to residence country and nationality.
     *
     * @param object|array $booking
     * @return string
     */
    private function get_booking_passport_country_value( $booking ) {
        return $this->get_booking_field_value( $booking, [
            'client_country_of_passport',
            'client_passport_country',
            'country_of_passport',
            'passport_country',
            'country',
            'client_nationality',
        ] );
    }

    /**
     * Validate required online-form fields.
     *
     * @param array $payload
     * @return array
     */
    private function validate_required_fields( $payload, $mode = 'online_form' ) {
        $required = [ 'first_name', 'last_name', 'date_of_birth' ];
        if ( 'clients' === $mode ) {
            $required = [ 'first_name', 'last_name', 'email', 'first_point_of_contact' ];
        }
        $missing  = [];

        foreach ( $required as $field ) {
            if ( 'online_form' === $mode && 'date_of_birth' === $field ) {
                $date_of_birth = isset( $payload['date_of_birth'] ) ? trim( (string) $payload['date_of_birth'] ) : '';
                $dob           = isset( $payload['dob'] ) ? trim( (string) $payload['dob'] ) : '';

                if ( '' === $date_of_birth && '' === $dob ) {
                    $missing[] = $field;
                }

                continue;
            }

            $value = isset( $payload[ $field ] ) ? trim( (string) $payload[ $field ] ) : '';
            if ( '' === $value ) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * Update AgentCIS sync status columns in DB.
     *
     * @param int         $booking_id
     * @param string      $status      'pending'|'synced'|'failed'
     * @param string|null $contact_id
     * @param string|null $error
     */
    public function update_sync_status( $booking_id, $status, $contact_id = null, $error = null ) {
        global $wpdb;
        $data = [ 
            'agentcis_sync_status' => $status,
            'agentcis_sync_at'     => current_time( 'mysql' )
        ];
        if ( $contact_id !== null ) {
            $data['agentcis_contact_id'] = $contact_id;
        }
        if ( $error !== null ) {
            $data['agentcis_sync_error'] = $error;
        }
        $wpdb->update( "{$wpdb->prefix}racc_bookings", $data, [ 'id' => $booking_id ] );
    }

    /**
    * Make an HTTP request to the AgentCIS online form endpoint.
     *
     * @param string $method   GET|POST|PUT|DELETE
    * @param string $endpoint Relative path or empty string when api_base is the full endpoint URL.
     * @param array  $payload
     * @return array|\WP_Error
     */
    private function request( $method, $endpoint, $payload = [] ) {
        $this->last_http_code   = 0;
        $this->last_retry_after = 0;
        $this->last_error_code  = '';

        $validation = $this->validate_api_base();
        if ( is_wp_error( $validation ) ) {
            $this->log( $validation->get_error_message(), 'error' );
            return $validation;
        }

        if ( empty( $endpoint ) ) {
            $url = $this->api_base;
        } elseif ( preg_match( '#^https?://#i', (string) $endpoint ) ) {
            $url = (string) $endpoint;
        } else {
            $url = $this->api_base . $endpoint;
        }

        $response = $this->perform_request_with_fallbacks( $method, $url, $payload );

        if ( is_wp_error( $response ) ) {
            $this->last_error_code = (string) $response->get_error_code();
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $this->last_http_code = (int) $code;

        if ( $code < 200 || $code >= 300 ) {
            $body = wp_remote_retrieve_body( $response );

            if ( 429 === (int) $code ) {
                $retry_after_header = wp_remote_retrieve_header( $response, 'retry-after' );
                $retry_after        = is_numeric( $retry_after_header ) ? (int) $retry_after_header : 0;
                $this->last_retry_after = max( 0, $retry_after );

                $message = __( 'AgentCIS rate limit reached (HTTP 429 Too Many Attempts). Please wait a moment before retrying.', 'racc-booking' );
                if ( $this->last_retry_after > 0 ) {
                    $message .= ' ' . sprintf( __( 'Retry after %d seconds.', 'racc-booking' ), $this->last_retry_after );
                }

                $this->log( "API Error (429): $body", 'error' );
                return new \WP_Error( 'agentcis_rate_limited', $message );
            }

            $this->log( "API Error ($code): $body", 'error' );
            return new \WP_Error( 'agentcis_api_error', "AgentCIS API Error $code: $body" );
        }

        return $response;
    }

    /**
     * Perform HTTP request with payload format fallbacks for AgentCIS online form.
     *
     * For POST we try:
     * 1) JSON body
     * 2) form-urlencoded body
     * 3) JSON wrapped as { data: payload }
     *
     * @param string $method
     * @param string $url
     * @param array  $payload
     * @return array|\WP_Error
     */
    private function perform_request_with_fallbacks( $method, $url, $payload = [] ) {
        $method = strtoupper( (string) $method );

        $base_headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Accept'        => 'application/json',
        ];
        $server_error_attempts = 0;

        $attempts = [];
        if ( 'POST' === $method || 'PUT' === $method ) {
            $is_clients_mode = $this->is_clients_api_mode();
            $expanded_payload_sets = [
                'mapped' => $payload,
            ];

            if ( ! $is_clients_mode ) {
                $expanded_payload_sets['minimal'] = $this->extract_minimal_payload( $payload );
            }

            $seen_payload_hashes = [];

            foreach ( $expanded_payload_sets as $payload_label => $active_payload ) {
                if ( empty( $active_payload ) ) {
                    continue;
                }

                // Skip duplicate variants.
                $payload_hash = md5( wp_json_encode( $active_payload ) );
                if ( isset( $seen_payload_hashes[ $payload_hash ] ) ) {
                    continue;
                }
                $seen_payload_hashes[ $payload_hash ] = true;

                $attempts[] = [
                    'label' => $payload_label . ':form-urlencoded',
                    'args'  => [
                        'method'  => $method,
                        'timeout' => 15,
                        'headers' => $base_headers,
                        'body'    => $active_payload,
                    ],
                ];

                $attempts[] = [
                    'label' => $payload_label . ':json',
                    'args'  => [
                        'method'  => $method,
                        'timeout' => 15,
                        'headers' => array_merge( $base_headers, [ 'Content-Type' => 'application/json' ] ),
                        'body'    => wp_json_encode( $active_payload ),
                    ],
                ];
            }

            // Avoid hitting AgentCIS rate limiting with too many experimental attempts.
            $attempts = array_slice( $attempts, 0, 4 );
        } else {
            $attempts[] = [
                'label' => 'default',
                'args'  => [
                    'method'  => $method,
                    'timeout' => 15,
                    'headers' => array_merge( $base_headers, [ 'Content-Type' => 'application/json' ] ),
                    'body'    => ! empty( $payload ) ? wp_json_encode( $payload ) : null,
                ],
            ];
        }

        $last_response = null;

        foreach ( $attempts as $attempt ) {
            $args = $attempt['args'];
            if ( empty( $args['body'] ) ) {
                unset( $args['body'] );
            }

            if ( isset( $args['body'] ) && is_array( $args['body'] ) ) {
                $this->log( 'Payload keys [attempt=' . $attempt['label'] . ']: ' . implode( ', ', array_keys( $args['body'] ) ), 'debug' );
            }

            $this->log( $method . ' ' . $url . ' [attempt=' . $attempt['label'] . ']', 'debug' );
            $response = wp_remote_request( $url, $args );

            if ( is_wp_error( $response ) ) {
                $last_response = $response;
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            $this->log( 'Response ' . $code . ' [attempt=' . $attempt['label'] . ']', 'debug' );

            $cf_ray = (string) wp_remote_retrieve_header( $response, 'cf-ray' );
            if ( '' !== $cf_ray ) {
                $this->log( 'Trace [attempt=' . $attempt['label'] . '] cf-ray: ' . $cf_ray, 'debug' );
            }

            $request_id = (string) wp_remote_retrieve_header( $response, 'x-request-id' );
            if ( '' !== $request_id ) {
                $this->log( 'Trace [attempt=' . $attempt['label'] . '] x-request-id: ' . $request_id, 'debug' );
            }

            $body_preview = (string) wp_remote_retrieve_body( $response );
            if ( '' !== $body_preview ) {
                $this->log( 'Body [attempt=' . $attempt['label'] . ']: ' . substr( $body_preview, 0, 500 ), 'debug' );
            }

            // Retry different payload format only for 5xx on write methods.
            if ( ( 'POST' === $method || 'PUT' === $method ) && $code >= 500 ) {
                $server_error_attempts++;
                $last_response = $response;
                continue;
            }

            return $response;
        }

        if ( ( 'POST' === $method || 'PUT' === $method ) && $server_error_attempts > 0 && $server_error_attempts === count( $attempts ) ) {
            $this->last_http_code  = 500;
            $this->last_error_code = 'agentcis_form_mismatch';
            return new \WP_Error(
                'agentcis_form_mismatch',
                __( 'AgentCIS returned HTTP 500 for every payload attempt. This usually means endpoint mismatch or required AgentCIS fields do not match the submitted booking fields.', 'racc-booking' )
            );
        }

        if ( null !== $last_response ) {
            return $last_response;
        }

        return new \WP_Error( 'agentcis_request_failed', __( 'AgentCIS request failed on all payload attempts.', 'racc-booking' ) );
    }

    /**
     * Extract a minimal payload using only common online-form fields.
     *
     * @param array $payload
     * @return array
     */
    private function extract_minimal_payload( $payload ) {
        $minimal_keys = [ 'first_name', 'last_name', 'date_of_birth', 'dob', 'email' ];
        $minimal      = [];

        foreach ( $minimal_keys as $key ) {
            if ( isset( $payload[ $key ] ) && '' !== trim( (string) $payload[ $key ] ) ) {
                $minimal[ $key ] = $payload[ $key ];
            }
        }

        return $minimal;
    }

    /**
     * Extract strictly required payload fields only.
     *
     * @param array $payload
     * @return array
     */
    private function extract_required_payload( $payload ) {
        $required_keys = [ 'first_name', 'last_name', 'date_of_birth', 'dob' ];
        $required      = [];

        foreach ( $required_keys as $key ) {
            if ( isset( $payload[ $key ] ) && '' !== trim( (string) $payload[ $key ] ) ) {
                $required[ $key ] = $payload[ $key ];
            }
        }

        return $required;
    }

    /**
     * Extract minimal payload for Clients API mode.
     *
     * @param array $payload
     * @return array
     */
    private function extract_clients_minimal_payload( $payload ) {
        $minimal_keys = [ 'first_name', 'last_name', 'email', 'first_point_of_contact' ];
        $minimal      = [];

        foreach ( $minimal_keys as $key ) {
            if ( isset( $payload[ $key ] ) && '' !== trim( (string) $payload[ $key ] ) ) {
                $minimal[ $key ] = $payload[ $key ];
            }
        }

        return $minimal;
    }

    private function validate_api_base() {
        if ( empty( $this->api_base ) ) {
            return new \WP_Error(
                'agentcis_api_base_missing',
                __( 'AgentCIS API Base URL is not configured.', 'racc-booking' )
            );
        }

        $scheme = wp_parse_url( $this->api_base, PHP_URL_SCHEME );
        if ( 'https' !== strtolower( (string) $scheme ) ) {
            return new \WP_Error(
                'agentcis_api_base_invalid',
                __( 'AgentCIS URL must use https.', 'racc-booking' )
            );
        }

        $host = wp_parse_url( $this->api_base, PHP_URL_HOST );

        if ( empty( $host ) ) {
            return new \WP_Error(
                'agentcis_api_base_invalid',
                __( 'AgentCIS API Base URL is invalid.', 'racc-booking' )
            );
        }

        if ( in_array( $host, [ 'api.agentcis.com', 'your-subdomain.agentcisapp.com' ], true ) ) {
            return new \WP_Error(
                'agentcis_api_base_invalid',
                __( 'The configured AgentCIS host is a placeholder or invalid. Use your real AgentCIS workspace/API host.', 'racc-booking' )
            );
        }

        return true;
    }

    /**
     * Determine whether integration should use Clients API mode.
     *
     * @return bool
     */
    private function is_clients_api_mode() {
        return false === strpos( $this->api_base, '/online-form/' );
    }

    /**
     * Resolve existing AgentCIS contact ID for a booking.
     *
     * Priority:
     * 1) current booking `agentcis_contact_id` (retry/resync safety)
     * 2) latest synced booking with same email in local DB
     *
     * @param int    $booking_id
     * @param object $booking
     * @return string
     */
    private function resolve_existing_contact_id_for_booking( $booking_id, $booking ) {
        $current_contact_id = trim( (string) ( $booking->agentcis_contact_id ?? '' ) );
        if ( '' !== $current_contact_id ) {
            return $current_contact_id;
        }

        $email = trim( (string) ( $booking->client_email ?? '' ) );
        if ( '' !== $email ) {
            $contact_id = $this->find_contact_id_by_email_from_synced_bookings( $email, (int) $booking_id );
            if ( '' !== $contact_id ) {
                return $contact_id;
            }
        }

        return '';
    }

    /**
     * Retry a duplicate client create response as an update when a contact ID can be resolved.
     *
     * @param mixed        $response
     * @param int          $booking_id
     * @param object       $booking
     * @param array        $payload
     * @param string       $submit_method
     * @param string       $submit_endpoint
     * @param string|int   $contact_id_hint
     * @return array
     */
    private function maybe_retry_duplicate_contact_as_update( $response, $booking_id, $booking, $payload, $submit_method, $submit_endpoint, $contact_id_hint ) {
        $result = [
            'response'   => $response,
            'payload'    => $payload,
            'method'     => $submit_method,
            'endpoint'   => $submit_endpoint,
            'contact_id' => trim( (string) $contact_id_hint ),
        ];

        if ( ! $this->is_clients_api_mode() || ! is_wp_error( $response ) || 'POST' !== strtoupper( (string) $submit_method ) ) {
            return $result;
        }

        $duplicate_conflicts = $this->extract_duplicate_contact_conflicts( $response->get_error_message() );
        if ( empty( $duplicate_conflicts ) ) {
            return $result;
        }

        $fallback_contact_id = $result['contact_id'];
        if ( '' === $fallback_contact_id ) {
            $fallback_contact_id = $this->resolve_existing_contact_id_for_booking( $booking_id, $booking );
        }
        if ( '' === $fallback_contact_id ) {
            $fallback_contact_id = $this->find_contact_id_by_email_from_agentcis( $booking->client_email ?? '' );
        }

        if ( '' === $fallback_contact_id ) {
            $email = trim( (string) ( $booking->client_email ?? '' ) );
            $message = sprintf(
                /* translators: %s: client email */
                __( 'AgentCIS says this email already exists, but the plugin could not resolve the existing client ID for %s. Check AgentCIS Clients API list/search permissions or add the AgentCIS Contact ID manually, then retry sync.', 'racc-booking' ),
                $email
            );
            $this->log( "Create returned duplicate identity fields for booking #$booking_id, but no AgentCIS client ID could be resolved for email: $email", 'warning' );
            $result['response'] = new \WP_Error( 'agentcis_duplicate_contact_unresolved', $message );
            return $result;
        }

        $retry_payload = $this->remove_conflicting_identity_fields_from_payload( $payload, $duplicate_conflicts );
        $retry_payload = $this->maybe_add_assignee_to_clients_update_payload( $retry_payload, $booking );

        $removed_fields = [];
        if ( ! empty( $duplicate_conflicts['email'] ) ) {
            $removed_fields[] = 'email';
            $removed_fields[] = 'secondary_email';
        }
        if ( ! empty( $duplicate_conflicts['phone'] ) ) {
            $removed_fields[] = 'phone';
        }

        $warnings = [];
        if ( ! empty( $removed_fields ) ) {
            $removed_fields = array_values( array_unique( $removed_fields ) );
            $this->log(
                "Create returned duplicate identity fields for booking #$booking_id. Removing conflicting fields (" . implode( ', ', $removed_fields ) . ") and retrying as update for contact ID: $fallback_contact_id",
                'warning'
            );
            $warnings[] = 'Warning: AgentCIS reported duplicate (' . implode(', ', $removed_fields) . '). These fields were not updated to prevent overwriting other clients.';
        } else {
            $this->log( "Create returned duplicate identity fields for booking #$booking_id. Retrying as update for contact ID: $fallback_contact_id", 'warning' );
        }

        $submit_method   = 'PUT';
        $submit_endpoint = $this->get_clients_update_endpoint( $fallback_contact_id );
        $response        = $this->request( $submit_method, $submit_endpoint, $retry_payload );

        return [
            'response'   => $response,
            'payload'    => $retry_payload,
            'method'     => $submit_method,
            'endpoint'   => $submit_endpoint,
            'contact_id' => trim( (string) $fallback_contact_id ),
            'warnings'   => $warnings,
        ];
    }

    /**
     * Find latest synced AgentCIS contact ID by client email from local bookings table.
     *
     * @param string $email
     * @param int    $exclude_booking_id
     * @return string
     */
    private function find_contact_id_by_email_from_synced_bookings( $email, $exclude_booking_id = 0 ) {
        global $wpdb;

        $email = trim( (string) $email );
        if ( '' === $email ) {
            return '';
        }

        $exclude_booking_id = (int) $exclude_booking_id;

        $sql = $wpdb->prepare(
            "SELECT agentcis_contact_id
             FROM {$wpdb->prefix}racc_bookings
             WHERE LOWER(client_email) = LOWER(%s)
               AND agentcis_contact_id <> ''
               AND agentcis_sync_status = 'synced'
               AND id <> %d
             ORDER BY id DESC
             LIMIT 1",
            $email,
            $exclude_booking_id
        );

        $contact_id = (string) $wpdb->get_var( $sql );

        return trim( $contact_id );
    }

    /**
     * Find an existing AgentCIS client ID by exact email via the Clients list endpoint.
     *
     * @param string $email
     * @return string
     */
    private function find_contact_id_by_email_from_agentcis( $email ) {
        $email = trim( (string) $email );
        if ( '' === $email || ! is_email( $email ) || ! $this->is_configured() || ! $this->is_clients_api_mode() ) {
            return '';
        }

        $endpoint = $this->get_clients_list_endpoint();
        $attempts = [
            [
                'page'     => 1,
                'per_page' => 20,
                'filters'  => [
                    'email' => [
                        'equals' => $email,
                    ],
                ],
            ],
            [
                'page'     => 1,
                'per_page' => 20,
                'filters'  => [
                    'email' => $email,
                ],
            ],
            [
                'page'     => 1,
                'per_page' => 20,
                'filters'  => [
                    'search' => $email,
                ],
            ],
        ];

        foreach ( $attempts as $payload ) {
            $response = $this->request( 'POST', $endpoint, $payload );
            if ( is_wp_error( $response ) ) {
                $this->log( 'AgentCIS email lookup failed for ' . $email . ': ' . $response->get_error_message(), 'warning' );
                continue;
            }

            $body       = json_decode( wp_remote_retrieve_body( $response ), true );
            $contact_id = $this->extract_contact_id_by_email_from_clients_list( $body, $email );

            if ( '' !== $contact_id ) {
                $this->log( "Resolved AgentCIS contact by email lookup ($email): $contact_id", 'info' );
                return $contact_id;
            }
        }

        $this->log( "AgentCIS email lookup did not find an exact client match for: $email", 'warning' );
        return '';
    }

    /**
     * Extract a client ID from an AgentCIS clients/list response by exact email.
     *
     * @param mixed  $body
     * @param string $email
     * @return string
     */
    private function extract_contact_id_by_email_from_clients_list( $body, $email ) {
        $email   = strtolower( trim( (string) $email ) );
        $records = $this->collect_client_records_from_response( $body );

        foreach ( $records as $record ) {
            if ( ! is_array( $record ) || ! isset( $record['id'] ) ) {
                continue;
            }

            if ( $this->record_contains_exact_email( $record, $email ) ) {
                return trim( (string) $record['id'] );
            }
        }

        return '';
    }

    /**
     * Collect likely client records from a response with unknown nesting.
     *
     * @param mixed $value
     * @return array
     */
    private function collect_client_records_from_response( $value ) {
        $records = [];

        if ( ! is_array( $value ) ) {
            return $records;
        }

        if ( isset( $value['id'] ) && $this->array_contains_email_value( $value ) ) {
            $records[] = $value;
        }

        foreach ( $value as $child ) {
            if ( is_array( $child ) ) {
                $records = array_merge( $records, $this->collect_client_records_from_response( $child ) );
            }
        }

        return $records;
    }

    /**
     * Determine whether an array has any email-like value.
     *
     * @param array $value
     * @return bool
     */
    private function array_contains_email_value( array $value ) {
        foreach ( $value as $child ) {
            if ( is_array( $child ) && $this->array_contains_email_value( $child ) ) {
                return true;
            }

            if ( is_string( $child ) && is_email( trim( $child ) ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a client record contains the exact target email.
     *
     * @param array  $record
     * @param string $target_email Lowercase target email.
     * @return bool
     */
    private function record_contains_exact_email( array $record, $target_email ) {
        foreach ( $record as $value ) {
            if ( is_array( $value ) && $this->record_contains_exact_email( $value, $target_email ) ) {
                return true;
            }

            if ( is_string( $value ) && strtolower( trim( $value ) ) === $target_email ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find latest synced AgentCIS contact ID by client phone from local bookings table.
     *
     * @param string $phone
     * @param int    $exclude_booking_id
     * @return string
     */
    private function find_contact_id_by_phone_from_synced_bookings( $phone, $exclude_booking_id = 0 ) {
        global $wpdb;

        $normalized_phone = $this->normalize_phone_for_lookup( $phone );
        if ( '' === $normalized_phone ) {
            return '';
        }

        $exclude_booking_id = (int) $exclude_booking_id;

        $sql = $wpdb->prepare(
            "SELECT agentcis_contact_id
             FROM {$wpdb->prefix}racc_bookings
             WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(client_phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') = %s
               AND agentcis_contact_id <> ''
               AND agentcis_sync_status = 'synced'
               AND id <> %d
             ORDER BY id DESC
             LIMIT 1",
            $normalized_phone,
            $exclude_booking_id
        );

        $contact_id = (string) $wpdb->get_var( $sql );

        return trim( $contact_id );
    }

    /**
     * Normalize phone text for DB lookup by stripping non-digit characters.
     *
     * @param string $phone
     * @return string
     */
    private function normalize_phone_for_lookup( $phone ) {
        $phone = preg_replace( '/\D+/', '', (string) $phone );
        return trim( (string) $phone );
    }

    /**
     * Normalize phone number for AgentCIS payload.
     *
     * For AU numbers, removes country prefix and trunk zero so:
     * - 0856...   => 856...
     * - +610856... => 856...
     * - +61856...  => 856...
     *
     * @param string $phone
     * @param string $country_code ISO-2 country code.
     * @return string
     */
    private function normalize_phone_for_agentcis_payload( $phone, $country_code = 'AU' ) {
        $digits = preg_replace( '/\D+/', '', (string) $phone );
        $digits = trim( (string) $digits );

        if ( '' === $digits ) {
            return '';
        }

        if ( 'AU' === strtoupper( (string) $country_code ) ) {
            if ( 0 === strpos( $digits, '0061' ) ) {
                $digits = '0' . substr( $digits, 4 );
            } elseif ( 0 === strpos( $digits, '61' ) ) {
                $digits = '0' . substr( $digits, 2 );
            }

            // Keep the national trunk prefix for AU when the API validates phone.number.
            if ( 0 === strpos( $digits, '0' ) ) {
                return $digits;
            }
        }

        return $digits;
    }

    /**
     * Resolve ISO-2 country code for the booking phone payload.
     *
     * @param object|array $booking
     * @return string
     */
    private function resolve_phone_country_code_for_booking( $booking ) {
        $country = $this->get_booking_residence_country_value( $booking );
        if ( '' === $country ) {
            return 'AU';
        }

        $country = trim( (string) $country );

        if ( 2 === strlen( $country ) && ctype_alpha( $country ) ) {
            return strtoupper( $country );
        }

        $countries = Country_Helper::get_country_list();
        $code      = array_search( $country, $countries, true );

        return is_string( $code ) && '' !== $code ? $code : 'AU';
    }

    /**
     * Detect duplicate identity-field conflicts from AgentCIS 422 message.
     *
     * @param string $error_message
     * @return array Example: [ 'email' => true, 'phone' => true ]
     */
    private function extract_duplicate_contact_conflicts( $error_message ) {
        $error_message = (string) $error_message;
        $conflicts     = [];

        $json_start = strpos( $error_message, '{' );
        if ( false === $json_start ) {
            return $conflicts;
        }

        $json_payload = substr( $error_message, $json_start );
        $decoded      = json_decode( $json_payload, true );

        if ( ! is_array( $decoded ) || empty( $decoded['errors'] ) || ! is_array( $decoded['errors'] ) ) {
            return $conflicts;
        }

        foreach ( $decoded['errors'] as $field => $messages ) {
            $field_key = strtolower( (string) $field );
            $text      = is_array( $messages ) ? implode( ' ', array_map( 'strval', $messages ) ) : (string) $messages;
            $text      = strtolower( $text );

            if ( false === strpos( $text, 'already been taken' ) && false === strpos( $text, 'has already been taken' ) ) {
                continue;
            }

            if ( 'email' === $field_key || 'secondary_email' === $field_key ) {
                $conflicts['email'] = true;
            }

            if ( 'phone' === $field_key || 'phone.number' === $field_key || 0 === strpos( $field_key, 'phone.' ) ) {
                $conflicts['phone'] = true;
            }
        }

        return $conflicts;
    }

    /**
     * Detect AgentCIS validation failures caused by an invalid phone.number field.
     *
     * @param string $error_message
     * @return bool
     */
    private function is_invalid_phone_number_error( $error_message ) {
        $error_message = (string) $error_message;

        $json_start = strpos( $error_message, '{' );
        if ( false !== $json_start ) {
            $decoded = json_decode( substr( $error_message, $json_start ), true );

            if ( is_array( $decoded ) && ! empty( $decoded['errors'] ) && is_array( $decoded['errors'] ) ) {
                foreach ( $decoded['errors'] as $field => $messages ) {
                    $field_key = strtolower( (string) $field );
                    if ( 'phone' !== $field_key && 'phone.number' !== $field_key && 0 !== strpos( $field_key, 'phone.' ) ) {
                        continue;
                    }

                    $text = is_array( $messages ) ? implode( ' ', array_map( 'strval', $messages ) ) : (string) $messages;
                    $text = strtolower( $text );

                    if ( false !== strpos( $text, 'invalid' ) ) {
                        return true;
                    }
                }
            }
        }

        $message = strtolower( $error_message );

        return false !== strpos( $message, 'phone.number' ) &&
            false !== strpos( $message, 'phone number' ) &&
            false !== strpos( $message, 'invalid' );
    }

    /**
     * Remove email/phone fields from payload for safer update retries.
     *
     * @param array $payload
     * @param array $conflicts Keys may include 'email' and/or 'phone'.
     * @return array
     */
    private function remove_conflicting_identity_fields_from_payload( $payload, $conflicts ) {
        $payload   = is_array( $payload ) ? $payload : [];
        $conflicts = is_array( $conflicts ) ? $conflicts : [];

        if ( ! empty( $conflicts['email'] ) ) {
            unset( $payload['email'], $payload['secondary_email'] );
        }

        if ( ! empty( $conflicts['phone'] ) ) {
            unset( $payload['phone'] );
        }

        return $payload;
    }

    /**
     * Resolve AgentCIS assignee ID for a booking update.
     *
     * The local booking agent_id belongs to this plugin's agent table, so do not
     * send it as an AgentCIS assignee unless a filter explicitly maps it.
     *
     * @param object|array $booking
     * @return int
     */
    private function resolve_assignee_id_for_booking( $booking ) {
        $assignee_id = (int) get_option( 'racc_agentcis_default_assignee_id', 0 );

        // If the booking has an agent, check if the agent is mapped to an AgentCIS user.
        $agent_id = 0;
        if ( is_array( $booking ) && isset( $booking['agent_id'] ) ) {
            $agent_id = (int) $booking['agent_id'];
        } elseif ( is_object( $booking ) && isset( $booking->agent_id ) ) {
            $agent_id = (int) $booking->agent_id;
        }

        if ( $agent_id > 0 ) {
            global $wpdb;
            $agentcis_assignee = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT agentcis_assignee_id FROM {$wpdb->prefix}racc_agents WHERE id = %d",
                $agent_id
            ) );

            if ( $agentcis_assignee > 0 ) {
                $assignee_id = $agentcis_assignee;
            }
        }

        return (int) apply_filters( 'racc_agentcis_assignee_id', $assignee_id, $booking );
    }

    /**
     * Add required AgentCIS assignee field to Clients API update payloads.
     *
     * @param array        $payload
     * @param object|array $booking
     * @return array
     */
    private function maybe_add_assignee_to_clients_update_payload( $payload, $booking ) {
        $payload     = is_array( $payload ) ? $payload : [];
        $assignee_id = $this->resolve_assignee_id_for_booking( $booking );

        if ( $assignee_id > 0 && empty( $payload['assignee'] ) ) {
            $payload['assignee'] = $assignee_id;
        }

        return $payload;
    }

    /**
     * Human-friendly message for AgentCIS assignee-required failures.
     *
     * @return string
     */
    private function get_assignee_required_message() {
        return __( 'AgentCIS requires an assignee when updating an existing client. Please map the "AgentCIS → Default Assignee ID" in the consultant profile, save, and then retry sync.', 'racc-booking' );
    }

    /**
     * Detect whether AgentCIS error indicates required assignee on update.
     *
     * @param string $error_message
     * @return bool
     */
    private function is_assignee_required_error( $error_message ) {
        $message = strtolower( (string) $error_message );

        if ( false === strpos( $message, '403' ) && false === strpos( $message, '"status":403' ) ) {
            return false;
        }

        return false !== strpos( $message, 'assignee' ) &&
            ( false !== strpos( $message, 'required' ) || false !== strpos( $message, 'unable to update the client' ) );
    }

    /**
     * Resolve create-client endpoint URL.
     *
     * @return string
     */
    private function get_clients_create_endpoint() {
        $base = untrailingslashit( (string) $this->api_base );

        if ( preg_match( '#/api/v2/clients$#', $base ) ) {
            return $base;
        }

        if ( preg_match( '#/api/v2$#', $base ) ) {
            return $base . '/clients';
        }

        return $base . '/clients';
    }

    /**
     * Resolve update-client endpoint URL.
     *
     * @param string|int $client_id
     * @return string
     */
    private function get_clients_update_endpoint( $client_id ) {
        $client_id = trim( (string) $client_id );
        return untrailingslashit( $this->get_clients_create_endpoint() ) . '/' . rawurlencode( $client_id );
    }

    /**
     * Resolve clients list endpoint URL.
     *
     * @return string
     */
    private function get_clients_list_endpoint() {
        return $this->get_api_v2_root_endpoint() . '/contacts/list';
    }

    /**
     * Resolve API v2 root endpoint URL.
     *
     * Supports both configured forms:
     * - https://host/api/v2
     * - https://host/api/v2/clients
     *
     * @return string
     */
    private function get_api_v2_root_endpoint() {
        $clients_endpoint = untrailingslashit( $this->get_clients_create_endpoint() );

        if ( preg_match( '#/clients$#', $clients_endpoint ) ) {
            return preg_replace( '#/clients$#', '', $clients_endpoint );
        }

        return untrailingslashit( (string) $this->api_base );
    }

    /**
     * Build absolute API v2 endpoint URL.
     *
     * @param string $path Relative API path, e.g. '/degree-levels'.
     * @return string
     */
    private function get_api_v2_endpoint( $path ) {
        $path = '/' . ltrim( (string) $path, '/' );
        return untrailingslashit( $this->get_api_v2_root_endpoint() ) . $path;
    }

    /**
     * Normalize API key text from settings.
     *
     * @param string $api_key
     * @return string
     */
    private function normalize_api_key( $api_key ) {
        $api_key = trim( (string) $api_key );

        if ( 0 === stripos( $api_key, 'Bearer ' ) ) {
            $api_key = trim( substr( $api_key, 7 ) );
        }

        return preg_replace( '/\s+/', '', $api_key );
    }

    /**
     * Normalize a date value to Y-m-d for AgentCIS. Returns empty string if invalid.
     *
     * @param mixed $value
     * @return string
     */
    private function normalize_date_for_agentcis( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return '';
        }

        $formats = [ 'Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'm-d-Y' ];
        foreach ( $formats as $format ) {
            $dt = \DateTime::createFromFormat( $format, $value );
            if ( false === $dt ) {
                continue;
            }

            $errors = \DateTime::getLastErrors();
            if ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) {
                continue;
            }

            $year = (int) $dt->format( 'Y' );
            $current_year = (int) gmdate( 'Y' );
            if ( $year < 1900 || $year > $current_year ) {
                return '';
            }

            return $dt->format( 'Y-m-d' );
        }

        return '';
    }

    /**
     * Determine whether DOB value is plausible for client records.
     *
     * @param string $dob Y-m-d
     * @return bool
     */
    private function is_plausible_dob( $dob ) {
        $dob = trim( (string) $dob );
        if ( '' === $dob ) {
            return false;
        }

        $dt = \DateTime::createFromFormat( 'Y-m-d', $dob );
        if ( false === $dt ) {
            return false;
        }

        $errors = \DateTime::getLastErrors();
        if ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) {
            return false;
        }

        $today = new \DateTimeImmutable( 'today', new \DateTimeZone( 'UTC' ) );
        $age   = (int) $dt->diff( $today )->y;

        return $age >= 10 && $age <= 100;
    }

    /**
     * Resolve a country name or ISO-2 code to an AgentCIS integer country ID.
     * Returns empty string if not resolvable (will be filtered from payload).
     *
     * @param string $country Country name or ISO-2 code.
     * @return int|string Integer ID, or empty string if unknown.
     */
    private function resolve_country_id( $country ) {
        $country = trim( (string) $country );
        if ( '' === $country ) {
            return '';
        }

        // Already an integer — cast and return.
        if ( ctype_digit( $country ) ) {
            return (int) $country;
        }

        // ISO-2 code support (e.g. AU, ID, IN).
        if ( 2 === strlen( $country ) && ctype_alpha( $country ) ) {
            $country_code = strtoupper( $country );
            $countries    = Country_Helper::get_country_list();
            if ( isset( $countries[ $country_code ] ) ) {
                $country = (string) $countries[ $country_code ];
            }
        }

        // Mapping: country name (lowercase) => AgentCIS country ID.
        // IDs sourced from AgentCIS /api/v2/countries endpoint (standard list).
        $map = [
            'afghanistan'                           => 1,
            'albania'                               => 2,
            'algeria'                               => 4,
            'american samoa'                        => 5,
            'andorra'                               => 6,
            'angola'                                => 7,
            'anguilla'                              => 190,
            'antarctica'                            => 3,
            'antigua and barbuda'                   => 8,
            'argentina'                             => 10,
            'armenia'                               => 16,
            'aruba'                                 => 153,
            'australia'                             => 11,
            'austria'                               => 12,
            'azerbaijan'                            => 9,
            'bahamas'                               => 13,
            'bahrain'                               => 14,
            'bangladesh'                            => 15,
            'barbados'                              => 17,
            'belarus'                               => 34,
            'belgium'                               => 18,
            'belize'                                => 26,
            'benin'                                 => 59,
            'bermuda'                               => 19,
            'bhutan'                                => 20,
            'bolivia, plurinational state of'       => 21,
            'bonaire, sint eustatius and saba'      => 155,
            'bosnia and herzegovina'                => 22,
            'botswana'                              => 23,
            'bouvet island'                         => 24,
            'brazil'                                => 25,
            'british indian ocean territory'        => 27,
            'brunei darussalam'                     => 30,
            'bulgaria'                              => 31,
            'burkina faso'                          => 242,
            'burundi'                               => 33,
            'cambodia'                              => 35,
            'cameroon'                              => 36,
            'canada'                                => 37,
            'cape verde'                            => 38,
            'cayman islands'                        => 39,
            'central african republic'              => 40,
            'chad'                                  => 42,
            'chile'                                 => 43,
            'china'                                 => 44,
            'christmas island'                      => 46,
            'cocos (keeling) islands'               => 47,
            'colombia'                              => 48,
            'comoros'                               => 49,
            'congo'                                 => 51,
            'congo, the democratic republic of the' => 52,
            'cook islands'                          => 53,
            'costa rica'                            => 54,
            'croatia'                               => 55,
            'cuba'                                  => 56,
            'curaçao'                              => 152,
            'cyprus'                                => 57,
            'czech republic'                        => 58,
            'côte d\'ivoire'                       => 110,
            'denmark'                               => 60,
            'djibouti'                              => 79,
            'dominica'                              => 61,
            'dominican republic'                    => 62,
            'ecuador'                               => 63,
            'egypt'                                 => 234,
            'el salvador'                           => 64,
            'equatorial guinea'                     => 65,
            'eritrea'                               => 67,
            'estonia'                               => 68,
            'ethiopia'                              => 66,
            'falkland islands (malvinas)'           => 70,
            'faroe islands'                         => 69,
            'fiji'                                  => 72,
            'finland'                               => 73,
            'france'                                => 75,
            'french guiana'                         => 76,
            'french polynesia'                      => 77,
            'french southern territories'           => 78,
            'gabon'                                 => 80,
            'gambia'                                => 82,
            'georgia'                               => 81,
            'germany'                               => 84,
            'ghana'                                 => 85,
            'gibraltar'                             => 86,
            'greece'                                => 88,
            'greenland'                             => 89,
            'grenada'                               => 90,
            'guadeloupe'                            => 91,
            'guam'                                  => 92,
            'guatemala'                             => 93,
            'guernsey'                              => 236,
            'guinea'                                => 94,
            'guinea-bissau'                         => 179,
            'guyana'                                => 95,
            'haiti'                                 => 96,
            'heard island and mcdonald islands'     => 97,
            'holy see (vatican city state)'         => 98,
            'honduras'                              => 99,
            'hong kong'                             => 100,
            'hungary'                               => 101,
            'iceland'                               => 102,
            'india'                                 => 103,
            'indonesia'                             => 104,
            'iran, islamic republic of'             => 105,
            'iraq'                                  => 106,
            'ireland'                               => 107,
            'isle of man'                           => 238,
            'israel'                                => 108,
            'italy'                                 => 109,
            'jamaica'                               => 111,
            'japan'                                 => 112,
            'jersey'                                => 237,
            'jordan'                                => 114,
            'kazakhstan'                            => 113,
            'kenya'                                 => 115,
            'kiribati'                              => 87,
            'korea, democratic people\'s republic of'=> 116,
            'korea, republic of'                    => 117,
            'kuwait'                                => 118,
            'kyrgyzstan'                            => 119,
            'lao people\'s democratic republic'     => 120,
            'latvia'                                => 123,
            'lebanon'                               => 121,
            'lesotho'                               => 122,
            'liberia'                               => 124,
            'libya'                                 => 125,
            'liechtenstein'                         => 126,
            'lithuania'                             => 127,
            'luxembourg'                            => 128,
            'macao'                                 => 129,
            'macedonia, the former yugoslav republic of'=> 233,
            'madagascar'                            => 130,
            'malawi'                                => 131,
            'malaysia'                              => 132,
            'maldives'                              => 133,
            'mali'                                  => 134,
            'malta'                                 => 135,
            'marshall islands'                      => 168,
            'martinique'                            => 136,
            'mauritania'                            => 137,
            'mauritius'                             => 138,
            'mayotte'                               => 50,
            'mexico'                                => 139,
            'micronesia, federated states of'       => 167,
            'moldova, republic of'                  => 142,
            'monaco'                                => 140,
            'mongolia'                              => 141,
            'montenegro'                            => 143,
            'montserrat'                            => 144,
            'morocco'                               => 145,
            'mozambique'                            => 146,
            'myanmar'                               => 32,
            'namibia'                               => 148,
            'nauru'                                 => 149,
            'nepal'                                 => 150,
            'netherlands'                           => 151,
            'new caledonia'                         => 156,
            'new zealand'                           => 158,
            'nicaragua'                             => 159,
            'niger'                                 => 160,
            'nigeria'                               => 161,
            'niue'                                  => 162,
            'norfolk island'                        => 163,
            'northern mariana islands'              => 165,
            'norway'                                => 164,
            'oman'                                  => 147,
            'pakistan'                              => 170,
            'palau'                                 => 169,
            'palestinian territory, occupied'       => 83,
            'panama'                                => 171,
            'papua new guinea'                      => 172,
            'paraguay'                              => 173,
            'peru'                                  => 174,
            'philippines'                           => 175,
            'pitcairn'                              => 176,
            'poland'                                => 177,
            'portugal'                              => 178,
            'puerto rico'                           => 181,
            'qatar'                                 => 182,
            'romania'                               => 184,
            'russian federation'                    => 185,
            'rwanda'                                => 186,
            'réunion'                              => 183,
            'saint barthélemy'                     => 187,
            'saint helena, ascension and tristan da cunha'=> 188,
            'saint kitts and nevis'                 => 189,
            'saint lucia'                           => 191,
            'saint martin (french part)'            => 192,
            'saint pierre and miquelon'             => 193,
            'saint vincent and the grenadines'      => 194,
            'samoa'                                 => 247,
            'san marino'                            => 195,
            'sao tome and principe'                 => 196,
            'saudi arabia'                          => 197,
            'senegal'                               => 198,
            'serbia'                                => 199,
            'seychelles'                            => 200,
            'sierra leone'                          => 201,
            'singapore'                             => 202,
            'sint maarten (dutch part)'             => 154,
            'slovakia'                              => 203,
            'slovenia'                              => 205,
            'solomon islands'                       => 28,
            'somalia'                               => 206,
            'south africa'                          => 207,
            'south georgia and the south sandwich islands'=> 71,
            'south sudan'                           => 210,
            'spain'                                 => 209,
            'sri lanka'                             => 41,
            'sudan'                                 => 211,
            'suriname'                              => 213,
            'svalbard and jan mayen'                => 214,
            'swaziland'                             => 215,
            'sweden'                                => 216,
            'switzerland'                           => 217,
            'syrian arab republic'                  => 218,
            'taiwan, province of china'             => 45,
            'tajikistan'                            => 219,
            'tanzania, united republic of'          => 239,
            'thailand'                              => 220,
            'timor-leste'                           => 180,
            'togo'                                  => 221,
            'tokelau'                               => 222,
            'tonga'                                 => 223,
            'trinidad and tobago'                   => 224,
            'tunisia'                               => 226,
            'turkey'                                => 227,
            'turkmenistan'                          => 228,
            'turks and caicos islands'              => 229,
            'tuvalu'                                => 230,
            'uganda'                                => 231,
            'ukraine'                               => 232,
            'united arab emirates'                  => 225,
            'united kingdom'                        => 235,
            'united states'                         => 240,
            'united states minor outlying islands'  => 166,
            'uruguay'                               => 243,
            'uzbekistan'                            => 244,
            'vanuatu'                               => 157,
            'venezuela, bolivarian republic of'     => 245,
            'viet nam'                              => 204,
            'virgin islands, british'               => 29,
            'virgin islands, u.s.'                  => 241,
            'wallis and futuna'                     => 246,
            'western sahara'                        => 212,
            'yemen'                                 => 248,
            'zambia'                                => 249,
            'zimbabwe'                              => 208,
            'Åland islands'                        => 74,
        ];

        $key = strtolower( $country );
        if ( isset( $map[ $key ] ) ) {
            return (int) $map[ $key ];
        }

        // Log unresolved country so it can be added to the map later.
        $this->log( 'resolve_country_id: no integer ID found for country "' . $country . '". Field country_of_passport will be omitted.', 'warning' );
        return '';
    }

    /**
     * Resolve tag string from "Where did you hear us from" to an array of Tag IDs.
     *
     * @param string $tag_string The referral source text.
     * @return array Array of integers (AgentCIS tag IDs).
     */
    private function resolve_tag_ids( $tag_string ) {
        if ( '' === trim( (string) $tag_string ) ) {
            return [];
        }

        $tag_string = strtolower( trim( $tag_string ) );
        $json_file  = dirname( __DIR__ ) . '/assets/agentcis-tags.json';

        if ( ! file_exists( $json_file ) ) {
            return [];
        }

        $json_content = file_get_contents( $json_file );
        $data         = json_decode( $json_content, true );

        if ( ! empty( $data['data'] ) ) {
            foreach ( $data['data'] as $tag_item ) {
                if ( strtolower( trim( $tag_item['name'] ) ) === $tag_string ) {
                    return [ (int) $tag_item['id'] ];
                }
            }
        }

        return [];
    }

    // ─── Education Background API ───────────────────────────────────────────────

    /**
     * Fetch all available degree levels from AgentCIS.
     * Results are cached in a transient for performance.
     *
     * @return array Array of degree levels with 'id' and 'name' keys, or empty on error.
     */
    public function get_degree_levels() {
        $cache_key = 'racc_agentcis_degree_levels';
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return (array) $cached;
        }

        if ( ! $this->is_configured() ) {
            $this->log( 'AgentCIS not configured — cannot fetch degree levels', 'warning' );
            return [];
        }

        $endpoint = $this->get_api_v2_endpoint( '/degree-levels' );
        $response = $this->request( 'GET', $endpoint );

        if ( is_wp_error( $response ) ) {
            $this->log( 'Failed to fetch degree levels: ' . $response->get_error_message(), 'error' );
            return [];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) ) {
            $this->log( 'Unexpected response format when fetching degree levels', 'error' );
            return [];
        }

        // Cache for 24 hours
        set_transient( $cache_key, $body, DAY_IN_SECONDS );

        return $body;
    }

    /**
     * Fetch all available subject areas and their related subjects from AgentCIS.
     * Results are cached in a transient for performance.
     *
     * @return array Array of subject areas with 'id', 'name', and 'subjects' array, or empty on error.
     */
    public function get_subject_areas() {
        $cache_key = 'racc_agentcis_subject_areas';
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return (array) $cached;
        }

        if ( ! $this->is_configured() ) {
            $this->log( 'AgentCIS not configured — cannot fetch subject areas', 'warning' );
            return [];
        }

        $endpoint = $this->get_api_v2_endpoint( '/subject-areas' );
        $response = $this->request( 'GET', $endpoint );

        if ( is_wp_error( $response ) ) {
            $this->log( 'Failed to fetch subject areas: ' . $response->get_error_message(), 'error' );
            return [];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) ) {
            $this->log( 'Unexpected response format when fetching subject areas', 'error' );
            return [];
        }

        // Cache for 24 hours
        set_transient( $cache_key, $body, DAY_IN_SECONDS );

        return $body;
    }

    /**
     * Insert or update an education background for a contact.
     *
     * @param string|int $client_id     AgentCIS client/contact ID.
     * @param array      $education     Education background data with keys:
     *                                  - degree_title (string, required)
     *                                  - degree_level_id (int, required)
     *                                  - institution (string, required)
     *                                  - course_start (Y-m-d, required)
     *                                  - course_end (Y-m-d, required)
     *                                  - subject_area (int, required)
     *                                  - subject_id (int, required)
     *                                  - academic_score_type (enum: 'percentage'|'gpa', optional)
     *                                  - academic_score (int|float, optional)
     * @return array|WP_Error Response body as array, or WP_Error on failure.
     */
    public function add_education_background( $client_id, $education ) {
        $client_id = trim( (string) $client_id );
        if ( '' === $client_id ) {
            return new \WP_Error(
                'agentcis_education_invalid_client_id',
                __( 'AgentCIS client ID is required to add education background.', 'racc-booking' )
            );
        }

        if ( ! $this->is_configured() ) {
            return new \WP_Error(
                'agentcis_not_configured',
                __( 'AgentCIS is not configured.', 'racc-booking' )
            );
        }

        // Validate required education fields
        $required_fields = [
            'degree_title',
            'degree_level_id',
            'institution',
            'course_start',
            'course_end',
            'subject_area',
            'subject_id',
        ];

        foreach ( $required_fields as $field ) {
            if ( empty( $education[ $field ] ) ) {
                return new \WP_Error(
                    'agentcis_education_missing_field',
                    sprintf( __( 'Missing required education field: %s', 'racc-booking' ), $field )
                );
            }
        }

        // Ensure academic scores are integers if provided
        if ( isset( $education['academic_score'] ) && '' !== trim( (string) $education['academic_score'] ) ) {
            $education['academic_score'] = (int) $education['academic_score'];
        }

        $endpoint = $this->get_api_v2_endpoint( '/client/' . rawurlencode( $client_id ) . '/education-backgrounds' );

        $response = $this->request( 'POST', $endpoint, $education );

        if ( is_wp_error( $response ) ) {
            $this->log( "Failed to add education background for client $client_id: " . $response->get_error_message(), 'error' );
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        $this->log( "Education background added for client $client_id", 'info' );

        return $body;
    }

    /**
     * Fetch all education backgrounds for a contact.
     *
     * @param string|int $client_id AgentCIS client/contact ID.
     * @return array|WP_Error Array with 'education_backgrounds' key containing education records, or WP_Error on failure.
     */
    public function get_education_backgrounds( $client_id ) {
        $client_id = trim( (string) $client_id );
        if ( '' === $client_id ) {
            return new \WP_Error(
                'agentcis_education_invalid_client_id',
                __( 'AgentCIS client ID is required to fetch education backgrounds.', 'racc-booking' )
            );
        }

        if ( ! $this->is_configured() ) {
            return new \WP_Error(
                'agentcis_not_configured',
                __( 'AgentCIS is not configured.', 'racc-booking' )
            );
        }

        $endpoint = $this->get_api_v2_endpoint( '/client/' . rawurlencode( $client_id ) . '/education' );

        $response = $this->request( 'GET', $endpoint );

        if ( is_wp_error( $response ) ) {
            $this->log( "Failed to fetch education backgrounds for client $client_id: " . $response->get_error_message(), 'error' );
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return $body;
    }

    /**
     * Sync education background from booking to AgentCIS after a successful contact sync.
     *
     * Called after a booking is synced to create/update a contact.
     * This method handles the complete education background integration flow:
     * 1. Fetches degree levels and subject areas if not cached
     * 2. Builds education payload from booking fields
     * 3. Sends education data to AgentCIS
     *
     * @param int    $booking_id    Booking ID from local DB.
     * @param string $client_id     AgentCIS client/contact ID.
     * @return bool True if education sync succeeded or was skipped, false on error.
     */
    public function sync_education_background( $booking_id, $client_id ) {
        $booking_id = (int) $booking_id;
        $client_id  = trim( (string) $client_id );

        if ( $booking_id <= 0 || '' === $client_id ) {
            $this->log( "Invalid booking_id ($booking_id) or client_id ($client_id) for education sync", 'error' );
            return false;
        }

        if ( ! $this->is_configured() ) {
            $this->log( "AgentCIS not configured — skipping education sync for booking #$booking_id", 'warning' );
            return false;
        }

        $booking = $this->get_booking( $booking_id );
        if ( ! $booking ) {
            $this->log( "Booking #$booking_id not found for education sync", 'error' );
            return false;
        }

        // Build education payload from booking fields
        $education_payload = $this->build_education_payload( $booking );

        if ( empty( $education_payload ) ) {
            $this->log( "No education data to sync for booking #$booking_id", 'info' );
            return true; // Skip, not an error
        }

        // Attempt to add education background
        $result = $this->add_education_background( $client_id, $education_payload );

        if ( is_wp_error( $result ) ) {
            $this->log( "Education background sync failed for booking #$booking_id: " . $result->get_error_message(), 'error' );
            return false;
        }

        $this->log( "✅ Education background synced for booking #$booking_id (Client: $client_id)" );
        return true;
    }

    /**
     * Build education background payload from booking fields.
     *
     * Maps booking form fields to AgentCIS education background structure:
    * - degree_title: from client_course_major
     * - degree_level_id: resolved from client_course_level mapping
     * - institution: from client_university
     * - course_start: from booking_date
     * - course_end: from client_course_completion
     * - subject_area: from client_course_major mapping or form field
     * - subject_id: from custom mapping
     * - academic_score_type: from form or default to 'percentage'
     * - academic_score: from custom field if available
     *
     * @param object $booking Booking row from DB.
     * @return array Education background payload, or empty array if insufficient data.
     */
    private function build_education_payload( $booking ) {
        $payload = [];

        // Degree Title — from client_course_major (racc-client-course-major)
        $degree_title = trim( (string) ( $booking->client_course_major ?? '' ) );
        if ( '' === $degree_title ) {
            return []; // Skip if no course major
        }
        $payload['degree_title'] = $degree_title;

        // Degree Level ID — resolved from client_course_level (racc-client-course-level)
        $degree_level_str = trim( (string) ( $booking->client_course_level ?? '' ) );
        $degree_level_id = $this->resolve_degree_level_id( $degree_level_str );
        if ( ! $degree_level_id ) {
            $this->log( "Could not resolve degree level ID for: $degree_level_str", 'warning' );
            return [];
        }
        $payload['degree_level_id'] = $degree_level_id;

        // Institution — required
        $institution = trim( (string) ( $booking->client_university ?? '' ) );
        if ( '' === $institution ) {
            return []; // Skip if no institution
        }
        $payload['institution'] = $institution;

        // Course Start — required (use booking_date)
        $course_start = $this->normalize_date_for_agentcis( $booking->booking_date ?? '' );
        if ( '' === $course_start ) {
            $this->log( 'Could not normalize course_start date', 'warning' );
            return [];
        }
        $payload['course_start'] = $course_start;

        // Course End — required
        $course_end = $this->normalize_date_for_agentcis( $booking->client_course_completion ?? '' );
        if ( '' === $course_end ) {
            $this->log( 'Could not normalize course_end date', 'warning' );
            return [];
        }
        $payload['course_end'] = $course_end;

        // Subject Area — required
        $subject_area = $this->resolve_subject_area_id( $booking->client_course_major ?? '' );
        if ( ! $subject_area ) {
            $this->log( 'Could not resolve subject area ID', 'warning' );
            return [];
        }
        $payload['subject_area'] = $subject_area;

        // Subject ID — required
        $subject_id = $this->resolve_subject_id( $booking->client_course_major ?? '', $subject_area );
        if ( ! $subject_id ) {
            $this->log( 'Could not resolve subject ID', 'warning' );
            return [];
        }
        $payload['subject_id'] = $subject_id;

        // Academic Score Type — optional
        $score_type = trim( (string) get_option( 'racc_agentcis_academic_score_type', 'percentage' ) );
        if ( '' !== $score_type && in_array( $score_type, [ 'percentage', 'gpa' ], true ) ) {
            $payload['academic_score_type'] = $score_type;
        }

        // Academic Score — optional
        $academic_score = trim( (string) get_option( 'racc_agentcis_academic_score', '' ) );
        if ( '' !== $academic_score && is_numeric( $academic_score ) ) {
            $payload['academic_score'] = (int) $academic_score;
        }

        /**
         * Allow education payload customization before submit.
         *
         * @param array  $payload
         * @param object $booking
         */
        return apply_filters( 'racc_agentcis_education_payload', $payload, $booking );
    }

    /**
     * Resolve a degree level ID from a degree title string.
     * Caches fetched degree levels for performance.
     *
     * @param string $degree_title e.g., 'Bachelor', 'Master', 'Diploma'
     * @return int Degree level ID, or 0 if not found.
     */
    private function resolve_degree_level_id( $degree_title ) {
        $degree_title = trim( (string) $degree_title );
        if ( '' === $degree_title ) {
            return 0;
        }

        $levels = $this->get_degree_levels();
        if ( empty( $levels ) ) {
            return 0;
        }

        $degree_title_lower = strtolower( $degree_title );

        foreach ( $levels as $level ) {
            if ( isset( $level['id'], $level['name'] ) ) {
                if ( strtolower( (string) $level['name'] ) === $degree_title_lower ) {
                    return (int) $level['id'];
                }
            }
        }

        // Try partial match if exact match not found
        foreach ( $levels as $level ) {
            if ( isset( $level['id'], $level['name'] ) ) {
                $level_name_lower = strtolower( (string) $level['name'] );
                if ( strpos( $degree_title_lower, $level_name_lower ) !== false ||
                     strpos( $level_name_lower, $degree_title_lower ) !== false ) {
                    return (int) $level['id'];
                }
            }
        }

        return 0;
    }

    /**
     * Resolve a subject area ID from a course major/subject string.
     * Caches fetched subject areas for performance.
     *
     * @param string $course_major e.g., 'Computer Science', 'Engineering'
     * @return int Subject area ID, or 0 if not found.
     */
    private function resolve_subject_area_id( $course_major ) {
        $course_major = trim( (string) $course_major );
        if ( '' === $course_major ) {
            return 0;
        }

        $areas = $this->get_subject_areas();
        if ( empty( $areas ) ) {
            return 0;
        }

        $course_major_lower = strtolower( $course_major );

        foreach ( $areas as $area ) {
            if ( isset( $area['id'], $area['name'] ) ) {
                if ( strtolower( (string) $area['name'] ) === $course_major_lower ) {
                    return (int) $area['id'];
                }
            }
        }

        // Try partial match if exact match not found
        foreach ( $areas as $area ) {
            if ( isset( $area['id'], $area['name'] ) ) {
                $area_name_lower = strtolower( (string) $area['name'] );
                if ( strpos( $course_major_lower, $area_name_lower ) !== false ||
                     strpos( $area_name_lower, $course_major_lower ) !== false ) {
                    return (int) $area['id'];
                }
            }
        }

        return 0;
    }

    /**
     * Resolve a subject ID within a subject area.
     * Uses cached subject areas.
     *
     * @param string $course_major  e.g., 'Computer Science', 'Software Engineering'
     * @param int    $subject_area_id  Subject area ID (already resolved).
     * @return int Subject ID, or 0 if not found.
     */
    private function resolve_subject_id( $course_major, $subject_area_id ) {
        $course_major   = trim( (string) $course_major );
        $subject_area_id = (int) $subject_area_id;

        if ( '' === $course_major || $subject_area_id <= 0 ) {
            return 0;
        }

        $areas = $this->get_subject_areas();
        if ( empty( $areas ) ) {
            return 0;
        }

        $course_major_lower = strtolower( $course_major );

        // Find the subject area and search its subjects
        foreach ( $areas as $area ) {
            if ( isset( $area['id'] ) && (int) $area['id'] === $subject_area_id ) {
                if ( isset( $area['subjects'] ) && is_array( $area['subjects'] ) ) {
                    foreach ( $area['subjects'] as $subject ) {
                        if ( isset( $subject['id'], $subject['name'] ) ) {
                            if ( strtolower( (string) $subject['name'] ) === $course_major_lower ) {
                                return (int) $subject['id'];
                            }
                        }
                    }

                    // Try partial match
                    foreach ( $area['subjects'] as $subject ) {
                        if ( isset( $subject['id'], $subject['name'] ) ) {
                            $subject_name_lower = strtolower( (string) $subject['name'] );
                            if ( strpos( $course_major_lower, $subject_name_lower ) !== false ||
                                 strpos( $subject_name_lower, $course_major_lower ) !== false ) {
                                return (int) $subject['id'];
                            }
                        }
                    }
                }
                break;
            }
        }

        return 0;
    }

    private function log( $message, $level = 'info' ) {
        $dir = dirname( $this->log_file );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $timestamp = current_time( 'Y-m-d H:i:s' );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $this->log_file, "[$timestamp] [$level] $message\n", FILE_APPEND | LOCK_EX );
    }

    /**
     * Fetch all users from AgentCIS and store them in an option.
     */
    public function sync_agentcis_users() {
        if ( empty( $this->api_key ) || empty( $this->api_base ) ) {
            return false;
        }

        $url = preg_replace( '#/api/v2/clients/?$#', '/api/v2/users', $this->api_base );
        if ( $url === $this->api_base ) {
            $url = preg_replace( '#/api/v2/?$#', '/api/v2/users', $this->api_base );
        }
        
        $all_users = [];
        $page = 1;
        
        do {
            $current_url = add_query_arg( 'page', $page, $url );
            $response = wp_remote_get( $current_url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Accept'        => 'application/json',
                ],
                'timeout' => 30,
            ] );
            
            if ( is_wp_error( $response ) ) {
                $this->log( 'Error fetching users: ' . $response->get_error_message(), 'error' );
                break;
            }
            
            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) {
                $this->log( 'Error fetching users, status: ' . $code, 'error' );
                break;
            }
            
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! isset( $body['data'] ) ) {
                break;
            }
            
            foreach ( $body['data'] as $user_data ) {
                $all_users[] = [
                    'id'    => $user_data['id'] ?? 0,
                    'name'  => $user_data['name'] ?? '',
                    'email' => $user_data['email'] ?? '',
                ];
            }
            
            $last_page = $body['meta']['last_page'] ?? 1;
            $page++;
        } while ( $page <= $last_page );
        
        if ( ! empty( $all_users ) ) {
            update_option( 'racc_agentcis_users_list', $all_users );
            return true;
        }
        
        return false;
    }
}
