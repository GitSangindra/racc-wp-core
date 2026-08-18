<?php
/**
 * Admin view: Help & Manual Page
 *
 * @package RACC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap racc-admin-wrap" style="max-width: 900px; margin: 20px auto; background: #fff; padding: 30px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
    <h1 class="wp-heading-inline" style="margin-bottom: 20px;">
        <span class="dashicons dashicons-editor-help" style="font-size: 28px; width: 28px; height: 28px; color: #2271b1; vertical-align: bottom;"></span> 
        RACC Booking System - User Manual
    </h1>
    <hr style="border: 0; border-top: 1px solid #eee; margin-bottom: 20px;" />

    <p>The RACC Booking System is a custom solution for managing consultation bookings, integrating with consultants' Google Calendar availability, and automatically synchronizing with the AgentCIS CRM.</p>
    <p>This guide will help you set up the system from scratch and manage daily bookings.</p>

    <h2 style="margin-top: 30px; border-bottom: 2px solid #f0f0f1; padding-bottom: 10px;">1. Setup AgentCIS</h2>
    <p>To ensure incoming client data is automatically forwarded to the AgentCIS CRM, you must connect the AgentCIS API.</p>
    <ol style="list-style-type: decimal; margin-left: 20px;">
        <li style="margin-bottom: 6px;">In the WordPress Dashboard, go to <strong>Settings > RACC Settings</strong>.</li>
        <li style="margin-bottom: 6px;">Enter your AgentCIS <strong>API Key</strong> in the provided field.</li>
        <li style="margin-bottom: 6px;">Click <strong>Connect</strong> or <strong>Save Changes</strong>.</li>
        <li style="margin-bottom: 6px;">The system will pull the Tag list and other configurations from AgentCIS. Ensure the connection status is successful.</li>
    </ol>
    <div style="margin: 15px 0;">
        <img src="<?php echo esc_url( RACC_BOOKING_URL . 'assets/images/manual/setup-agentcis.png' ); ?>" style="max-width: 100%; height: auto; border: 1px solid #ccc; box-shadow: 0 1px 3px rgba(0,0,0,0.1);" />
    </div>

    <h2 style="margin-top: 30px; border-bottom: 2px solid #f0f0f1; padding-bottom: 10px;">2. Setup Consultant</h2>
    <p>Consultants are the core of this booking system. Each consultant has their own schedule, rates, specializations, and calendar integration.</p>
    <ol style="list-style-type: decimal; margin-left: 20px;">
        <li style="margin-bottom: 6px;">Go to the <strong>Users</strong> menu in WordPress. Ensure the consultant has a WordPress account with the <strong>Consultant</strong> role.</li>
        <li style="margin-bottom: 6px;">Edit the consultant's user profile.</li>
        <li style="margin-bottom: 6px;"><strong>Basic Data:</strong> Fill in their full name, description, and profile picture.</li>
        <li style="margin-bottom: 6px;"><strong>Google Calendar Sync:</strong>
            <ul style="list-style-type: disc; margin-left: 20px; margin-top: 5px;">
                <li>Find the <strong>Google Calendar Integration</strong> section.</li>
                <li>Click the <strong>Sign in with Google</strong> button.</li>
                <li>Follow the Google login authorization steps.</li>
                <li>Once successful, the status will change to <code>✅ Calendar connected</code>.</li>
            </ul>
        </li>
        <li style="margin-bottom: 6px;"><strong>AgentCIS Assignee:</strong>
            <ul style="list-style-type: disc; margin-left: 20px; margin-top: 5px;">
                <li>Find the <strong>AgentCIS Representative / Assignee</strong> dropdown.</li>
                <li>Select the AgentCIS account that corresponds to this consultant.</li>
            </ul>
        </li>
        <li style="margin-bottom: 6px;"><strong>Nation Coverage (Specialization):</strong> Check the destination countries handled by this consultant.</li>
        <li style="margin-bottom: 6px;"><strong>Zoom Link:</strong> Enter the consultant's static Zoom Personal Meeting Room link. This will automatically be included in confirmation emails if the client books an Online Consultation service.</li>
        <li style="margin-bottom: 6px;">Click <strong>Update User</strong> at the bottom to save.</li>
    </ol>
    <div style="margin: 15px 0;">
        <img src="<?php echo esc_url( RACC_BOOKING_URL . 'assets/images/manual/setup-consultant.png' ); ?>" style="max-width: 100%; height: auto; border: 1px solid #ccc; box-shadow: 0 1px 3px rgba(0,0,0,0.1);" />
    </div>

    <h2 style="margin-top: 30px; border-bottom: 2px solid #f0f0f1; padding-bottom: 10px;">3. Setup Product / Service</h2>
    <p>The RACC system uses WooCommerce products as the basis for bookable services.</p>
    <ol style="list-style-type: decimal; margin-left: 20px;">
        <li style="margin-bottom: 6px;">Go to <strong>Products > All Products</strong> in WordPress.</li>
        <li style="margin-bottom: 6px;">Add a new product or edit an existing one (e.g., "Citizenship Consultation").</li>
        <li style="margin-bottom: 6px;">In the <strong>Product Data</strong> section (select <em>Simple Product</em>):
            <ul style="list-style-type: disc; margin-left: 20px; margin-top: 5px;">
                <li><strong>General:</strong> Enter the service <em>Price</em>. If it's free, enter <code>0</code>.</li>
                <li><strong>RACC Booking (Custom Tab):</strong>
                    <ul style="list-style-type: circle; margin-left: 20px;">
                        <li><strong>Duration:</strong> Set the booking duration.</li>
                        <li><strong>Consultants:</strong> Select which consultants can handle this service.</li>
                        <li><strong>Online Consultation (Zoom):</strong> Check this option if the service requires an online meeting. The system will automatically inject the assigned consultant's Zoom link into the confirmation and reschedule emails.</li>
                    </ul>
                </li>
            </ul>
        </li>
        <li style="margin-bottom: 6px;">Make sure the product is Published.</li>
    </ol>
    <div style="margin: 15px 0;">
        <img src="<?php echo esc_url( RACC_BOOKING_URL . 'assets/images/manual/setup-product.png' ); ?>" style="max-width: 100%; height: auto; border: 1px solid #ccc; box-shadow: 0 1px 3px rgba(0,0,0,0.1);" />
    </div>

    <h2 style="margin-top: 30px; border-bottom: 2px solid #f0f0f1; padding-bottom: 10px;">4. Setup Referral Link</h2>
    <p>This feature automatically locks the "Where did you hear us from?" input based on URL parameters.</p>
    <ol style="list-style-type: decimal; margin-left: 20px;">
        <li style="margin-bottom: 6px;">Go to <strong>RACC Booking > Referral Mappings</strong>.</li>
        <li style="margin-bottom: 6px;">In the right column of the AgentCIS Tag, enter the desired <code>?ref</code> parameters (comma separated). E.g., <code>fb, facebook</code>.</li>
        <li style="margin-bottom: 6px;">Click <strong>Save Mapping</strong>.</li>
        <li style="margin-bottom: 6px;">When clients visit <code>https://racc.imajiku.net/booking/?ref=fb</code>, the form will automatically lock to "Facebook".</li>
    </ol>
    <div style="margin: 15px 0;">
        <img src="<?php echo esc_url( RACC_BOOKING_URL . 'assets/images/manual/setup-referral.png' ); ?>" style="max-width: 100%; height: auto; border: 1px solid #ccc; box-shadow: 0 1px 3px rgba(0,0,0,0.1);" />
    </div>

    <h2 style="margin-top: 30px; border-bottom: 2px solid #f0f0f1; padding-bottom: 10px;">5. Get Booking</h2>
    <ol style="list-style-type: decimal; margin-left: 20px;">
        <li style="margin-bottom: 6px;">Clients select a <strong>Service</strong> and <strong>Consultant</strong> on the booking form.</li>
        <li style="margin-bottom: 6px;">The system checks consultant availability directly from Google Calendar.</li>
        <li style="margin-bottom: 6px;">Clients fill in their personal details and choose a payment method.</li>
        <li style="margin-bottom: 6px;">Email notifications are immediately sent to both the Client and Consultant.</li>
    </ol>

    <h2 style="margin-top: 30px; border-bottom: 2px solid #f0f0f1; padding-bottom: 10px;">6. Manage Booking</h2>
    <p>All incoming bookings are managed through the <strong>RACC Booking</strong> menu. Click the <strong>Eye / Edit</strong> icon on a specific booking to enter the <strong>Reschedule Page</strong>.</p>
    <div style="margin: 15px 0;">
        <img src="<?php echo esc_url( RACC_BOOKING_URL . 'assets/images/manual/manage-booking-list.png' ); ?>" style="max-width: 100%; height: auto; border: 1px solid #ccc; box-shadow: 0 1px 3px rgba(0,0,0,0.1);" />
    </div>
    
    <h3 style="margin-top: 20px;">A. Edit / Reschedule</h3>
    <ul style="list-style-type: disc; margin-left: 20px;">
        <li>Open the <strong>Date & Time</strong> box.</li>
        <li>Uncheck the <em>"Keep current schedule"</em> checkbox.</li>
        <li>Select a new date and time, then click <strong>Save All Changes</strong>.</li>
    </ul>

    <h3 style="margin-top: 20px;">B. Re-assign Consultant</h3>
    <ul style="list-style-type: disc; margin-left: 20px;">
        <li>Open the <strong>Consultant</strong> box.</li>
        <li>Click on the new available consultant.</li>
        <li>The new consultant will receive a reassignment notification email.</li>
    </ul>

    <h3 style="margin-top: 20px;">C. AgentCIS Status</h3>
    <p>In the <strong>AgentCIS Sync</strong> panel on the right:</p>
    <ul style="list-style-type: disc; margin-left: 20px;">
        <li>✅ <strong>SYNCED</strong>: Data successfully sent.</li>
        <li>⏳ <strong>PENDING</strong>: Data is being processed.</li>
        <li>❌ <strong>FAILED</strong>: Data failed to send, usually accompanied by an error message.</li>
    </ul>

    <h3 style="margin-top: 20px;">D. Fixing Duplicate Email Error</h3>
    <p>If you see the <code>"email has already been taken"</code> message:</p>
    <ol style="list-style-type: decimal; margin-left: 20px;">
        <li style="margin-bottom: 6px;">Click the <strong>🔑 Input Client ID</strong> button below the error.</li>
        <li style="margin-bottom: 6px;">Enter the Contact ID from the AgentCIS URL (You can click the <span class="dashicons dashicons-editor-help" style="color: #2271b1; vertical-align: middle;"></span> icon to view the visual guide).</li>
        <li style="margin-bottom: 6px;">Click <strong>OK</strong> and the system will immediately save and sync the data.</li>
    </ol>
    <div style="margin: 15px 0;">
        <img src="<?php echo esc_url( RACC_BOOKING_URL . 'assets/images/manual/manage-booking-edit.png' ); ?>" style="max-width: 100%; height: auto; border: 1px solid #ccc; box-shadow: 0 1px 3px rgba(0,0,0,0.1);" />
    </div>

    <h2 style="margin-top: 30px; border-bottom: 2px solid #f0f0f1; padding-bottom: 10px;">7. Calendar View</h2>
    <p>Since all bookings automatically sync to each consultant's Google Calendar, the best way to monitor daily schedules is by <em>sharing calendars</em> among consultants via <strong>Google Workspace</strong>.</p>
    <p>Any schedule changes made from the RACC Booking Edit page will update the event timing in your Google Calendar in <em>real-time</em>.</p>

</div>
