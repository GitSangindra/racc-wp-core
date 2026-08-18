<?php
/**
 * Booking class — handles shortcode rendering and booking logic.
 *
 * @package RACC_Booking
 */

namespace RACC_Booking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Booking {

    public function __construct() {
        // Any booking-related hooks
    }

    /**
     * Render the booking form shortcode.
     *
     * Flow: Service → Consultant → Schedule (Date + Timezone + Availability + Review) → Details
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_booking_form( $atts = [] ) {
        $atts = shortcode_atts( [
            'title' => __( 'Book Your Appointment', 'racc-booking' ),
        ], $atts, 'racc_booking_form' );

        $current_visa_options = Visa_Categories::get_options();

        $australian_state_options = [
            'Australian Capital Territory',
            'New South Wales',
            'Northern Territory',
            'Queensland',
            'South Australia',
            'Tasmania',
            'Victoria',
            'Western Australia',
        ];

        ob_start();
        ?>
        <div id="racc-booking-app" class="racc-booking-wrapper">
            <div class="racc-booking-header">
                <h2><?php echo esc_html( $atts['title'] ); ?></h2>
                <p class="racc-booking-subtitle"><?php esc_html_e( 'Schedule a consultation with our expert team.', 'racc-booking' ); ?></p>
            </div>

            <!-- Progress Steps -->
            <div class="racc-booking-steps">
                <div class="racc-step active" data-step="1">
                    <span class="racc-step-number">1</span>
                    <span class="racc-step-label"><?php esc_html_e( 'Service', 'racc-booking' ); ?></span>
                </div>
                <div class="racc-step-line"></div>
                <div class="racc-step" data-step="3">
                    <span class="racc-step-number">2</span>
                    <span class="racc-step-label"><?php esc_html_e( 'Schedule', 'racc-booking' ); ?></span>
                </div>
                <div class="racc-step-line"></div>
                <div class="racc-step" data-step="4">
                    <span class="racc-step-number">3</span>
                    <span class="racc-step-label"><?php esc_html_e( 'Details', 'racc-booking' ); ?></span>
                </div>
            </div>

            <!-- Step 1: Select Service -->
            <div class="racc-booking-step-content" data-step="1" style="display:block;">
                <h3><i class="racc-icon">📋</i> <?php esc_html_e( 'Select a Service', 'racc-booking' ); ?></h3>
                <p class="racc-step-description"><?php esc_html_e( 'Choose the type of consultation you need.', 'racc-booking' ); ?></p>
                <div id="racc-services-list" class="racc-services-grid">
                    <div class="racc-loading">
                        <div class="racc-spinner"></div>
                        <p><?php esc_html_e( 'Loading services...', 'racc-booking' ); ?></p>
                    </div>
                </div>
            </div>

            <!-- Step 3: Schedule (Date + Timezone + Availability + Review — all on one page) -->
            <div class="racc-booking-step-content" data-step="3" style="display:none;">
                <h3><i class="racc-icon">📅</i> <?php esc_html_e( 'Schedule Your Appointment', 'racc-booking' ); ?></h3>
                <p class="racc-step-description"><?php esc_html_e( 'Pick a date and timezone, then choose from the available time slots.', 'racc-booking' ); ?></p>

                <!-- Google connectivity alert (shown early if calendar is not connected) -->
                <div id="racc-google-alert" class="racc-google-alert" style="display:none;"></div>

                <!-- Inline Calendar — always visible, no click required -->
                <div class="racc-date-picker-wrap">
                    <label for="racc-date-picker"><?php esc_html_e( 'Select Date:', 'racc-booking' ); ?></label>
                    <input type="text" id="racc-date-picker" placeholder="<?php esc_attr_e( 'Select a date...', 'racc-booking' ); ?>" readonly />
                </div>

                <!-- Timezone + Check Availability side-by-side below the calendar -->
                <div class="racc-schedule-row racc-schedule-controls">
                    <div class="racc-schedule-col">
                        <div class="racc-form-group">
                            <label for="racc-timezone-select"><?php esc_html_e( 'Timezone:', 'racc-booking' ); ?></label>
                            <select id="racc-timezone-select" class="racc-select">
                                <!-- Populated by JavaScript -->
                            </select>
                        </div>
                    </div>
                    <div class="racc-schedule-col racc-availability-col">
                        <div class="racc-availability-wrap">
                            <button type="button" id="racc-check-availability" class="racc-btn racc-btn-secondary" disabled>
                                <?php esc_html_e( 'Check Availability', 'racc-booking' ); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Time slots (shown after Check Availability clicked) -->
                <div id="racc-time-slots" class="racc-time-slots" style="display:none;">
                    <label><?php esc_html_e( 'Available Time Slots:', 'racc-booking' ); ?></label>
                    <div id="racc-slots-grid" class="racc-slots-grid"></div>
                </div>

                <!-- Review card (shown after slot selected) -->
                <div id="racc-review-card" class="racc-review-card" style="display:none;">
                    <h4><i class="racc-icon">✅</i> <?php esc_html_e( 'Booking Summary', 'racc-booking' ); ?></h4>
                    <div class="racc-review-item">
                        <span class="racc-review-label"><?php esc_html_e( 'Service:', 'racc-booking' ); ?></span>
                        <span id="racc-review-service" class="racc-review-value"></span>
                    </div>
                    <div class="racc-review-item">
                        <span class="racc-review-label"><?php esc_html_e( 'Date:', 'racc-booking' ); ?></span>
                        <span id="racc-review-date" class="racc-review-value"></span>
                    </div>
                    <div class="racc-review-item">
                        <span class="racc-review-label"><?php esc_html_e( 'Time:', 'racc-booking' ); ?></span>
                        <span id="racc-review-time" class="racc-review-value"></span>
                    </div>
                    <div class="racc-review-item">
                        <span class="racc-review-label"><?php esc_html_e( 'Timezone:', 'racc-booking' ); ?></span>
                        <span id="racc-review-timezone" class="racc-review-value"></span>
                    </div>
                </div>
            </div>

            <!-- Step 4: Client Details & Confirm -->
            <div class="racc-booking-step-content" data-step="4" style="display:none;">
                <h3><i class="racc-icon">✍️</i> <?php esc_html_e( 'Your Details', 'racc-booking' ); ?></h3>

                <div class="racc-form-fields">
                    <!-- Personal Information -->
                    <h4 class="racc-form-section-title"><?php esc_html_e( 'Personal Information', 'racc-booking' ); ?></h4>

                    <div class="racc-form-row">
                        <div class="racc-form-group">
                            <label for="racc-client-name"><?php esc_html_e( 'Full Name', 'racc-booking' ); ?> <span class="required">*</span></label>
                            <input type="text" id="racc-client-name" required placeholder="<?php esc_attr_e( 'John Doe', 'racc-booking' ); ?>" />
                        </div>
                        <div class="racc-form-group">
                            <label for="racc-client-email"><?php esc_html_e( 'Email Address', 'racc-booking' ); ?> <span class="required">*</span></label>
                            <input type="email" id="racc-client-email" required placeholder="<?php esc_attr_e( 'john@example.com', 'racc-booking' ); ?>" />
                        </div>
                    </div>

                    <div class="racc-form-row">
                        <div class="racc-form-group">
                            <label for="racc-client-phone"><?php esc_html_e( 'Phone Number', 'racc-booking' ); ?> <span class="required">*</span></label>
                            <input type="tel" id="racc-client-phone" required />
                        </div>
                        <div class="racc-form-group">
                            <label for="racc-client-nationality"><?php esc_html_e( 'Nationality', 'racc-booking' ); ?> <span class="required">*</span></label>
                            <select id="racc-client-nationality" class="racc-searchable-select" data-racc-searchable-select="1" data-search-placeholder="<?php esc_attr_e( 'Search nationality...', 'racc-booking' ); ?>" required>
                                <option value=""><?php esc_html_e( '— Select Nationality —', 'racc-booking' ); ?></option>
                                <?php
                                $countries = Country_Helper::get_country_list();
                                foreach ( $countries as $code => $name ) {
                                    echo '<option value="' . esc_attr( $name ) . '">' . esc_html( $name ) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="racc-form-row">
                        <div class="racc-form-group racc-form-full">
                            <label for="racc-client-dob"><?php esc_html_e( 'Date of Birth', 'racc-booking' ); ?> <span class="required">*</span></label>
                            <input type="date" id="racc-client-dob" required />
                        </div>
                    </div>

                    <!-- Education Background -->
                    <h4 class="racc-form-section-title"><?php esc_html_e( 'Education Background', 'racc-booking' ); ?></h4>

                    <div class="racc-form-row">
                        <div class="racc-form-group">
                            <label for="racc-client-university"><?php esc_html_e( 'University/School Name', 'racc-booking' ); ?> <span class="required">*</span></label>
                            <input type="text" id="racc-client-university" required placeholder="<?php esc_attr_e( 'University of Sydney', 'racc-booking' ); ?>" />
                        </div>
                        <div class="racc-form-group">
                            <label for="racc-client-course-level"><?php esc_html_e( 'Course Level', 'racc-booking' ); ?> <span class="required">*</span></label>
                            <select id="racc-client-course-level" required>
                                <option value=""><?php esc_html_e( 'Select...', 'racc-booking' ); ?></option>
                                <option value="High School"><?php esc_html_e( 'High School', 'racc-booking' ); ?></option>
                                <option value="Certificate"><?php esc_html_e( 'Certificate', 'racc-booking' ); ?></option>
                                <option value="Diploma"><?php esc_html_e( 'Diploma', 'racc-booking' ); ?></option>
                                <option value="Advanced Diploma"><?php esc_html_e( 'Advanced Diploma', 'racc-booking' ); ?></option>
                                <option value="Bachelor"><?php esc_html_e( 'Bachelor', 'racc-booking' ); ?></option>
                                <option value="Graduate Certificate"><?php esc_html_e( 'Graduate Certificate', 'racc-booking' ); ?></option>
                                <option value="Graduate Diploma"><?php esc_html_e( 'Graduate Diploma', 'racc-booking' ); ?></option>
                                <option value="Master"><?php esc_html_e( 'Master', 'racc-booking' ); ?></option>
                                <option value="Doctorate/PhD"><?php esc_html_e( 'Doctorate/PhD', 'racc-booking' ); ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="racc-form-row">
                        <div class="racc-form-group">
                            <label for="racc-client-course-major"><?php esc_html_e( 'Course Major', 'racc-booking' ); ?> <span class="required">*</span></label>
                            <input type="text" id="racc-client-course-major" required placeholder="<?php esc_attr_e( 'Business Administration', 'racc-booking' ); ?>" />
                        </div>
                        <div class="racc-form-group">
                            <label for="racc-client-course-completion"><?php esc_html_e( 'Course Completion Date', 'racc-booking' ); ?> <span class="required">*</span></label>
                            <input type="date" id="racc-client-course-completion" required />
                        </div>
                    </div>

                    <!-- Visa & Immigration -->
                    <h4 class="racc-form-section-title"><?php esc_html_e( 'Visa & Immigration', 'racc-booking' ); ?></h4>

                    <div class="racc-form-row">
                        <div class="racc-form-group">
                            <label for="racc-client-visa-type"><?php esc_html_e( 'What is your current visa?', 'racc-booking' ); ?> <span class="required">*</span></label>
                            <select id="racc-client-visa-type" class="racc-searchable-select" data-racc-searchable-select="1" data-search-placeholder="<?php esc_attr_e( 'Search visa...', 'racc-booking' ); ?>" required>
                                <option value=""><?php esc_html_e( 'Select...', 'racc-booking' ); ?></option>
                                <?php foreach ( $current_visa_options as $visa_option ) : ?>
                                    <option value="<?php echo esc_attr( $visa_option ); ?>"><?php echo esc_html( $visa_option ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="racc-client-country-group" class="racc-form-group">
                            <label for="racc-client-country"><?php esc_html_e( 'Where did you live?', 'racc-booking' ); ?> <span class="required">*</span></label>
                            <select id="racc-client-country" class="racc-searchable-select" data-racc-searchable-select="1" data-search-placeholder="<?php esc_attr_e( 'Search country...', 'racc-booking' ); ?>">
                                <option value=""><?php esc_html_e( '— Select Country —', 'racc-booking' ); ?></option>
                                <?php
                                $countries = Country_Helper::get_country_list();
                                foreach ( $countries as $code => $name ) {
                                    echo '<option value="' . esc_attr( $name ) . '">' . esc_html( $name ) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="racc-form-row">
                        <div id="racc-client-visa-expiry-group" class="racc-form-group">
                            <label for="racc-client-visa-expiry"><?php esc_html_e( 'Visa Expiry Date', 'racc-booking' ); ?> <span class="required">*</span></label>
                            <input type="date" id="racc-client-visa-expiry" required />
                            <small class="racc-field-note"><?php esc_html_e( "Leave as today's date if Offshore", 'racc-booking' ); ?></small>
                        </div>
                        <div id="racc-client-state-group" class="racc-form-group" style="display:none;">
                            <label for="racc-client-state"><?php esc_html_e( 'State', 'racc-booking' ); ?> <span class="required">*</span></label>
                            <select id="racc-client-state">
                                <option value=""><?php esc_html_e( 'Select...', 'racc-booking' ); ?></option>
                                <option value="Offshore" hidden><?php esc_html_e( 'Offshore', 'racc-booking' ); ?></option>
                                <?php foreach ( $australian_state_options as $state_option ) : ?>
                                    <option value="<?php echo esc_attr( $state_option ); ?>"><?php echo esc_html( $state_option ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Additional Information -->
                    <h4 class="racc-form-section-title"><?php esc_html_e( 'Additional Information', 'racc-booking' ); ?></h4>

                    <div class="racc-form-row">
                        <div class="racc-form-group">
                            <label for="racc-client-occupation"><?php esc_html_e( 'What is your current Occupation?', 'racc-booking' ); ?> <span class="required">*</span></label>
                            <input type="text" id="racc-client-occupation" required placeholder="<?php esc_attr_e( 'e.g. Student, Chef, Accountant', 'racc-booking' ); ?>" />
                        </div>
                        <div class="racc-form-group">
                            <label for="racc-client-contact-link"><?php esc_html_e( 'WhatsApp/Viber/Messenger Link', 'racc-booking' ); ?></label>
                            <input type="text" id="racc-client-contact-link" placeholder="<?php esc_attr_e( 'https://wa.me/61400000000', 'racc-booking' ); ?>" />
                            <small class="racc-field-note"><?php esc_html_e( 'If outside Australia, provide contact link', 'racc-booking' ); ?></small>
                        </div>
                    </div>

                    <div class="racc-form-row">
                        <div class="racc-form-group racc-form-full">
                            <label for="racc-client-referral"><?php esc_html_e( 'Where did you hear us from?', 'racc-booking' ); ?> <span class="required">*</span></label>
                            <select id="racc-client-referral" required>
                                <option value=""><?php esc_html_e( 'Select...', 'racc-booking' ); ?></option>
                                <?php
                                $referral_tags = function_exists( 'racc_get_referral_tags' ) ? racc_get_referral_tags() : [];
                                foreach ( $referral_tags as $tag_name ) {
                                    echo '<option value="' . esc_attr( $tag_name ) . '">' . esc_html( $tag_name ) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="racc-form-group">
                        <label for="racc-notes"><?php esc_html_e( 'What is your enquiry for this consultation?', 'racc-booking' ); ?> <span class="required">*</span></label>
                        <textarea id="racc-notes" rows="4" required placeholder="<?php esc_attr_e( 'Please describe what you would like to discuss...', 'racc-booking' ); ?>"></textarea>
                    </div>
                </div>

                <!-- Booking Summary -->
                <div id="racc-booking-summary" class="racc-booking-summary" style="display:none;">
                    <h4><?php esc_html_e( 'Booking Summary', 'racc-booking' ); ?></h4>
                    <div class="racc-summary-grid">
                        <div class="racc-summary-item">
                            <span class="racc-summary-label"><?php esc_html_e( 'Service:', 'racc-booking' ); ?></span>
                            <span id="racc-summary-service" class="racc-summary-value"></span>
                        </div>
                        <div class="racc-summary-item">
                            <span class="racc-summary-label"><?php esc_html_e( 'Date & Time:', 'racc-booking' ); ?></span>
                            <span id="racc-summary-datetime" class="racc-summary-value"></span>
                        </div>
                        <div class="racc-summary-item">
                            <span class="racc-summary-label"><?php esc_html_e( 'Timezone:', 'racc-booking' ); ?></span>
                            <span id="racc-summary-timezone" class="racc-summary-value"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Navigation Buttons -->
            <div class="racc-booking-nav">
                <button type="button" id="racc-prev-step" class="racc-btn racc-btn-outline" style="display:none;">
                    ← <?php esc_html_e( 'Back', 'racc-booking' ); ?>
                </button>
                <button type="button" id="racc-next-step" class="racc-btn racc-btn-primary" disabled>
                    <?php esc_html_e( 'Continue', 'racc-booking' ); ?> →
                </button>
                <button type="button" id="racc-submit-booking" class="racc-btn racc-btn-success" style="display:none;">
                    <?php esc_html_e( 'Confirm Booking', 'racc-booking' ); ?>
                </button>
            </div>

            <!-- Success/Error Messages -->
            <div id="racc-booking-message" class="racc-booking-message" style="display:none;"></div>

            <!-- Final Booking Summary -->
            <div id="racc-final-summary" class="racc-final-summary" style="display:none;">
                <div class="racc-final-summary-header">
                    <h3><?php esc_html_e( 'Booking Summary', 'racc-booking' ); ?></h3>
                    <p><?php esc_html_e( 'Please review the details of the booking you just created.', 'racc-booking' ); ?></p>
                </div>

                <div class="racc-final-summary-grid">
                    <div class="racc-final-summary-item">
                        <span class="racc-final-summary-label"><?php esc_html_e( 'Booking ID', 'racc-booking' ); ?></span>
                        <span id="racc-final-booking-id" class="racc-final-summary-value"></span>
                    </div>
                    <div class="racc-final-summary-item">
                        <span class="racc-final-summary-label"><?php esc_html_e( 'Status', 'racc-booking' ); ?></span>
                        <span id="racc-final-booking-status" class="racc-final-summary-value"></span>
                    </div>
                    <div class="racc-final-summary-item">
                        <span class="racc-final-summary-label"><?php esc_html_e( 'Service', 'racc-booking' ); ?></span>
                        <span id="racc-final-service" class="racc-final-summary-value"></span>
                    </div>
                    <div class="racc-final-summary-item">
                        <span class="racc-final-summary-label"><?php esc_html_e( 'Consultant', 'racc-booking' ); ?></span>
                        <span id="racc-final-agent" class="racc-final-summary-value"></span>
                    </div>
                    <div class="racc-final-summary-item">
                        <span class="racc-final-summary-label"><?php esc_html_e( 'Schedule', 'racc-booking' ); ?></span>
                        <span id="racc-final-schedule" class="racc-final-summary-value"></span>
                    </div>
                    <div class="racc-final-summary-item">
                        <span class="racc-final-summary-label"><?php esc_html_e( 'Timezone', 'racc-booking' ); ?></span>
                        <span id="racc-final-timezone" class="racc-final-summary-value"></span>
                    </div>
                    <div class="racc-final-summary-item">
                        <span class="racc-final-summary-label"><?php esc_html_e( 'Name', 'racc-booking' ); ?></span>
                        <span id="racc-final-client-name" class="racc-final-summary-value"></span>
                    </div>
                    <div class="racc-final-summary-item">
                        <span class="racc-final-summary-label"><?php esc_html_e( 'Email', 'racc-booking' ); ?></span>
                        <span id="racc-final-client-email" class="racc-final-summary-value"></span>
                    </div>
                    <div class="racc-final-summary-item">
                        <span class="racc-final-summary-label"><?php esc_html_e( 'Phone', 'racc-booking' ); ?></span>
                        <span id="racc-final-client-phone" class="racc-final-summary-value"></span>
                    </div>
                </div>

                <div id="racc-final-notes-wrap" class="racc-final-summary-notes" style="display:none;">
                    <span class="racc-final-summary-label"><?php esc_html_e( 'Enquiry', 'racc-booking' ); ?></span>
                    <p id="racc-final-notes" class="racc-final-summary-note-text"></p>
                </div>

                <div id="racc-final-actions" class="racc-final-summary-actions" style="display:none;">
                    <a id="racc-final-payment-link" class="racc-btn racc-btn-primary" href="#" style="display:none;">
                        <?php esc_html_e( 'Continue to Payment', 'racc-booking' ); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
