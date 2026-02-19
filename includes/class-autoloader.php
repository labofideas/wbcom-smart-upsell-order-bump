<?php
/**
 * Autoloader.
 *
 * @package WbcomSmartUpsellOrderBump
 */

namespace Wbcom\SmartUpsell;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Autoloader {
	/**
	 * Register autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Autoload classes.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function autoload( string $class_name ): void {
		$prefix = 'Wbcom\\SmartUpsell\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );

		if ( 1 === count( $parts ) ) {
			$file = 'class-' . strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $parts[0] ) ) . '.php';
			$path = WBCOM_SUO_PLUGIN_DIR . 'includes/' . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
			return;
		}

		$module_parts = array_map(
			static function ( string $part ): string {
				return strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $part ) );
			},
			array_slice( $parts, 0, -1 )
		);

		// Namespace already includes "Modules", and filesystem path is rooted at includes/modules.
		if ( ! empty( $module_parts ) && 'modules' === $module_parts[0] ) {
			array_shift( $module_parts );
		}

		$class_file   = 'class-' . strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', end( $parts ) ) ) . '.php';
		$subdir       = empty( $module_parts ) ? '' : implode( '/', $module_parts ) . '/';
		$path         = WBCOM_SUO_PLUGIN_DIR . 'includes/modules/' . $subdir . $class_file;

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}
