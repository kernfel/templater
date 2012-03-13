<?php

require_once( 'parser.php' );
require_once( 'templater.php' );
require_once( 'plugin.php' );

$dir = dirname(__FILE__);
$plugindir = $dir . '/plugins';
$fbk_registered_plugins = array();
if ( is_dir( $plugindir ) ) {
	foreach ( scandir( $plugindir ) as $plugin ) {
		if ( '.php' == substr($plugin, -4) ) {
			include_once( $plugindir . '/' . $plugin );
		}
	}
}

/**
 * Load and instantiate a template file. This function allows a template to process itself using just two lines of PHP code in the file header!
 *
 * @arg $file          string   Path to the template file
 * @arg $features      array    Feature sets to be included in the process. Pass features in either of the following ways (or mix at will):
 *                              - Numerically indexed strings (i.e. array( 'feature' ) ) to use default arguments for the feature plugins, or
 *                              - associative arrays (i.e. array( 'feature' => $args ) ) to pass explicit arguments to the feature plugins.
 * @arg $data          array    The data with which the template is to be instantiated
 * @arg $die_when_done boolean  True to end processing after the instantiation is complete
 */
function load( $file, $features = array(), $data = array(), $die_when_done = true ) {
	global $fbk_registered_plugins;

	$templater = new FBK_Templater( $file );

	$plugins = array();
	foreach ( $features as $key => $value ) {
		if ( is_int($key) && array_key_exists( $value, $fbk_registered_plugins ) ) {
			foreach ( $fbk_registered_plugins[$value] as $plugin )
				$plugins[] = new $plugin( $templater );
		} elseif ( is_string($key) && array_key_exists( $key, $fbk_registered_plugins ) ) {
			foreach ( $fbk_registered_plugins[$key] as $plugin )
				$plugins[] = new $plugin( $templater, $value );
		}
	}

	$templater->instantiate( $data );

	if ( $die_when_done )
		die;
}
?>