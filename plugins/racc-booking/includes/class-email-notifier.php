<?php
/**
 * Email notification handler.
 *
 * @package RACC_Booking
 */

namespace RACC_Booking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Email_Notifier {

    /**
     * Send booking confirmation to client and agent.
     *
     * @param int $booking_id Booking ID.
     */
    public function send_booking_confirmation( $booking_id ) {
        $data = $this->get_booking_data( $booking_id );
        if ( ! $data ) {
            return;
        }

        $subject = sprintf(
            __( 'Booking Confirmation — %s on %s', 'racc-booking' ),
            $data->service_type,
            date_i18n( 'l, j F Y', strtotime( $data->booking_date ) )
        );

        // Email to client
        $client_body = $this->render_template( 'email-confirmation', [
            'client_name'  => $data->client_name,
            'agent_name'   => $data->agent_name,
            'agent_email'  => $data->agent_email,
            'service_type' => $data->service_type,
            'booking_date' => date_i18n( 'l, j F Y', strtotime( $data->booking_date ) ),
            'time_start'   => date_i18n( 'g:i A', strtotime( $data->booking_time_start ) ),
            'time_end'     => date_i18n( 'g:i A', strtotime( $data->booking_time_end ) ),
            'notes'        => $data->notes,
            'booking_id'   => $booking_id,
            'gcal_url'     => $this->build_gcal_url( $data, $data->booking_date, $data->booking_time_start, $data->booking_time_end ),
            'zoom_link'    => $this->is_online_consultation( $data->service_type ) ? $data->agent_zoom_link : '',
        ] );

        $this->send( $data->client_email, $subject, $client_body );

        // Email to agent
        $agent_subject = sprintf(
            __( 'New Booking — %s from %s', 'racc-booking' ),
            $data->service_type,
            $data->client_name
        );

        $agent_body = $this->render_template( 'email-confirmation-agent', [
            'client_name'  => $data->client_name,
            'client_email' => $data->client_email,
            'client_phone' => $data->client_phone,
            'agent_name'   => $data->agent_name,
            'service_type' => $data->service_type,
            'booking_date' => date_i18n( 'l, j F Y', strtotime( $data->booking_date ) ),
            'time_start'   => date_i18n( 'g:i A', strtotime( $data->booking_time_start ) ),
            'time_end'     => date_i18n( 'g:i A', strtotime( $data->booking_time_end ) ),
            'notes'        => $data->notes,
            'booking_id'   => $booking_id,
        ] );

        $this->send( $data->agent_email, $agent_subject, $agent_body );

        // Notify admin
        $settings = get_option( 'racc_booking_settings', [] );
        $admin_email = $settings['notification_email'] ?? get_option( 'admin_email' );
        if ( $admin_email && $admin_email !== $data->agent_email ) {
            $this->send( $admin_email, $agent_subject, $agent_body );
        }
    }

