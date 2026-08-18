<?php
/**
 * Core WooCommerce Bridge logic.
 *
 * Responsibilities:
 *  1. Replace RACC Booking services with WooCommerce products.
 *  2. Override initial booking status to pending_payment.
 *  3. Suppress confirmation email until payment is complete.
 *  4. Create a WooCommerce order after booking is inserted.
 *  5. Inject checkout_url into the REST response.
 *  6. On WC payment → confirm booking, fire emails, sync Calendar + AgentCIS.
 *  7. On WC cancellation → cancel booking, remove Calendar event.
 *  8. Admin settings page under WooCommerce > Booking Services.
 *
 * @package RACC_Booking_Woo
 */

namespace RACC_Booking_Woo;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Woo_Bridge {

    /** @var array Plugin settings from DB. */
    private array $settings;

    public function __construct() {
        $this->settings = (array) get_option( 'racc_woo_bridge_settings', [
            'category_slug'        => 'booking-services',
            'pending_hold_minutes' => 30,
            'price_display'        => 'yes',
        ] );

        // ── RACC Booking filters ───────────────────────────────────────────
        add_filter( 'racc_booking_services',                  [ $this, 'filter_services' ] );
        add_filter( 'racc_booking_initial_status',            [ $this, 'set_pending_payment_status' ] );
        add_filter( 'racc_booking_send_confirmation_email',   [ $this, 'suppress_confirmation_email' ], 10, 2 );
        add_filter( 'racc_booking_created_response',          [ $this, 'inject_checkout_url' ], 10, 2 );

        // After booking inserted → create WC order.
        add_action( 'racc_booking_created', [ $this, 'create_woo_order' ] );

        // ── WooCommerce order status hooks ────────────────────────────────
        add_action( 'woocommerce_payment_complete',                    [ $this, 'on_payment_complete' ] );
        add_action( 'woocommerce_order_status_processing',             [ $this, 'on_order_confirmed' ], 10, 2 );
        add_action( 'woocommerce_order_status_completed',              [ $this, 'on_order_confirmed' ], 10, 2 );
        add_action( 'woocommerce_order_status_cancelled',              [ $this, 'on_order_cancelled' ], 10, 2 );
        add_action( 'woocommerce_order_status_failed',                 [ $this, 'on_order_cancelled' ], 10, 2 );
        add_action( 'woocommerce_order_status_refunded',               [ $this, 'on_order_cancelled' ], 10, 2 );

        // Cleanup expired pending bookings (cron).
        add_action( 'racc_woo_cleanup_pending_bookings', [ $this, 'cleanup_pending_bookings' ] );
        if ( ! wp_next_scheduled( 'racc_woo_cleanup_pending_bookings' ) ) {
            wp_schedule_event( time(), 'hourly', 'racc_woo_cleanup_pending_bookings' );
        }

        // ── Admin ─────────────────────────────────────────────────────────
        add_action( 'admin_menu',            [ $this, 'register_admin_menu' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'admin_footer',          [ $this, 'render_product_field_ui_script' ] );
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'render_product_consultant_field' ] );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_product_consultant_field' ] );
        add_filter( 'rest_request_before_callbacks', [ $this, 'validate_booking_request_assignment' ], 10, 3 );

