<?php
/**
 * Email template: Internal Booking Cancellation Notice.
 *
 * Variables available: $client_name, $client_email, $client_phone, $agent_name,
 *                      $service_type, $booking_date, $time_start, $time_end, $booking_id
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

    <tr>
        <td style="background:linear-gradient(135deg,#7f1d1d,#dc2626);padding:30px 40px;text-align:center;">
            <h1 style="color:#ffffff;margin:0;font-size:24px;">🚫 <?php esc_html_e( 'Booking Cancelled', 'racc-booking' ); ?></h1>
            <p style="color:#fecaca;margin:8px 0 0;font-size:14px;"><?php echo esc_html( $site_name ); ?></p>
        </td>
    </tr>

    <tr>
        <td style="padding:40px;">
            <p style="font-size:16px;color:#333;margin:0 0 20px;">
                <?php printf( esc_html__( 'Hello %s,', 'racc-booking' ), '<strong>' . esc_html( $agent_name ) . '</strong>' ); ?>
            </p>
            <p style="font-size:15px;color:#555;line-height:1.6;margin:0 0 25px;">
                <?php esc_html_e( 'A booking has been cancelled. Please review the cancelled appointment details below.', 'racc-booking' ); ?>
            </p>

            <table width="100%" cellpadding="0" cellspacing="0" style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;margin:0 0 25px;">
                <tr>
                    <td style="padding:20px;">
                        <table width="100%" cellpadding="5" cellspacing="0">
                            <tr>
                                <td style="color:#991b1b;font-size:13px;padding:8px 0;border-bottom:1px solid #fecaca;width:140px;"><?php esc_html_e( 'Booking ID', 'racc-booking' ); ?></td>
                                <td style="color:#7f1d1d;font-size:14px;padding:8px 0;border-bottom:1px solid #fecaca;font-weight:600;">#<?php echo esc_html( $booking_id ); ?></td>
                            </tr>
                            <tr>
                                <td style="color:#991b1b;font-size:13px;padding:8px 0;border-bottom:1px solid #fecaca;"><?php esc_html_e( 'Client Name', 'racc-booking' ); ?></td>
                                <td style="color:#7f1d1d;font-size:14px;padding:8px 0;border-bottom:1px solid #fecaca;"><?php echo esc_html( $client_name ); ?></td>
                            </tr>
                            <tr>
                                <td style="color:#991b1b;font-size:13px;padding:8px 0;border-bottom:1px solid #fecaca;"><?php esc_html_e( 'Client Email', 'racc-booking' ); ?></td>
                                <td style="color:#7f1d1d;font-size:14px;padding:8px 0;border-bottom:1px solid #fecaca;"><?php echo esc_html( $client_email ); ?></td>
                            </tr>
                            <?php if ( ! empty( $client_phone ) ) : ?>
                            <tr>
                                <td style="color:#991b1b;font-size:13px;padding:8px 0;border-bottom:1px solid #fecaca;"><?php esc_html_e( 'Client Phone', 'racc-booking' ); ?></td>
                                <td style="color:#7f1d1d;font-size:14px;padding:8px 0;border-bottom:1px solid #fecaca;"><?php echo esc_html( $client_phone ); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td style="color:#991b1b;font-size:13px;padding:8px 0;border-bottom:1px solid #fecaca;"><?php esc_html_e( 'Service', 'racc-booking' ); ?></td>
                                <td style="color:#7f1d1d;font-size:14px;padding:8px 0;border-bottom:1px solid #fecaca;"><?php echo esc_html( $service_type ); ?></td>
                            </tr>
                            <tr>
                                <td style="color:#991b1b;font-size:13px;padding:8px 0;border-bottom:1px solid #fecaca;"><?php esc_html_e( 'Date', 'racc-booking' ); ?></td>
                                <td style="color:#7f1d1d;font-size:14px;padding:8px 0;border-bottom:1px solid #fecaca;text-decoration:line-through;"><?php echo esc_html( $booking_date ); ?></td>
                            </tr>
                            <tr>
                                <td style="color:#991b1b;font-size:13px;padding:8px 0;"><?php esc_html_e( 'Time', 'racc-booking' ); ?></td>
                                <td style="color:#7f1d1d;font-size:14px;padding:8px 0;text-decoration:line-through;"><?php echo esc_html( $time_start . ' — ' . $time_end ); ?></td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            <p style="font-size:14px;color:#555;line-height:1.6;">
                <?php esc_html_e( 'Please update any internal records or follow-up actions related to this appointment.', 'racc-booking' ); ?>
            </p>
        </td>
    </tr>

    <tr>
        <td style="background:#f8fafc;padding:20px 40px;text-align:center;border-top:1px solid #e2e8f0;">
            <p style="font-size:12px;color:#94a3b8;margin:0;">
                &copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php echo esc_html( $site_name ); ?>
            </p>
        </td>
    </tr>

</table>
</td></tr>
</table>
</body>
</html>