    /**
     * Send reschedule notification to client.
     *
     * @param int    $booking_id                Booking ID.
     * @param string $old_date                  Old date (Y-m-d).
     * @param string $old_time_start            Old start time.
     * @param string $old_time_end              Old end time.
     * @param bool        $schedule_effective_changed Whether date/time changed effectively.
     * @param string|null $new_date                 New date (Y-m-d), optional override.
     * @param string|null $new_time_start           New start time (HH:MM or HH:MM:SS), optional override.
     * @param string|null $new_time_end             New end time (HH:MM or HH:MM:SS), optional override.
     */
    public function send_reschedule_notification( $booking_id, $old_date, $old_time_start, $old_time_end, $schedule_effective_changed = true, $new_date = null, $new_time_start = null, $new_time_end = null ) {
        $data = $this->get_booking_data( $booking_id );
        if ( ! $data ) {
            return;
        }

        $resolved_new_date       = ( null !== $new_date && '' !== trim( (string) $new_date ) ) ? (string) $new_date : (string) $data->booking_date;
        $resolved_new_time_start = ( null !== $new_time_start && '' !== trim( (string) $new_time_start ) ) ? (string) $new_time_start : (string) $data->booking_time_start;
        $resolved_new_time_end   = ( null !== $new_time_end && '' !== trim( (string) $new_time_end ) ) ? (string) $new_time_end : (string) $data->booking_time_end;

        $subject = sprintf(
            __( 'Booking Rescheduled — %s', 'racc-booking' ),
            $data->service_type
        );

        $body = $this->render_template( 'email-reschedule', [
            'client_name'    => $data->client_name,
            'agent_name'     => $data->agent_name,
            'agent_email'    => $data->agent_email,
            'service_type'   => $data->service_type,
            'old_date'       => $this->format_display_date( $old_date ),
            'old_time_start' => date_i18n( 'g:i A', strtotime( $old_time_start ) ),
            'old_time_end'   => date_i18n( 'g:i A', strtotime( $old_time_end ) ),
            'new_date'       => $this->format_display_date( $resolved_new_date ),
            'new_time_start' => date_i18n( 'g:i A', strtotime( $resolved_new_time_start ) ),
            'new_time_end'   => date_i18n( 'g:i A', strtotime( $resolved_new_time_end ) ),
            'booking_id'     => $booking_id,
            'gcal_url'       => $this->build_gcal_url( $data, $resolved_new_date, $resolved_new_time_start, $resolved_new_time_end ),
            'show_delete_old_event_notice' => (bool) $schedule_effective_changed,
            'zoom_link'    => $this->is_online_consultation( $data->service_type ) ? $data->agent_zoom_link : '',
        ] );

        $this->send( $data->client_email, $subject, $body );

        // Notify agent
        $this->send( $data->agent_email, $subject, $body );
    }

    /**
     * Send notification to the new consultant when a booking is reassigned to them.
     *
     * @param int $booking_id
     */
    public function send_reassign_notification( $booking_id ) {
        $data = $this->get_booking_data( $booking_id );
        if ( ! $data ) {
            return;
        }

        $subject = sprintf(
            __( 'New Booking Reassigned to You — %s', 'racc-booking' ),
            $data->service_type
        );

        $body = $this->render_template( 'email-confirmation-agent', [
            'client_name'  => $data->client_name,
            'client_email' => $data->client_email,
            'client_phone' => $data->client_phone,
            'agent_name'   => $data->agent_name,
            'service_type' => $data->service_type,
            'booking_date' => $this->format_display_date( $data->booking_date ),
            'time_start'   => date_i18n( 'g:i A', strtotime( $data->booking_time_start ) ),
            'time_end'     => date_i18n( 'g:i A', strtotime( $data->booking_time_end ) ),
            'notes'        => $data->notes,
            'booking_id'   => $booking_id,
        ] );

        // Only notify the new agent, not the client (client was not affected)
        $this->send( $data->agent_email, $subject, $body );
    }

    /**
     * Send cancellation notification.
     *
     * @param int $booking_id Booking ID.
     */
    public function send_cancellation_notification( $booking_id ) {
        $data = $this->get_booking_data( $booking_id );
        if ( ! $data ) {
            return;
        }

        $subject = sprintf(
            __( 'Booking Cancelled — %s on %s', 'racc-booking' ),
            $data->service_type,
            date_i18n( 'l, j F Y', strtotime( $data->booking_date ) )
        );

        $body = $this->render_template( 'email-cancellation', [
            'client_name'  => $data->client_name,
            'agent_name'   => $data->agent_name,
            'service_type' => $data->service_type,
            'booking_date' => date_i18n( 'l, j F Y', strtotime( $data->booking_date ) ),
            'time_start'   => date_i18n( 'g:i A', strtotime( $data->booking_time_start ) ),
            'time_end'     => date_i18n( 'g:i A', strtotime( $data->booking_time_end ) ),
            'booking_id'   => $booking_id,
        ] );

        $internal_subject = sprintf(
            __( 'Cancelled Booking Notice — %s from %s', 'racc-booking' ),
            $data->service_type,
            $data->client_name
        );

        $internal_body = $this->render_template( 'email-cancellation-internal', [
            'client_name'  => $data->client_name,
            'client_email' => $data->client_email,
            'client_phone' => $data->client_phone,
            'agent_name'   => $data->agent_name,
            'service_type' => $data->service_type,
            'booking_date' => date_i18n( 'l, j F Y', strtotime( $data->booking_date ) ),
            'time_start'   => date_i18n( 'g:i A', strtotime( $data->booking_time_start ) ),
            'time_end'     => date_i18n( 'g:i A', strtotime( $data->booking_time_end ) ),
            'booking_id'   => $booking_id,
        ] );

        $this->send( $data->client_email, $subject, $body );

        if ( ! empty( $data->agent_email ) && 0 !== strcasecmp( (string) $data->agent_email, (string) $data->client_email ) ) {
            $this->send( $data->agent_email, $internal_subject, $internal_body );
        }

        $settings    = get_option( 'racc_booking_settings', [] );
        $admin_email = $settings['notification_email'] ?? get_option( 'admin_email' );

        if (
            ! empty( $admin_email ) &&
            0 !== strcasecmp( (string) $admin_email, (string) $data->client_email ) &&
            0 !== strcasecmp( (string) $admin_email, (string) $data->agent_email )
        ) {
            $this->send( $admin_email, $internal_subject, $internal_body );
        }
    }

