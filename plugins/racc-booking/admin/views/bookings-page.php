<?php
/**
 * Admin view: All Bookings page.
 *
 * @package RACC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$message = sanitize_text_field( $_GET['message'] ?? '' );

$get_order_edit_link = static function ( $order_id ) {
    $order_id = absint( $order_id );

    if ( ! $order_id ) {
        return '';
    }

    $classic_link = get_edit_post_link( $order_id, 'raw' );
    if ( $classic_link ) {
        return $classic_link;
    }

    return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
};

// Filters
$filter_status   = sanitize_text_field( $_GET['status'] ?? '' );
$filter_agent    = absint( $_GET['filter_agent'] ?? 0 );
$filter_date_start = sanitize_text_field( $_GET['filter_date_start'] ?? '' );
$filter_date_end   = sanitize_text_field( $_GET['filter_date_end'] ?? '' );
$filter_nationality = sanitize_text_field( $_GET['filter_nationality'] ?? '' );
$filter_domicile = sanitize_text_field( $_GET['filter_domicile'] ?? '' );
$filter_agentcis_status = sanitize_text_field( $_GET['filter_agentcis_status'] ?? '' );
$search_query    = sanitize_text_field( $_GET['s'] ?? '' );
$paged           = max( 1, absint( $_GET['paged'] ?? 1 ) );
$per_page        = 20;
$offset          = ( $paged - 1 ) * $per_page;

// Build query
$where = [ '1=1' ];
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

// Total count
$count_sql = "SELECT COUNT(*)
              FROM {$wpdb->prefix}racc_bookings b
              LEFT JOIN {$wpdb->prefix}racc_agents a ON b.agent_id = a.id
              LEFT JOIN {$wpdb->posts} p ON p.ID = b.woo_product_id AND p.post_type = 'product'
              WHERE {$where_clause}";
if ( ! empty( $params ) ) {
    $count_sql = $wpdb->prepare( $count_sql, ...$params );
}
$total = intval( $wpdb->get_var( $count_sql ) );

// Get bookings
$sql = "SELECT b.*, a.name as agent_name, a.email as agent_email, a.agentcis_assignee_id, a.calendar_id as agent_calendar_id, a.timezone as agent_timezone, p.post_title as woo_product_name, u.display_name as changed_by_display_name
    FROM {$wpdb->prefix}racc_bookings b
    LEFT JOIN {$wpdb->prefix}racc_agents a ON b.agent_id = a.id
    LEFT JOIN {$wpdb->posts} p ON p.ID = b.woo_product_id AND p.post_type = 'product'
    LEFT JOIN {$wpdb->users} u ON u.ID = b.changed_by_user_id
    WHERE {$where_clause}
    ORDER BY b.created_at DESC, b.id DESC
    LIMIT %d OFFSET %d";

$query_params = array_merge( $params, [ $per_page, $offset ] );
$bookings = $wpdb->get_results( $wpdb->prepare( $sql, ...$query_params ) );

// Format mapped agent name if available
$mapped_users = get_option( 'racc_agentcis_users_list', [] );
if ( is_array( $mapped_users ) && ! empty( $mapped_users ) ) {
    foreach ( $bookings as $booking ) {
        if ( ! empty( $booking->agent_name ) && ! empty( $booking->agentcis_assignee_id ) ) {
            foreach ( $mapped_users as $u ) {
                if ( (int) $u['id'] === (int) $booking->agentcis_assignee_id ) {
                    $booking->agent_name .= ' (' . $u['name'] . ')';
                    break;
                }
            }
        }
    }
}

$total_pages = ceil( $total / $per_page );

// Get agents for filter dropdown
$agents = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}racc_agents ORDER BY name ASC" );

// Get booking meta values for filter dropdowns
$nationalities = $wpdb->get_col(
    "SELECT DISTINCT client_nationality
     FROM {$wpdb->prefix}racc_bookings
     WHERE client_nationality != ''
     ORDER BY client_nationality ASC"
);
$domiciles = $wpdb->get_col(
    "SELECT DISTINCT client_country
     FROM {$wpdb->prefix}racc_bookings
     WHERE client_country != ''
     ORDER BY client_country ASC"
);
$agentcis_statuses = $wpdb->get_col(
    "SELECT DISTINCT agentcis_sync_status
     FROM {$wpdb->prefix}racc_bookings
     WHERE agentcis_sync_status != ''
     ORDER BY agentcis_sync_status ASC"
);
$agentcis_status_labels = [
    'pending' => __( 'Pending', 'racc-booking' ),
    'synced'  => __( 'Synced', 'racc-booking' ),
    'failed'  => __( 'Failed', 'racc-booking' ),
];

// Status counts
$status_counts = $wpdb->get_results(
    "SELECT status, COUNT(*) as count FROM {$wpdb->prefix}racc_bookings GROUP BY status",
    OBJECT_K
);

$export_args = [
    'page'   => 'racc-booking',
    'action' => 'export_bookings_csv',
];
if ( $filter_status ) {
    $export_args['status'] = $filter_status;
}
if ( $filter_agent ) {
    $export_args['filter_agent'] = $filter_agent;
}
if ( $filter_date_start ) {
    $export_args['filter_date_start'] = $filter_date_start;
}
if ( $filter_date_end ) {
    $export_args['filter_date_end'] = $filter_date_end;
}
if ( $filter_nationality ) {
    $export_args['filter_nationality'] = $filter_nationality;
}
if ( $filter_domicile ) {
    $export_args['filter_domicile'] = $filter_domicile;
}
if ( $filter_agentcis_status ) {
    $export_args['filter_agentcis_status'] = $filter_agentcis_status;
}
if ( $search_query ) {
    $export_args['s'] = $search_query;
}
$export_url = wp_nonce_url(
    add_query_arg( $export_args, admin_url( 'admin.php' ) ),
    'racc_export_bookings_csv'
);

$build_reassign_url = static function ( array $booking_ids ) {
    $booking_ids = array_values( array_filter( array_map( 'absint', $booking_ids ) ) );

    return wp_nonce_url(
        add_query_arg(
            [
                'page'        => 'racc-booking-reassign',
                'booking_ids' => $booking_ids,
            ],
            admin_url( 'admin.php' )
        ),
        'racc_reassign_booking_link'
    );
};
?>
<div class="wrap racc-admin-wrap">
    <h1 class="racc-admin-title">
        <span class="dashicons dashicons-calendar-alt"></span>
        <?php esc_html_e( 'RACC Booking — All Bookings', 'racc-booking' ); ?>
        <span class="racc-count"><?php printf( esc_html__( '%d total', 'racc-booking' ), $total ); ?></span>
    </h1>

    <?php if ( $message === 'booking_cancelled' ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Booking cancelled successfully.', 'racc-booking' ); ?></p></div>
    <?php elseif ( $message === 'booking_rescheduled' ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Booking rescheduled successfully.', 'racc-booking' ); ?></p></div>
    <?php elseif ( $message === 'booking_deleted' ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Booking deleted successfully.', 'racc-booking' ); ?></p></div>
    <?php elseif ( $message === 'bookings_deleted' ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php printf( esc_html__( '%d booking(s) deleted successfully.', 'racc-booking' ), absint( $_GET['count'] ?? 0 ) ); ?></p></div>
    <?php elseif ( $message === 'bookings_reassigned' ) : ?>
        <div class="notice notice-success is-dismissible"><p>
            <?php
            printf(
                esc_html__( '%1$d booking(s) reassigned. %2$d skipped, %3$d failed.', 'racc-booking' ),
                absint( $_GET['count'] ?? 0 ),
                absint( $_GET['skipped'] ?? 0 ),
                absint( $_GET['failed'] ?? 0 )
            );
            ?>
        </p></div>
    <?php elseif ( $message === 'no_bookings_selected' ) : ?>
        <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Please select at least one booking first.', 'racc-booking' ); ?></p></div>
    <?php elseif ( $message === 'reassign_invalid_agent' ) : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Selected consultant is invalid or inactive.', 'racc-booking' ); ?></p></div>
    <?php endif; ?>

    <!-- Status Tabs -->
    <ul class="subsubsub">
        <li>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=racc-booking' ) ); ?>"
               class="<?php echo empty( $filter_status ) ? 'current' : ''; ?>">
                <?php esc_html_e( 'All', 'racc-booking' ); ?>
                <span class="count">(<?php echo $total; ?>)</span>
            </a> |
        </li>
        <?php
        $statuses = [
            'pending_payment' => __( 'Pending Payment', 'racc-booking' ),
            'confirmed'   => __( 'Confirmed', 'racc-booking' ),
            'rescheduled' => __( 'Rescheduled', 'racc-booking' ),
            'cancelled'   => __( 'Cancelled', 'racc-booking' ),
            'completed'   => __( 'Completed', 'racc-booking' ),
        ];
        $i = 0;
        foreach ( $statuses as $key => $label ) :
            $count = intval( $status_counts[ $key ]->count ?? 0 );
            $i++;
        ?>
            <li>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=racc-booking&status=' . $key ) ); ?>"
                   class="<?php echo $filter_status === $key ? 'current' : ''; ?>">
                    <?php echo esc_html( $label ); ?>
                    <span class="count">(<?php echo $count; ?>)</span>
                </a><?php echo $i < count( $statuses ) ? ' |' : ''; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <!-- Filters -->
    <div class="racc-filters tablenav top">
        <form method="get" class="racc-filter-form">
            <input type="hidden" name="page" value="racc-booking" />
            <?php if ( $filter_status ) : ?>
                <input type="hidden" name="status" value="<?php echo esc_attr( $filter_status ); ?>" />
            <?php endif; ?>

            <select name="filter_agent">
                <option value=""><?php esc_html_e( 'All Consultants', 'racc-booking' ); ?></option>
                <?php foreach ( $agents as $agent ) : ?>
                    <option value="<?php echo esc_attr( $agent->id ); ?>" <?php selected( $filter_agent, $agent->id ); ?>>
                        <?php echo esc_html( $agent->name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="date" name="filter_date_start" value="<?php echo esc_attr( $filter_date_start ); ?>" placeholder="<?php esc_attr_e( 'Start date', 'racc-booking' ); ?>" title="<?php esc_attr_e( 'Start date', 'racc-booking' ); ?>" />
            <span style="display:inline-block; margin: 0 4px; vertical-align:middle;">-</span>
            <input type="date" name="filter_date_end" value="<?php echo esc_attr( $filter_date_end ); ?>" placeholder="<?php esc_attr_e( 'End date', 'racc-booking' ); ?>" title="<?php esc_attr_e( 'End date', 'racc-booking' ); ?>" />

            <select name="filter_nationality">
                <option value=""><?php esc_html_e( 'All Nationalities', 'racc-booking' ); ?></option>
                <?php foreach ( $nationalities as $nationality ) : ?>
                    <option value="<?php echo esc_attr( $nationality ); ?>" <?php selected( $filter_nationality, $nationality ); ?>>
                        <?php echo esc_html( $nationality ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="filter_domicile">
                <option value=""><?php esc_html_e( 'All Domiciles', 'racc-booking' ); ?></option>
                <?php foreach ( $domiciles as $domicile ) : ?>
                    <option value="<?php echo esc_attr( $domicile ); ?>" <?php selected( $filter_domicile, $domicile ); ?>>
                        <?php echo esc_html( $domicile ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="filter_agentcis_status">
                <option value=""><?php esc_html_e( 'All AgentCIS Sync', 'racc-booking' ); ?></option>
                <?php foreach ( $agentcis_statuses as $agentcis_status ) : ?>
                    <option value="<?php echo esc_attr( $agentcis_status ); ?>" <?php selected( $filter_agentcis_status, $agentcis_status ); ?>>
                        <?php echo esc_html( $agentcis_status_labels[ $agentcis_status ] ?? ucwords( str_replace( '_', ' ', (string) $agentcis_status ) ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="search" name="s" value="<?php echo esc_attr( $search_query ); ?>" placeholder="<?php esc_attr_e( 'Search booking, client, service, consultant…', 'racc-booking' ); ?>" style="min-width:280px;" />

            <button type="submit" class="button"><?php esc_html_e( 'Filter', 'racc-booking' ); ?></button>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=racc-booking' ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'racc-booking' ); ?></a>
            <a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Export CSV', 'racc-booking' ); ?></a>
        </form>
    </div>

    <!-- Bookings Table -->
    <?php if ( empty( $bookings ) ) : ?>
        <div class="racc-empty-state">
            <p><?php esc_html_e( 'No bookings found.', 'racc-booking' ); ?></p>
        </div>
    <?php else : ?>
        <form method="post" action="">
            <?php wp_nonce_field( 'racc_bulk_booking_action' ); ?>
            <input type="hidden" name="page" value="racc-booking" />

            <div class="tablenav top">
                <div class="alignleft actions bulkactions">
                    <label for="bulk-action-selector-top" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'racc-booking' ); ?></label>
                    <select name="racc_bulk_action" id="bulk-action-selector-top">
                        <option value="-1"><?php esc_html_e( 'Bulk actions', 'racc-booking' ); ?></option>
                        <option value="reassign"><?php esc_html_e( 'Change Consultant / Reassign', 'racc-booking' ); ?></option>
                        <option value="delete"><?php esc_html_e( 'Delete permanently', 'racc-booking' ); ?></option>
                    </select>
                    <button type="submit" class="button action racc-bulk-apply"><?php esc_html_e( 'Apply', 'racc-booking' ); ?></button>
                </div>
                <br class="clear" />
            </div>

        <table class="wp-list-table widefat fixed striped racc-bookings-table">
            <thead>
                <tr>
                    <th class="check-column"><input type="checkbox" id="racc-select-all-bookings" /></th>
                    <th class="column-id"><?php esc_html_e( 'ID', 'racc-booking' ); ?></th>
                    <th><?php esc_html_e( 'Client', 'racc-booking' ); ?></th>
                    <th><?php esc_html_e( 'Nationality', 'racc-booking' ); ?></th>
                    <th><?php esc_html_e( 'Country/State', 'racc-booking' ); ?></th>
                    <th><?php esc_html_e( 'Consultant', 'racc-booking' ); ?></th>
                    <th class="column-service"><?php esc_html_e( 'Service', 'racc-booking' ); ?></th>
                    <th><?php esc_html_e( 'Date & Time', 'racc-booking' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'racc-booking' ); ?></th>
                    <th><?php esc_html_e( 'AgentCIS Sync', 'racc-booking' ); ?></th>
                    <th><?php esc_html_e( 'Created', 'racc-booking' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $bookings as $booking ) :
                    $booking_date_raw = (string) ( $booking->booking_date ?? '' );
                    $booking_date_ts  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $booking_date_raw ) ? strtotime( $booking_date_raw ) : false;
                    $start_time_ts    = ! empty( $booking->booking_time_start ) ? strtotime( $booking->booking_time_start ) : false;
                    $end_time_ts      = ! empty( $booking->booking_time_end ) ? strtotime( $booking->booking_time_end ) : false;
                    $is_past          = ( false !== $booking_date_ts && $booking_date_ts < strtotime( 'today' ) );
                ?>
                    <tr class="<?php echo $is_past ? 'racc-row-past' : ''; ?>">
                        <th scope="row" class="check-column">
                            <input type="checkbox" name="booking_ids[]" value="<?php echo esc_attr( $booking->id ); ?>" class="racc-booking-checkbox" />
                        </th>
                        <td class="column-id">#<?php echo esc_html( $booking->id ); ?></td>
                        <td>
                            <strong><?php echo esc_html( $booking->client_name ); ?></strong><br>
                            <small><?php echo esc_html( $booking->client_email ); ?></small>
                            <?php if ( $booking->client_phone ) : ?>
                                <br><small><?php echo esc_html( $booking->client_phone ); ?></small>
                            <?php endif; ?>

                            <div class="row-actions racc-row-actions">
                                <span class="view">
                                    <a href="javascript:void(0);" class="racc-view-details" data-booking-id="<?php echo esc_attr( $booking->id ); ?>">
                                        <?php esc_html_e( 'View Details', 'racc-booking' ); ?>
                                    </a>
                                </span>

                                <span class="reassign"> |
                                    <a href="<?php echo esc_url( $build_reassign_url( [ $booking->id ] ) ); ?>">
                                        <?php esc_html_e( 'Change Consultant', 'racc-booking' ); ?>
                                    </a>
                                </span>

                                <?php if ( in_array( $booking->status, [ 'confirmed', 'rescheduled' ], true ) && ! $is_past ) : ?>
                                    <span class="reschedule"> |
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=racc-booking-reschedule&booking_id=' . $booking->id ) ); ?>">
                                            <?php esc_html_e( 'Edit / Reschedule', 'racc-booking' ); ?>
                                        </a>
                                    </span>
                                    <?php if ( ! empty( $booking->agent_calendar_id ) ) :
                                        $calendar_url = add_query_arg(
                                            [
                                                'src' => $booking->agent_calendar_id,
                                                'ctz' => ! empty( $booking->agent_timezone ) ? $booking->agent_timezone : wp_timezone_string(),
                                            ],
                                            'https://calendar.google.com/calendar/embed'
                                        );
                                    ?>
                                        <span class="consultant-calendar"> |
                                            <a href="<?php echo esc_url( $calendar_url ); ?>" target="_blank" rel="noopener">
                                                <?php esc_html_e( 'Calendar', 'racc-booking' ); ?>
                                            </a>
                                        </span>
                                    <?php endif; ?>
                                    <span class="cancel"> |
                                        <a href="<?php echo esc_url( wp_nonce_url(
                                            admin_url( 'admin.php?page=racc-booking&action=cancel_booking&booking_id=' . $booking->id ),
                                            'racc_cancel_booking'
                                        ) ); ?>"
                                           class="submitdelete"
                                           onclick="return confirm('<?php esc_attr_e( 'Cancel this booking? The client will be notified.', 'racc-booking' ); ?>');">
                                            <?php esc_html_e( 'Cancel', 'racc-booking' ); ?>
                                        </a>
                                    </span>
                                <?php endif; ?>

                                <span class="delete"> |
                                    <a href="<?php echo esc_url( wp_nonce_url(
                                        admin_url( 'admin.php?page=racc-booking&action=delete_booking&booking_id=' . $booking->id ),
                                        'racc_delete_booking'
                                    ) ); ?>"
                                       class="submitdelete"
                                       onclick="return confirm('<?php esc_attr_e( 'Delete this booking permanently? This cannot be undone.', 'racc-booking' ); ?>');">
                                        <?php esc_html_e( 'Delete', 'racc-booking' ); ?>
                                    </a>
                                </span>
                            </div>
                        </td>
                        <td>
                            <?php echo ! empty( $booking->client_nationality ) ? esc_html( $booking->client_nationality ) : '<span style="color:#aaa;">—</span>'; ?>
                        </td>
                        <td>
                            <?php 
                                $is_old_state = in_array( $booking->client_country, [ 'Australian Capital Territory', 'New South Wales', 'Northern Territory', 'Queensland', 'South Australia', 'Tasmania', 'Victoria', 'Western Australia' ], true );
                                $disp_country = $is_old_state ? 'Australia' : $booking->client_country;
                                $disp_state = $is_old_state ? $booking->client_country : $booking->client_state;
                                
                                echo ! empty( $disp_country ) ? esc_html( $disp_country ) . ( ! empty( $disp_state ) ? ' - ' . esc_html( $disp_state ) : '' ) : '<span style="color:#aaa;">—</span>';
                            ?>
                        </td>
                        <td><?php echo esc_html( $booking->agent_name ?? __( 'Unknown', 'racc-booking' ) ); ?></td>
                        <td class="column-service">
                            <span class="racc-tag"><?php echo esc_html( $booking->service_type ); ?></span>
                            <?php if ( ! empty( $booking->woo_product_id ) ) : ?>
                                <br><small>
                                    <?php esc_html_e( 'Product:', 'racc-booking' ); ?>
                                    <a href="<?php echo esc_url( admin_url( 'post.php?post=' . absint( $booking->woo_product_id ) . '&action=edit' ) ); ?>">
                                        #<?php echo esc_html( absint( $booking->woo_product_id ) ); ?><?php echo ! empty( $booking->woo_product_name ) ? ' — ' . esc_html( $booking->woo_product_name ) : ''; ?>
                                    </a>
                                </small>
                            <?php endif; ?>
                            <?php if ( ! empty( $booking->woo_order_id ) ) : ?>
                                <br><small>
                                    <?php esc_html_e( 'Order ID:', 'racc-booking' ); ?>
                                    <a href="<?php echo esc_url( $get_order_edit_link( $booking->woo_order_id ) ); ?>">#<?php echo esc_html( absint( $booking->woo_order_id ) ); ?></a>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( false !== $booking_date_ts ) : ?>
                                <strong><?php echo esc_html( date_i18n( 'D, j M Y', $booking_date_ts ) ); ?></strong><br>
                            <?php else : ?>
                                <strong style="color:#b32d2e;"><?php esc_html_e( 'Invalid date', 'racc-booking' ); ?></strong><br>
                            <?php endif; ?>
                            <?php
                            echo esc_html(
                                ( false !== $start_time_ts ? date_i18n( 'g:i A', $start_time_ts ) : '—' ) .
                                ' — ' .
                                ( false !== $end_time_ts ? date_i18n( 'g:i A', $end_time_ts ) : '—' )
                            );
                            ?>
                        </td>
                        <td>
                            <span class="racc-status-badge racc-status-<?php echo esc_attr( $booking->status ); ?>">
                                <?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $booking->status ) ) ); ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            $agentcis_sync_status = ! empty( $booking->agentcis_sync_status ) ? (string) $booking->agentcis_sync_status : 'pending';
                            ?>
                            <span class="racc-status-badge racc-agentcis-sync-<?php echo esc_attr( sanitize_html_class( $agentcis_sync_status ) ); ?>"
                                  <?php if ( ! empty( $booking->agentcis_sync_error ) ) : ?>
                                      title="<?php echo esc_attr( $booking->agentcis_sync_error ); ?>"
                                  <?php endif; ?>>
                                <?php echo esc_html( $agentcis_status_labels[ $agentcis_sync_status ] ?? ucwords( str_replace( '_', ' ', $agentcis_sync_status ) ) ); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ( ! empty( $booking->created_at ) ) : ?>
                                <span title="<?php echo esc_attr( $booking->created_at ); ?>">
                                    <?php echo esc_html( date_i18n( 'j M Y', strtotime( $booking->created_at ) ) ); ?>
                                </span><br>
                                <small style="color:#787c82;"><?php echo esc_html( date_i18n( 'g:i A', strtotime( $booking->created_at ) ) ); ?></small>
                            <?php else : ?>
                                <span style="color:#aaa;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="tablenav bottom">
            <div class="alignleft actions bulkactions">
                <label for="bulk-action-selector-bottom" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'racc-booking' ); ?></label>
                <select name="racc_bulk_action_bottom" id="bulk-action-selector-bottom">
                    <option value="-1"><?php esc_html_e( 'Bulk actions', 'racc-booking' ); ?></option>
                    <option value="reassign"><?php esc_html_e( 'Change Consultant / Reassign', 'racc-booking' ); ?></option>
                    <option value="delete"><?php esc_html_e( 'Delete permanently', 'racc-booking' ); ?></option>
                </select>
                <button type="submit" class="button action racc-bulk-apply"><?php esc_html_e( 'Apply', 'racc-booking' ); ?></button>
            </div>
            <br class="clear" />
        </div>
        </form>

        <!-- Pagination -->
        <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    $base_url = admin_url( 'admin.php?page=racc-booking' );
                    if ( $filter_status ) $base_url .= '&status=' . $filter_status;
                    if ( $filter_agent ) $base_url .= '&filter_agent=' . $filter_agent;
                    if ( $filter_date ) $base_url .= '&filter_date=' . $filter_date;
                    if ( $filter_nationality ) $base_url .= '&filter_nationality=' . rawurlencode( $filter_nationality );
                    if ( $filter_domicile ) $base_url .= '&filter_domicile=' . rawurlencode( $filter_domicile );
                    if ( $filter_agentcis_status ) $base_url .= '&filter_agentcis_status=' . rawurlencode( $filter_agentcis_status );
                    if ( $search_query ) $base_url .= '&s=' . rawurlencode( $search_query );

                    echo paginate_links( [
                        'base'    => $base_url . '&paged=%#%',
                        'format'  => '',
                        'current' => $paged,
                        'total'   => $total_pages,
                    ] );
                    ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var selectAll = document.getElementById('racc-select-all-bookings');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            var checked = !!selectAll.checked;
            document.querySelectorAll('.racc-booking-checkbox').forEach(function (cb) {
                cb.checked = checked;
            });
        });
    }

    document.querySelectorAll('.racc-bulk-apply').forEach(function (button) {
        button.addEventListener('click', function (event) {
            var form = button.closest('form');
            var actionTop = form.querySelector('[name="racc_bulk_action"]');
            var actionBottom = form.querySelector('[name="racc_bulk_action_bottom"]');
            var action = actionTop && actionTop.value !== '-1' ? actionTop.value : (actionBottom ? actionBottom.value : '-1');
            var selected = form.querySelectorAll('.racc-booking-checkbox:checked').length;

            if (selected < 1) {
                event.preventDefault();
                alert('<?php echo esc_js( __( 'Please select at least one booking first.', 'racc-booking' ) ); ?>');
                return;
            }

            if (action === 'delete' && !confirm('<?php echo esc_js( __( 'Delete selected bookings permanently?', 'racc-booking' ) ); ?>')) {
                event.preventDefault();
            }
        });
    });
});
</script>

<!-- Customer Details Modal -->
<div id="racc-details-modal" class="racc-modal" style="display:none;">
    <div class="racc-modal-content racc-modal-wide">
        <span class="racc-modal-close">&times;</span>
        <h2><?php esc_html_e( 'Customer Details', 'racc-booking' ); ?></h2>
        <div id="racc-details-content" class="racc-details-grid">
            <div class="racc-loading">
                <div class="racc-spinner"></div>
                <p><?php esc_html_e( 'Loading customer details...', 'racc-booking' ); ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Notes Modal -->
<div id="racc-notes-modal" class="racc-modal" style="display:none;">
    <div class="racc-modal-content">
        <span class="racc-modal-close">&times;</span>
        <h3><?php esc_html_e( 'Booking Notes', 'racc-booking' ); ?></h3>
        <div id="racc-notes-content"></div>
    </div>
</div>

<style>
.racc-modal {
    display: none;
    position: fixed;
    z-index: 100000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.5);
}

.racc-modal-content {
    background-color: #fff;
    margin: 50px auto;
    padding: 30px;
    border-radius: 8px;
    width: 90%;
    max-width: 600px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    position: relative;
}

.racc-modal-wide {
    max-width: 900px;
}

.racc-modal-close {
    color: #aaa;
    float: right;
    font-size: 28px;
    font-weight: bold;
    line-height: 20px;
    cursor: pointer;
}

.racc-modal-close:hover,
.racc-modal-close:focus {
    color: #000;
}

.racc-details-grid {
    margin-top: 20px;
}

.racc-detail-section {
    margin-bottom: 25px;
    padding-bottom: 20px;
    border-bottom: 1px solid #e5e5e5;
}

.racc-detail-section:last-child {
    border-bottom: none;
}

.racc-detail-section h3 {
    margin: 0 0 15px 0;
    padding: 8px 12px;
    background: #f0f6fc;
    border-left: 4px solid #2271b1;
    font-size: 14px;
    font-weight: 600;
    color: #1d2327;
}

.racc-detail-row {
    display: grid;
    grid-template-columns: 180px 1fr;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px solid #f0f0f0;
}

.racc-detail-row:last-child {
    border-bottom: none;
}

.racc-detail-label {
    font-weight: 600;
    color: #50575e;
    font-size: 13px;
}

.racc-detail-value {
    color: #1d2327;
    font-size: 13px;
}

.racc-detail-value.empty {
    color: #999;
    font-style: italic;
}

.racc-status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}

.racc-status-confirmed {
    background: #d1fae5;
    color: #065f46;
}

.racc-status-pending_payment {
    background: #fef3c7;
    color: #92400e;
}

.racc-status-rescheduled {
    background: #dbeafe;
    color: #1e40af;
}

.racc-status-cancelled {
    background: #fee2e2;
    color: #991b1b;
}

.racc-status-completed {
    background: #e0e7ff;
    color: #3730a3;
}

.racc-agentcis-sync-pending {
    background: #fef3c7;
    color: #92400e;
}

.racc-agentcis-sync-synced {
    background: #d1fae5;
    color: #065f46;
}

.racc-agentcis-sync-failed {
    background: #fee2e2;
    color: #991b1b;
}

.racc-bookings-table .column-service {
    width: 14%;
    max-width: 180px;
    white-space: normal;
    overflow-wrap: anywhere;
    word-break: break-word;
}

.racc-bookings-table .column-service .racc-tag {
    max-width: 100%;
    white-space: normal;
    line-height: 1.35;
}

.racc-bookings-table .column-service small,
.racc-bookings-table .column-service a {
    overflow-wrap: anywhere;
    word-break: break-word;
}

.racc-bookings-table .racc-row-actions {
    visibility: hidden;
    margin-top: 6px;
    color: #646970;
    font-size: 12px;
}

.racc-bookings-table tr:hover .racc-row-actions,
.racc-bookings-table tr:focus-within .racc-row-actions {
    visibility: visible;
}

@media (max-width: 768px) {
    .racc-modal-content {
        width: 95%;
        margin: 20px auto;
        padding: 20px;
    }
    
    .racc-detail-row {
        grid-template-columns: 1fr;
        gap: 4px;
    }

    .racc-bookings-table .racc-row-actions {
        visibility: visible;
    }
}
</style>
