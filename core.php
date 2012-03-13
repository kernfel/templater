<?php

require_once( 'parser.php' );
require_once( 'templater.php' );
require_once( 'plugin.php' );

$dir = dirname(__FILE__);
$plugindir = $dir . '/plugins';
if ( is_dir( $plugindir ) ) {
	foreach ( scandir( $plugindir ) as $plugin ) {
		if ( '.php' == substr($plugin, -4) ) {
			include_once( $plugindir . '/' . $plugin );
		}
	}
}

function load( $file, $features = array(), $data = array(), $die_when_done = true ) {

	$registered_plugins_NYI = array(
		'form' => 'FBK_Form_Basics'
	);

	$templater = new FBK_Templater( $file );
	$plugins = array();
	foreach ( $features as $key => $value ) {
		if ( is_int($key) && array_key_exists( $value, $registered_plugins_NYI ) ) {
			$plugins[] = new $registered_plugins_NYI[$value]( $templater );
		} elseif ( is_string($key) && array_key_exists( $key, $registered_plugins_NYI ) ) {
			$plugins[] = new $registered_plugins_NYI[$key]( $templater, $value );
		}
	}

	// Testing purposes only
	$templater->parse( true );

	$templater->instantiate( $data );

	if ( $die_when_done )
		die;
}
?>