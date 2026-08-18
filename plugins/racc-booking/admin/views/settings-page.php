<?php
/**
 * Admin view: Settings page.
 *
 * @package RACC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$settings = get_option( 'racc_booking_settings', [] );
$message  = sanitize_text_field( $_GET['message'] ?? '' );
$error    = sanitize_text_field( $_GET['error'] ?? '' );
$visa_categories = \RACC_Booking\Visa_Categories::get_options();
?>
<div class="wrap racc-admin-wrap">
    <h1 class="racc-admin-title">
        <span class="dashicons dashicons-calendar-alt"></span>
        <?php esc_html_e( 'RACC Booking — Settings', 'racc-booking' ); ?>
    </h1>

    <?php if ( $message === 'settings_saved' ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Settings saved successfully.', 'racc-booking' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( $error ) : ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html( $error ); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <?php wp_nonce_field( 'racc_save_settings' ); ?>

        <!-- Google API Settings -->
        <div class="racc-settings-section">
            <h2><?php esc_html_e( 'Google Calendar API', 'racc-booking' ); ?></h2>
            
            <div class="notice notice-info inline" style="margin: 15px 0; padding: 12px;">
                <p><strong><?php esc_html_e( 'Setup Instructions:', 'racc-booking' ); ?></strong></p>
                <ol style="margin-left: 20px;">
                    <li><?php esc_html_e( 'Go to Google Cloud Console and create a new project', 'racc-booking' ); ?>: <a href="https://console.cloud.google.com/" target="_blank">console.cloud.google.com</a></li>
                    <li><?php esc_html_e( 'Enable the Google Calendar API', 'racc-booking' ); ?></li>
                    <li><?php esc_html_e( 'Configure OAuth consent screen (add email as Test user if in Testing mode)', 'racc-booking' ); ?></li>
                    <li><?php esc_html_e( 'Create OAuth 2.0 Client ID credentials (Web application type)', 'racc-booking' ); ?></li>
                    <li><?php esc_html_e( 'Add the Redirect URI below to your OAuth 2.0 settings', 'racc-booking' ); ?></li>
                    <li><?php esc_html_e( 'Copy Client ID and Client Secret here', 'racc-booking' ); ?></li>
                </ol>
                <p style="margin-top: 10px;">
                    📖 <a href="<?php echo esc_url( RACC_BOOKING_URL . 'GOOGLE_OAUTH_SETUP.md' ); ?>" target="_blank">
                        <?php esc_html_e( 'Read detailed setup guide', 'racc-booking' ); ?>
                    </a>
                </p>
            </div>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="google_client_id"><?php esc_html_e( 'Client ID', 'racc-booking' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="google_client_id" name="google_client_id"
                               value="<?php echo esc_attr( $settings['google_client_id'] ?? '' ); ?>"
                               class="large-text" placeholder="xxxxx.apps.googleusercontent.com" />
                        <p class="description">
                            <?php esc_html_e( 'Get this from Google Cloud Console > APIs & Services > Credentials', 'racc-booking' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="google_client_secret"><?php esc_html_e( 'Client Secret', 'racc-booking' ); ?></label>
                    </th>
                    <td>
                        <input type="password" id="google_client_secret" name="google_client_secret"
                               value="<?php echo esc_attr( $settings['google_client_secret'] ?? '' ); ?>"
                               class="large-text" placeholder="GOCSPX-xxxx" />
                        <p class="description">
                            <?php esc_html_e( 'Keep this secret and never share it publicly', 'racc-booking' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <?php esc_html_e( 'Redirect URI', 'racc-booking' ); ?>
                    </th>
                    <td>
                        <?php $redirect_uri = admin_url( 'admin.php?page=racc-booking-settings&racc_oauth_callback=1' ); ?>
                        <div style="background: #f0f0f0; padding: 10px; border-radius: 4px; margin-bottom: 10px;">
                            <code style="font-size: 13px; user-select: all;"><?php echo esc_html( $redirect_uri ); ?></code>
                            <button type="button" class="button button-small" style="margin-left: 10px;" onclick="navigator.clipboard.writeText('<?php echo esc_js( $redirect_uri ); ?>'); this.textContent='✓ Copied!';">
                                <?php esc_html_e( 'Copy', 'racc-booking' ); ?>
                            </button>
                        </div>
                        <p class="description" style="color: #d63638;">
                            <strong>⚠️ <?php esc_html_e( 'IMPORTANT:', 'racc-booking' ); ?></strong>
                            <?php esc_html_e( 'Copy this EXACT URL and add it as an "Authorized redirect URI" in your Google Cloud Console OAuth 2.0 Client settings. The URL must match exactly (including http/https).', 'racc-booking' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php if ( ! empty( $settings['google_client_id'] ) && ! empty( $settings['google_client_secret'] ) ) : ?>
                <div class="notice notice-success inline" style="margin-top: 15px;">
                    <p>✅ <?php esc_html_e( 'Google API credentials are configured. You can now connect agent calendars.', 'racc-booking' ); ?></p>
                </div>
            <?php else : ?>
                <div class="notice notice-warning inline" style="margin-top: 15px;">
                    <p>⚠️ <?php esc_html_e( 'Please configure Google API credentials to enable calendar integration.', 'racc-booking' ); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- General Settings -->
        <div class="racc-settings-section">
            <h2><?php esc_html_e( 'General Settings', 'racc-booking' ); ?></h2>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="slot_duration"><?php esc_html_e( 'Fallback Slot Duration (minutes)', 'racc-booking' ); ?></label>
                    </th>
                    <td>
                        <select id="slot_duration" name="slot_duration">
                            <?php
                            $durations = [ 15, 30, 45, 60, 90, 120 ];
                            $current   = intval( $settings['slot_duration'] ?? 60 );
                            foreach ( $durations as $d ) :
                            ?>
                                <option value="<?php echo $d; ?>" <?php selected( $current, $d ); ?>>
                                    <?php echo $d; ?> <?php esc_html_e( 'minutes', 'racc-booking' ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'Used only when a WooCommerce product has no slot duration yet. Product-level duration takes priority.', 'racc-booking' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="timezone"><?php esc_html_e( 'Default Timezone', 'racc-booking' ); ?></label>
                    </th>
                    <td>
                        <select id="timezone" name="timezone">
                            <?php
                            $current_tz = $settings['timezone'] ?? 'Australia/Sydney';
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
                    <th scope="row">
                        <label for="notification_email"><?php esc_html_e( 'Notification Email', 'racc-booking' ); ?></label>
                    </th>
                    <td>
                        <input type="email" id="notification_email" name="notification_email"
                               value="<?php echo esc_attr( $settings['notification_email'] ?? get_option( 'admin_email' ) ); ?>"
                               class="regular-text" />
                        <p class="description">
                            <?php esc_html_e( 'Admin email that receives booking notifications.', 'racc-booking' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="racc-settings-section">
            <h2><?php esc_html_e( 'Default Contact Lokasi', 'racc-booking' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Info ini akan dipakai jika lokasi memilih opsi "Gunakan info kontak yang sama seperti lokasi default Anda".', 'racc-booking' ); ?>
            </p>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="default_contact_name"><?php esc_html_e( 'Nama Kontak', 'racc-booking' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="default_contact_name" name="default_contact_name"
                               value="<?php echo esc_attr( $settings['default_contact_name'] ?? '' ); ?>"
                               class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="default_contact_phone"><?php esc_html_e( 'Telepon Kontak', 'racc-booking' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="default_contact_phone" name="default_contact_phone"
                               value="<?php echo esc_attr( $settings['default_contact_phone'] ?? '' ); ?>"
                               class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="default_contact_email"><?php esc_html_e( 'Email Kontak', 'racc-booking' ); ?></label>
                    </th>
                    <td>
                        <input type="email" id="default_contact_email" name="default_contact_email"
                               value="<?php echo esc_attr( $settings['default_contact_email'] ?? '' ); ?>"
                               class="regular-text" />
                    </td>
                </tr>
            </table>
        </div>

        <div class="racc-settings-section">
            <h2><?php esc_html_e( 'Manage Visa Categories', 'racc-booking' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Daftar ini dipakai untuk dropdown Current Visa di form booking dan halaman reschedule. Isi satu kategori visa per baris.', 'racc-booking' ); ?>
            </p>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="visa_categories"><?php esc_html_e( 'Visa Categories', 'racc-booking' ); ?></label>
                    </th>
                    <td>
                        <textarea id="visa_categories"
                                  name="visa_categories"
                                  class="large-text code racc-visa-categories-field"
                                  rows="18"><?php echo esc_textarea( implode( "\n", $visa_categories ) ); ?></textarea>
                        <p class="description">
                            <?php esc_html_e( 'Baris kosong dan duplikat akan diabaikan saat disimpan.', 'racc-booking' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Shortcode Info -->
        <div class="racc-settings-section">
            <h2><?php esc_html_e( 'Shortcode', 'racc-booking' ); ?></h2>
            <p><?php esc_html_e( 'Use the following shortcode to display the booking form on any page:', 'racc-booking' ); ?></p>
            <code>[racc_booking_form]</code>
            <p class="description" style="margin-top: 10px;">
                <?php esc_html_e( 'Optional attribute: title — e.g., [racc_booking_form title="Book a Consultation"]', 'racc-booking' ); ?>
            </p>
        </div>

        <p class="submit">
            <input type="submit" name="racc_save_settings" class="button button-primary button-large"
                   value="<?php esc_attr_e( 'Save Settings', 'racc-booking' ); ?>" />
        </p>
    </form>
</div>
