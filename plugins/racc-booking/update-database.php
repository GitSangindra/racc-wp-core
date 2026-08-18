<?php
/**
 * Database Update Script
 * 
 * This script adds new customer detail columns to the racc_bookings table.
 * Run this once after updating the plugin to add the new fields.
 * 
 * Usage: Access via WordPress admin at:
 * /wp-content/plugins/racc-booking/update-database.php
 * 
 * @package RACC_Booking
 */

// Security: Only run if accessed via WordPress
define('WP_USE_THEMES', false);
require_once('../../../wp-load.php');

// Check if user is admin
if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to access this page.');
}

global $wpdb;
$table = $wpdb->prefix . 'racc_bookings';

// Check if columns already exist
$columns = $wpdb->get_results("DESCRIBE {$table}");
$existing_columns = array();
foreach ($columns as $column) {
    $existing_columns[] = $column->Field;
}

$new_columns = array(
    'client_nationality'       => "VARCHAR(100) DEFAULT '' AFTER client_phone",
    'client_dob'               => "DATE DEFAULT NULL AFTER client_nationality",
    'client_university'        => "VARCHAR(255) DEFAULT '' AFTER client_dob",
    'client_course_level'      => "VARCHAR(100) DEFAULT '' AFTER client_university",
    'client_course_major'      => "VARCHAR(255) DEFAULT '' AFTER client_course_level",
    'client_course_completion' => "DATE DEFAULT NULL AFTER client_course_major",
    'client_visa_type'         => "VARCHAR(100) DEFAULT '' AFTER client_course_completion",
    'client_visa_expiry'       => "DATE DEFAULT NULL AFTER client_visa_type",
    'client_country'           => "VARCHAR(100) DEFAULT '' AFTER client_visa_expiry",
    'client_occupation'        => "VARCHAR(255) DEFAULT '' AFTER client_country",
    'client_contact_link'      => "VARCHAR(255) DEFAULT '' AFTER client_occupation",
    'client_referral_source'   => "VARCHAR(255) DEFAULT '' AFTER client_contact_link",
    // AgentCIS integration columns
    'agentcis_contact_id'      => "VARCHAR(255) DEFAULT '' AFTER status COMMENT 'AgentCIS Contact ID'",
    'agentcis_sync_status'     => "VARCHAR(20) DEFAULT 'pending' AFTER agentcis_contact_id COMMENT 'pending|synced|failed'",
    'agentcis_sync_error'      => "TEXT DEFAULT NULL AFTER agentcis_sync_status COMMENT 'Last sync error message'",
);

echo '<h1>RACC Booking - Database Update</h1>';
echo '<div style="font-family: monospace; background: #f5f5f5; padding: 20px; border-radius: 5px;">';

$updated = 0;
$skipped = 0;

foreach ($new_columns as $column_name => $column_definition) {
    if (!in_array($column_name, $existing_columns)) {
        $sql = "ALTER TABLE {$table} ADD COLUMN {$column_name} {$column_definition}";
        $result = $wpdb->query($sql);
        
        if ($result !== false) {
            echo "✅ Added column: <strong>{$column_name}</strong><br>";
            $updated++;
        } else {
            echo "❌ Failed to add column: <strong>{$column_name}</strong><br>";
            echo "Error: " . $wpdb->last_error . "<br>";
        }
    } else {
        echo "⏭️  Column already exists: <strong>{$column_name}</strong><br>";
        $skipped++;
    }
}

echo '<br><hr><br>';
echo "<strong>Summary:</strong><br>";
echo "✅ Columns added: {$updated}<br>";
echo "⏭️  Columns skipped (already exist): {$skipped}<br>";
echo "<br>";
echo "Database update completed!<br>";
echo '<br><a href="' . admin_url('admin.php?page=racc-booking') . '">← Back to RACC Booking</a>';
echo '</div>';
