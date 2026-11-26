<?php
/**
 * Test helper exception.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

/**
 * Redirects are turned into exceptions so handlers that exit can be tested.
 */
final class RedirectException extends \RuntimeException {}
