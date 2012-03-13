<?php
abstract class FBK_Handler_Plugin {
	public $version = '__default__';

	public $escape_data;
	public $inst_var;
	public $struct_var;
	public $parse_key;

	protected $default_inst_key = '__default__';
	protected $default_parse_key = '__default__';

	/**
	 * Setup handlers to parse basic form elements (input, select, textarea).
	 * @arg &$templater          Reference to FBK_Templater instance
	 * @arg $escape_data         Boolean, whether or not instantiation data should be escaped via htmlspecialchars()
	 * @arg $instantiation_key   String, the index of the $data variable at which the instantiation data is held.
	 *                            Pass false to denote top-level access, i.e. form data will be in $data[$field_name].
	 * @arg $parse_data_key      String, the index of the parser's $data property into which parsed information should be placed.
	 */
	public function __construct( &$templater, $args = array() ) {
		$defaults = array(
			'instantiation_key' => $this->default_inst_key,
			'parse_data_key' => $this->default_parse_key,
			'escape_data' => true
		);
		extract( array_merge( $defaults, $args ), EXTR_SKIP );

		$this->escape_data = $escape_data;
		$this->set_inst_var( $instantiation_key );
		$this->parse_key = $parse_data_key;
		$this->set_struct_var();

		$handler_version = serialize( array( $this->version, $instantiation_key, $parse_data_key, $escape_data ) );
		foreach ( $this->get_handlers() as $handler ) {
			$templater->add_handler( $handler[0], $handler[1], array( &$this, $handler[2] ), $handler_version );
		}
	}

	/**
	 * Return a set of array( $handler_type, $handler_trigger, $function_name ), e.g.
	 * array(
	 *	array( 'start_el', 'div', 'myFunc' )
	 * )
	 * Note that the function name referenced should be a function of the plugin class, as it will be added as such in the constructor.
	 */
	abstract protected function get_handlers();

	protected function set_inst_var( $instantiation_key ) {
		if ( false == $instantiation_key )
			$this->inst_var = "\$data";
		else
			$this->inst_var = "\$data['$instantiation_key']";
	}

	protected function set_struct_var() {
		$this->struct_var = "\$struct['$this->parse_key']";
	}
}

/**
 * Register a plugin class or set of classes through this function.
 * Registered plugins are available for simple loading by feature name through load(), see core.php
 */
function register_plugin( $feature, $classname ) {
	global $fbk_registered_plugins;

	$classnames = (array) $classname;

	if ( ! array_key_exists( $feature, $fbk_registered_plugins ) ) {
		$fbk_registered_plugins[$feature] = $classnames;
	} else {
		$fbk_registered_plugins[$feature] = array_unique( array_merge( $fbk_registered_plugins[$feature], $classnames ) );
	}
}
?>