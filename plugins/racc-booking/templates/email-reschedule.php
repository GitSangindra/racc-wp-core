<?php
/**
 * Email template: Booking Rescheduled.
 *
 * Variables available: $client_name, $agent_name, $agent_email, $service_type,
 *                      $old_date, $old_time_start, $old_time_end,
 *                      $new_date, $new_time_start, $new_time_end, $booking_id,
 *                      $show_delete_old_event_notice
 *
 * @package RACC_Booking
 */
if ( ! defined( 'ABSPATH' ) ) exit;
$site_name = get_bloginfo( 'name' );
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:40px 20px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">

    <!-- Header -->
    <tr>
        <td style="background:linear-gradient(135deg,#92400e,#f59e0b);padding:30px 40px;text-align:center;">
            <h1 style="color:#ffffff;margin:0;font-size:24px;">🔄 <?php esc_html_e( 'Booking Rescheduled', 'racc-booking' ); ?></h1>
            <p style="color:#fef3c7;margin:8px 0 0;font-size:14px;"><?php echo esc_html( $site_name ); ?></p>
        </td>
    </tr>

    <!-- Body -->
    <tr>
        <td style="padding:40px;">
            <p style="font-size:16px;color:#333;margin:0 0 20px;">
                <?php printf( esc_html__( 'Dear %s,', 'racc-booking' ), '<strong>' . esc_html( $client_name ) . '</strong>' ); ?>
            </p>
            <p style="font-size:15px;color:#555;line-height:1.6;margin:0 0 25px;">
                <?php esc_html_e( 'Your appointment has been rescheduled. Please see the updated details below:', 'racc-booking' ); ?>
            </p>

            <!-- Old Schedule (strikethrough) -->
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;margin:0 0 15px;">
                <tr>
                    <td style="padding:15px 20px;">
                        <p style="margin:0 0 5px;font-size:12px;color:#991b1b;font-weight:600;text-transform:uppercase;"><?php esc_html_e( 'Previous Schedule', 'racc-booking' ); ?></p>
                        <p style="margin:0;font-size:14px;color:#7f1d1d;text-decoration:line-through;">
                            📅 <?php echo esc_html( $old_date ); ?> &nbsp; 🕐 <?php echo esc_html( $old_time_start . ' — ' . $old_time_end ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <!-- New Schedule -->
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;margin:0 0 25px;">
                <tr>
                    <td style="padding:15px 20px;">
                        <p style="margin:0 0 5px;font-size:12px;color:#166534;font-weight:600;text-transform:uppercase;"><?php esc_html_e( 'New Schedule', 'racc-booking' ); ?></p>
                        <p style="margin:0;font-size:16px;color:#14532d;font-weight:700;">
                            📅 <?php echo esc_html( $new_date ); ?> &nbsp; 🕐 <?php echo esc_html( $new_time_start . ' — ' . $new_time_end ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php if ( ! empty( $show_delete_old_event_notice ) ) : ?>
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;margin:0 0 25px;">
                <tr>
                    <td style="padding:15px 20px;">
                        <p style="margin:0;font-size:13px;color:#92400e;line-height:1.6;">
                            ⚠️ <?php esc_html_e( 'If you have already saved the previous appointment in your personal calendar, please delete the old event to avoid duplicate reminders.', 'racc-booking' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php endif; ?>

            <!-- Booking Details -->
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin:0 0 25px;">
                <tr>
                    <td style="padding:20px;">
                        <table width="100%" cellpadding="5" cellspacing="0">
                            <tr>
                                <td style="color:#64748b;font-size:13px;padding:8px 0;border-bottom:1px solid #e2e8f0;width:140px;"><?php esc_html_e( 'Booking ID', 'racc-booking' ); ?></td>
                                <td style="color:#1e293b;font-size:14px;padding:8px 0;border-bottom:1px solid #e2e8f0;">#<?php echo esc_html( $booking_id ); ?></td>
                            </tr>
                            <tr>
                                <td style="color:#64748b;font-size:13px;padding:8px 0;border-bottom:1px solid #e2e8f0;"><?php esc_html_e( 'Consultant', 'racc-booking' ); ?></td>
                                <td style="color:#1e293b;font-size:14px;padding:8px 0;border-bottom:1px solid #e2e8f0;"><?php echo esc_html( $agent_name ); ?></td>
                            </tr>
                            <tr>
                                <td style="color:#64748b;font-size:13px;padding:8px 0;"><?php esc_html_e( 'Service', 'racc-booking' ); ?></td>
                                <td style="color:#1e293b;font-size:14px;padding:8px 0;"><?php echo esc_html( $service_type ); ?></td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            <p style="font-size:14px;color:#555;line-height:1.6;">
                <?php esc_html_e( 'If you have any concerns about this change, please contact us.', 'racc-booking' ); ?>
            </p>

            <?php if ( ! empty( $zoom_link ) ) : ?>
            <!-- Zoom Link -->
            <table width="100%" cellpadding="0" cellspacing="0" style="margin:25px 0 0;">
                <tr>
                    <td align="center">
                        <a href="<?php echo esc_url( $zoom_link ); ?>"
                           target="_blank"
                           rel="noopener noreferrer"
                           style="display:inline-block;background:#2D8CFF;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:12px 28px;border-radius:6px;box-shadow:0 4px 6px rgba(45,140,255,0.2);">
                            📹 <?php esc_html_e( 'Join Zoom Meeting', 'racc-booking' ); ?>
                        </a>
                        <p style="font-size:11px;color:#94a3b8;margin:8px 0 0;">
                            <?php esc_html_e( 'Please click this link at the scheduled time. You will wait in the waiting room until the host admits you.', 'racc-booking' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php endif; ?>

            <?php if ( ! empty( $gcal_url ) ) : ?>
            <!-- Add to Google Calendar -->
            <table width="100%" cellpadding="0" cellspacing="0" style="margin:25px 0 0;">
                <tr>
                    <td align="center" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:20px;">
                        <p style="margin:0 0 12px;font-size:13px;color:#166534;font-weight:600;">
                            📅 <?php esc_html_e( 'Update your calendar with the new schedule:', 'racc-booking' ); ?>
                        </p>
                        <a href="<?php echo esc_url( $gcal_url ); ?>"
                           target="_blank"
                           rel="noopener noreferrer"
                           style="display:inline-block;background:#1a73e8;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:12px 28px;border-radius:6px;">
                            📅 <?php esc_html_e( 'Add New Schedule to Google Calendar', 'racc-booking' ); ?>
                        </a>
                        <p style="font-size:11px;color:#94a3b8;margin:8px 0 0;">
                            <?php esc_html_e( 'Click to save the updated appointment to your Google Calendar.', 'racc-booking' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php endif; ?>
        </td>
    </tr>

    <!-- Footer -->
    <tr>
        <td style="background:#f8fafc;padding:20px 40px;text-align:center;border-top:1px solid #e2e8f0;">
            <p style="font-size:12px;color:#94a3b8;margin:0;">
                &copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php echo esc_html( $site_name ); ?>. <?php esc_html_e( 'All rights reserved.', 'racc-booking' ); ?>
            </p>
        </td>
    </tr>

</table>
</td></tr>
</table>
</body>
</html>
