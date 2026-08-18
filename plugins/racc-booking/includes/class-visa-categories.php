<?php
/**
 * Visa category helpers.
 *
 * @package RACC_Booking
 */

namespace RACC_Booking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Visa_Categories {

    /**
     * Default current visa options.
     *
     * @return array<int,string>
     */
    public static function get_default_options() {
        return [
            'Offshore',
            'Temporary Graduate Visa (TR-485)',
            'Student Visa (500)',
            'Covid Visa (408)',
            'Tourist Visa (600)',
            'Training Visa (407)',
            'Skills In Demand Visa (482)',
            'Temporary Skill Shortage (TSS) (482)',
            'Employer Nomination Scheme Visa (186)',
            'Regional Employer Sponsored (494)',
            'Protection Visa (subclass 866)',
            'Working Holiday Visa (WHV) 462',
            'Working Holiday Visa (WHV) 417',
            'Partner visa (Temporary) 820',
            'Partner visa (Permanent) 801',
            'Partner visa Overseas (Provisional) 309',
            'Partner visa Overseas (Migrant) 100',
            'Bridging Visa (A)',
            'Bridging Visa (B)',
            'Bridging Visa (C)',
            'Permanent Resident',
            'Australian Citizen',
            'Visitor (subclass 600)',
            'Skilled Independent Visa (subclass 189)',
            'Skilled Nominated Visa (subclass 190)',
            'Skilled Work Regional (Provisional) Visa (subclass 491)',
            'Adoption Visa (subclass 102)',
            'Aged Dependent Relative Visa (subclass 114)',
            'Aged Dependent Relative Visa (subclass 838)',
            'Aged Parent Visa (subclass 804)',
            'Bridging Visa D (subclass 040, 041)',
            'Bridging Visa E (subclass 050, 051)',
            'Bridging Visa F (subclass 060)',
            'Bridging Visa R (subclass 070)',
            'Business Innovation and Investment (Permanent) Visa (subclass 888)',
            'Business Innovation and Investment (Provisional) Visa (subclass 188)',
            'Business Owner Visa (subclass 890)',
            'Carer Visa (subclass 116)',
            'Carer Visa (subclass 836)',
            'Child Visa (subclass 101)',
            'Child Visa (subclass 802)',
            'Contributory Aged Parent (Temporary) Visa (subclass 884)',
            'Contributory Aged Parent Visa (subclass 864)',
            'Contributory Parent (Temporary) Visa (subclass 173)',
            'Contributory Parent Visa (subclass 143)',
            'Electronic Travel Authority (ETA) (subclass 601)',
            'eVisitor (subclass 651)',
            'Former Resident Visa (subclass 151)',
            'Global Special Humanitarian Visa (subclass 202)',
            'Global Talent Visa (subclass 858)',
            'In-country Special Humanitarian Visa (subclass 201)',
            'Investor Visa (subclass 891)',
            'Maritime Crew Visa (subclass 988)',
            'New Zealand Citizen Family Relationship (Temporary) Visa (subclass 461)',
            'Parent Visa (subclass 103)',
            'Prospective Marriage Visa (subclass 300)',
            'Refugee Visa (subclass 200)',
            'Remaining Relative Visa (subclass 115)',
            'Remaining Relative Visa (subclass 835)',
            'Resident Return Visa (subclass 155, 157)',
            'Resolution of Status Visa (subclass 851)',
            'Safe Haven Enterprise Visa (subclass 790)',
            'Sponsored Parent (Temporary) Visa (subclass 870)',
            'Student Guardian Visa (subclass 590)',
            'Temporary Protection Visa (subclass 785)',
            'Temporary Skill Shortage (TSS) Visa (subclass 482)',
            'Transit Visa (subclass 771)',
            'National Innovation Visa',
        ];
    }

    /**
     * Sanitize options from settings input.
     *
     * @param mixed $raw_options Raw options from POST or stored option.
     * @return array<int,string>
     */
    public static function sanitize_options( $raw_options ) {
        if ( is_string( $raw_options ) ) {
            $raw_options = preg_split( '/\r\n|\r|\n/', $raw_options );
        }

        if ( ! is_array( $raw_options ) ) {
            return [];
        }

        $options = [];
        foreach ( $raw_options as $option ) {
            $option = sanitize_text_field( wp_unslash( $option ) );

            if ( $option === '' || in_array( $option, $options, true ) ) {
                continue;
            }

            $options[] = $option;
        }

        return $options;
    }

    /**
     * Get stored visa options with default fallback.
     *
     * @return array<int,string>
     */
    public static function get_options() {
        $settings = get_option( 'racc_booking_settings', [] );
        $options  = self::sanitize_options( $settings['visa_categories'] ?? [] );

        if ( empty( $options ) ) {
            $options = self::get_default_options();
        }

        return apply_filters( 'racc_booking_visa_categories', $options );
    }
}
