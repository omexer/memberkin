<?php
/**
 * Coupons admin screen.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Coupons;

use Memberkin\Admin\Flash;
use Memberkin\Core\Capabilities;
use Memberkin\Core\Currency;
use Memberkin\Core\Money;
use Memberkin\Core\Plugin;
use Memberkin\Core\ValidationException;
use Memberkin\Domain\Coupon;
use Memberkin\Domain\Period;
use Memberkin\Repository\CouponRepository;
use Memberkin\Repository\LevelGroupRepository;
use Memberkin\Repository\LevelRepository;
use Memberkin\Service\CouponService;

defined( 'ABSPATH' ) || exit;

/**
 * List and edit coupons.
 */
final class CouponsScreen {

	private const PAGE = 'memberkin-coupons';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_memberkin_save_coupon', array( $this, 'handle_save' ) );
		add_action( 'admin_post_memberkin_delete_coupon', array( $this, 'handle_delete' ) );
	}

	/**
	 * URL helper.
	 *
	 * @param array $args Args.
	 */
	private function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Capability guard.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_COUPONS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage coupons.', 'memberkin' ), 403 );
		}
	}

	/**
	 * Router.
	 */
	public function render(): void {
		$this->authorize();
		echo '<div class="wrap">';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['view'] ) && 'edit' === $_GET['view'] ) {
			$this->render_edit( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} else {
			$this->render_list();
		}
		echo '</div>';
	}

	/**
	 * Coupon list.
	 */
	private function render_list(): void {
		$table = new CouponsListTable();
		$table->prepare_items();
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Coupons', 'memberkin' ); ?></h1>
		<a href="<?php echo esc_url( $this->url( array( 'view' => 'edit' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Coupon', 'memberkin' ); ?></a>
		<hr class="wp-header-end" />
		<?php Flash::render(); ?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
			<?php
			$table->search_box( __( 'Search codes', 'memberkin' ), 'memberkin-coupons' );
			$table->display();
			?>
		</form>
		<?php
	}

	/**
	 * Decimal display of a stored amount.
	 *
	 * @param string   $type   Discount type.
	 * @param int|null $amount Stored amount.
	 */
	private function amount_input( string $type, ?int $amount ): string {
		if ( null === $amount ) {
			return '';
		}
		if ( Coupon::PERCENT === $type ) {
			return rtrim( rtrim( Money::of( $amount, 'USD' )->to_decimal(), '0' ), '.' );
		}
		return Money::of( $amount, Currency::default() )->to_decimal();
	}

	/**
	 * Local date-time for an input value.
	 *
	 * @param \DateTimeImmutable|null $date Date.
	 */
	private function local( ?\DateTimeImmutable $date ): string {
		return $date ? wp_date( 'Y-m-d\TH:i', $date->getTimestamp() ) : '';
	}

	/**
	 * Edit form.
	 *
	 * @param int $id Coupon id.
	 */
	private function render_edit( int $id ): void {
		$coupon = $id ? $this->plugin->get( CouponRepository::class )->find( $id ) : null;
		if ( $id && ! $coupon ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Coupon not found.', 'memberkin' ) . '</p></div>';
			return;
		}
		$old    = Flash::old_input() ?? array();
		$v      = static fn( string $key, mixed $fallback ): mixed => array_key_exists( $key, $old ) ? $old[ $key ] : $fallback;
		$levels = $this->plugin->get( LevelRepository::class )->all();
		$groups = $this->plugin->get( LevelGroupRepository::class )->all();
		$type   = (string) $v( 'discount_type', $coupon->discount_type ?? Coupon::PERCENT );
		$sel    = (array) $v( 'level_ids', $coupon ? array_keys( $coupon->levels ) : array() );
		?>
		<h1><?php echo $coupon ? esc_html__( 'Edit Coupon', 'memberkin' ) : esc_html__( 'Add New Coupon', 'memberkin' ); ?></h1>
		<?php Flash::render(); ?>
		<?php if ( $coupon ) : ?>
			<p class="memberkin-muted">
				<?php
				/* translators: 1: times used, 2: limit. */
				echo esc_html( sprintf( __( 'Used %1$d times (limit: %2$s).', 'memberkin' ), $coupon->uses_count, $coupon->max_uses ? (string) $coupon->max_uses : __( 'none', 'memberkin' ) ) );
				?>
			</p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="memberkin_save_coupon" />
			<input type="hidden" name="id" value="<?php echo (int) ( $coupon->id ?? 0 ); ?>" />
			<?php wp_nonce_field( 'memberkin_save_coupon' ); ?>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><label for="memberkin-code"><?php esc_html_e( 'Code', 'memberkin' ); ?></label></th>
					<td><input type="text" id="memberkin-code" name="code" class="regular-text" required maxlength="64" value="<?php echo esc_attr( (string) $v( 'code', $coupon->code ?? '' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Letters, numbers, dashes and underscores. Not case sensitive.', 'memberkin' ); ?></p></td></tr>
				<tr><th scope="row"><label for="memberkin-desc"><?php esc_html_e( 'Description', 'memberkin' ); ?></label></th>
					<td><input type="text" id="memberkin-desc" name="description" class="large-text" value="<?php echo esc_attr( (string) $v( 'description', $coupon->description ?? '' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'For your reference only.', 'memberkin' ); ?></p></td></tr>
				<tr><th scope="row"><label for="memberkin-status"><?php esc_html_e( 'Status', 'memberkin' ); ?></label></th>
					<td><select id="memberkin-status" name="status">
						<option value="active" <?php selected( $v( 'status', $coupon->status ?? 'active' ), 'active' ); ?>><?php esc_html_e( 'Active', 'memberkin' ); ?></option>
						<option value="inactive" <?php selected( $v( 'status', $coupon->status ?? 'active' ), 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'memberkin' ); ?></option>
					</select></td></tr>
				<tr><th scope="row"><label for="memberkin-type"><?php esc_html_e( 'Discount', 'memberkin' ); ?></label></th>
					<td class="memberkin-inline-fields">
						<select id="memberkin-type" name="discount_type">
							<?php foreach ( Coupon::types() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<label class="screen-reader-text" for="memberkin-amount"><?php esc_html_e( 'Amount', 'memberkin' ); ?></label>
						<input type="text" inputmode="decimal" id="memberkin-amount" name="amount" class="small-text" value="<?php echo esc_attr( (string) $v( 'amount', $this->amount_input( $type, $coupon->amount ?? null ) ) ); ?>" />
						<p class="description">
							<?php
							/* translators: %s: currency code. */
							printf( esc_html__( 'Percent (e.g. 25) or an amount in %s.', 'memberkin' ), esc_html( Currency::default() ) );
							?>
						</p>
					</td></tr>
				<tr><th scope="row"><label for="memberkin-applies"><?php esc_html_e( 'Applies to', 'memberkin' ); ?></label></th>
					<td class="memberkin-inline-fields">
						<select id="memberkin-applies" name="applies_to">
							<?php
							$applies = array(
								'both'      => __( 'First payment and renewals', 'memberkin' ),
								'initial'   => __( 'First payment only', 'memberkin' ),
								'recurring' => __( 'Renewals only', 'memberkin' ),
							);
							foreach ( $applies as $key => $label ) :
								?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $v( 'applies_to', $coupon->applies_to ?? 'both' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<label for="memberkin-cycles"><?php esc_html_e( 'Number of discounted renewals', 'memberkin' ); ?></label>
						<input type="number" min="0" id="memberkin-cycles" name="recurring_cycles" class="small-text" placeholder="∞" value="<?php echo esc_attr( (string) $v( 'recurring_cycles', null === ( $coupon->recurring_cycles ?? null ) ? '' : (string) $coupon->recurring_cycles ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Leave empty to discount every renewal.', 'memberkin' ); ?></p>
					</td></tr>
				<tr><th scope="row"><label for="memberkin-first-type"><?php esc_html_e( 'Different first-payment discount', 'memberkin' ); ?></label></th>
					<td class="memberkin-inline-fields">
						<?php $first_type = (string) $v( 'first_payment_type', $coupon->first_payment_type ?? '' ); ?>
						<select id="memberkin-first-type" name="first_payment_type">
							<option value=""><?php esc_html_e( '— Same as above —', 'memberkin' ); ?></option>
							<?php foreach ( Coupon::types() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $first_type, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<label class="screen-reader-text" for="memberkin-first-amount"><?php esc_html_e( 'First payment discount amount', 'memberkin' ); ?></label>
						<input type="text" inputmode="decimal" id="memberkin-first-amount" name="first_payment_amount" class="small-text" value="<?php echo esc_attr( (string) $v( 'first_payment_amount', $this->amount_input( '' !== $first_type ? $first_type : Coupon::FIXED, $coupon->first_payment_amount ?? null ) ) ); ?>" />
					</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Trial', 'memberkin' ); ?></th>
					<td class="memberkin-inline-fields">
						<?php $has_trial = (bool) $v( 'trial_override', null !== ( $coupon->trial_override_length ?? null ) ); ?>
						<label><input type="checkbox" name="trial_override" value="1" <?php checked( $has_trial ); ?> /> <?php esc_html_e( 'Give a trial of', 'memberkin' ); ?></label>
						<label class="screen-reader-text" for="memberkin-trial-length"><?php esc_html_e( 'Trial length', 'memberkin' ); ?></label>
						<input type="number" min="0" id="memberkin-trial-length" name="trial_length" class="small-text" value="<?php echo (int) $v( 'trial_length', $coupon->trial_override_length ?? 7 ); ?>" />
						<label class="screen-reader-text" for="memberkin-trial-period"><?php esc_html_e( 'Trial unit', 'memberkin' ); ?></label>
						<select id="memberkin-trial-period" name="trial_period">
							<?php foreach ( Period::options( true ) as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $v( 'trial_period', $coupon->trial_override_period ?? 'day' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<label for="memberkin-trial-price"><?php esc_html_e( 'for', 'memberkin' ); ?></label>
						<input type="text" inputmode="decimal" id="memberkin-trial-price" name="trial_price" class="small-text" placeholder="0" value="<?php echo esc_attr( (string) $v( 'trial_price', $this->amount_input( Coupon::FIXED, $coupon->trial_override_amount ?? null ) ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Replaces the level’s trial for subscriptions.', 'memberkin' ); ?></p>
					</td></tr>
				<tr><th scope="row"><label for="memberkin-starts"><?php esc_html_e( 'Valid', 'memberkin' ); ?></label></th>
					<td class="memberkin-inline-fields">
						<input type="datetime-local" id="memberkin-starts" name="starts_at" value="<?php echo esc_attr( (string) $v( 'starts_at', $this->local( $coupon->starts_at ?? null ) ) ); ?>" />
						<label for="memberkin-expires"><?php esc_html_e( 'until', 'memberkin' ); ?></label>
						<input type="datetime-local" id="memberkin-expires" name="expires_at" value="<?php echo esc_attr( (string) $v( 'expires_at', $this->local( $coupon->expires_at ?? null ) ) ); ?>" />
						<p class="description">
							<?php
							/* translators: %s: timezone name. */
							printf( esc_html__( 'Site time (%s). Leave empty for no limit.', 'memberkin' ), esc_html( wp_timezone_string() ) );
							?>
						</p>
					</td></tr>
				<tr><th scope="row"><label for="memberkin-max"><?php esc_html_e( 'Usage limits', 'memberkin' ); ?></label></th>
					<td class="memberkin-inline-fields">
						<input type="number" min="0" id="memberkin-max" name="max_uses" class="small-text" value="<?php echo (int) $v( 'max_uses', $coupon->max_uses ?? 0 ); ?>" />
						<label for="memberkin-max"><?php esc_html_e( 'uses in total;', 'memberkin' ); ?></label>
						<input type="number" min="0" id="memberkin-max-user" name="max_uses_per_user" class="small-text" value="<?php echo (int) $v( 'max_uses_per_user', $coupon->max_uses_per_user ?? 0 ); ?>" />
						<label for="memberkin-max-user"><?php esc_html_e( 'per member within', 'memberkin' ); ?></label>
						<input type="number" min="0" id="memberkin-window" name="per_user_window_days" class="small-text" value="<?php echo (int) $v( 'per_user_window_days', $coupon->per_user_window_days ?? 0 ); ?>" />
						<label for="memberkin-window"><?php esc_html_e( 'days', 'memberkin' ); ?></label>
						<p class="description"><?php esc_html_e( '0 = unlimited / all time.', 'memberkin' ); ?></p>
					</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Plan changes', 'memberkin' ); ?></th>
					<td><label><input type="hidden" name="allow_on_change" value="0" /><input type="checkbox" name="allow_on_change" value="1" <?php checked( (bool) $v( 'allow_on_change', $coupon->allow_on_change ?? true ) ); ?> /> <?php esc_html_e( 'Can be used when upgrading or downgrading', 'memberkin' ); ?></label></td></tr>
				<tr><th scope="row"><label for="memberkin-auto"><?php esc_html_e( 'Apply automatically', 'memberkin' ); ?></label></th>
					<td><select id="memberkin-auto" name="auto_apply_group_id">
						<option value="0"><?php esc_html_e( '— No —', 'memberkin' ); ?></option>
						<?php foreach ( $groups as $group ) : ?>
							<option value="<?php echo (int) $group->id; ?>" <?php selected( (int) $v( 'auto_apply_group_id', $coupon->auto_apply_group_id ?? 0 ), $group->id ); ?>>
								<?php
								/* translators: %s: level group. */
								echo esc_html( sprintf( __( 'To checkouts of group “%s”', 'memberkin' ), $group->name ) );
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Useful for sales: combine with the validity dates.', 'memberkin' ); ?></p></td></tr>
			</tbody></table>

			<h2><?php esc_html_e( 'Levels', 'memberkin' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Leave all unchecked to allow every level. Optionally give a level a completely different price with this coupon.', 'memberkin' ); ?></p>
			<input type="hidden" name="level_ids[]" value="0" />
			<table class="widefat striped" style="max-width:900px;">
				<tbody>
				<?php foreach ( $levels as $level ) : ?>
					<?php
					$override = $coupon->levels[ $level->id ]['schedule'] ?? null;
					$enabled  = (bool) ( $coupon->levels[ $level->id ]['override'] ?? false );
					$prefix   = 'overrides[' . $level->id . ']';
					$ov_first = $override ? $this->amount_input( Coupon::FIXED, $override->initial_amount ) : '';
					$ov_rec   = $override ? $this->amount_input( Coupon::FIXED, $override->recurring_amount ) : '';
					$ov_int   = $override ? $override->billing_interval : $level->schedule->billing_interval;
					$ov_per   = $override ? $override->billing_period : $level->schedule->billing_period;
					?>
					<tr>
						<td style="width:30%;"><label><input type="checkbox" name="level_ids[]" value="<?php echo (int) $level->id; ?>" <?php checked( in_array( $level->id, array_map( 'intval', $sel ), true ) ); ?> /> <?php echo esc_html( $level->name ); ?></label></td>
						<td class="memberkin-inline-fields">
							<label><input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[enabled]" value="1" <?php checked( $enabled ); ?> /> <?php esc_html_e( 'Custom price:', 'memberkin' ); ?></label>
							<label class="screen-reader-text" for="memberkin-ov-initial-<?php echo (int) $level->id; ?>"><?php esc_html_e( 'First payment', 'memberkin' ); ?></label>
							<input type="text" inputmode="decimal" id="memberkin-ov-initial-<?php echo (int) $level->id; ?>" name="<?php echo esc_attr( $prefix ); ?>[initial_price]" class="small-text" placeholder="<?php esc_attr_e( 'first', 'memberkin' ); ?>" value="<?php echo esc_attr( $ov_first ); ?>" />
							<label class="screen-reader-text" for="memberkin-ov-rec-<?php echo (int) $level->id; ?>"><?php esc_html_e( 'Recurring amount', 'memberkin' ); ?></label>
							<input type="text" inputmode="decimal" id="memberkin-ov-rec-<?php echo (int) $level->id; ?>" name="<?php echo esc_attr( $prefix ); ?>[recurring_price]" class="small-text" placeholder="<?php esc_attr_e( 'recurring', 'memberkin' ); ?>" value="<?php echo esc_attr( $ov_rec ); ?>" />
							<?php esc_html_e( 'every', 'memberkin' ); ?>
							<label class="screen-reader-text" for="memberkin-ov-int-<?php echo (int) $level->id; ?>"><?php esc_html_e( 'Interval', 'memberkin' ); ?></label>
							<input type="number" min="1" id="memberkin-ov-int-<?php echo (int) $level->id; ?>" name="<?php echo esc_attr( $prefix ); ?>[billing_interval]" class="small-text" value="<?php echo (int) $ov_int; ?>" />
							<label class="screen-reader-text" for="memberkin-ov-per-<?php echo (int) $level->id; ?>"><?php esc_html_e( 'Period', 'memberkin' ); ?></label>
							<select id="memberkin-ov-per-<?php echo (int) $level->id; ?>" name="<?php echo esc_attr( $prefix ); ?>[billing_period]">
								<?php foreach ( Period::options() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $ov_per, $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( $coupon ? __( 'Save Coupon', 'memberkin' ) : __( 'Create Coupon', 'memberkin' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Save handler.
	 */
	public function handle_save(): void {
		$this->authorize();
		check_admin_referer( 'memberkin_save_coupon' );
		$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised in CouponService::save().
		unset( $input['_wpnonce'], $input['_wp_http_referer'], $input['action'] );
		$input['level_ids'] = array_values( array_filter( array_map( 'intval', (array) ( $input['level_ids'] ?? array() ) ) ) );
		if ( empty( $input['first_payment_type'] ) ) {
			unset( $input['first_payment_type'] );
		}
		$input['trial_override'] = ! empty( $input['trial_override'] );
		try {
			$coupon = $this->plugin->get( CouponService::class )->save( $input );
			Flash::add( __( 'Coupon saved.', 'memberkin' ) );
			Flash::redirect(
				$this->url(
					array(
						'view' => 'edit',
						'id'   => $coupon->id,
					)
				)
			);
		} catch ( ValidationException $e ) {
			Flash::errors( $e->errors() );
			Flash::keep_input( $input );
			Flash::redirect(
				$this->url(
					array(
						'view' => 'edit',
						'id'   => (int) ( $input['id'] ?? 0 ),
					)
				)
			);
		}
	}

	/**
	 * Delete handler.
	 */
	public function handle_delete(): void {
		$this->authorize();
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'memberkin_delete_coupon_' . $id );
		$this->plugin->get( CouponRepository::class )->delete( $id );
		Flash::add( __( 'Coupon deleted.', 'memberkin' ) );
		Flash::redirect( $this->url() );
	}
}
