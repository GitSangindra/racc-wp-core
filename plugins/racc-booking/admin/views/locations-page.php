<?php
/**
 * Admin view: Master Lokasi.
 *
 * @package RACC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$message     = sanitize_text_field( $_GET['message'] ?? '' );
$error       = sanitize_text_field( $_GET['error'] ?? '' );
$action      = sanitize_text_field( $_GET['location_action'] ?? '' );
$location_id = absint( $_GET['location_id'] ?? 0 );

$editing_location = null;
if ( 'edit' === $action && $location_id > 0 ) {
    $editing_location = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}racc_locations WHERE id = %d",
        $location_id
    ) );
}

$locations = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}racc_locations ORDER BY name ASC" );
?>
<div class="wrap racc-admin-wrap">
    <h1 class="racc-admin-title">
        <span class="dashicons dashicons-location"></span>
        <?php esc_html_e( 'RACC Booking — Master Lokasi', 'racc-booking' ); ?>
    </h1>

    <?php if ( 'location_added' === $message ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Lokasi berhasil ditambahkan.', 'racc-booking' ); ?></p></div>
    <?php elseif ( 'location_updated' === $message ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Lokasi berhasil diperbarui.', 'racc-booking' ); ?></p></div>
    <?php elseif ( 'location_deleted' === $message ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Lokasi berhasil dihapus.', 'racc-booking' ); ?></p></div>
    <?php endif; ?>

    <?php if ( 'location_required_fields' === $error ) : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Nama lokasi, negara/wilayah, dan kota wajib diisi.', 'racc-booking' ); ?></p></div>
    <?php endif; ?>

    <div class="racc-admin-layout">
        <div class="racc-admin-card racc-agent-form-card">
            <h2>
                <?php echo $editing_location
                    ? esc_html__( 'Edit Lokasi', 'racc-booking' )
                    : esc_html__( 'Tambah Lokasi Baru', 'racc-booking' ); ?>
            </h2>

            <form method="post" action="">
                <?php wp_nonce_field( 'racc_save_location' ); ?>
                <input type="hidden" name="location_id" value="<?php echo esc_attr( $editing_location->id ?? 0 ); ?>" />

                <table class="form-table">
                    <tr>
                        <th><label for="location_name"><?php esc_html_e( 'Nama lokasi', 'racc-booking' ); ?> <span class="required">*</span></label></th>
                        <td><input type="text" id="location_name" name="location_name" class="regular-text" required value="<?php echo esc_attr( $editing_location->name ?? '' ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="country_region"><?php esc_html_e( 'Negara/Wilayah', 'racc-booking' ); ?> <span class="required">*</span></label></th>
                        <td>
                            <select id="country_region" name="country_region" class="regular-text"
                                    data-racc-searchable-select="1"
                                    data-search-placeholder="<?php esc_attr_e( 'Cari negara...', 'racc-booking' ); ?>"
                                    required>
                                <option value=""><?php esc_html_e( '— Pilih Negara/Wilayah —', 'racc-booking' ); ?></option>
                                <?php foreach ( \RACC_Booking\Country_Helper::get_country_list() as $code => $name ) : ?>
                                    <option value="<?php echo esc_attr( $name ); ?>" <?php selected( $editing_location->country_region ?? '', $name ); ?>>
                                        <?php echo esc_html( $name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="city"><?php esc_html_e( 'Kota', 'racc-booking' ); ?> <span class="required">*</span></label></th>
                        <td><input type="text" id="city" name="city" class="regular-text" required value="<?php echo esc_attr( $editing_location->city ?? '' ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="postal_code"><?php esc_html_e( 'Kode Pos', 'racc-booking' ); ?></label></th>
                        <td><input type="text" id="postal_code" name="postal_code" class="regular-text" value="<?php echo esc_attr( $editing_location->postal_code ?? '' ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="street_name"><?php esc_html_e( 'Nama Jalan', 'racc-booking' ); ?></label></th>
                        <td><input type="text" id="street_name" name="street_name" class="regular-text" value="<?php echo esc_attr( $editing_location->street_name ?? '' ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="house_number"><?php esc_html_e( 'Nomor Rumah', 'racc-booking' ); ?></label></th>
                        <td><input type="text" id="house_number" name="house_number" class="regular-text" value="<?php echo esc_attr( $editing_location->house_number ?? '' ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="apartment_suite"><?php esc_html_e( 'Apartemen, suite, dll.', 'racc-booking' ); ?></label></th>
                        <td><input type="text" id="apartment_suite" name="apartment_suite" class="regular-text" value="<?php echo esc_attr( $editing_location->apartment_suite ?? '' ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="address_description"><?php esc_html_e( 'Deskripsi Alamat (opsional)', 'racc-booking' ); ?></label></th>
                        <td>
                            <textarea id="address_description" name="address_description" rows="3" class="large-text"><?php echo esc_textarea( $editing_location->address_description ?? '' ); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Info Kontak', 'racc-booking' ); ?></th>
                        <td>
                            <label style="display:block;margin-bottom:10px;">
                                <input type="checkbox" name="use_default_contact" value="1" <?php checked( ! empty( $editing_location->use_default_contact ) ); ?> />
                                <?php esc_html_e( 'Gunakan info kontak yang sama seperti lokasi default Anda', 'racc-booking' ); ?>
                            </label>
                            <input type="text" name="location_contact_name" class="regular-text" placeholder="<?php esc_attr_e( 'Nama kontak lokasi', 'racc-booking' ); ?>" value="<?php echo esc_attr( $editing_location->location_contact_name ?? '' ); ?>" />
                            <br><br>
                            <input type="text" name="location_contact_phone" class="regular-text" placeholder="<?php esc_attr_e( 'Telepon kontak lokasi', 'racc-booking' ); ?>" value="<?php echo esc_attr( $editing_location->location_contact_phone ?? '' ); ?>" />
                            <br><br>
                            <input type="email" name="location_contact_email" class="regular-text" placeholder="<?php esc_attr_e( 'Email kontak lokasi', 'racc-booking' ); ?>" value="<?php echo esc_attr( $editing_location->location_contact_email ?? '' ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="location_status"><?php esc_html_e( 'Status', 'racc-booking' ); ?></label></th>
                        <td>
                            <select id="location_status" name="location_status">
                                <option value="active" <?php selected( $editing_location->status ?? 'active', 'active' ); ?>><?php esc_html_e( 'Active', 'racc-booking' ); ?></option>
                                <option value="inactive" <?php selected( $editing_location->status ?? '', 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'racc-booking' ); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="racc_save_location" class="button button-primary"
                           value="<?php echo $editing_location ? esc_attr__( 'Update Lokasi', 'racc-booking' ) : esc_attr__( 'Tambah Lokasi', 'racc-booking' ); ?>" />
                    <?php if ( $editing_location ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=racc-booking-locations' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'racc-booking' ); ?></a>
                    <?php endif; ?>
                </p>
            </form>
        </div>

        <div class="racc-admin-card racc-agents-list-card">
            <h2><?php esc_html_e( 'Daftar Master Lokasi', 'racc-booking' ); ?></h2>

            <?php if ( empty( $locations ) ) : ?>
                <p class="racc-empty-state"><?php esc_html_e( 'Belum ada lokasi. Tambahkan lokasi pertama Anda dari form di samping.', 'racc-booking' ); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Nama lokasi', 'racc-booking' ); ?></th>
                            <th><?php esc_html_e( 'Alamat', 'racc-booking' ); ?></th>
                            <th><?php esc_html_e( 'Kontak', 'racc-booking' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'racc-booking' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'racc-booking' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $locations as $location ) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $location->name ); ?></strong>
                                    <?php if ( ! empty( $location->address_description ) ) : ?>
                                        <br><small><?php echo esc_html( $location->address_description ); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $address_parts = array_filter( [
                                        $location->house_number,
                                        $location->street_name,
                                        $location->apartment_suite,
                                        $location->city,
                                        $location->postal_code,
                                        $location->country_region,
                                    ] );
                                    echo esc_html( implode( ', ', $address_parts ) ?: '-' );
                                    ?>
                                </td>
                                <td>
                                    <?php if ( ! empty( $location->use_default_contact ) ) : ?>
                                        <span class="racc-tag"><?php esc_html_e( 'Use Default Contact', 'racc-booking' ); ?></span>
                                    <?php else : ?>
                                        <?php echo esc_html( $location->location_contact_name ?: '-' ); ?><br>
                                        <small><?php echo esc_html( $location->location_contact_phone ?: '' ); ?></small><br>
                                        <small><?php echo esc_html( $location->location_contact_email ?: '' ); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="racc-status racc-status-<?php echo esc_attr( $location->status ); ?>">
                                        <?php echo 'active' === $location->status ? esc_html__( 'Active', 'racc-booking' ) : esc_html__( 'Inactive', 'racc-booking' ); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=racc-booking-locations&location_action=edit&location_id=' . absint( $location->id ) ) ); ?>" class="button button-small">
                                        <?php esc_html_e( 'Edit', 'racc-booking' ); ?>
                                    </a>
                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=racc-booking-locations&action=delete_location&location_id=' . absint( $location->id ) ), 'racc_delete_location' ) ); ?>"
                                       class="button button-small button-link-delete"
                                       onclick="return confirm('<?php esc_attr_e( 'Delete this location?', 'racc-booking' ); ?>');">
                                        <?php esc_html_e( 'Delete', 'racc-booking' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
