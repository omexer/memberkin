<?php
/**
 * Gateway capability flags.
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Capability names used by Gateway::supports().
 */
final class Capability {
	public const ONE_TIME              = 'one_time';
	public const RECURRING_GATEWAY     = 'recurring_gateway';
	public const RECURRING_SITE        = 'recurring_site';
	public const TRIALS                = 'trials';
	public const REFUND_FULL           = 'refund_full';
	public const REFUND_PARTIAL        = 'refund_partial';
	public const CANCEL                = 'cancel';
	public const PAUSE                 = 'pause';
	public const CHANGE_PLAN           = 'change_plan';
	public const PAYMENT_METHOD_UPDATE = 'payment_method_update';
	public const SAVED_METHODS         = 'saved_methods';
	public const ONSITE_FIELDS         = 'onsite_fields';
	public const OFFSITE_REDIRECT      = 'offsite_redirect';
	public const WEBHOOKS              = 'webhooks';
	public const MERCHANT_OF_RECORD    = 'merchant_of_record';
	/**
	 * Payments are requested at once but confirmed days later (bank debits): a pending order with a payment id is
	 * in progress, not abandoned.
	 */
	public const DELAYED_CONFIRMATION = 'delayed_confirmation';
	public const MANUAL_CONFIRMATION  = 'manual_confirmation';
}
