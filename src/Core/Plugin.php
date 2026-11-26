<?php
/**
 * Plugin kernel.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Boots modules and exposes the service container.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Module registry.
	 *
	 * @var ModuleRegistry
	 */
	private ModuleRegistry $modules;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Returns the shared instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->container = new Container();
		$this->modules   = new ModuleRegistry();
		$this->container->instance( self::class, $this );
		$this->container->instance( ModuleRegistry::class, $this->modules );
	}

	/**
	 * Module classes shipped with the plugin, in load order.
	 *
	 * @return array<int, class-string<Module>>
	 */
	public static function module_classes(): array {
		return array(
			\Memberkin\Modules\Foundation\FoundationModule::class,
			\Memberkin\Modules\Levels\LevelsModule::class,
			\Memberkin\Modules\Memberships\MembershipsModule::class,
			\Memberkin\Modules\Coupons\CouponsModule::class,
			\Memberkin\Modules\Billing\BillingModule::class,
			\Memberkin\Modules\Access\AccessModule::class,
			\Memberkin\Modules\Emails\EmailsModule::class,
			\Memberkin\Frontend\FrontendModule::class,
		);
	}

	/**
	 * Boots the plugin: registers modules, runs pending migrations, wires enabled modules.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		/**
		 * Filters the module classes to load. Third parties may append their own Module subclasses.
		 *
		 * @param array<int, class-string<Module>> $classes Module classes.
		 */
		$classes = (array) apply_filters( 'memberkin_module_classes', self::module_classes() );
		foreach ( $classes as $class ) {
			if ( is_string( $class ) && is_subclass_of( $class, Module::class ) ) {
				$this->modules->add( new $class() );
			}
		}

		Installer::maybe_upgrade( $this );

		foreach ( $this->modules->enabled() as $module ) {
			$module->register( $this );
			/**
			 * Fires after a module registered its hooks.
			 *
			 * @param Module $module Module.
			 */
			do_action( 'memberkin_module_registered', $module );
		}

		/**
		 * Fires once Memberkin is fully loaded.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'memberkin_loaded', $this );
	}

	/**
	 * Service container.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Module registry.
	 */
	public function modules(): ModuleRegistry {
		return $this->modules;
	}

	/**
	 * Shortcut for container()->get().
	 *
	 * @template T
	 * @param class-string<T>|string $id Service id.
	 * @return T|mixed
	 */
	public function get( string $id ): mixed {
		return $this->container->get( $id );
	}
}
