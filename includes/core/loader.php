<?php
/**
 * Smarfle core loader.
 *
 * Every Smarfle plugin ships its own copy of this shared core (connection, weekly business info, fallbacks, the
 * Smarfle admin page). When several Smarfle plugins are active, each registers its copy and only the newest one
 * loads, so the site keeps one connection and one weekly refresh whatever the number of Smarfle plugins.
 *
 * @package Smarfle
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'smarfle_core_register' ) ) {
	/**
	 * Registers one bundled copy of the core.
	 *
	 * @param string $version Core version of this copy.
	 * @param string $dir     Folder of this copy (no trailing slash).
	 * @param string $url     URL of this copy (no trailing slash).
	 */
	function smarfle_core_register( $version, $dir, $url ) {
		global $smarfle_core_copies;
		if ( ! is_array( $smarfle_core_copies ) ) {
			$smarfle_core_copies = array();
			add_action( 'plugins_loaded', 'smarfle_core_load', -100 );
		}
		$smarfle_core_copies[ $dir ] = array(
			'version' => (string) $version,
			'url'     => (string) $url,
		);
	}

	/**
	 * Loads the newest registered copy of the core, once.
	 */
	function smarfle_core_load() {
		global $smarfle_core_copies;
		if ( class_exists( 'Smarfle_Core', false ) || empty( $smarfle_core_copies ) ) {
			return;
		}
		$best = '';
		foreach ( $smarfle_core_copies as $dir => $copy ) {
			if ( '' === $best || version_compare( $copy['version'], $smarfle_core_copies[ $best ]['version'], '>' ) ) {
				$best = $dir;
			}
		}
		require_once $best . '/class-smarfle-core.php';
		Smarfle_Core::boot( $smarfle_core_copies[ $best ]['version'], $best, $smarfle_core_copies[ $best ]['url'] );
	}
}
