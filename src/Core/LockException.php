<?php
/**
 * Lock failure.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a named lock cannot be acquired.
 */
class LockException extends \RuntimeException {}
