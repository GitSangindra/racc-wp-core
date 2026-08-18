<?php
/**
 * Admin view: AgentCIS Integration Settings.
 *
 * @package RACC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$api_key      = get_option( 'racc_agentcis_api_key', '' );
$api_base     = get_option( 'racc_agentcis_api_base', '' );
$default_assignee_id = (int) get_option( 'racc_agentcis_default_assignee_id', 0 );
$is_connected = ! empty( $api_key ) && ! empty( $api_base );
$is_clients_api_mode = false === strpos( (string) $api_base, '/online-form/' );
$log_file     = WP_CONTENT_DIR . '/logs/racc-agentcis.log';
$nonce        = wp_create_nonce( 'racc_agentcis_nonce' );

// Custom field UUID values
$cf_course           = get_option( 'racc_agentcis_cf_course', '' );
$cf_release_letter   = get_option( 'racc_agentcis_cf_release_letter', '' );
$cf_university       = get_option( 'racc_agentcis_cf_university', '' );
$cf_interested_in    = get_option( 'racc_agentcis_cf_interested_in', '' );
$cf_cold_caller_id   = get_option( 'racc_agentcis_cf_cold_caller_id', '' );
$cf_cold_caller_date = get_option( 'racc_agentcis_cf_cold_caller_date', '' );
$cf_consultant_date  = get_option( 'racc_agentcis_cf_consultant_date', '' );
$cf_state            = get_option( 'racc_agentcis_cf_state', '' );
?>
<div class="wrap racc-admin-wrap">
    <h1 class="racc-admin-title">
        <span class="dashicons dashicons-admin-generic"></span>
        <?php esc_html_e( 'AgentCIS Integration Settings', 'racc-booking' ); ?>
    </h1>

    <?php if ( isset( $_GET['settings-updated'] ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Settings saved.', 'racc-booking' ); ?></p>
        </div>
    <?php elseif ( isset( $_GET['message'] ) && $_GET['message'] === 'users_synced' ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'AgentCIS Users synced successfully.', 'racc-booking' ); ?></p>
        </div>
    <?php elseif ( isset( $_GET['message'] ) && $_GET['message'] === 'users_sync_failed' ) : ?>
        <div class="notice notice-error is-dismissible">
            <p><?php esc_html_e( 'Failed to sync users. Please check API settings and logs.', 'racc-booking' ); ?></p>
        </div>
    <?php endif; ?>

    <div style="display:flex; gap:20px; align-items:flex-start; flex-wrap:wrap;">
        <div style="flex:1; min-width:400px;">
            <!-- API Key Card -->
            <div class="racc-admin-card" style="max-width:640px;">
                <h2 style="margin-top:0;"><?php esc_html_e( 'API Configuration', 'racc-booking' ); ?></h2>
                <form method="post" action="options.php">
                    <?php settings_fields( 'racc_agentcis_settings' ); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="racc_agentcis_api_key">
                                    <?php esc_html_e( 'API Key', 'racc-booking' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="password"
                                       name="racc_agentcis_api_key"
                                       id="racc_agentcis_api_key"
                                       value="<?php echo esc_attr( $api_key ); ?>"
                                       class="regular-text"
                                       placeholder="sk_live_..." />
                                <button type="button" id="racc-toggle-api-key" class="button button-small" style="margin-left:6px;">
                                    👁 <?php esc_html_e( 'Show', 'racc-booking' ); ?>
                                </button>
                                <p class="description">
                                    <?php esc_html_e( 'Get your API key from AgentCIS Dashboard → Settings → API Keys.', 'racc-booking' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="racc_agentcis_api_base">
                                    <?php esc_html_e( 'API Base URL / Endpoint', 'racc-booking' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="url"
                                       name="racc_agentcis_api_base"
                                       id="racc_agentcis_api_base"
                                       value="<?php echo esc_attr( $api_base ); ?>"
                                       class="regular-text"
                                       placeholder="https://yourdomain.agentcisapp.com/api/v2" />
                                <p class="description">
                                    <?php esc_html_e( 'Preferred: use AgentCIS base API URL, e.g. https://yourdomain.agentcisapp.com/api/v2 (plugin will call /clients). Legacy online-form URL is still supported.', 'racc-booking' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="racc_agentcis_default_assignee_id">
                                    <?php esc_html_e( 'Default Assignee ID', 'racc-booking' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number"
                                       min="0"
                                       step="1"
                                       name="racc_agentcis_default_assignee_id"
                                       id="racc_agentcis_default_assignee_id"
                                       value="<?php echo esc_attr( $default_assignee_id ); ?>"
                                       class="small-text" />
                                <p class="description">
                                    <?php esc_html_e( 'Required for Clients API mode when updating an existing client. Use the assignee/user ID from AgentCIS, not the local RACC consultant ID.', 'racc-booking' ); ?>
                                </p>
                                <?php if ( $is_clients_api_mode && $is_connected && $default_assignee_id <= 0 ) : ?>
                                    <p class="description" style="color:#b45309;">
                                        <?php esc_html_e( 'AgentCIS may reject client updates until this value is filled.', 'racc-booking' ); ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Status', 'racc-booking' ); ?></th>
                            <td>
                                <?php if ( $is_connected ) : ?>
                                    <span style="background:#dcfce7;color:#166534;padding:5px 12px;border-radius:4px;border:1px solid #86efac;font-size:13px;">
                                        ✅ <?php esc_html_e( 'API Key + Base URL Configured', 'racc-booking' ); ?>
                                    </span>
                                <?php else : ?>
                                    <span style="background:#fee2e2;color:#991b1b;padding:5px 12px;border-radius:4px;border:1px solid #fecaca;font-size:13px;">
                                        ❌ <?php esc_html_e( 'Configuration Incomplete', 'racc-booking' ); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    <p>
                        <button type="button" id="racc-agentcis-test-connection" class="button button-secondary">
                            <?php esc_html_e( 'Test Connection', 'racc-booking' ); ?>
                        </button>
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=racc-booking-settings&action=sync_agentcis_users' ), 'racc_sync_agentcis_users' ) ); ?>" class="button button-secondary" style="margin-left:8px;">
                            <?php esc_html_e( 'Sync Users from AgentCIS', 'racc-booking' ); ?>
                        </a>
                    </p>
                    <div id="racc-agentcis-test-result" style="display:none; margin:12px 0 0; padding:12px 14px; border-radius:6px;"></div>
                    <!-- Move submit button to the end -->
                </div>

            <!-- Custom Field UUID Mapping Card -->
            <div class="racc-admin-card" style="max-width:700px; margin-top: 20px;">
                <h2 style="margin-top:0;"><?php esc_html_e( 'Custom Field UUID Mapping', 'racc-booking' ); ?></h2>
                <p class="description" style="margin-bottom:16px;">
                    <?php esc_html_e( 'Map Agentcis custom fields to booking data. To find a UUID, GET a client detail from the Agentcis API and inspect the custom_fields keys in the response.', 'racc-booking' ); ?>
                    <br><code>GET https://{tenant}.agentcisapp.com/api/v2/clients/{client_id}</code>
                </p>
                <table class="form-table">

                        <tr><td colspan="2"><strong style="font-size:13px;color:#555;"><?php esc_html_e( '📚 Academic History', 'racc-booking' ); ?></strong></td></tr>

                        <tr>
                            <th scope="row">
                                <label for="racc_agentcis_cf_course"><?php esc_html_e( 'Course', 'racc-booking' ); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       name="racc_agentcis_cf_course"
                                       id="racc_agentcis_cf_course"
                                       value="<?php echo esc_attr( $cf_course ); ?>"
                                       class="regular-text"
                                       placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
                                <p class="description"><?php esc_html_e( 'Booking field: client_course_level', 'racc-booking' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="racc_agentcis_cf_release_letter"><?php esc_html_e( 'Release Letter', 'racc-booking' ); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       name="racc_agentcis_cf_release_letter"
                                       id="racc_agentcis_cf_release_letter"
                                       value="<?php echo esc_attr( $cf_release_letter ); ?>"
                                       class="regular-text"
                                       placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
                                <p class="description"><?php esc_html_e( 'Booking field: client_course_completion (date → YYYY-MM-DD)', 'racc-booking' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="racc_agentcis_cf_university"><?php esc_html_e( 'University', 'racc-booking' ); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       name="racc_agentcis_cf_university"
                                       id="racc_agentcis_cf_university"
                                       value="<?php echo esc_attr( $cf_university ); ?>"
                                       class="regular-text"
                                       placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
                                <p class="description"><?php esc_html_e( 'Booking field: client_university', 'racc-booking' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="racc_agentcis_cf_interested_in"><?php esc_html_e( 'What are you interested in', 'racc-booking' ); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       name="racc_agentcis_cf_interested_in"
                                       id="racc_agentcis_cf_interested_in"
                                       value="<?php echo esc_attr( $cf_interested_in ); ?>"
                                       class="regular-text"
                                       placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
                                <p class="description"><?php esc_html_e( 'Booking field: service_type', 'racc-booking' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="racc_agentcis_cf_state"><?php esc_html_e( 'State / Province', 'racc-booking' ); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       name="racc_agentcis_cf_state"
                                       id="racc_agentcis_cf_state"
                                       value="<?php echo esc_attr( $cf_state ); ?>"
                                       class="regular-text"
                                       placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
                                <p class="description"><?php esc_html_e( 'Booking field: client_state', 'racc-booking' ); ?></p>
                            </td>
                        </tr>

                        <tr><td colspan="2" style="padding-top:12px;"><strong style="font-size:13px;color:#555;"><?php esc_html_e( '📞 Contact', 'racc-booking' ); ?></strong></td></tr>

                        <tr>
                            <th scope="row">
                                <label for="racc_agentcis_cf_cold_caller_id"><?php esc_html_e( 'Cold Caller ID', 'racc-booking' ); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       name="racc_agentcis_cf_cold_caller_id"
                                       id="racc_agentcis_cf_cold_caller_id"
                                       value="<?php echo esc_attr( $cf_cold_caller_id ); ?>"
                                       class="regular-text"
                                       placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
                                <p class="description"><?php esc_html_e( 'Booking field: agent_id — sent as dropdown option ID (custom_fields[uuid][])', 'racc-booking' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="racc_agentcis_cf_cold_caller_date"><?php esc_html_e( 'Cold Caller Last Contact Date', 'racc-booking' ); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       name="racc_agentcis_cf_cold_caller_date"
                                       id="racc_agentcis_cf_cold_caller_date"
                                       value="<?php echo esc_attr( $cf_cold_caller_date ); ?>"
                                       class="regular-text"
                                       placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
                                <p class="description"><?php esc_html_e( 'Booking field: booking_date (date → YYYY-MM-DD)', 'racc-booking' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="racc_agentcis_cf_consultant_date"><?php esc_html_e( 'Consultant Latest Contact Date', 'racc-booking' ); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       name="racc_agentcis_cf_consultant_date"
                                       id="racc_agentcis_cf_consultant_date"
                                       value="<?php echo esc_attr( $cf_consultant_date ); ?>"
                                       class="regular-text"
                                       placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
                                <p class="description"><?php esc_html_e( 'Booking field: booking_date (date → YYYY-MM-DD)', 'racc-booking' ); ?></p>
                            </td>
                        </tr>
                    </table>

                    <p class="description" style="margin-bottom:8px;">
                        <?php esc_html_e( 'Leave any UUID field blank to skip it. Only fields with a UUID entered will be included in the sync payload.', 'racc-booking' ); ?>
                    </p>
                    <?php submit_button( __( 'Save All Settings', 'racc-booking' ) ); ?>
                </form>
            </div>

            <div class="notice notice-warning" style="max-width:700px; margin: 0 0 20px;">
                <p>
                    <strong><?php esc_html_e( 'Integration mode:', 'racc-booking' ); ?></strong>
                    <?php esc_html_e( 'If URL contains /online-form/, plugin uses online-form mode. Otherwise it uses authenticated Clients API mode (/api/v2/clients).', 'racc-booking' ); ?>
                </p>
            </div>

            <!-- How It Works Card -->
            <div class="racc-admin-card" style="max-width:700px;">
                <h2 style="margin-top:0;"><?php esc_html_e( 'How It Works', 'racc-booking' ); ?></h2>
                <ul style="line-height:2.2;margin:0;padding-left:20px;">
                    <li>📥 <strong><?php esc_html_e( 'New Booking', 'racc-booking' ); ?></strong> — <?php esc_html_e( 'Creates client in AgentCIS via Clients API mode (recommended) or submits to online-form mode (legacy).', 'racc-booking' ); ?></li>
                    <li>🔄 <strong><?php esc_html_e( 'Reschedule', 'racc-booking' ); ?></strong> — <?php esc_html_e( 'Logged locally. Client update endpoint is not triggered automatically yet.', 'racc-booking' ); ?></li>
                    <li>❌ <strong><?php esc_html_e( 'Cancellation', 'racc-booking' ); ?></strong> — <?php esc_html_e( 'Logged locally only.', 'racc-booking' ); ?></li>
                    <li>🔁 <strong><?php esc_html_e( 'Retry Logic', 'racc-booking' ); ?></strong> — <?php esc_html_e( 'Failed submissions can be retried from the booking reschedule page.', 'racc-booking' ); ?></li>
                </ul>
            </div>

            <!-- Sync Logs Card -->
            <div class="racc-admin-card">
                <h2 style="margin-top:0;">
                    <?php esc_html_e( 'Sync Logs', 'racc-booking' ); ?>
                    <button type="button" id="racc-refresh-logs" class="button button-small" style="margin-left:10px;vertical-align:middle;">
                        <span class="dashicons dashicons-update" style="vertical-align:middle;margin-top:-2px;"></span>
                        <?php esc_html_e( 'Refresh', 'racc-booking' ); ?>
                    </button>
                    <?php if ( file_exists( $log_file ) ) : ?>
                        <a href="#" id="racc-clear-logs" class="button button-small" style="margin-left:6px;vertical-align:middle;color:#b91c1c;border-color:#b91c1c;">
                            🗑 <?php esc_html_e( 'Clear Logs', 'racc-booking' ); ?>
                        </a>
                    <?php endif; ?>
                </h2>
                <pre id="racc-agentcis-logs"
                     style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:6px;
                            max-height:450px;overflow-y:auto;font-size:12px;line-height:1.7;
                            font-family:'Courier New',monospace;margin:0;white-space:pre-wrap;">
                    <?php esc_html_e( 'Loading logs...', 'racc-booking' ); ?>
                </pre>
                <p class="description" style="margin-top:8px;">
                    <?php if ( file_exists( $log_file ) ) : ?>
                        <?php printf(
                            /* translators: 1: path, 2: size */
                            esc_html__( 'Log file: %1$s (%2$s)', 'racc-booking' ),
                            '<code>' . esc_html( $log_file ) . '</code>',
                            esc_html( size_format( filesize( $log_file ) ) )
                        ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'No log file yet. Sync a booking to begin logging.', 'racc-booking' ); ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- Right Column: AgentCIS Users Status Table -->
        <div style="flex:1; min-width:400px;">
            <div class="racc-admin-card">
                <h2 style="margin-top:0;">
                    <?php esc_html_e( 'AgentCIS Synced Users Status', 'racc-booking' ); ?>
                </h2>
                <p class="description" style="margin-bottom:15px;">
                    <?php esc_html_e( 'This table shows all users pulled from AgentCIS and their current mapping status with your local consultants.', 'racc-booking' ); ?>
                </p>
                <?php
                $agentcis_users = get_option( 'racc_agentcis_users_list', [] );
                if ( empty( $agentcis_users ) ) : ?>
                    <div class="notice notice-warning inline"><p><?php esc_html_e( 'No users synced yet. Please click "Sync Users from AgentCIS" first.', 'racc-booking' ); ?></p></div>
                <?php else : 
                    global $wpdb;
                    $local_agents = $wpdb->get_results( "SELECT id, name, agentcis_assignee_id FROM {$wpdb->prefix}racc_agents WHERE status != 'trashed'" );
                    $mapped_ids = [];
                    foreach ( $local_agents as $la ) {
                        if ( $la->agentcis_assignee_id > 0 ) {
                            $mapped_ids[ $la->agentcis_assignee_id ][] = $la->name;
                        }
                    }
                ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'AgentCIS Name', 'racc-booking' ); ?></th>
                                <th><?php esc_html_e( 'Email', 'racc-booking' ); ?></th>
                                <th><?php esc_html_e( 'Local Mapping Status', 'racc-booking' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $agentcis_users as $user ) : 
                                $is_mapped = isset( $mapped_ids[ $user['id'] ] );
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html( $user['name'] ); ?></strong>
                                        <div style="font-size:11px;color:#777;">ID: <?php echo esc_html( $user['id'] ); ?></div>
                                    </td>
                                    <td>
                                        <a href="mailto:<?php echo esc_attr( $user['email'] ); ?>"><?php echo esc_html( $user['email'] ); ?></a>
                                    </td>
                                    <td>
                                        <?php if ( $is_mapped ) : ?>
                                            <span style="color:#166534;font-weight:600;">✅ Mapped to:</span><br>
                                            <small><?php echo esc_html( implode( ', ', $mapped_ids[ $user['id'] ] ) ); ?></small>
                                        <?php else : ?>
                                            <span style="color:#b91c1c;">❌ Not Mapped</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function($) {
    var nonce = '<?php echo esc_js( $nonce ); ?>';

    // Toggle API key visibility
    $( '#racc-toggle-api-key' ).on( 'click', function() {
        var $field = $( '#racc_agentcis_api_key' );
        if ( $field.attr( 'type' ) === 'password' ) {
            $field.attr( 'type', 'text' );
            $( this ).html( '🙈 <?php esc_html_e( 'Hide', 'racc-booking' ); ?>' );
        } else {
            $field.attr( 'type', 'password' );
            $( this ).html( '👁 <?php esc_html_e( 'Show', 'racc-booking' ); ?>' );
        }
    });

    // Load logs
    function loadLogs() {
        $( '#racc-refresh-logs' ).prop( 'disabled', true );
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: { action: 'racc_get_agentcis_logs', nonce: nonce },
            success: function( response ) {
                if ( response.success ) {
                    $( '#racc-agentcis-logs' ).text( response.data.logs || 'No logs yet.' );
                }
            },
            error: function() {
                $( '#racc-agentcis-logs' ).text( 'Failed to load logs.' );
            },
            complete: function() {
                $( '#racc-refresh-logs' ).prop( 'disabled', false );
            }
        });
    }

    $( '#racc-refresh-logs' ).on( 'click', loadLogs );

    $( '#racc-agentcis-test-connection' ).on( 'click', function() {
        var $btn = $( this );
        var $result = $( '#racc-agentcis-test-result' );

        $btn.prop( 'disabled', true ).text( 'Testing...' );
        $result.hide().removeAttr( 'style' ).attr( 'style', 'display:none; margin:12px 0 0; padding:12px 14px; border-radius:6px;' );

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'racc_agentcis_test_connection',
                nonce: nonce
            },
            success: function( response ) {
                if ( response.success ) {
                    var html = '<strong>✅ ' + response.data.message + '</strong>';
                    html += '<br>URL: ' + response.data.url;
                    if ( response.data.preview ) {
                        html += '<br><br><em>Response preview:</em><br>' + response.data.preview;
                    }
                    $result.html( html )
                        .css({
                            display: 'block',
                            background: '#ecfdf5',
                            border: '1px solid #86efac',
                            color: '#166534'
                        });
                } else {
                    $result.html( '<strong>❌ Connection failed.</strong><br>' + ( response.data && response.data.message ? response.data.message : 'Unknown error.' ) )
                        .css({
                            display: 'block',
                            background: '#fef2f2',
                            border: '1px solid #fecaca',
                            color: '#991b1b'
                        });
                }
            },
            error: function() {
                $result.html( '<strong>❌ Connection failed.</strong><br>Request could not be completed.' )
                    .css({
                        display: 'block',
                        background: '#fef2f2',
                        border: '1px solid #fecaca',
                        color: '#991b1b'
                    });
            },
            complete: function() {
                $btn.prop( 'disabled', false ).text( 'Test Connection' );
            }
        });
    });

    // Clear logs
    $( '#racc-clear-logs' ).on( 'click', function(e) {
        e.preventDefault();
        if ( ! confirm( '<?php esc_html_e( 'Clear all sync logs?', 'racc-booking' ); ?>' ) ) {
            return;
        }
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: { action: 'racc_clear_agentcis_logs', nonce: nonce },
            success: function() {
                $( '#racc-agentcis-logs' ).text( 'Logs cleared.' );
            }
        });
    });

    // Load on page init
    loadLogs();
})(jQuery);
</script>
