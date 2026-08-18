<?php
/**
 * Admin view: Change consultant / reassign bookings.
 *
 * @package RACC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$booking_ids = [];
$batch_key   = sanitize_text_field( wp_unslash( $_GET['batch'] ?? '' ) );

if ( $batch_key ) {
    $transient_key = 'racc_reassign_batch_' . get_current_user_id() . '_' . $batch_key;
    $batch_ids     = get_transient( $transient_key );
    if ( is_array( $batch_ids ) ) {
        $booking_ids = array_map( 'absint', $batch_ids );
    }
} else {
    if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'racc_reassign_booking_link' ) ) {
        echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Invalid reassignment link.', 'racc-booking' ) . '</p></div></div>';
        return;
    }

    $raw_booking_ids = $_GET['booking_ids'] ?? [];
    if ( ! is_array( $raw_booking_ids ) ) {
        $raw_booking_ids = [ $raw_booking_ids ];
    }

    $booking_ids = array_map( 'absint', wp_unslash( $raw_booking_ids ) );
}

$booking_ids = array_values( array_unique( array_filter( $booking_ids ) ) );

if ( empty( $booking_ids ) ) {
    echo '<div class="wrap"><div class="notice notice-warning"><p>' . esc_html__( 'No bookings selected.', 'racc-booking' ) . '</p></div></div>';
    return;
}

$placeholders = implode( ',', array_fill( 0, count( $booking_ids ), '%d' ) );
$bookings_sql = "SELECT b.*, a.name AS agent_name, p.post_title AS woo_product_name
    FROM {$wpdb->prefix}racc_bookings b
    LEFT JOIN {$wpdb->prefix}racc_agents a ON b.agent_id = a.id
    LEFT JOIN {$wpdb->posts} p ON p.ID = b.woo_product_id AND p.post_type = 'product'
    WHERE b.id IN ({$placeholders})
    ORDER BY b.booking_date DESC, b.booking_time_start DESC";
$bookings     = $wpdb->get_results( $wpdb->prepare( $bookings_sql, ...$booking_ids ) );

$agents = $wpdb->get_results(
    "SELECT id, name, email
     FROM {$wpdb->prefix}racc_agents
     WHERE status = 'active'
     ORDER BY name ASC"
);
?>
<div class="wrap racc-admin-wrap">
    <h1 class="racc-admin-title">
        <span class="dashicons dashicons-businessperson"></span>
        <?php esc_html_e( 'Change Consultant / Reassign', 'racc-booking' ); ?>
    </h1>

    <a href="<?php echo esc_url( admin_url( 'admin.php?page=racc-booking' ) ); ?>" class="page-title-action">
        <?php esc_html_e( 'Back to Bookings', 'racc-booking' ); ?>
    </a>

    <?php if ( empty( $bookings ) ) : ?>
        <div class="notice notice-warning" style="margin-top:15px;">
            <p><?php esc_html_e( 'Selected bookings could not be found.', 'racc-booking' ); ?></p>
        </div>
    <?php elseif ( empty( $agents ) ) : ?>
        <div class="notice notice-error" style="margin-top:15px;">
            <p><?php esc_html_e( 'No active consultants are available.', 'racc-booking' ); ?></p>
        </div>
    <?php else : ?>
        <form method="post" class="racc-reassign-form" style="margin-top:20px;">
            <?php wp_nonce_field( 'racc_reassign_bookings' ); ?>
            <?php foreach ( $bookings as $booking ) : ?>
                <input type="hidden" name="booking_ids[]" value="<?php echo esc_attr( $booking->id ); ?>" />
            <?php endforeach; ?>

            <div class="racc-admin-card" style="max-width:760px;margin-bottom:20px;">
                <h2><?php esc_html_e( 'New Consultant', 'racc-booking' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="racc-reassign-agent"><?php esc_html_e( 'Consultant', 'racc-booking' ); ?></label>
                        </th>
                        <td>
                            <select id="racc-reassign-agent" name="agent_id" class="regular-text" required>
                                <option value=""><?php esc_html_e( 'Select consultant...', 'racc-booking' ); ?></option>
                                <?php foreach ( $agents as $agent ) : ?>
                                    <option value="<?php echo esc_attr( $agent->id ); ?>">
                                        <?php echo esc_html( $agent->name . ' — ' . $agent->email ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'Booking date and time will stay unchanged. Existing Google Calendar events will be moved to the new consultant when possible.', 'racc-booking' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <h2><?php printf( esc_html__( 'Selected Bookings (%d)', 'racc-booking' ), count( $bookings ) ); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="column-id"><?php esc_html_e( 'ID', 'racc-booking' ); ?></th>
                        <th><?php esc_html_e( 'Client', 'racc-booking' ); ?></th>
                        <th><?php esc_html_e( 'Current Consultant', 'racc-booking' ); ?></th>
                        <th><?php esc_html_e( 'Service', 'racc-booking' ); ?></th>
                        <th><?php esc_html_e( 'Date & Time', 'racc-booking' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'racc-booking' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $bookings as $booking ) : ?>
                        <tr>
                            <td>#<?php echo esc_html( $booking->id ); ?></td>
                            <td>
                                <strong><?php echo esc_html( $booking->client_name ); ?></strong><br>
                                <small><?php echo esc_html( $booking->client_email ); ?></small>
                            </td>
                            <td><?php echo esc_html( $booking->agent_name ?: __( 'Unknown', 'racc-booking' ) ); ?></td>
                            <td>
                                <?php echo esc_html( $booking->service_type ); ?>
                                <?php if ( ! empty( $booking->woo_product_name ) ) : ?>
                                    <br><small><?php echo esc_html( $booking->woo_product_name ); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo esc_html( date_i18n( 'D, j M Y', strtotime( $booking->booking_date ) ) ); ?></strong><br>
                                <?php
                                echo esc_html(
                                    date_i18n( 'g:i A', strtotime( $booking->booking_time_start ) ) .
                                    ' - ' .
                                    date_i18n( 'g:i A', strtotime( $booking->booking_time_end ) )
                                );
                                ?>
                            </td>
                            <td><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $booking->status ) ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="submit">
                <button type="submit"
                        name="racc_reassign_bookings"
                        value="1"
                        class="button button-primary"
                        onclick="return confirm('<?php echo esc_js( __( 'Change consultant for the selected bookings without changing their schedule?', 'racc-booking' ) ); ?>');">
                    <?php esc_html_e( 'Change Consultant', 'racc-booking' ); ?>
                </button>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=racc-booking' ) ); ?>" class="button">
                    <?php esc_html_e( 'Cancel', 'racc-booking' ); ?>
                </a>
            </p>
        </form>
    <?php endif; ?>
</div>
