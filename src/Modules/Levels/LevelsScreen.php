<?php
/**
 * Levels admin screen.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Levels;

use Memberkin\Admin\Flash;
use Memberkin\Core\Capabilities;
use Memberkin\Core\Money;
use Memberkin\Core\Plugin;
use Memberkin\Core\ValidationException;
use Memberkin\Domain\Level;
use Memberkin\Domain\LevelGroup;
use Memberkin\Domain\Period;
use Memberkin\Repository\LevelGroupRepository;
use Memberkin\Repository\LevelRepository;
use Memberkin\Repository\MembershipRepository;
use Memberkin\Service\LevelService;

defined( 'ABSPATH' ) || exit;

/**
 * List, edit, duplicate and delete levels; manage level groups.
 */
final class LevelsScreen {

	private const PAGE = 'memberkin-levels';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Hooks for form handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_memberkin_save_level', array( $this, 'handle_save' ) );
		add_action( 'admin_post_memberkin_level_action', array( $this, 'handle_action' ) );
		add_action( 'admin_post_memberkin_save_level_group', array( $this, 'handle_save_group' ) );
	}

	/**
	 * Base URL with arguments.
	 *
	 * @param array $args Query args.
	 */
	private function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Guards capability.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_LEVELS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage levels.', 'memberkin' ), 403 );
		}
	}

	/**
	 * Page router.
	 */
	public function render(): void {
		$this->authorize();
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap">';
		if ( 'edit' === $view ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$this->render_edit( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( 'group' === $view ) {
			$this->render_group( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
			// phpcs:enable
		} else {
			$this->render_list();
		}
		echo '</div>';
	}

	/**
	 * Levels grouped by level group.
	 */
	private function render_list(): void {
		$service = $this->plugin->get( LevelService::class );
		$service->default_group();
		$levels = $this->plugin->get( LevelRepository::class )->all();
		$groups = $this->plugin->get( LevelGroupRepository::class )->all();
		$counts = $this->plugin->get( MembershipRepository::class )->active_counts_by_level();
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Membership Levels', 'memberkin' ); ?></h1>
		<a href="<?php echo esc_url( $this->url( array( 'view' => 'edit' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Level', 'memberkin' ); ?></a>
		<a href="<?php echo esc_url( $this->url( array( 'view' => 'group' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Group', 'memberkin' ); ?></a>
		<hr class="wp-header-end" />
		<?php Flash::render(); ?>
		<?php foreach ( $groups as $group ) : ?>
			<?php $group_levels = array_filter( $levels, static fn( Level $l ) => $l->group_id === $group->id ); ?>
			<h2>
				<?php echo esc_html( $group->name ); ?>
				<span class="memberkin-muted" style="font-weight:normal;font-size:13px;">
					— <?php echo $group->allow_multiple ? esc_html__( 'members may hold several levels', 'memberkin' ) : esc_html__( 'one level per member', 'memberkin' ); ?>
					· <a href="
					<?php
					echo esc_url(
						$this->url(
							array(
								'view' => 'group',
								'id'   => $group->id,
							)
						)
					);
					?>
								"><?php esc_html_e( 'Edit group', 'memberkin' ); ?></a>
				</span>
			</h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" style="width:4em;"><?php esc_html_e( 'ID', 'memberkin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Name', 'memberkin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Price', 'memberkin' ); ?></th>
						<th scope="col" style="width:9em;"><?php esc_html_e( 'Active members', 'memberkin' ); ?></th>
						<th scope="col" style="width:12em;"><?php esc_html_e( 'Status', 'memberkin' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $group_levels ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No levels in this group yet.', 'memberkin' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $group_levels as $level ) : ?>
					<tr>
						<td><?php echo (int) $level->id; ?></td>
						<td>
							<strong><a class="row-title" href="
							<?php
							echo esc_url(
								$this->url(
									array(
										'view' => 'edit',
										'id'   => $level->id,
									)
								)
							);
							?>
																"><?php echo esc_html( $level->name ); ?></a></strong>
							<div class="row-actions">
								<span class="edit"><a href="
								<?php
								echo esc_url(
									$this->url(
										array(
											'view' => 'edit',
											'id'   => $level->id,
										)
									)
								);
								?>
															"><?php esc_html_e( 'Edit', 'memberkin' ); ?></a> | </span>
								<span><a href="<?php echo esc_url( $this->action_url( 'duplicate', $level->id ) ); ?>"><?php esc_html_e( 'Duplicate', 'memberkin' ); ?></a> | </span>
								<?php if ( $level->is_purchasable() ) : ?>
									<span><a href="<?php echo esc_url( memberkin_checkout_url( $level ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Checkout link', 'memberkin' ); ?></a> | </span>
								<?php endif; ?>
								<span class="trash"><a href="<?php echo esc_url( $this->action_url( 'delete', $level->id ) ); ?>" data-memberkin-confirm="<?php esc_attr_e( 'Delete this level? Levels that have members are archived instead.', 'memberkin' ); ?>"><?php esc_html_e( 'Delete', 'memberkin' ); ?></a></span>
							</div>
						</td>
						<td><?php echo esc_html( $level->schedule->describe() ); ?></td>
						<td><?php echo (int) ( $counts[ $level->id ] ?? 0 ); ?></td>
						<td>
							<span class="memberkin-badge memberkin-badge--<?php echo esc_attr( Level::STATUS_ACTIVE === $level->status ? 'active' : ( Level::STATUS_ARCHIVED === $level->status ? 'ended' : 'pending' ) ); ?>"><?php echo esc_html( Level::statuses()[ $level->status ] ?? $level->status ); ?></span>
							<?php if ( ! $level->allow_signups && Level::STATUS_ARCHIVED !== $level->status ) : ?>
								<br /><span class="memberkin-muted"><?php esc_html_e( 'Signups closed', 'memberkin' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Nonce-protected action URL.
	 *
	 * @param string $action Action.
	 * @param int    $id     Level id.
	 */
	private function action_url( string $action, int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'memberkin_level_action',
					'do'     => $action,
					'id'     => $id,
				),
				admin_url( 'admin-post.php' )
			),
			'memberkin_level_' . $action . '_' . $id
		);
	}

	/**
	 * Level edit form.
	 *
	 * @param int $id Level id (0 = new).
	 */
	private function render_edit( int $id ): void {
		$repo  = $this->plugin->get( LevelRepository::class );
		$level = $id ? $repo->find( $id ) : null;
		if ( $id && ! $level ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Level not found.', 'memberkin' ) . '</p></div>';
			return;
		}
		$old = Flash::old_input();
		$s   = $level?->schedule;

		$v     = static function ( string $key, mixed $fallback ) use ( $old ): mixed {
			return is_array( $old ) && array_key_exists( $key, $old ) ? $old[ $key ] : $fallback;
		};
		$m     = static function ( string $key, mixed $fallback ) use ( $old, $repo, $level ): mixed {
			if ( is_array( $old ) && isset( $old['meta'] ) && array_key_exists( $key, $old['meta'] ) ) {
				return $old['meta'][ $key ];
			}
			return $level ? $repo->meta( $level->id, $key, $fallback ) : $fallback;
		};
		$money = static fn( ?int $minor ): string => null === $minor || 0 === $minor ? '' : Money::of( $minor, $s->currency ?? \Memberkin\Core\Currency::default() )->to_decimal();

		$billing_type = $v( 'billing_type', $s ? ( $s->is_recurring() ? 'recurring' : ( $s->is_free() ? 'free' : 'one_time' ) ) : 'recurring' );
		$groups       = $this->plugin->get( LevelService::class )->default_group() ? $this->plugin->get( LevelGroupRepository::class )->all() : array();
		$currency     = $s->currency ?? \Memberkin\Core\Currency::default();
		?>
		<h1><?php echo $level ? esc_html__( 'Edit Level', 'memberkin' ) : esc_html__( 'Add New Level', 'memberkin' ); ?></h1>
		<?php Flash::render(); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="memberkin-level-form">
			<input type="hidden" name="action" value="memberkin_save_level" />
			<input type="hidden" name="id" value="<?php echo (int) ( $level->id ?? 0 ); ?>" />
			<?php wp_nonce_field( 'memberkin_save_level' ); ?>

			<h2><?php esc_html_e( 'General', 'memberkin' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><label for="memberkin-name"><?php esc_html_e( 'Name', 'memberkin' ); ?></label></th>
					<td><input type="text" id="memberkin-name" name="name" class="regular-text" required value="<?php echo esc_attr( (string) $v( 'name', $level->name ?? '' ) ); ?>" /></td></tr>
				<tr><th scope="row"><label for="memberkin-slug"><?php esc_html_e( 'Slug', 'memberkin' ); ?></label></th>
					<td><input type="text" id="memberkin-slug" name="slug" class="regular-text" value="<?php echo esc_attr( (string) $v( 'slug', $level->slug ?? '' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Used in checkout links. Leave empty to generate it from the name.', 'memberkin' ); ?></p></td></tr>
				<tr><th scope="row"><label for="memberkin-description"><?php esc_html_e( 'Description', 'memberkin' ); ?></label></th>
					<td>
					<?php
					wp_editor(
						(string) $v( 'description', $level->description ?? '' ),
						'memberkin-description',
						array(
							'textarea_name' => 'description',
							'textarea_rows' => 6,
							'media_buttons' => false,
						)
					);
					?>
						</td></tr>
				<tr><th scope="row"><label for="memberkin-group"><?php esc_html_e( 'Group', 'memberkin' ); ?></label></th>
					<td><select id="memberkin-group" name="group_id">
						<?php foreach ( $groups as $group ) : ?>
							<option value="<?php echo (int) $group->id; ?>" <?php selected( (int) $v( 'group_id', $level->group_id ?? 0 ), $group->id ); ?>><?php echo esc_html( $group->name ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
				<tr><th scope="row"><label for="memberkin-status"><?php esc_html_e( 'Status', 'memberkin' ); ?></label></th>
					<td><select id="memberkin-status" name="status">
						<?php foreach ( Level::statuses() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( (string) $v( 'status', $level->status ?? Level::STATUS_ACTIVE ), $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<label style="margin-left:1em;"><input type="hidden" name="allow_signups" value="0" /><input type="checkbox" name="allow_signups" value="1" <?php checked( (bool) $v( 'allow_signups', $level->allow_signups ?? true ) ); ?> /> <?php esc_html_e( 'Allow new signups', 'memberkin' ); ?></label></td></tr>
				<tr><th scope="row"><label for="memberkin-order"><?php esc_html_e( 'Display order', 'memberkin' ); ?></label></th>
					<td><input type="number" id="memberkin-order" name="display_order" class="small-text" value="<?php echo (int) $v( 'display_order', $level->display_order ?? 0 ); ?>" /></td></tr>
			</tbody></table>

			<h2><?php esc_html_e( 'Pricing', 'memberkin' ); ?></h2>
			<table class="form-table memberkin-price-fields" role="presentation"><tbody>
				<tr><th scope="row"><?php esc_html_e( 'Billing', 'memberkin' ); ?></th>
					<td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Billing', 'memberkin' ); ?></legend>
						<?php
						$types = array(
							'free'      => __( 'Free', 'memberkin' ),
							'one_time'  => __( 'One-time payment', 'memberkin' ),
							'recurring' => __( 'Recurring subscription', 'memberkin' ),
						);
						foreach ( $types as $key => $label ) :
							?>
							<label style="margin-right:1.5em;"><input type="radio" name="billing_type" value="<?php echo esc_attr( $key ); ?>" <?php checked( $billing_type, $key ); ?> /> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<p class="description">
						<?php
						/* translators: %s: currency code. */
						printf( esc_html__( 'Amounts are in %s.', 'memberkin' ), esc_html( $currency ) );
						?>
					</p></td></tr>
				<tr class="memberkin-paid-row"><th scope="row"><label for="memberkin-initial"><?php esc_html_e( 'Price / first payment', 'memberkin' ); ?></label></th>
					<td><input type="text" inputmode="decimal" id="memberkin-initial" name="initial_price" class="small-text" value="<?php echo esc_attr( (string) $v( 'initial_price', $money( $s?->initial_amount ) ) ); ?>" />
					<p class="description"><?php esc_html_e( 'For subscriptions this is the first payment; usually the same as the recurring amount.', 'memberkin' ); ?></p></td></tr>
				<tr class="memberkin-recurring-row"><th scope="row"><label for="memberkin-recurring"><?php esc_html_e( 'Recurring amount', 'memberkin' ); ?></label></th>
					<td class="memberkin-inline-fields">
						<input type="text" inputmode="decimal" id="memberkin-recurring" name="recurring_price" class="small-text" value="<?php echo esc_attr( (string) $v( 'recurring_price', $money( $s?->recurring_amount ) ) ); ?>" />
						<label for="memberkin-interval"><?php esc_html_e( 'every', 'memberkin' ); ?></label>
						<input type="number" min="1" max="365" id="memberkin-interval" name="billing_interval" class="small-text" value="<?php echo (int) $v( 'billing_interval', $s->billing_interval ?? 1 ); ?>" />
						<label class="screen-reader-text" for="memberkin-period"><?php esc_html_e( 'Billing period', 'memberkin' ); ?></label>
						<select id="memberkin-period" name="billing_period"><?php $this->options( Period::options(), (string) $v( 'billing_period', $s->billing_period ?? 'month' ) ); ?></select>
					</td></tr>
				<tr class="memberkin-recurring-row"><th scope="row"><label for="memberkin-cycles"><?php esc_html_e( 'Number of payments', 'memberkin' ); ?></label></th>
					<td><input type="number" min="0" id="memberkin-cycles" name="billing_cycles" class="small-text" value="<?php echo (int) $v( 'billing_cycles', $s->billing_cycles ?? 0 ); ?>" />
					<p class="description"><?php esc_html_e( 'Total number of payments including the first one. 0 = until cancelled.', 'memberkin' ); ?></p></td></tr>
				<tr class="memberkin-paid-row"><th scope="row"><label for="memberkin-signup-fee"><?php esc_html_e( 'Signup fee', 'memberkin' ); ?></label></th>
					<td><input type="text" inputmode="decimal" id="memberkin-signup-fee" name="signup_fee_price" class="small-text" value="<?php echo esc_attr( (string) $v( 'signup_fee_price', $money( $s?->signup_fee ) ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Added to the first payment only.', 'memberkin' ); ?></p></td></tr>
				<tr class="memberkin-recurring-row"><th scope="row"><label for="memberkin-trial-length"><?php esc_html_e( 'Trial', 'memberkin' ); ?></label></th>
					<td class="memberkin-inline-fields">
						<input type="number" min="0" id="memberkin-trial-length" name="trial_length" class="small-text" value="<?php echo (int) $v( 'trial_length', $s->trial_length ?? 0 ); ?>" />
						<label class="screen-reader-text" for="memberkin-trial-period"><?php esc_html_e( 'Trial period', 'memberkin' ); ?></label>
						<select id="memberkin-trial-period" name="trial_period"><?php $this->options( Period::options( true ), (string) $v( 'trial_period', $s->trial_period ?? 'day' ) ); ?></select>
						<label for="memberkin-trial-price"><?php esc_html_e( 'for', 'memberkin' ); ?></label>
						<input type="text" inputmode="decimal" id="memberkin-trial-price" name="trial_price" class="small-text" placeholder="0" value="<?php echo esc_attr( (string) $v( 'trial_price', $money( $s?->trial_amount ) ) ); ?>" />
						<p class="description"><?php esc_html_e( '0 = no trial. Leave the price empty for a free trial.', 'memberkin' ); ?></p>
					</td></tr>
				<tr><th scope="row"><label for="memberkin-exp-type"><?php esc_html_e( 'Membership ends', 'memberkin' ); ?></label></th>
					<td class="memberkin-inline-fields">
						<select id="memberkin-exp-type" name="expiration_type" data-memberkin-toggle=".memberkin-exp-duration" data-memberkin-show-values="duration">
							<?php
							$this->options(
								array(
									'never'        => __( 'Never (or when the subscription ends)', 'memberkin' ),
									'duration'     => __( 'After a period of time', 'memberkin' ),
									'fixed_date'   => __( 'On a fixed date', 'memberkin' ),
									'after_cycles' => __( 'After the last payment period', 'memberkin' ),
								),
								(string) $v( 'expiration_type', $s->expiration_type ?? 'never' )
							);
							?>
						</select>
						<span class="memberkin-exp-duration">
							<label class="screen-reader-text" for="memberkin-exp-length"><?php esc_html_e( 'Duration', 'memberkin' ); ?></label>
							<input type="number" min="1" id="memberkin-exp-length" name="expiration_length" class="small-text" value="<?php echo (int) $v( 'expiration_length', $s->expiration_length ?? 1 ); ?>" />
							<label class="screen-reader-text" for="memberkin-exp-period"><?php esc_html_e( 'Duration unit', 'memberkin' ); ?></label>
							<select id="memberkin-exp-period" name="expiration_period"><?php $this->options( Period::options(), (string) $v( 'expiration_period', $s->expiration_period ?? 'year' ) ); ?></select>
						</span>
						<label for="memberkin-exp-date"><?php esc_html_e( 'Fixed date:', 'memberkin' ); ?></label>
						<input type="date" id="memberkin-exp-date" name="expiration_date" value="<?php echo esc_attr( (string) $v( 'expiration_date', $s?->expiration_date ? wp_date( 'Y-m-d', $s->expiration_date->getTimestamp() ) : '' ) ); ?>" />
					</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Options', 'memberkin' ); ?></th>
					<td>
						<label><input type="hidden" name="meta[trial_once]" value="0" /><input type="checkbox" name="meta[trial_once]" value="1" <?php checked( (bool) $m( 'trial_once', true ) ); ?> /> <?php esc_html_e( 'Members get the trial only once', 'memberkin' ); ?></label><br />
						<label><input type="hidden" name="meta[allow_renewal]" value="0" /><input type="checkbox" name="meta[allow_renewal]" value="1" <?php checked( (bool) $m( 'allow_renewal', true ) ); ?> /> <?php esc_html_e( 'Allow early renewal of one-time memberships (extends the end date)', 'memberkin' ); ?></label>
					</td></tr>
				<?php
				/**
				 * Whether the level screen shows the tax class field (a tax add-on turns it on).
				 *
				 * @param bool $show Show.
				 */
				if ( apply_filters( 'memberkin_level_tax_class_field', false ) ) :
					?>
				<tr><th scope="row"><label for="memberkin-tax-class"><?php esc_html_e( 'Tax class', 'memberkin' ); ?></label></th>
					<td><select id="memberkin-tax-class" name="tax_class"><?php $this->options( self::tax_classes(), (string) $v( 'tax_class', $level->tax_class ?? 'standard' ) ); ?></select></td></tr>
				<?php endif; ?>
			</tbody></table>

			<h2><?php esc_html_e( 'Display', 'memberkin' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><label for="memberkin-cost-text"><?php esc_html_e( 'Custom price text', 'memberkin' ); ?></label></th>
					<td><input type="text" id="memberkin-cost-text" name="meta[cost_text]" class="large-text" value="<?php echo esc_attr( (string) $m( 'cost_text', '' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Replaces the automatic price description on the levels page and at checkout.', 'memberkin' ); ?></p></td></tr>
				<tr><th scope="row"><label for="memberkin-benefits"><?php esc_html_e( 'Benefits', 'memberkin' ); ?></label></th>
					<td><textarea id="memberkin-benefits" name="meta[benefits]" class="large-text" rows="4"><?php echo esc_textarea( implode( "\n", (array) $m( 'benefits', array() ) ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One per line. Shown in pricing tables.', 'memberkin' ); ?></p></td></tr>
				<tr><th scope="row"><label for="memberkin-button-text"><?php esc_html_e( 'Button text', 'memberkin' ); ?></label></th>
					<td><input type="text" id="memberkin-button-text" name="meta[button_text]" class="regular-text" placeholder="<?php esc_attr_e( 'Select', 'memberkin' ); ?>" value="<?php echo esc_attr( (string) $m( 'button_text', '' ) ); ?>" />
					<label style="margin-left:1em;"><input type="hidden" name="meta[highlight]" value="0" /><input type="checkbox" name="meta[highlight]" value="1" <?php checked( (bool) $m( 'highlight', false ) ); ?> /> <?php esc_html_e( 'Highlight in pricing tables', 'memberkin' ); ?></label></td></tr>
			</tbody></table>

			<h2><?php esc_html_e( 'After signup', 'memberkin' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><label for="memberkin-confirmation"><?php esc_html_e( 'Confirmation message', 'memberkin' ); ?></label></th>
					<td><textarea id="memberkin-confirmation" name="meta[confirmation_message]" class="large-text" rows="4"><?php echo esc_textarea( (string) $m( 'confirmation_message', '' ) ); ?></textarea></td></tr>
				<tr><th scope="row"><label for="memberkin-thank-you"><?php esc_html_e( 'Thank-you page', 'memberkin' ); ?></label></th>
					<td>
					<?php
					wp_dropdown_pages(
						array(
							'name'              => 'meta[thank_you_page]',
							'id'                => 'memberkin-thank-you',
							'selected'          => (int) $m( 'thank_you_page', 0 ),
							'show_option_none'  => esc_html__( '— Default confirmation page —', 'memberkin' ),
							'option_none_value' => '0',
						)
					);
					?>
						</td></tr>
				<tr><th scope="row"><label for="memberkin-login-redirect"><?php esc_html_e( 'Login redirect', 'memberkin' ); ?></label></th>
					<td><input type="url" id="memberkin-login-redirect" name="meta[login_redirect]" class="regular-text" value="<?php echo esc_attr( (string) $m( 'login_redirect', '' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Where members of this level land after logging in. Empty = global setting.', 'memberkin' ); ?></p></td></tr>
				<tr><th scope="row"><label for="memberkin-account-message"><?php esc_html_e( 'Account page message', 'memberkin' ); ?></label></th>
					<td><textarea id="memberkin-account-message" name="meta[account_message]" class="large-text" rows="3"><?php echo esc_textarea( (string) $m( 'account_message', '' ) ); ?></textarea></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'WordPress roles', 'memberkin' ); ?></th>
					<td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'WordPress roles', 'memberkin' ); ?></legend>
						<input type="hidden" name="meta[roles]" value="" />
						<?php foreach ( wp_roles()->get_names() as $role => $label ) : ?>
							<?php
							if ( 'administrator' === $role ) {
								continue;
							}
							?>
							<label style="margin-right:1em;"><input type="checkbox" name="meta[roles][]" value="<?php echo esc_attr( $role ); ?>" <?php checked( in_array( $role, (array) $m( 'roles', array() ), true ) ); ?> /> <?php echo esc_html( translate_user_role( $label ) ); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<label><input type="hidden" name="meta[remove_roles_on_end]" value="0" /><input type="checkbox" name="meta[remove_roles_on_end]" value="1" <?php checked( (bool) $m( 'remove_roles_on_end', true ) ); ?> /> <?php esc_html_e( 'Remove these roles when the membership ends', 'memberkin' ); ?></label></td></tr>
			</tbody></table>
			<?php
			/**
			 * Fires at the end of the level edit form (modules add their own sections).
			 *
			 * @param Level|null $level Level being edited.
			 */
			do_action( 'memberkin_level_form', $level );
			submit_button( $level ? __( 'Save Level', 'memberkin' ) : __( 'Create Level', 'memberkin' ) );
			?>
		</form>
		<?php
	}

	/**
	 * Group form.
	 *
	 * @param int $id Group id.
	 */
	private function render_group( int $id ): void {
		$group  = $id ? $this->plugin->get( LevelGroupRepository::class )->find( $id ) : null;
		$levels = $this->plugin->get( LevelRepository::class )->all();
		?>
		<h1><?php echo $group ? esc_html__( 'Edit Level Group', 'memberkin' ) : esc_html__( 'Add Level Group', 'memberkin' ); ?></h1>
		<?php Flash::render(); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="memberkin_save_level_group" />
			<input type="hidden" name="id" value="<?php echo (int) ( $group->id ?? 0 ); ?>" />
			<?php wp_nonce_field( 'memberkin_save_level_group' ); ?>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><label for="memberkin-group-name"><?php esc_html_e( 'Name', 'memberkin' ); ?></label></th>
					<td><input type="text" id="memberkin-group-name" name="name" class="regular-text" required value="<?php echo esc_attr( $group->name ?? '' ); ?>" /></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Multiple levels', 'memberkin' ); ?></th>
					<td><label><input type="hidden" name="allow_multiple" value="0" /><input type="checkbox" name="allow_multiple" value="1" <?php checked( $group->allow_multiple ?? false ); ?> /> <?php esc_html_e( 'Members can hold several levels of this group at the same time', 'memberkin' ); ?></label>
					<p class="description"><?php esc_html_e( 'When off, joining another level of this group replaces the current one (upgrade/downgrade).', 'memberkin' ); ?></p></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Plan changes', 'memberkin' ); ?></th>
					<td><label><input type="hidden" name="is_upgrade_path" value="0" /><input type="checkbox" name="is_upgrade_path" value="1" <?php checked( $group->is_upgrade_path ?? false ); ?> /> <?php esc_html_e( 'Offer "change plan" between these levels on the account page', 'memberkin' ); ?></label></td></tr>
				<tr><th scope="row"><label for="memberkin-proration"><?php esc_html_e( 'Upgrades', 'memberkin' ); ?></label></th>
					<td><select id="memberkin-proration" name="proration_mode"><?php $this->options( LevelGroup::proration_modes(), $group->proration_mode ?? LevelGroup::PRORATION_IMMEDIATE ); ?></select></td></tr>
				<tr><th scope="row"><label for="memberkin-downgrade"><?php esc_html_e( 'Downgrades', 'memberkin' ); ?></label></th>
					<td><select id="memberkin-downgrade" name="downgrade_timing">
						<?php
						$this->options(
							array(
								'period_end' => __( 'Take effect at the end of the paid period', 'memberkin' ),
								'immediate'  => __( 'Take effect immediately (with proration)', 'memberkin' ),
							),
							$group->downgrade_timing ?? 'period_end'
						);
						?>
					</select></td></tr>
				<tr><th scope="row"><label for="memberkin-fallback"><?php esc_html_e( 'Fallback level', 'memberkin' ); ?></label></th>
					<td><select id="memberkin-fallback" name="fallback_level_id">
						<option value="0"><?php esc_html_e( '— None —', 'memberkin' ); ?></option>
						<?php foreach ( $levels as $level ) : ?>
							<option value="<?php echo (int) $level->id; ?>" <?php selected( $group->fallback_level_id ?? 0, $level->id ); ?>><?php echo esc_html( $level->name ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Granted automatically when a membership in this group expires or is cancelled (for example a free tier).', 'memberkin' ); ?></p></td></tr>
				<tr><th scope="row"><label for="memberkin-group-order"><?php esc_html_e( 'Display order', 'memberkin' ); ?></label></th>
					<td><input type="number" id="memberkin-group-order" name="display_order" class="small-text" value="<?php echo (int) ( $group->display_order ?? 0 ); ?>" /></td></tr>
			</tbody></table>
			<?php submit_button( $group ? __( 'Save Group', 'memberkin' ) : __( 'Create Group', 'memberkin' ) ); ?>
			<?php if ( $group ) : ?>
				<p><a class="button-link-delete" href="
				<?php
				echo esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'action' => 'memberkin_level_action',
								'do'     => 'delete_group',
								'id'     => $group->id,
							),
							admin_url( 'admin-post.php' )
						),
						'memberkin_level_delete_group_' . $group->id
					)
				);
				?>
														" data-memberkin-confirm="<?php esc_attr_e( 'Delete this group? Its levels move to another group.', 'memberkin' ); ?>"><?php esc_html_e( 'Delete group', 'memberkin' ); ?></a></p>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Prints <option> tags.
	 *
	 * @param array  $options  value => label.
	 * @param string $selected Selected value.
	 */
	private function options( array $options, string $selected ): void {
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( (string) $value ) . '" ' . selected( (string) $value, $selected, false ) . '>' . esc_html( $label ) . '</option>';
		}
	}

	/**
	 * Tax classes.
	 *
	 * @return array<string, string>
	 */
	public static function tax_classes(): array {
		/**
		 * Filters tax classes.
		 *
		 * @param array<string,string> $classes Classes.
		 */
		return (array) apply_filters(
			'memberkin_tax_classes',
			array(
				'standard' => __( 'Standard rate', 'memberkin' ),
				'reduced'  => __( 'Reduced rate', 'memberkin' ),
				'exempt'   => __( 'Tax exempt', 'memberkin' ),
			)
		);
	}

	/**
	 * Saves the level form.
	 */
	public function handle_save(): void {
		$this->authorize();
		check_admin_referer( 'memberkin_save_level' );
		$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised in LevelService::save().
		unset( $input['_wpnonce'], $input['_wp_http_referer'], $input['action'] );
		if ( isset( $input['meta']['roles'] ) && '' === $input['meta']['roles'] ) {
			$input['meta']['roles'] = array();
		}
		try {
			$level = $this->plugin->get( LevelService::class )->save( $input );
			Flash::add( __( 'Level saved.', 'memberkin' ) );
			Flash::redirect(
				$this->url(
					array(
						'view' => 'edit',
						'id'   => $level->id,
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
	 * Saves the group form.
	 */
	public function handle_save_group(): void {
		$this->authorize();
		check_admin_referer( 'memberkin_save_level_group' );
		$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised in LevelService::save_group().
		try {
			$this->plugin->get( LevelService::class )->save_group( $input );
			Flash::add( __( 'Group saved.', 'memberkin' ) );
			Flash::redirect( $this->url() );
		} catch ( ValidationException $e ) {
			Flash::errors( $e->errors() );
			Flash::redirect(
				$this->url(
					array(
						'view' => 'group',
						'id'   => (int) ( $input['id'] ?? 0 ),
					)
				)
			);
		}
	}

	/**
	 * Row actions: duplicate, delete, delete_group.
	 */
	public function handle_action(): void {
		$this->authorize();
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'memberkin_level_' . $do . '_' . $id );
		$service = $this->plugin->get( LevelService::class );
		try {
			switch ( $do ) {
				case 'duplicate':
					$copy = $service->duplicate( $id );
					Flash::add( __( 'Level duplicated. The copy is hidden until you review it.', 'memberkin' ) );
					Flash::redirect(
						$this->url(
							array(
								'view' => 'edit',
								'id'   => $copy->id,
							)
						)
					);
					// No break needed: redirect exits.
				case 'delete':
					$result = $service->delete( $id );
					Flash::add( 'archived' === $result ? __( 'The level has members, so it was archived instead of deleted.', 'memberkin' ) : __( 'Level deleted.', 'memberkin' ), 'archived' === $result ? 'warning' : 'success' );
					break;
				case 'delete_group':
					$service->delete_group( $id );
					Flash::add( __( 'Group deleted.', 'memberkin' ) );
					break;
			}
		} catch ( ValidationException $e ) {
			Flash::errors( $e->errors() );
		}
		Flash::redirect( $this->url() );
	}
}
