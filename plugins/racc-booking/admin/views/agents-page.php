<?php
/**
 * Admin view: Consultants (Agents) page.
 *
 * @package RACC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$message  = sanitize_text_field( $_GET['message'] ?? '' );
$action   = sanitize_text_field( $_GET['agent_action'] ?? '' );
$agent_id = absint( $_GET['agent_id'] ?? 0 );
$gcal     = new \RACC_Booking\Google_Calendar();
$settings = get_option( 'racc_booking_settings', [] );
$countries = \RACC_Booking\Country_Helper::get_country_list();

// If editing an agent, fetch their data
$editing_agent = null;
if ( $action === 'edit' && $agent_id > 0 ) {
    $editing_agent = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}racc_agents WHERE id = %d",
        $agent_id
    ) );
}

// Get all agents
$agents = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}racc_agents ORDER BY name ASC" );
?>
<div class="wrap racc-admin-wrap">
    <h1 class="racc-admin-title">
        <span class="dashicons dashicons-groups"></span>
        <?php esc_html_e( 'RACC Booking — Consultants', 'racc-booking' ); ?>
    </h1>

    <?php if ( $message === 'agent_added' ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Consultant added successfully.', 'racc-booking' ); ?></p></div>
    <?php elseif ( $message === 'agent_updated' ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Consultant updated successfully.', 'racc-booking' ); ?></p></div>
    <?php elseif ( $message === 'agent_deleted' ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Consultant deleted.', 'racc-booking' ); ?></p></div>
    <?php elseif ( $message === 'google_connected' ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Google Calendar connected successfully!', 'racc-booking' ); ?></p></div>
    <?php elseif ( $message === 'google_disconnected' ) : ?>
        <div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Google Calendar disconnected.', 'racc-booking' ); ?></p></div>
    <?php endif; ?>

    <div class="racc-admin-layout">
        <!-- Add/Edit Agent Form -->
        <div class="racc-admin-card racc-agent-form-card">
            <h2>
                <?php echo $editing_agent
                    ? esc_html__( 'Edit Consultant', 'racc-booking' )
                    : esc_html__( 'Add New Consultant', 'racc-booking' ); ?>
            </h2>

            <form method="post" action="">
                <?php wp_nonce_field( 'racc_save_agent' ); ?>
                <input type="hidden" name="agent_id" value="<?php echo esc_attr( $editing_agent->id ?? 0 ); ?>" />

                <table class="form-table">
                    <tr>
                        <th><label for="agent_name"><?php esc_html_e( 'Name', 'racc-booking' ); ?> <span class="required">*</span></label></th>
                        <td>
                            <input type="text" id="agent_name" name="agent_name" class="regular-text" required
                                   value="<?php echo esc_attr( $editing_agent->name ?? '' ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="agent_email"><?php esc_html_e( 'Email', 'racc-booking' ); ?> <span class="required">*</span></label></th>
                        <td>
                            <input type="email" id="agent_email" name="agent_email" class="regular-text" required
                                   value="<?php echo esc_attr( $editing_agent->email ?? '' ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="agent_zoom_link"><?php esc_html_e( 'Zoom Link', 'racc-booking' ); ?></label></th>
                        <td>
                            <input type="url" id="agent_zoom_link" name="agent_zoom_link" class="regular-text"
                                   placeholder="https://zoom.us/j/..."
                                   value="<?php echo esc_attr( $editing_agent->zoom_link ?? '' ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="agent_phone"><?php esc_html_e( 'Phone', 'racc-booking' ); ?></label></th>
                        <td>
                            <input type="text" id="agent_phone" name="agent_phone" class="regular-text"
                                   value="<?php echo esc_attr( $editing_agent->phone ?? '' ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="agent_nationality"><?php esc_html_e( 'Nationality', 'racc-booking' ); ?></label></th>
                        <td>
                            <select id="agent_nationality"
                                    name="agent_nationality"
                                    class="regular-text racc-searchable-select"
                                    data-racc-searchable-select="1"
                                    data-search-placeholder="<?php esc_attr_e( 'Search nationality...', 'racc-booking' ); ?>">
                                <option value=""><?php esc_html_e( '— Select Nationality —', 'racc-booking' ); ?></option>
                                <?php foreach ( $countries as $code => $name ) : ?>
                                    <option value="<?php echo esc_attr( $name ); ?>" <?php selected( $editing_agent->nationality ?? '', $name ); ?>>
                                        <?php echo esc_html( $name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="agent_domicile"><?php esc_html_e( 'Domicile', 'racc-booking' ); ?></label></th>
                        <td>
                            <select id="agent_domicile"
                                    name="agent_domicile"
                                    class="regular-text racc-searchable-select"
                                    data-racc-searchable-select="1"
                                    data-search-placeholder="<?php esc_attr_e( 'Search domicile...', 'racc-booking' ); ?>">
                                <option value=""><?php esc_html_e( '— Select Domicile —', 'racc-booking' ); ?></option>
                                <?php foreach ( $countries as $code => $name ) : ?>
                                    <option value="<?php echo esc_attr( $name ); ?>" <?php selected( $editing_agent->domicile ?? '', $name ); ?>>
                                        <?php echo esc_html( $name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="agent_nation_coverage"><?php esc_html_e( 'Nation Coverage', 'racc-booking' ); ?></label></th>
                        <td>
                            <?php
                            $current_coverage = [];
                            if ( ! empty( $editing_agent->nation_coverage ) ) {
                                $current_coverage = json_decode( $editing_agent->nation_coverage, true ) ?: [];
                            }
                            ?>
                            <select id="agent_nation_coverage"
                                    name="agent_nation_coverage[]"
                                    class="regular-text"
                                    multiple="multiple"
                                    data-racc-searchable-multi-select="1"
                                    data-search-placeholder="<?php esc_attr_e( 'Search country...', 'racc-booking' ); ?>">
                                <?php foreach ( $countries as $code => $name ) : ?>
                                    <option value="<?php echo esc_attr( $name ); ?>" <?php echo in_array( $name, $current_coverage, true ) ? 'selected="selected"' : ''; ?>>
                                        <?php echo esc_html( $name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Select multiple countries to auto-assign bookings based on the lead\'s domicile/nationality.', 'racc-booking' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Service Assignment', 'racc-booking' ); ?></th>
                        <td>
                            <p class="description" style="margin-top:0;">
                                <?php esc_html_e( 'Service-to-consultant mapping is managed from WooCommerce products.', 'racc-booking' ); ?>
                            </p>
                            <p>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=racc-booking-woo-settings' ) ); ?>" class="button button-small">
                                    <?php esc_html_e( 'Open Booking Bridge Settings', 'racc-booking' ); ?>
                                </a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Working Hours', 'racc-booking' ); ?></label></th>
                        <td>
                            <input type="time" name="working_hours_start"
                                   value="<?php echo esc_attr( $editing_agent->working_hours_start ?? '09:00' ); ?>" />
                            <?php esc_html_e( 'to', 'racc-booking' ); ?>
                            <input type="time" name="working_hours_end"
                                   value="<?php echo esc_attr( $editing_agent->working_hours_end ?? '17:00' ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Working Days', 'racc-booking' ); ?></th>
                        <td>
                            <?php
                            $days = [
                                1 => __( 'Monday', 'racc-booking' ),
                                2 => __( 'Tuesday', 'racc-booking' ),
                                3 => __( 'Wednesday', 'racc-booking' ),
                                4 => __( 'Thursday', 'racc-booking' ),
                                5 => __( 'Friday', 'racc-booking' ),
                                6 => __( 'Saturday', 'racc-booking' ),
                                7 => __( 'Sunday', 'racc-booking' ),
                            ];
                            $current_days = $editing_agent
                                ? array_map( 'intval', explode( ',', $editing_agent->working_days ) )
                                : [ 1, 2, 3, 4, 5 ];
                            foreach ( $days as $num => $label ) :
                            ?>
                                <label style="display:inline-block; margin-right:12px;">
                                    <input type="checkbox" name="working_days[]" value="<?php echo $num; ?>"
                                        <?php checked( in_array( $num, $current_days, true ) ); ?> />
                                    <?php echo esc_html( $label ); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="agent_timezone"><?php esc_html_e( 'Timezone', 'racc-booking' ); ?></label></th>
                        <td>
                            <select id="agent_timezone" name="agent_timezone">
                                <?php
                                $current_tz = $editing_agent->timezone ?? $settings['timezone'] ?? 'Australia/Sydney';
                                $timezones  = timezone_identifiers_list();
                                foreach ( $timezones as $tz ) :
                                ?>
                                    <option value="<?php echo esc_attr( $tz ); ?>" <?php selected( $current_tz, $tz ); ?>>
                                        <?php echo esc_html( $tz ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="agent_status"><?php esc_html_e( 'Status', 'racc-booking' ); ?></label></th>
                        <td>
                            <select id="agent_status" name="agent_status">
                                <option value="active" <?php selected( ( $editing_agent->status ?? 'active' ), 'active' ); ?>>
                                    <?php esc_html_e( 'Active', 'racc-booking' ); ?>
                                </option>
                                <option value="inactive" <?php selected( ( $editing_agent->status ?? '' ), 'inactive' ); ?>>
                                    <?php esc_html_e( 'Inactive', 'racc-booking' ); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="agentcis_assignee_id"><?php esc_html_e( 'AgentCIS User Profile', 'racc-booking' ); ?></label></th>
                        <td>
                            <select id="agentcis_assignee_id" name="agentcis_assignee_id" class="regular-text racc-searchable-select" data-racc-searchable-select="1">
                                <option value="0"><?php esc_html_e( '(None / Use Default Assignee)', 'racc-booking' ); ?></option>
                                <?php
                                $agentcis_users = get_option( 'racc_agentcis_users_list', [] );
                                foreach ( $agentcis_users as $user ) :
                                    $selected = selected( (int) ( $editing_agent->agentcis_assignee_id ?? 0 ), (int) $user['id'], false );
                                ?>
                                    <option value="<?php echo esc_attr( $user['id'] ); ?>" <?php echo $selected; ?>>
                                        <?php echo esc_html( $user['name'] . ' (' . $user['email'] . ')' ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'Select the matching user in AgentCIS. If "(None)", the Default Assignee ID from AgentCIS Settings will be used.', 'racc-booking' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="racc_save_agent" class="button button-primary"
                           value="<?php echo $editing_agent
                               ? esc_attr__( 'Update Consultant', 'racc-booking' )
                               : esc_attr__( 'Add Consultant', 'racc-booking' ); ?>" />
                    <?php if ( $editing_agent ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=racc-booking-agents' ) ); ?>" class="button">
                            <?php esc_html_e( 'Cancel', 'racc-booking' ); ?>
                        </a>
                    <?php endif; ?>
                </p>
            </form>
        </div>

        <!-- Agents List -->
        <div class="racc-admin-card racc-agents-list-card">
            <h2><?php esc_html_e( 'All Consultants', 'racc-booking' ); ?></h2>

            <?php if ( empty( $agents ) ) : ?>
                <p class="racc-empty-state"><?php esc_html_e( 'No consultants added yet. Use the form to add your first consultant.', 'racc-booking' ); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Name', 'racc-booking' ); ?></th>
                            <th><?php esc_html_e( 'Email', 'racc-booking' ); ?></th>
                            <th><?php esc_html_e( 'Nationality', 'racc-booking' ); ?></th>
                            <th><?php esc_html_e( 'Domicile', 'racc-booking' ); ?></th>
                            <th><?php esc_html_e( 'Service Assignment', 'racc-booking' ); ?></th>
                            <th><?php esc_html_e( 'Google Calendar', 'racc-booking' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'racc-booking' ); ?></th>
                            <th><?php esc_html_e( 'AgentCIS Mapping', 'racc-booking' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $agents as $agent ) :
                            $connection = $gcal->get_connection_status( $agent->id, true );
                            $connection_status = $connection['status'] ?? 'not_connected';
                            $is_connected = ( 'connected' === $connection_status );
                            $needs_reconnect = ( 'reconnect_required' === $connection_status );
                            $has_creds    = ! empty( $settings['google_client_id'] ) && ! empty( $settings['google_client_secret'] );
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $agent->name ); ?></strong>

                                    <div class="row-actions racc-row-actions">
                                        <span class="edit">
                                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=racc-booking-agents&agent_action=edit&agent_id=' . $agent->id ) ); ?>">
                                                <?php esc_html_e( 'Edit', 'racc-booking' ); ?>
                                            </a>
                                        </span>
                                        <span class="delete"> |
                                            <a href="<?php echo esc_url( wp_nonce_url(
                                                admin_url( 'admin.php?page=racc-booking-agents&action=delete_agent&agent_id=' . $agent->id ),
                                                'racc_delete_agent'
                                            ) ); ?>"
                                               class="submitdelete"
                                               onclick="return confirm('<?php esc_attr_e( 'Delete this consultant? This cannot be undone.', 'racc-booking' ); ?>');">
                                                <?php esc_html_e( 'Delete', 'racc-booking' ); ?>
                                            </a>
                                        </span>
                                    </div>
                                </td>
                                <td><?php echo esc_html( $agent->email ); ?></td>
                                <td>
                                    <?php echo ! empty( $agent->nationality ) ? esc_html( $agent->nationality ) : '<span style="color:#aaa;">—</span>'; ?>
                                </td>
                                <td>
                                    <?php echo ! empty( $agent->domicile ) ? esc_html( $agent->domicile ) : '<span style="color:#aaa;">—</span>'; ?>
                                </td>
                                <td>
                                    <span class="racc-tag racc-tag-muted"><?php esc_html_e( 'Managed via Woo products', 'racc-booking' ); ?></span>
                                </td>
                                <td>
                                    <?php if ( $is_connected ) : ?>
                                        <span class="racc-status racc-status-connected">✅ <?php esc_html_e( 'Connected', 'racc-booking' ); ?></span>
                                        <?php if ( $agent->calendar_id ) : ?>
                                            <br><small><?php echo esc_html( $agent->calendar_id ); ?></small>
                                        <?php endif; ?>
                                        <br>
                                        <a href="<?php echo esc_url( wp_nonce_url(
                                            admin_url( 'admin.php?page=racc-booking-agents&action=disconnect_google&agent_id=' . $agent->id ),
                                            'racc_disconnect_google'
                                        ) ); ?>" class="button button-small" onclick="return confirm('<?php esc_attr_e( 'Disconnect Google Calendar?', 'racc-booking' ); ?>');">
                                            <?php esc_html_e( 'Disconnect', 'racc-booking' ); ?>
                                        </a>
                                    <?php elseif ( $needs_reconnect && $has_creds ) : ?>
                                        <span class="racc-status racc-status-warning">⚠️ <?php esc_html_e( 'Token invalid/expired — reconnect required', 'racc-booking' ); ?></span>
                                        <?php if ( $agent->calendar_id ) : ?>
                                            <br><small><?php echo esc_html( $agent->calendar_id ); ?></small>
                                        <?php endif; ?>
                                        <br>
                                        <a href="<?php echo esc_url( wp_nonce_url(
                                            admin_url( 'admin.php?page=racc-booking-agents&action=reconnect_google&agent_id=' . $agent->id ),
                                            'racc_reconnect_google'
                                        ) ); ?>" class="button button-primary button-small">
                                            <?php esc_html_e( 'Reconnect Google Calendar', 'racc-booking' ); ?>
                                        </a>
                                    <?php elseif ( $has_creds ) : ?>
                                        <a href="<?php echo esc_url( $gcal->get_auth_url( $agent->id ) ); ?>" class="button button-primary button-small">
                                            <?php esc_html_e( 'Connect Google Calendar', 'racc-booking' ); ?>
                                        </a>
                                    <?php else : ?>
                                        <span class="racc-status racc-status-warning">⚠️ <?php esc_html_e( 'Configure API keys first', 'racc-booking' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="racc-status racc-status-<?php echo esc_attr( $agent->status ); ?>">
                                        <?php echo $agent->status === 'active'
                                            ? esc_html__( 'Active', 'racc-booking' )
                                            : esc_html__( 'Inactive', 'racc-booking' ); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    $mapped_name = '<span style="color:#aaa;">(None)</span>';
                                    if ( ! empty( $agent->agentcis_assignee_id ) ) {
                                        $agentcis_users = get_option( 'racc_agentcis_users_list', [] );
                                        foreach ( $agentcis_users as $u ) {
                                            if ( (int) $u['id'] === (int) $agent->agentcis_assignee_id ) {
                                                $mapped_name = esc_html( $u['name'] );
                                                break;
                                            }
                                        }
                                        if ( $mapped_name === '<span style="color:#aaa;">(None)</span>' ) {
                                            $mapped_name = esc_html( $agent->agentcis_assignee_id ); // fallback to ID
                                        }
                                    }
                                    echo $mapped_name;
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.racc-agents-list-card .racc-row-actions {
    visibility: hidden;
    margin-top: 6px;
    color: #646970;
    font-size: 12px;
}

.racc-agents-list-card .wp-list-table tr:hover .racc-row-actions,
.racc-agents-list-card .wp-list-table tr:focus-within .racc-row-actions {
    visibility: visible;
}

@media (max-width: 768px) {
    .racc-agents-list-card .racc-row-actions {
        visibility: visible;
    }
}
</style>