        // ── Frontend JS ───────────────────────────────────────────────────
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );

        // ── Woo checkout UX for booking payment ──────────────────────────
        add_filter( 'woocommerce_checkout_fields', [ $this, 'simplify_checkout_fields_for_booking' ], 99 );
        add_filter( 'woocommerce_enable_order_notes_field', [ $this, 'maybe_disable_order_notes_field' ] );
        add_filter( 'woocommerce_enable_checkout_login_reminder', [ $this, 'maybe_disable_checkout_login_reminder' ] );
        add_filter( 'woocommerce_coupons_enabled', [ $this, 'maybe_disable_coupons_for_booking_checkout' ] );

        // ── RACC services REST: add price info ────────────────────────────
        add_filter( 'racc_booking_services', [ $this, 'maybe_add_price_to_services' ], 20 );

        // ── Google Calendar: thank you page ───────────────────────────────
        add_action( 'woocommerce_thankyou', [ $this, 'render_add_to_google_calendar' ], 20 );

        // ── Data alignment: ensure old bookings map to Woo products ───────
        $this->maybe_backfill_booking_product_mapping();
        $this->maybe_backfill_product_slot_durations();
    }

    // =========================================================================
    // 1. Services: Replace with WooCommerce products
    // =========================================================================

    /**
     * Pull service names from WooCommerce products in the configured category.
     * Falls back to the original list if category is not configured or empty.
     *
     * @param array $services Original array of service name strings.
     * @return array
     */
    public function filter_services( array $services ): array {
        $category_slug = $this->settings['category_slug'] ?? 'booking-services';
        if ( empty( $category_slug ) ) {
            return $services;
        }

        $products = wc_get_products( [
            'category' => [ $category_slug ],
            'status'   => 'publish',
            'limit'    => -1,
            'orderby'  => 'menu_order',
            'order'    => 'ASC',
        ] );

        if ( empty( $products ) ) {
            return $services; // fallback to agent services
        }

        return array_map( fn( $p ) => $p->get_name(), $products );
    }

    /**
     * Optionally attach price to each service for frontend display.
     * Only runs when price_display setting is 'yes'.
     *
     * NOTE: REST /racc/v1/services currently returns string[].
     * This filter runs AFTER filter_services (priority 20) but we keep the
     * same string[] format for backwards compatibility — price is injected
     * via a separate wp_localize_script variable (raccWooBridge.services).
     *
     * @param array $services
     * @return array Unchanged (price passed via JS variable instead).
     */
    public function maybe_add_price_to_services( array $services ): array {
        return $services;
    }

    // =========================================================================
    // 2. Override initial booking status
    // =========================================================================

    public function set_pending_payment_status(): string {
        return 'pending_payment';
    }

    // =========================================================================
    // 3. Suppress confirmation email until payment arrives
    // =========================================================================

    /**
     * @param bool $should_send
     * @param int  $booking_id
     * @return bool
     */
    public function suppress_confirmation_email( bool $should_send, int $booking_id ): bool {
        return false; // Emails will be sent from on_order_confirmed().
    }

    // =========================================================================
    // 4. Create WooCommerce order after booking is inserted
    // =========================================================================

    /**
     * Creates a WC order for the booking.
     * Stores woo_order_id in racc_bookings table.
     * Fired on racc_booking_created action.
     *
     * @param int $booking_id
     */
    public function create_woo_order( int $booking_id ): void {
        global $wpdb;

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}racc_bookings WHERE id = %d",
            $booking_id
        ) );

        if ( ! $booking ) {
            return;
        }

        // Find the WC product matching service name.
        $product = $this->find_product_by_service_name( $booking->service_type );
        if ( ! $product ) {
            // No matching product — log and leave booking as is.
            // Admin should check WooCommerce product setup.
            error_log( "[RACC Woo Bridge] No WooCommerce product found for service: {$booking->service_type}. Booking #{$booking_id} left as pending_payment." );
            return;
        }

        // Split client name into first / last.
        $name_parts = explode( ' ', $booking->client_name, 2 );
        $first_name = $name_parts[0] ?? '';
        $last_name  = $name_parts[1] ?? '';

        // Build order.
        $order = wc_create_order( [
            'status'      => 'pending',
            'customer_id' => $this->get_or_create_customer_id( $booking->client_email, $first_name, $last_name ),
        ] );

        if ( is_wp_error( $order ) ) {
            error_log( "[RACC Woo Bridge] Failed to create WC order for booking #{$booking_id}: " . $order->get_error_message() );
            return;
        }

        // Add product line item.
        $order->add_product( $product, 1 );

        // Billing info.
        $order->set_billing_first_name( $first_name );
        $order->set_billing_last_name( $last_name );
        $order->set_billing_email( $booking->client_email );
        $order->set_billing_phone( $booking->client_phone );
        $order->set_billing_country( $this->get_default_billing_country() );
        $order->set_billing_address_1( __( 'Booking payment', 'racc-booking-woo' ) );
        $order->set_billing_city( __( 'Not required', 'racc-booking-woo' ) );
        $order->set_billing_postcode( '0000' );

        // Store booking ID for reverse lookup.
        $order->update_meta_data( '_racc_booking_id', $booking_id );
        $order->update_meta_data( '_racc_product_id', $product->get_id() );

        // Store booking summary as order note.
        $formatted_date  = gmdate( 'd M Y', strtotime( $booking->booking_date ) );
        $formatted_start = substr( $booking->booking_time_start, 0, 5 );
        $formatted_end   = substr( $booking->booking_time_end, 0, 5 );
        $order->add_order_note( sprintf(
            __( 'RACC Booking #%1$d — %2$s on %3$s, %4$s–%5$s', 'racc-booking-woo' ),
            $booking_id,
            esc_html( $booking->service_type ),
            esc_html( $formatted_date ),
            esc_html( $formatted_start ),
            esc_html( $formatted_end )
        ), false );

        $order->calculate_totals();
        $order->save();

        $order_id = $order->get_id();

        // Store woo_order_id in bookings table.
        $wpdb->update(
            $wpdb->prefix . 'racc_bookings',
            [
                'woo_product_id' => $product->get_id(),
                'woo_order_id'   => $order_id,
            ],
            [ 'id' => $booking_id ],
            [ '%d', '%d' ],
            [ '%d' ]
        );

        // Store order_id in transient so inject_checkout_url can read it.
        set_transient( "racc_woo_order_{$booking_id}", $order_id, MINUTE_IN_SECONDS * 5 );

        // Free product flow: auto-complete payment and confirm booking.
        if ( ! $order->needs_payment() || (float) $order->get_total() <= 0 ) {
            $order->add_order_note( __( 'Free booking product detected. Payment step skipped and booking confirmed automatically.', 'racc-booking-woo' ), false );

            if ( method_exists( $order, 'payment_complete' ) ) {
                $order->payment_complete();
            }

            if ( $order->has_status( 'pending' ) ) {
                $order->update_status( 'completed', __( 'Auto-completed for free booking.', 'racc-booking-woo' ) );
            }

            set_transient( "racc_woo_free_booking_{$booking_id}", 1, MINUTE_IN_SECONDS * 10 );
        }

        error_log( "[RACC Woo Bridge] Created WC order #{$order_id} for booking #{$booking_id}." );
    }

    // =========================================================================
    // 5. Inject checkout_url into REST response
    // =========================================================================

    /**
     * @param array $response_data
     * @param int   $booking_id
     * @return array
     */
    public function inject_checkout_url( array $response_data, int $booking_id ): array {
        // Retrieve the order ID we just stored.
        $order_id = get_transient( "racc_woo_order_{$booking_id}" );

        if ( ! $order_id ) {
            global $wpdb;
            $order_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT woo_order_id FROM {$wpdb->prefix}racc_bookings WHERE id = %d LIMIT 1",
                $booking_id
            ) );
        }

        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                if ( ! $order->needs_payment() || (float) $order->get_total() <= 0 || get_transient( "racc_woo_free_booking_{$booking_id}" ) ) {
                    $response_data['free_booking_confirmed'] = true;
                    $response_data['message']                = __( 'Your free appointment has been confirmed successfully.', 'racc-booking-woo' );
                    unset( $response_data['checkout_url'] );
                } else {
                    $response_data['checkout_url'] = $order->get_checkout_payment_url();
                    $response_data['message']      = __( 'Appointment reserved! Please complete payment to confirm your booking.', 'racc-booking-woo' );
                }

                $response_data['order_id']     = $order_id;
            }
        }

        return $response_data;
    }

    /**
     * Validate that the selected consultant is assigned to the selected Woo product.
     *
     * @param mixed            $response
     * @param array            $handler
     * @param \WP_REST_Request $request
     * @return mixed
     */
    public function validate_booking_request_assignment( $response, array $handler, \WP_REST_Request $request ) {
        if ( '/racc/v1/bookings' !== $request->get_route() || 'POST' !== $request->get_method() ) {
            return $response;
        }

        $service_name = sanitize_text_field( (string) $request->get_param( 'service_type' ) );
        $agent_id     = absint( $request->get_param( 'agent_id' ) );

        if ( '' === $service_name || $agent_id <= 0 ) {
            return $response;
        }

        $product = $this->find_product_by_service_name( $service_name );
        if ( ! $product ) {
            return new \WP_Error(
                'racc_woo_service_not_found',
                __( 'The selected service is not linked to a WooCommerce product.', 'racc-booking-woo' ),
                [ 'status' => 400 ]
            );
        }

        $consultant_ids = $this->get_product_consultant_ids( $product->get_id() );

        if ( empty( $consultant_ids ) ) {
            return new \WP_Error(
                'racc_woo_consultants_not_assigned',
                __( 'This service has no assigned consultants yet. Please contact support or choose another service.', 'racc-booking-woo' ),
                [ 'status' => 400 ]
            );
        }

        if ( ! in_array( $agent_id, $consultant_ids, true ) ) {
            return new \WP_Error(
                'racc_woo_consultant_not_allowed',
                __( 'The selected consultant is not available for this service.', 'racc-booking-woo' ),
                [ 'status' => 400 ]
            );
        }

        // Normalize payload for booking data consistency.
        $request->set_param( 'woo_product_id', (int) $product->get_id() );
        $request->set_param( 'service_type', (string) $product->get_name() );

        return $response;
    }

    // =========================================================================
    // 6. On WC payment → confirm booking
    // =========================================================================

    /**
     * Fired when payment is complete (covers all payment gateways).
     * Also acts as safety net for `woocommerce_payment_complete` which is
     * fired by direct payment methods.
     *
     * @param int $order_id
     */
    public function on_payment_complete( int $order_id ): void {
        $this->confirm_booking_for_order( $order_id );
    }

    /**
     * Fired when order moves to processing or completed status.
     *
     * @param int       $order_id
     * @param \WC_Order $order
     */
    public function on_order_confirmed( int $order_id, \WC_Order $order ): void {
        $this->confirm_booking_for_order( $order_id );
    }

    /**
     * Confirm the booking linked to a WC order.
     *
     * @param int $order_id
     */
    private function confirm_booking_for_order( int $order_id ): void {
        global $wpdb;

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}racc_bookings WHERE woo_order_id = %d AND status = 'pending_payment'",
            $order_id
        ) );

        if ( ! $booking ) {
            return; // Already confirmed or not a RACC booking.
        }

        $booking_id = (int) $booking->id;

        $wpdb->update(
            $wpdb->prefix . 'racc_bookings',
            [ 'status' => 'confirmed' ],
            [ 'id' => $booking_id ],
            [ '%s' ],
            [ '%d' ]
        );

        // Send confirmation email now.
        if ( class_exists( 'RACC_Booking\Email_Notifier' ) ) {
            $email_notifier = new \RACC_Booking\Email_Notifier();
            $email_notifier->send_booking_confirmation( $booking_id );
        }

        // Trigger AgentCIS sync.
        do_action( 'racc_booking_confirmed_after_payment', $booking_id, $order_id );

        // Sync AgentCIS if available.
        if ( class_exists( 'RACC_Booking\Agentcis' ) ) {
            $agentcis = new \RACC_Booking\Agentcis();
            if ( method_exists( $agentcis, 'sync_booking' ) ) {
                $agentcis->sync_booking( $booking_id );
            }
        }

        // Log for order notes.
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->add_order_note( sprintf(
                __( 'RACC Booking #%d confirmed and emails dispatched.', 'racc-booking-woo' ),
                $booking_id
            ), false );
        }

        error_log( "[RACC Woo Bridge] Booking #{$booking_id} confirmed after payment for WC order #{$order_id}." );
    }

    // =========================================================================
    // 7. On WC cancellation → cancel booking & delete Calendar event
    // =========================================================================

    /**
     * @param int       $order_id
     * @param \WC_Order $order
     */
    public function on_order_cancelled( int $order_id, \WC_Order $order ): void {
        global $wpdb;

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}racc_bookings WHERE woo_order_id = %d AND status = 'pending_payment'",
            $order_id
        ) );

        if ( ! $booking ) {
            return;
        }

        $booking_id = (int) $booking->id;

        $wpdb->update(
            $wpdb->prefix . 'racc_bookings',
            [ 'status' => 'cancelled' ],
            [ 'id' => $booking_id ],
            [ '%s' ],
            [ '%d' ]
        );

        // Delete Google Calendar event that was holding the slot.
        if ( ! empty( $booking->google_event_id ) && class_exists( 'RACC_Booking\Google_Calendar' ) ) {
            $gcal = new \RACC_Booking\Google_Calendar();
            $gcal->delete_event( (int) $booking->agent_id, $booking->google_event_id );
        }

        $order->add_order_note( sprintf(
            __( 'RACC Booking #%d cancelled and slot released.', 'racc-booking-woo' ),
            $booking_id
        ), false );

        error_log( "[RACC Woo Bridge] Booking #{$booking_id} cancelled after WC order #{$order_id} was cancelled/failed/refunded." );
    }

    // =========================================================================
    // 8. Cron: cleanup stale pending_payment bookings
    // =========================================================================

    /**
     * Cancel bookings that have been stuck in pending_payment beyond the
     * configured hold window (default 30 min). Frees slots automatically.
     */
    public function cleanup_pending_bookings(): void {
        global $wpdb;

        $hold_minutes = (int) ( $this->settings['pending_hold_minutes'] ?? 30 );
        $cutoff       = gmdate( 'Y-m-d H:i:s', time() - $hold_minutes * MINUTE_IN_SECONDS );

        $expired = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}racc_bookings
             WHERE status = 'pending_payment'
             AND created_at < %s",
            $cutoff
        ) );

        foreach ( $expired as $booking ) {
            // Check if WC order is still pending — if paid, skip.
            if ( $booking->woo_order_id ) {
                $order = wc_get_order( (int) $booking->woo_order_id );
                if ( $order && in_array( $order->get_status(), [ 'processing', 'completed' ], true ) ) {
                    continue; // Paid, let on_order_confirmed handle it.
                }
            }

            // Cancel.
            $wpdb->update(
                $wpdb->prefix . 'racc_bookings',
                [ 'status' => 'cancelled' ],
                [ 'id' => (int) $booking->id ],
                [ '%s' ],
                [ '%d' ]
            );

            // Release Google Calendar event.
            if ( ! empty( $booking->google_event_id ) && class_exists( 'RACC_Booking\Google_Calendar' ) ) {
                $gcal = new \RACC_Booking\Google_Calendar();
                $gcal->delete_event( (int) $booking->agent_id, $booking->google_event_id );
            }

            error_log( "[RACC Woo Bridge] Expired pending booking #{$booking->id} cancelled by cron." );
        }
    }

    // =========================================================================
    // 9. Admin settings
    // =========================================================================

    public function register_admin_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Booking Bridge Settings', 'racc-booking-woo' ),
            __( 'Booking Bridge', 'racc-booking-woo' ),
            'manage_woocommerce',
            'racc-booking-woo-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings(): void {
        register_setting( 'racc_woo_bridge_settings_group', 'racc_woo_bridge_settings', [
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
        ] );
    }

    public function sanitize_settings( $input ): array {
        return [
            'category_slug'        => sanitize_text_field( $input['category_slug'] ?? 'booking-services' ),
            'pending_hold_minutes' => absint( $input['pending_hold_minutes'] ?? 30 ),
            'price_display'        => ( isset( $input['price_display'] ) && $input['price_display'] === 'yes' ) ? 'yes' : 'no',
        ];
    }

    public function render_settings_page(): void {
        // Build list of all product categories for the dropdown.
        $categories = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
        ] );

        $saved_slug   = $this->settings['category_slug']        ?? 'booking-services';
        $hold_minutes = $this->settings['pending_hold_minutes'] ?? 30;
        $price_disp   = $this->settings['price_display']        ?? 'yes';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'RACC Booking — WooCommerce Bridge Settings', 'racc-booking-woo' ); ?></h1>

            <div class="notice notice-info">
                <p><?php esc_html_e( 'Services shown in the booking form are pulled from WooCommerce products in the selected category. Each product name = one service. The product price is charged at checkout.', 'racc-booking-woo' ); ?></p>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'racc_woo_bridge_settings_group' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="racc_woo_category"><?php esc_html_e( 'Product Category for Services', 'racc-booking-woo' ); ?></label>
                        </th>
                        <td>
                            <select id="racc_woo_category" name="racc_woo_bridge_settings[category_slug]">
                                <?php if ( ! is_wp_error( $categories ) ) : ?>
                                    <?php foreach ( $categories as $cat ) : ?>
                                        <option value="<?php echo esc_attr( $cat->slug ); ?>" <?php selected( $saved_slug, $cat->slug ); ?>>
                                            <?php echo esc_html( $cat->name . ' (' . $cat->slug . ')' ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s = WooCommerce product categories URL */
                                    esc_html__( 'Create a category (e.g. "Booking Services") and add your consultation products there. %s', 'racc-booking-woo' ),
                                    '<a href="' . esc_url( admin_url( 'edit-tags.php?taxonomy=product_cat&post_type=product' ) ) . '" target="_blank">' . esc_html__( 'Manage categories →', 'racc-booking-woo' ) . '</a>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="racc_woo_hold"><?php esc_html_e( 'Slot Hold Duration (minutes)', 'racc-booking-woo' ); ?></label>
                        </th>
                        <td>
                            <input type="number" id="racc_woo_hold" name="racc_woo_bridge_settings[pending_hold_minutes]"
                                   value="<?php echo esc_attr( $hold_minutes ); ?>" min="5" max="120" step="5" />
                            <p class="description"><?php esc_html_e( 'Booking slot is released and status changed to cancelled if payment is not completed within this time.', 'racc-booking-woo' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Show Price on Booking Form', 'racc-booking-woo' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="racc_woo_bridge_settings[price_display]" value="yes" <?php checked( $price_disp, 'yes' ); ?> />
                                <?php esc_html_e( 'Display service price on booking form service cards', 'racc-booking-woo' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr />
            <h2><?php esc_html_e( 'Products in Selected Category', 'racc-booking-woo' ); ?></h2>
            <?php
            $products = wc_get_products( [
                'category' => [ $saved_slug ],
                'status'   => 'publish',
                'limit'    => -1,
            ] );

            $products_without_consultants = [];

            foreach ( $products as $product ) {
                if ( empty( $this->get_product_consultant_ids( $product->get_id() ) ) ) {
                    $products_without_consultants[] = $product;
                }
            }

            if ( ! empty( $products_without_consultants ) ) {
                echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Action required:', 'racc-booking-woo' ) . '</strong> ' . esc_html__( 'The following products do not have consultant assignments yet, so they cannot be booked.', 'racc-booking-woo' ) . '</p><ul style="margin-left:18px;list-style:disc;">';
                foreach ( $products_without_consultants as $warning_product ) {
                    echo '<li><a href="' . esc_url( get_edit_post_link( $warning_product->get_id() ) ) . '">' . esc_html( $warning_product->get_name() ) . '</a></li>';
                }
                echo '</ul></div>';
            }

            if ( empty( $products ) ) {
                echo '<p style="color:#d63638;">' . esc_html__( 'No published products found in the selected category. No WooCommerce booking services are available yet.', 'racc-booking-woo' ) . '</p>';
            } else {
                echo '<table class="widefat striped"><thead><tr>';
                echo '<th>' . esc_html__( 'Product Name (= Service)', 'racc-booking-woo' ) . '</th>';
                echo '<th>' . esc_html__( 'Price', 'racc-booking-woo' ) . '</th>';
                echo '<th>' . esc_html__( 'Assigned Consultants', 'racc-booking-woo' ) . '</th>';
                echo '<th>' . esc_html__( 'Edit', 'racc-booking-woo' ) . '</th>';
                echo '</tr></thead><tbody>';
                foreach ( $products as $p ) {
                    $consultant_ids   = array_map( 'absint', (array) get_post_meta( $p->get_id(), '_racc_booking_consultant_ids', true ) );
                    $consultant_names = [];

                    foreach ( $this->get_agent_choices() as $agent_id => $agent_name ) {
                        if ( in_array( (int) $agent_id, $consultant_ids, true ) ) {
                            $consultant_names[] = $agent_name;
                        }
                    }

                    echo '<tr>';
                    echo '<td>' . esc_html( $p->get_name() ) . '</td>';
                    echo '<td>' . wp_kses_post( $p->get_price_html() ) . '</td>';
                    echo '<td>' . ( $consultant_names ? esc_html( implode( ', ', $consultant_names ) ) : '<span style="color:#d63638;font-weight:600;">' . esc_html__( 'Not assigned', 'racc-booking-woo' ) . '</span>' ) . '</td>';
                    echo '<td><a href="' . esc_url( get_edit_post_link( $p->get_id() ) ) . '">' . esc_html__( 'Edit', 'racc-booking-woo' ) . '</a></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
            ?>
        </div>
        <?php
    }

    // =========================================================================
    // 10. Assets
    // =========================================================================

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'racc-booking-woo-settings' ) === false ) {
            return;
        }
        // No extra assets needed for now.
    }

    public function enqueue_frontend_assets(): void {
        if ( ! is_page() && ! is_singular() ) {
            return;
        }

        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'racc_booking_form' ) ) {
            return;
        }

        $category_slug = $this->settings['category_slug'] ?? 'booking-services';
        $price_display = $this->settings['price_display']  ?? 'yes';
        $service_map   = $this->get_product_service_map( $category_slug );

        // Build product price map for frontend: { "Service Name": "AUD 150.00" }
        $price_map = [];
        if ( $price_display === 'yes' ) {
            foreach ( $service_map as $service_name => $service_data ) {
                $price_map[ $service_name ] = (string) ( $service_data['price_html'] ?? '' );
            }
        }

        wp_enqueue_script(
            'racc-booking-woo-redirect',
            RACC_BOOKING_WOO_URL . 'assets/js/checkout-redirect.js',
            [ 'jquery' ],
            RACC_BOOKING_WOO_VERSION,
            true
        );

        wp_localize_script( 'racc-booking-woo-redirect', 'raccWooBridge', [
            'priceDisplay' => $price_display,
            'priceMap'     => $price_map,
            'serviceMap'   => $service_map,
            'i18n'         => [
                'reservedMessage' => __( 'Appointment reserved! Redirecting to payment…', 'racc-booking-woo' ),
            ],
        ] );
    }

    /**
     * Hide non-essential checkout fields for booking payment orders.
     * Keeps only first name, last name, email, phone and payment fields.
     *
     * @param array $fields
     * @return array
     */
    public function simplify_checkout_fields_for_booking( array $fields ): array {
        if ( ! $this->is_booking_payment_context() ) {
            return $fields;
        }

        // Keep billing fields that payment gateways commonly inspect for eligibility.
        $allowed_billing_fields = [
            'billing_first_name',
            'billing_last_name',
            'billing_email',
            'billing_phone',
            'billing_country',
            'billing_state',
            'billing_city',
            'billing_address_1',
            'billing_address_2',
            'billing_postcode',
        ];

        if ( isset( $fields['billing'] ) && is_array( $fields['billing'] ) ) {
            foreach ( array_keys( $fields['billing'] ) as $field_key ) {
                if ( ! in_array( $field_key, $allowed_billing_fields, true ) ) {
                    unset( $fields['billing'][ $field_key ] );
                }
            }
        }

        // Never ask shipping fields for booking payment context.
        if ( isset( $fields['shipping'] ) ) {
            $fields['shipping'] = [];
        }

        return $fields;
    }

    /**
     * Disable order notes field on booking payment flow.
     *
     * @param bool $enabled
     * @return bool
     */
    public function maybe_disable_order_notes_field( bool $enabled ): bool {
        if ( $this->is_booking_payment_context() ) {
            return false;
        }

        return $enabled;
    }

    /**
     * Disable "returning customer" login reminder on booking payment flow.
     *
     * @param bool $enabled
     * @return bool
     */
    public function maybe_disable_checkout_login_reminder( bool $enabled ): bool {
        if ( $this->is_booking_payment_context() ) {
            return false;
        }

        return $enabled;
    }

    /**
     * Disable coupon input on booking payment flow.
     *
     * @param bool $enabled
     * @return bool
     */
    public function maybe_disable_coupons_for_booking_checkout( bool $enabled ): bool {
        if ( $this->is_booking_payment_context() ) {
            return false;
        }

        return $enabled;
    }

    /**
     * Render a multi-select field on WooCommerce product edit screen.
     */
    public function render_product_consultant_field(): void {
        global $post;

        if ( ! $post || 'product' !== $post->post_type ) {
            return;
        }

        $selected = array_map( 'absint', (array) get_post_meta( $post->ID, '_racc_booking_consultant_ids', true ) );
        $slot_duration = absint( get_post_meta( $post->ID, '_racc_booking_slot_duration', true ) );
        if ( $slot_duration <= 0 ) {
            $slot_duration = 60;
        }
        $agents   = $this->get_agent_choices();
        $is_online = get_post_meta( $post->ID, '_racc_booking_online_meeting', true ) === 'yes';

        echo '<div class="options_group">';
        wp_nonce_field( 'racc_booking_woo_save_product_consultants', 'racc_booking_woo_product_consultants_nonce' );
        
        woocommerce_wp_checkbox( [
            'id'            => '_racc_booking_online_meeting',
            'label'         => esc_html__( 'Online Consultation (Zoom)', 'racc-booking-woo' ),
            'description'   => esc_html__( 'If checked, the consultant\'s Zoom link will be sent in booking confirmation and reschedule emails.', 'racc-booking-woo' ),
            'desc_tip'      => true,
            'value'         => $is_online ? 'yes' : 'no',
        ] );

        echo '<p class="form-field">';
        echo '<label for="racc_booking_slot_duration">' . esc_html__( 'Slot Duration (minutes)', 'racc-booking-woo' ) . '</label>';
        echo '<select id="racc_booking_slot_duration" name="racc_booking_slot_duration">';
        foreach ( [ 15, 30, 45, 60, 90, 120 ] as $duration ) {
            echo '<option value="' . esc_attr( (string) $duration ) . '" ' . selected( $slot_duration, $duration, false ) . '>' . esc_html( (string) $duration ) . ' ' . esc_html__( 'minutes', 'racc-booking-woo' ) . '</option>';
        }
        echo '</select>';
        echo '<span class="description" style="display:block; margin-top:8px;">' . esc_html__( 'This duration is used to generate booking time slots for this service/product.', 'racc-booking-woo' ) . '</span>';
        echo '</p>';
        echo '<p class="form-field">';
        echo '<label for="racc_booking_consultant_ids">' . esc_html__( 'Allowed Consultants', 'racc-booking-woo' ) . '</label>';
        echo '<span class="wrap">';
        echo '<select id="racc_booking_consultant_ids" class="wc-enhanced-select" data-placeholder="' . esc_attr__( 'Search consultants…', 'racc-booking-woo' ) . '" name="racc_booking_consultant_ids[]" multiple="multiple" style="width:100%; min-height:140px;">';

        foreach ( $agents as $agent_id => $agent_name ) {
            echo '<option value="' . esc_attr( (string) $agent_id ) . '" ' . selected( in_array( (int) $agent_id, $selected, true ), true, false ) . '>' . esc_html( $agent_name ) . '</option>';
        }

        echo '</select>';
        echo '<span class="racc-woo-consultant-actions" style="display:flex;gap:8px;align-items:center;margin-top:8px;">';
        echo '<button type="button" class="button button-secondary" id="racc-woo-select-all-consultants">' . esc_html__( 'Select all', 'racc-booking-woo' ) . '</button>';
        echo '<button type="button" class="button button-link-delete" id="racc-woo-clear-consultants">' . esc_html__( 'Clear', 'racc-booking-woo' ) . '</button>';
        echo '<span id="racc-woo-consultant-count" class="description"></span>';
        echo '</span>';
        echo '</span>';
        echo '<span class="description" style="display:block; margin-top:8px;">' . esc_html__( 'If empty, the booking form falls back to the original service-to-consultant matching from RACC Booking. If selected, only these consultants can be chosen for this product/service.', 'racc-booking-woo' ) . '</span>';
        if ( empty( $agents ) ) {
            echo '<span class="description" style="display:block; margin-top:6px; color:#b32d2e;">' . esc_html__( 'No active consultants found. Please add consultants in RACC Booking first.', 'racc-booking-woo' ) . '</span>';
        }
        echo '</p>';
        echo '</div>';
    }

    /**
     * Improve Woo product consultant field UX (search, count, quick actions).
     */
    public function render_product_field_ui_script(): void {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || 'product' !== $screen->post_type ) {
            return;
        }
        ?>
        <script>
            jQuery(function($){
                var $select = $('#racc_booking_consultant_ids');
                if (!$select.length) {
                    return;
                }

                var $count = $('#racc-woo-consultant-count');
                var updateCount = function () {
                    var selectedCount = ($select.val() || []).length;
                    var totalCount = $select.find('option').length;
                    $count.text(selectedCount + ' / ' + totalCount + ' <?php echo esc_js( __( 'selected', 'racc-booking-woo' ) ); ?>');
                };

                $('#racc-woo-select-all-consultants').on('click', function () {
                    $select.find('option').prop('selected', true);
                    $select.trigger('change');
                });

                $('#racc-woo-clear-consultants').on('click', function () {
                    $select.val(null).trigger('change');
                });

                $select.on('change', updateCount);
                updateCount();
            });
        </script>
        <?php
    }

    /**
     * Save consultant assignments for a WooCommerce product.
     *
     * @param int $product_id
     */
    public function save_product_consultant_field( int $product_id ): void {
        if ( ! isset( $_POST['racc_booking_woo_product_consultants_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['racc_booking_woo_product_consultants_nonce'] ) ), 'racc_booking_woo_save_product_consultants' ) ) {
            return;
        }

        $slot_duration = absint( $_POST['racc_booking_slot_duration'] ?? 60 );
        if ( ! in_array( $slot_duration, [ 15, 30, 45, 60, 90, 120 ], true ) ) {
            $slot_duration = 60;
        }

        $consultant_ids = isset( $_POST['racc_booking_consultant_ids'] ) ? (array) wp_unslash( $_POST['racc_booking_consultant_ids'] ) : [];
        $consultant_ids = array_values( array_filter( array_map( 'absint', $consultant_ids ) ) );

        update_post_meta( $product_id, '_racc_booking_slot_duration', $slot_duration );
        update_post_meta( $product_id, '_racc_booking_consultant_ids', $consultant_ids );

        $is_online = isset( $_POST['_racc_booking_online_meeting'] ) ? 'yes' : 'no';
        update_post_meta( $product_id, '_racc_booking_online_meeting', $is_online );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Find a WooCommerce product by service name (exact match, case-insensitive).
     *
     * @param string $service_name
     * @return \WC_Product|null
     */
    private function find_product_by_service_name( string $service_name ): ?\WC_Product {
        $category_slug = $this->settings['category_slug'] ?? 'booking-services';

        $products = wc_get_products( [
            'category' => [ $category_slug ],
            'status'   => 'publish',
            'limit'    => -1,
        ] );

        foreach ( $products as $p ) {
            if ( strtolower( $p->get_name() ) === strtolower( $service_name ) ) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Build a service map keyed by product/service name.
     *
     * @param string $category_slug
     * @return array<string, array<string, mixed>>
     */
    private function get_product_service_map( string $category_slug ): array {
        $products = wc_get_products( [
            'category' => [ $category_slug ],
            'status'   => 'publish',
            'limit'    => -1,
            'orderby'  => 'menu_order',
            'order'    => 'ASC',
        ] );

        $service_map = [];

        foreach ( $products as $product ) {
            $service_map[ $product->get_name() ] = [
                'product_id'      => $product->get_id(),
                'consultant_ids'  => $this->get_product_consultant_ids( $product->get_id() ),
                'slot_duration'   => $this->get_product_slot_duration( $product->get_id() ),
                'price_html'      => wp_strip_all_tags( $product->get_price_html() ),
            ];
        }

        return $service_map;
    }

    /**
     * Return active RACC consultants as id => name.
     *
     * @return array<int, string>
     */
    private function get_agent_choices(): array {
        global $wpdb;

        $rows = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}racc_agents WHERE status = 'active' ORDER BY name ASC" );
        $agents = [];

        foreach ( $rows as $row ) {
            $agents[ (int) $row->id ] = (string) $row->name;
        }

        return $agents;
    }

    /**
     * Return a valid two-letter country code for booking payment orders.
     *
     * @return string
     */
    private function get_default_billing_country(): string {
        $country = '';

        if ( function_exists( 'WC' ) && WC()->countries ) {
            $country = (string) WC()->countries->get_base_country();
        }

        if ( '' === $country ) {
            $country = (string) get_option( 'woocommerce_default_country', '' );
            $country = strtoupper( substr( $country, 0, 2 ) );
        }

        return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : 'AU';
    }

    /**
     * Get assigned consultant IDs for a product.
     *
     * @param int $product_id
     * @return array<int>
     */
    private function get_product_consultant_ids( int $product_id ): array {
        return array_values( array_filter( array_map( 'absint', (array) get_post_meta( $product_id, '_racc_booking_consultant_ids', true ) ) ) );
    }

    /**
     * Get slot duration for a product.
     *
     * @param int $product_id
     * @return int
     */
    private function get_product_slot_duration( int $product_id ): int {
        $duration = absint( get_post_meta( $product_id, '_racc_booking_slot_duration', true ) );
        if ( $duration <= 0 ) {
            $duration = 60;
        }
        return $duration;
    }

    /**
     * Backfill legacy bookings missing woo_product_id by matching service_type.
     * Runs once and stores completion flag in options.
     */
    private function maybe_backfill_booking_product_mapping(): void {
        $flag = 'racc_woo_backfill_booking_products_done';
        if ( 'yes' === get_option( $flag ) ) {
            return;
        }

        global $wpdb;
        $bookings_table = $wpdb->prefix . 'racc_bookings';

        $has_woo_product_column = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$bookings_table} LIKE %s",
            'woo_product_id'
        ) );

        if ( ! $has_woo_product_column ) {
            return;
        }

        $rows = $wpdb->get_results(
            "SELECT id, service_type
             FROM {$bookings_table}
             WHERE (woo_product_id IS NULL OR woo_product_id = 0)
               AND service_type <> ''"
        );

        if ( empty( $rows ) ) {
            update_option( $flag, 'yes' );
            return;
        }

        foreach ( $rows as $row ) {
            $product = $this->find_product_by_service_name( (string) $row->service_type );
            if ( ! $product ) {
                continue;
            }

            $wpdb->update(
                $bookings_table,
                [
                    'woo_product_id' => (int) $product->get_id(),
                    'service_type'   => (string) $product->get_name(),
                ],
                [ 'id' => (int) $row->id ],
                [ '%d', '%s' ],
                [ '%d' ]
            );
        }

        update_option( $flag, 'yes' );
    }

    /**
     * Backfill product slot duration meta from assigned consultants (legacy setup).
     * Runs once and stores completion flag in options.
     */
    private function maybe_backfill_product_slot_durations(): void {
        $flag = 'racc_woo_backfill_product_slot_durations_done';
        if ( 'yes' === get_option( $flag ) ) {
            return;
        }

        if ( ! function_exists( 'wc_get_products' ) ) {
            return;
        }

        global $wpdb;

        $category_slug = $this->settings['category_slug'] ?? 'booking-services';
        $products      = wc_get_products( [
            'category' => [ $category_slug ],
            'status'   => 'publish',
            'limit'    => -1,
        ] );

        foreach ( $products as $product ) {
            $product_id       = (int) $product->get_id();
            $existing_duration = absint( get_post_meta( $product_id, '_racc_booking_slot_duration', true ) );

            if ( $existing_duration > 0 ) {
                continue;
            }

            $consultant_ids = $this->get_product_consultant_ids( $product_id );
            if ( empty( $consultant_ids ) ) {
                continue;
            }

            $first_consultant_id = (int) $consultant_ids[0];
            $legacy_duration     = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT slot_duration FROM {$wpdb->prefix}racc_agents WHERE id = %d",
                $first_consultant_id
            ) );

            if ( $legacy_duration <= 0 ) {
                $legacy_duration = 60;
            }

            update_post_meta( $product_id, '_racc_booking_slot_duration', $legacy_duration );
        }

        update_option( $flag, 'yes' );
    }

    /**
     * Return existing WP user ID for email, or 0 for guest checkout.
     *
     * @param string $email
     * @param string $first_name
     * @param string $last_name
     * @return int
     */
    private function get_or_create_customer_id( string $email, string $first_name, string $last_name ): int {
        $existing = get_user_by( 'email', $email );
        if ( $existing ) {
            return $existing->ID;
        }
        return 0; // Guest checkout.
    }

    /**
     * Detect whether current request is Woo order-pay for a RACC booking order.
     *
     * @return bool
     */
    private function is_booking_payment_context(): bool {
        if ( ! function_exists( 'is_checkout_pay_page' ) || ! is_checkout_pay_page() ) {
            return false;
        }

        $order_id = 0;

        if ( function_exists( 'get_query_var' ) ) {
            $order_id = absint( get_query_var( 'order-pay' ) );
        }

        if ( ! $order_id && isset( $_GET['order_id'] ) ) {
            $order_id = absint( wp_unslash( $_GET['order_id'] ) );
        }

        if ( $order_id <= 0 ) {
            return false;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        return (int) $order->get_meta( '_racc_booking_id', true ) > 0;
    }

    // =========================================================================
    // Google Calendar: Add to Calendar on Thank You page
    // =========================================================================

    /**
     * Build Google Calendar details text from a booking row.
     *
     * @param object $booking    Booking row with agent fields.
     * @param int    $booking_id Booking ID.
     * @return string
     */
    private function build_google_calendar_details( object $booking, int $booking_id ): string {
        $consultant_name = trim( (string) ( $booking->agent_name ?? '' ) );
        if ( '' === $consultant_name ) {
            $consultant_name = __( 'Unknown Consultant', 'racc-booking-woo' );
        }

        $details = sprintf(
            "=== APPOINTMENT ===\n" .
            "Consultant: %s\n" .
            "Service Type: %s\n" .
            "Booking ID: #%d\n" .
            "\n" .
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
            "\n=== ENQUIRY ===\n" .
            "%s",
            $consultant_name,
            $booking->service_type ?? '',
            $booking_id,
            $booking->client_name ?? '',
            $booking->client_email ?? '',
            $booking->client_phone ?? '',
            $booking->client_nationality ?? '',
            $booking->client_dob ?? '',
            $booking->client_country ?? '',
            $booking->client_university ?? '',
            $booking->client_course_level ?? '',
            $booking->client_course_major ?? '',
            $booking->client_course_completion ?? '',
            $booking->client_visa_type ?? '',
            $booking->client_visa_expiry ?? '',
            $booking->client_occupation ?? '',
            $booking->client_contact_link ?? '',
            $booking->client_referral_source ?? '',
            $booking->notes ?? ''
        );

        return apply_filters( 'racc_woo_google_calendar_details', $details, $booking, $booking_id );
    }

    /**
     * Render an "Add to Google Calendar" button on the WooCommerce order-received page
     * for orders that are linked to a RACC booking.
     *
     * @param int $order_id WooCommerce order ID.
     */
    public function render_add_to_google_calendar( int $order_id ): void {
        global $wpdb;

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Only show if the order is confirmed or awaiting payment (any non-cancelled status).
        if ( in_array( $order->get_status(), [ 'cancelled', 'failed', 'refunded' ], true ) ) {
            return;
        }

        $booking_id = (int) $order->get_meta( '_racc_booking_id', true );
        if ( $booking_id <= 0 ) {
            return;
        }

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, 
                    a.name AS agent_name, a.timezone AS agent_timezone
             FROM {$wpdb->prefix}racc_bookings b
             LEFT JOIN {$wpdb->prefix}racc_agents a ON a.id = b.agent_id
             WHERE b.id = %d",
            $booking_id
        ) );

        if ( ! $booking ) {
            return;
        }

        $date       = str_replace( '-', '', $booking->booking_date );
        $time_start = str_replace( ':', '', substr( $booking->booking_time_start, 0, 5 ) ) . '00';
        $time_end   = str_replace( ':', '', substr( $booking->booking_time_end,   0, 5 ) ) . '00';
        $timezone   = $booking->agent_timezone ?: 'UTC';
        $title      = sprintf(
            '[RACC] %s - %s',
            $booking->service_type,
            $booking->client_name
        );
        $details    = $this->build_google_calendar_details( $booking, $booking_id );

        $gcal_url = 'https://calendar.google.com/calendar/render?' . http_build_query( [
            'action'  => 'TEMPLATE',
            'text'    => $title,
            'dates'   => $date . 'T' . $time_start . '/' . $date . 'T' . $time_end,
            'details' => $details,
            'ctz'     => $timezone,
        ], '', '&', PHP_QUERY_RFC3986 );

        ?>
        <section class="racc-woo-gcal-section woocommerce-order-details">
            <h2 class="woocommerce-order-details__title">
                <?php esc_html_e( 'Save Your Appointment', 'racc-booking-woo' ); ?>
            </h2>
            <p><?php esc_html_e( 'Don\'t forget your consultation! Add it to your Google Calendar with one click.', 'racc-booking-woo' ); ?></p>
            <a href="<?php echo esc_attr( $gcal_url ); ?>"
               target="_blank"
               rel="noopener noreferrer"
               class="racc-gcal-btn racc-gcal-btn-woo">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="20" height="20" style="flex-shrink:0;">
                    <rect x="6" y="6" width="36" height="36" rx="4" fill="#fff"/>
                    <rect x="6" y="6" width="36" height="36" rx="4" fill="none" stroke="#dadce0" stroke-width="2"/>
                    <path d="M14 6v8H6" fill="none" stroke="#dadce0" stroke-width="2"/>
                    <text x="24" y="32" text-anchor="middle" font-family="Arial,sans-serif" font-size="16" font-weight="bold" fill="#1a73e8">31</text>
                </svg>
                <?php esc_html_e( 'Add to Google Calendar', 'racc-booking-woo' ); ?>
            </a>
        </section>
        <style>
        .racc-woo-gcal-section {
            margin: 30px 0;
            padding: 24px;
            background: #f0f7ff;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            text-align: center;
        }
        .racc-woo-gcal-section p {
            margin-bottom: 16px;
            color: #374151;
        }
        .racc-gcal-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 22px;
            background: #fff;
            color: #1a73e8;
            border: 2px solid #1a73e8;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.2s, color 0.2s;
        }
        .racc-gcal-btn:hover {
            background: #1a73e8;
            color: #fff;
        }
        </style>
        <?php
    }
}
