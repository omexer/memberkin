<?php
/**
 * Generic gateway operation result.
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Success flag, message, and optional remote id/data (refunds, cancellations, updates).
 */
final class OperationResult {

	/**
	 * Constructor.
	 *
	 * @param bool        $ok        Success.
	 * @param string      $message   Message (errors are safe to show to admins).
	 * @param string|null $remote_id Remote object id (e.g. refund id).
	 * @param array       $data      Extra data.
	 */
	public function __construct(
		public readonly bool $ok,
		public readonly string $message = '',
		public readonly ?string $remote_id = null,
		public readonly array $data = array()
	) {}

	/**
	 * Success.
	 *
	 * @param string|null $remote_id Remote id.
	 * @param array       $data      Data.
	 */
	public static function ok( ?string $remote_id = null, array $data = array() ): self {
		return new self( true, '', $remote_id, $data );
	}

	/**
	 * Failure.
	 *
	 * @param string $message Message.
	 */
	public static function error( string $message ): self {
		return new self( false, $message );
	}
}
