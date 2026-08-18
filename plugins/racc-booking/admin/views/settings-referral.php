<?php
/**
 * Admin view: Referral Mapping Advanced Settings.
 *
 * @package RACC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$advanced_mapping = get_option( 'racc_referral_mapping_advanced', [] );
if ( ! is_array( $advanced_mapping ) ) {
    $advanced_mapping = [];
}

$referral_tags = function_exists( 'racc_get_referral_tags' ) ? racc_get_referral_tags() : [];
?>
<div class="wrap racc-admin-wrap">
    <h1 class="racc-admin-title">
        <span class="dashicons dashicons-admin-links"></span>
        <?php esc_html_e( 'Referral Links Mapping (?ref)', 'racc-booking' ); ?>
    </h1>

    <?php if ( isset( $_GET['settings-updated'] ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Settings saved.', 'racc-booking' ); ?></p>
        </div>
    <?php endif; ?>

    <div class="racc-admin-card" style="max-width:800px; margin-top:20px;">
        <h2 style="margin-top:0;"><?php esc_html_e( 'Mapping Configuration', 'racc-booking' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Enter one or more URL parameters (separated by comma) that will automatically select the corresponding Tag in the frontend form.', 'racc-booking' ); ?><br>
            <?php esc_html_e( 'Example: For Facebook, you might enter:', 'racc-booking' ); ?> <code>fb, facebook, fbook</code>.<br>
            <?php esc_html_e( 'Then when a user visits /booking/?ref=fb, Facebook will be auto-selected and locked.', 'racc-booking' ); ?>
        </p>

        <form method="post" action="options.php" style="margin-top:20px;">
            <?php settings_fields( 'racc_referral_settings' ); ?>
            
            <table class="form-table">
                <thead>
                    <tr>
                        <th scope="col" style="padding-bottom:10px; font-weight:600; border-bottom: 1px solid #ccd0d4;"><?php esc_html_e( 'AgentCIS Tag / Dropdown Option', 'racc-booking' ); ?></th>
                        <th scope="col" style="padding-bottom:10px; font-weight:600; border-bottom: 1px solid #ccd0d4;"><?php esc_html_e( 'Mapped ?ref Parameters (Comma-separated)', 'racc-booking' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $referral_tags ) ) : ?>
                        <tr>
                            <td colspan="2">
                                <p style="color: #d63638;"><?php esc_html_e( 'Error: No tags found in agentcis-tags.json.', 'racc-booking' ); ?></p>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $referral_tags as $tag_name ) : 
                            $current_value = isset( $advanced_mapping[ $tag_name ] ) ? $advanced_mapping[ $tag_name ] : '';
                        ?>
                            <tr>
                                <th scope="row" style="vertical-align: middle;">
                                    <?php echo esc_html( $tag_name ); ?>
                                </th>
                                <td>
                                    <input type="text"
                                           name="racc_referral_mapping_advanced[<?php echo esc_attr( $tag_name ); ?>]"
                                           value="<?php echo esc_attr( $current_value ); ?>"
                                           class="regular-text ltr"
                                           style="width: 100%; max-width: 400px;"
                                           placeholder="<?php esc_attr_e( 'e.g. tag1, tag2', 'racc-booking' ); ?>" />
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div style="margin-top:25px;">
                <?php submit_button( __( 'Save Mapping', 'racc-booking' ), 'primary', 'submit', false ); ?>
            </div>
        </form>
    </div>
</div>
