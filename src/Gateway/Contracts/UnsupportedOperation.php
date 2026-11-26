<?php
/**
 * Thrown when a gateway does not support an operation.
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Unsupported gateway operation.
 */
class UnsupportedOperation extends \RuntimeException {}
