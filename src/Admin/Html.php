<?php
/**
 * Small admin rendering helpers.
 *
 * @package Memberkin
 */

namespace Memberkin\Admin;

use Memberkin\Core\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Escaped HTML snippets shared by admin screens.
 */
final class Html {

	/**
	 * Status badge.
	 *
	 * @param string $status Status key (used as CSS modifier).
	 * @param string $label  Label.
	 */
	public static function badge( string $status, string $label ): string {
		return '<span class="memberkin-badge memberkin-badge--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Localised date (and time).
	 *
	 * @param \DateTimeImmutable|null $date      Date.
	 * @param bool                    $with_time Include time.
	 */
	public static function date( ?\DateTimeImmutable $date, bool $with_time = false ): string {
		if ( ! $date ) {
			return '—';
		}
		$format = get_option( 'date_format' ) . ( $with_time ? ' ' . get_option( 'time_format' ) : '' );
		return esc_html( wp_date( $format, $date->getTimestamp() ) );
	}

	/**
	 * Formatted money.
	 *
	 * @param int    $amount   Minor units.
	 * @param string $currency Currency.
	 */
	public static function money( int $amount, string $currency ): string {
		return esc_html( Money::of( $amount, $currency )->format() );
	}

	/**
	 * Link to a user (member detail screen).
	 *
	 * @param int $user_id User.
	 */
	public static function member_link( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			/* translators: %d: user id. */
			return esc_html( sprintf( __( 'Deleted user #%d', 'memberkin' ), $user_id ) );
		}
		$url = add_query_arg(
			array(
				'page'    => 'memberkin-members',
				'user_id' => $user_id,
			),
			admin_url( 'admin.php' )
		);
		return '<a href="' . esc_url( $url ) . '">' . esc_html( $user->display_name ) . '</a><br /><span class="memberkin-muted">' . esc_html( $user->user_email ) . '</span>';
	}

	/**
	 * Admin page URL.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Args.
	 */
	public static function url( string $page, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Small POST form with a single button (nonce-protected admin-post action).
	 *
	 * @param string $action  admin-post action.
	 * @param string $nonce   Nonce action.
	 * @param array  $fields  Hidden fields.
	 * @param string $label   Button label.
	 * @param string $css_class Button classes.
	 * @param string $confirm Confirmation message.
	 */
	public static function action_button( string $action, string $nonce, array $fields, string $label, string $css_class = 'button', string $confirm = '' ): string {
		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 6px 6px 0;">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		foreach ( $fields as $name => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
		$html .= wp_nonce_field( $nonce, '_wpnonce', true, false );
		$html .= '<button type="submit" class="' . esc_attr( $css_class ) . '"' . ( '' !== $confirm ? ' data-memberkin-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>' . esc_html( $label ) . '</button></form>';
		return $html;
	}

	/**
	 * Extra title actions (links next to a list screen's heading) contributed by add-ons, already escaped.
	 *
	 * @param string $screen  Screen id: members, orders or subscriptions.
	 * @param array  $filters Current list filters (sanitised).
	 */
	public static function screen_actions( string $screen, array $filters = array() ): string {
		/**
		 * Filters the title actions of a list screen. Each action: array( 'label' => string, 'url' => string ).
		 * Callbacks check their own capabilities.
		 *
		 * @param array  $actions Actions.
		 * @param string $screen  Screen id.
		 * @param array  $filters Current list filters.
		 */
		$actions = (array) apply_filters( 'memberkin_list_screen_actions', array(), $screen, $filters );
		$html    = '';
		foreach ( $actions as $action ) {
			if ( is_array( $action ) && ! empty( $action['url'] ) && ! empty( $action['label'] ) ) {
				$html .= ' <a class="page-title-action" href="' . esc_url( (string) $action['url'] ) . '">' . esc_html( (string) $action['label'] ) . '</a>';
			}
		}
		return $html;
	}
}
