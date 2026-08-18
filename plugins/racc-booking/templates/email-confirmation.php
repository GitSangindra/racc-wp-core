<?php
/**
 * Email template: Booking Confirmation (Client).
 *
 * Variables available: $client_name, $agent_name, $agent_email, $service_type,
 *                      $booking_date, $time_start, $time_end, $notes, $booking_id
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
        <td style="background:linear-gradient(135deg,#1e3a5f,#2d6da8);padding:30px 40px;text-align:center;">
            <h1 style="color:#ffffff;margin:0;font-size:24px;">✅ <?php esc_html_e( 'Booking Confirmed', 'racc-booking' ); ?></h1>
            <p style="color:#a8d4f7;margin:8px 0 0;font-size:14px;"><?php echo esc_html( $site_name ); ?></p>
        </td>
    </tr>

    <!-- Body -->
    <tr>
        <td style="padding:40px;">
            <p style="font-size:16px;color:#333;margin:0 0 20px;">
                <?php printf( esc_html__( 'Dear %s,', 'racc-booking' ), '<strong>' . esc_html( $client_name ) . '</strong>' ); ?>
            </p>
            <p style="font-size:15px;color:#555;line-height:1.6;margin:0 0 25px;">
                <?php esc_html_e( 'Your appointment has been successfully booked. Here are the details:', 'racc-booking' ); ?>
            </p>

            <!-- Details Card -->
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin:0 0 25px;">
                <tr>
                    <td style="padding:20px;">
                        <table width="100%" cellpadding="5" cellspacing="0">
                            <tr>
                                <td style="color:#64748b;font-size:13px;padding:8px 0;border-bottom:1px solid #e2e8f0;width:140px;"><?php esc_html_e( 'Booking ID', 'racc-booking' ); ?></td>
                                <td style="color:#1e293b;font-size:14px;padding:8px 0;border-bottom:1px solid #e2e8f0;font-weight:600;">#<?php echo esc_html( $booking_id ); ?></td>
                            </tr>
                            <tr>
                                <td style="color:#64748b;font-size:13px;padding:8px 0;border-bottom:1px solid #e2e8f0;"><?php esc_html_e( 'Consultant', 'racc-booking' ); ?></td>
                                <td style="color:#1e293b;font-size:14px;padding:8px 0;border-bottom:1px solid #e2e8f0;"><?php echo esc_html( $agent_name ); ?></td>
                            </tr>
                            <tr>
                                <td style="color:#64748b;font-size:13px;padding:8px 0;border-bottom:1px solid #e2e8f0;"><?php esc_html_e( 'Service', 'racc-booking' ); ?></td>
                                <td style="color:#1e293b;font-size:14px;padding:8px 0;border-bottom:1px solid #e2e8f0;"><?php echo esc_html( $service_type ); ?></td>
                            </tr>
                            <tr>
                                <td style="color:#64748b;font-size:13px;padding:8px 0;border-bottom:1px solid #e2e8f0;"><?php esc_html_e( 'Date', 'racc-booking' ); ?></td>
                                <td style="color:#1e293b;font-size:14px;padding:8px 0;border-bottom:1px solid #e2e8f0;font-weight:600;">📅 <?php echo esc_html( $booking_date ); ?></td>
                            </tr>
                            <tr>
                                <td style="color:#64748b;font-size:13px;padding:8px 0;"><?php esc_html_e( 'Time', 'racc-booking' ); ?></td>
                                <td style="color:#1e293b;font-size:14px;padding:8px 0;font-weight:600;">🕐 <?php echo esc_html( $time_start . ' — ' . $time_end ); ?></td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            <?php if ( ! empty( $notes ) ) : ?>
                <p style="font-size:13px;color:#64748b;margin:0 0 5px;"><?php esc_html_e( 'Your Notes:', 'racc-booking' ); ?></p>
                <p style="font-size:14px;color:#333;background:#f1f5f9;padding:12px;border-radius:6px;margin:0 0 25px;"><?php echo esc_html( $notes ); ?></p>
            <?php endif; ?>

            <p style="font-size:14px;color:#555;line-height:1.6;">
                <?php esc_html_e( 'If you need to make any changes to your appointment, please contact us.', 'racc-booking' ); ?>
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
                    <td align="center">
                        <a href="<?php echo esc_url( $gcal_url ); ?>"
                           target="_blank"
                           rel="noopener noreferrer"
                           style="display:inline-block;background:#1a73e8;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:12px 28px;border-radius:6px;">
                            📅 <?php esc_html_e( 'Add to Google Calendar', 'racc-booking' ); ?>
                        </a>
                        <p style="font-size:11px;color:#94a3b8;margin:8px 0 0;">
                            <?php esc_html_e( 'Click to save this appointment to your Google Calendar.', 'racc-booking' ); ?>
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
