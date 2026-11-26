<?php
/**
 * HTML email layout.
 *
 * Override: yourtheme/memberkin/emails/layout.php
 *
 * @version 1.0.0
 * @package Memberkin
 *
 * @var string $subject   Subject.
 * @var string $content   Body HTML (already sanitised).
 * @var string $site_name Site name.
 * @var string $site_url  Site URL.
 * @var string $template  Template id.
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $subject ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1d2327;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f4f5;padding:24px 0;">
	<tr><td align="center">
		<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:6px;">
			<tr><td style="padding:24px 32px;border-bottom:1px solid #e5e7eb;font-size:18px;font-weight:600;">
				<a href="<?php echo esc_url( $site_url ); ?>" style="color:#1d2327;text-decoration:none;"><?php echo esc_html( $site_name ); ?></a>
			</td></tr>
			<tr><td style="padding:24px 32px;font-size:15px;line-height:1.6;">
				<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitised with wp_kses_post() by the renderer. ?>
			</td></tr>
			<tr><td style="padding:16px 32px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;">
				<?php
				/* translators: %s: site name. */
				echo esc_html( sprintf( __( 'You receive this email because you have an account at %s.', 'memberkin' ), $site_name ) );
				?>
			</td></tr>
		</table>
	</td></tr>
</table>
</body>
</html>
