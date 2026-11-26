<?php
/**
 * Thrown when a webhook signature cannot be verified.
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Invalid webhook signature.
 */
class InvalidSignature extends \RuntimeException {}