    /**
     * Build a Google Calendar "Add Event" URL from booking data.
     *
     * This uses the public Google Calendar render URL — no OAuth or API key needed.
     * It opens google.com/calendar in the user's browser with the event pre-filled.
     *
     * @param object $data       Booking data row (with agent_name, agent_timezone).
     * @param string $date       Date string (Y-m-d).
     * @param string $time_start Time string (H:i:s or H:i).
     * @param string $time_end   Time string (H:i:s or H:i).
     * @return string Google Calendar URL.
     */
    private function build_gcal_url( $data, $date, $time_start, $time_end ) {
        $tz_str   = ! empty( $data->agent_timezone ) ? $data->agent_timezone : wp_timezone_string();
        $timezone = new \DateTimeZone( $tz_str );

        // Build start & end DateTime in agent timezone
        $start_dt = new \DateTime( $date . ' ' . substr( $time_start, 0, 5 ), $timezone );
        $end_dt   = new \DateTime( $date . ' ' . substr( $time_end,   0, 5 ), $timezone );

        // Google Calendar expects UTC: YYYYMMDDTHHmmssZ
        $utc = new \DateTimeZone( 'UTC' );
        $start_dt->setTimezone( $utc );
        $end_dt->setTimezone( $utc );

        $start_str = $start_dt->format( 'Ymd\THis\Z' );
        $end_str   = $end_dt->format( 'Ymd\THis\Z' );

        $site_name   = get_bloginfo( 'name' );
        $title       = sprintf( '[RACC] %s - %s', $data->service_type ?? '', $data->client_name ?? '' );
        $description = implode( "\n", array_filter( [
            '=== CLIENT INFORMATION ===',
            'Name: '              . ( $data->client_name        ?? '' ),
            'Email: '             . ( $data->client_email       ?? '' ),
            'Phone: '             . ( $data->client_phone       ?? '' ),
            'Nationality: '       . ( $data->client_nationality  ?? '' ),
            'Date of Birth: '     . ( $data->client_dob         ?? '' ),
            'Country: '           . ( $data->client_country     ?? '' ),
            '',
            '=== EDUCATION ===',
            'University/School: ' . ( $data->client_university       ?? '' ),
            'Course Level: '      . ( $data->client_course_level     ?? '' ),
            'Course Major: '      . ( $data->client_course_major     ?? '' ),
            'Course Completion: ' . ( $data->client_course_completion ?? '' ),
            '',
            '=== VISA & IMMIGRATION ===',
            'Current Visa: '      . ( $data->client_visa_type   ?? '' ),
            'Visa Expiry: '       . ( $data->client_visa_expiry ?? '' ),
            '',
            '=== ADDITIONAL INFO ===',
            'Occupation: '        . ( $data->client_occupation       ?? '' ),
            'Contact Link: '      . ( $data->client_contact_link     ?? '' ),
            'Referral Source: '   . ( $data->client_referral_source  ?? '' ),
            '',
            '=== SERVICE ===',
            'Service Type: '      . ( $data->service_type ?? '' ),
            'Consultant: '        . ( $data->agent_name   ?? '' ),
            'Booking ID: #'       . ( $data->id           ?? '' ),
            ! empty( $data->notes ) ? ( "\n=== ENQUIRY ===\n" . $data->notes ) : '',
        ] ) );

        return add_query_arg( [
            'action'  => 'TEMPLATE',
            'text'    => rawurlencode( $title ),
            'dates'   => rawurlencode( $start_str . '/' . $end_str ),
            'details' => rawurlencode( $description ),
            'sf'      => 'true',
            'output'  => 'xml',
        ], 'https://calendar.google.com/calendar/render' );
    }

