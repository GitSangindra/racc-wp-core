<?php
/**
 * Admin view: Calendar iframe page.
 *
 * @package RACC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

?>
<div class="wrap racc-admin-wrap">
    <h1 class="racc-admin-title">
        <span class="dashicons dashicons-calendar"></span>
        <?php esc_html_e( 'RACC Booking — Calendar View', 'racc-booking' ); ?>
    </h1>

    <div class="racc-admin-card">
        <div id="racc-calendar-toolbar" class="racc-calendar-toolbar">
            <label for="racc-calendar-mode"><strong><?php esc_html_e( 'Mode', 'racc-booking' ); ?>:</strong></label>
            <select id="racc-calendar-mode" class="racc-calendar-mode-select">
                <option value="DB"><?php esc_html_e( 'Booking DB', 'racc-booking' ); ?></option>
                <option value="GOOGLE"><?php esc_html_e( 'Google Calendar', 'racc-booking' ); ?></option>
            </select>

            <div id="racc-google-view-wrap" class="racc-google-view-wrap" style="display:none;">
                <label for="racc-google-view"><strong><?php esc_html_e( 'Google View', 'racc-booking' ); ?>:</strong></label>
                <select id="racc-google-view" class="racc-google-view-select">
                    <option value="IFRAME"><?php esc_html_e( 'Iframe', 'racc-booking' ); ?></option>
                    <option value="CUSTOM"><?php esc_html_e( 'Custom', 'racc-booking' ); ?></option>
                </select>
            </div>

            <label><strong><?php esc_html_e( 'Consultant', 'racc-booking' ); ?>:</strong></label>
            <div id="racc-calendar-account-wrap" class="racc-multiselect-wrap">
                <button type="button" id="racc-multiselect-trigger" class="racc-multiselect-trigger button">
                    <span id="racc-multiselect-label"><?php esc_html_e( 'All Consultants', 'racc-booking' ); ?></span>
                    <span class="racc-multiselect-arrow">&#9660;</span>
                </button>
                <div id="racc-multiselect-dropdown" class="racc-multiselect-dropdown" style="display:none;">
                    <label class="racc-multiselect-item racc-multiselect-all">
                        <input type="checkbox" value="__all__" checked> <?php esc_html_e( 'All Consultants', 'racc-booking' ); ?>
                    </label>
                    <div id="racc-multiselect-options"></div>
                </div>
            </div>

            <button type="button" class="button" data-racc-nav="prev"><?php esc_html_e( '← Prev', 'racc-booking' ); ?></button>
            <button type="button" class="button" data-racc-nav="next"><?php esc_html_e( 'Next →', 'racc-booking' ); ?></button>
            <span id="racc-calendar-range" class="racc-calendar-range"></span>

            <button type="button" class="button" data-racc-view="DAY"><?php esc_html_e( 'Day', 'racc-booking' ); ?></button>
            <button type="button" class="button button-primary" data-racc-view="WEEK"><?php esc_html_e( 'Week', 'racc-booking' ); ?></button>
        </div>

        <div id="racc-calendar-message" class="racc-calendar-message" style="display:none;">
            <?php esc_html_e( 'No connected consultant calendar found. Connect Google Calendar on the Consultants page first.', 'racc-booking' ); ?>
        </div>

        <div id="racc-calendar-legend" class="racc-calendar-legend" style="display:none;"></div>

        <div id="racc-calendar-db" class="racc-calendar-db-wrap" style="display:none;"></div>

        <div id="racc-calendar-google-wrap" class="racc-calendar-frame-wrap">
            <iframe
                id="racc-calendar-iframe"
                src=""
                class="racc-calendar-iframe"
                frameborder="0"
                scrolling="no"
                title="RACC Booking Calendar"
            ></iframe>
        </div>

        <p id="racc-google-note" class="description">
            <?php esc_html_e( 'Note: Day mode uses Google Calendar agenda embed behavior and may differ from native Google Calendar layout.', 'racc-booking' ); ?>
        </p>

        <p id="racc-calendar-action-note" class="description" style="display:none;"></p>
    </div>
</div>

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