    /**
     * Get booking data with agent info.
     *
     * @param int $booking_id Booking ID.
     * @return object|null
     */
    private function get_booking_data( $booking_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, a.name as agent_name, a.email as agent_email, a.timezone as agent_timezone, a.zoom_link as agent_zoom_link
             FROM {$wpdb->prefix}racc_bookings b
             LEFT JOIN {$wpdb->prefix}racc_agents a ON b.agent_id = a.id
             WHERE b.id = %d",
            $booking_id
        ) );
    }

    /**
     * Check if a service requires an online consultation (Zoom).
     *
     * @param string $service_type
     * @return bool
     */
    private function is_online_consultation( $service_type ) {
        if ( empty( $service_type ) || ! class_exists( 'WooCommerce' ) ) {
            return false;
        }

        $products = get_posts( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'title'          => $service_type,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ] );

        if ( ! empty( $products ) ) {
            return get_post_meta( $products[0], '_racc_booking_online_meeting', true ) === 'yes';
        }

        return false;
    }

    /**
     * Render an email template.
     *
     * @param string $template Template name (without .php).
     * @param array  $vars     Variables to pass to the template.
     * @return string Rendered HTML.
     */
    private function render_template( $template, $vars = [] ) {
        $file = RACC_BOOKING_PATH . 'templates/' . $template . '.php';

        if ( ! file_exists( $file ) ) {
            // Fallback: simple text email
            return $this->simple_email_body( $vars );
        }

        extract( $vars, EXTR_SKIP );
        ob_start();
        include $file;
        return ob_get_clean();
    }

    /**
     * Simple fallback email body.
     */
    private function simple_email_body( $vars ) {
        $lines = [];
        foreach ( $vars as $key => $value ) {
            if ( ! empty( $value ) ) {
                $label   = ucwords( str_replace( '_', ' ', $key ) );
                $lines[] = "{$label}: {$value}";
            }
        }
        return implode( "\n", $lines );
    }

    /**
     * Format date for human-readable email output.
     *
     * @param mixed $date Raw date string.
     * @return string
     */
    private function format_display_date( $date ) {
        $date = trim( (string) $date );
        if ( '' === $date ) {
            return '';
        }

        if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
            $year  = (int) $m[1];
            $month = (int) $m[2];
            $day   = (int) $m[3];

            if ( $year >= 1900 && checkdate( $month, $day, $year ) ) {
                return date_i18n( 'l, j F Y', strtotime( $date ) );
            }
        }

        return $date;
    }

    /**
     * Send an HTML email using wp_mail().
     *
     * @param string $to      Recipient.
     * @param string $subject Subject.
     * @param string $body    HTML body.
     * @return bool
     */
    private function send( $to, $subject, $body ) {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        /**
         * Filter email headers for booking emails.
         *
         * Keep sender identity controlled by SMTP/mailer plugin by default.
         *
         * @param array  $headers Email headers.
         * @param string $to      Recipient email.
         * @param string $subject Email subject.
         * @param string $body    Email body.
         */
        $headers = apply_filters( 'racc_booking_email_headers', $headers, $to, $subject, $body );

        return wp_mail( $to, $subject, $body, $headers );
    }
}
