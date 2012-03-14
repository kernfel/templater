<?php

class FBK_Parser {

	public $handlers;

	public $data = array();
	public $parents = array();

	protected $waiting_for_tag_end = false;
	protected $mute = false;

	protected $void_elements;

	protected $target, $header;

	public function __construct( $source, $target, $header, $handlers ) {
		$this->handlers = $handlers;
		$this->void_elements = $this->get_void_elements();

		$this->header = fopen( $header, 'wb' );

		$this->parse( $source, $target );

		fclose( $this->header );
	}

	public function add_header( $content ) {
		fwrite( $this->header, $content );
	}

	protected function parse( $source, $target ) {
		$tpl = fopen( $source, 'rb' );

		$this->target = fopen( $target, 'wb' );
		ob_start( array( &$this, 'write' ), 4096 );

		$ws = array( " ", "\t", "\r", "\n" );

		$next = '<';
		$passthru = true;
		$bucket = '';
		$state = false;
		$elements = array();

		while ( false !== $c = fgetc($tpl) ) {
			if ( $c == $next ) {
				if ( '<' == $c ) {
					if ( ! $state && $bucket ) {
						$this->cdata( $bucket );
					}

					$c = fgetc($tpl);
					if ( '!' == $c ) {
						$c = fgetc($tpl) . fgetc($tpl);
						if ( '--' == $c )
							$state = 'comment';
						else
							$state = 'DTD';
						$passthru = true;
						$next = '>';
						$bucket = '';
						echo '<!' . $c;
					} elseif ( '?' == $c ) {
						$passthru = false;
						$next = '>';
						$state = 'PI';
						$bucket = '';
					} elseif ( '/' == $c ) {
						$name = '';
						while ( preg_match( '/[a-zA-Z0-9_:]/', $c = fgetc($tpl) ) )
							$name .= $c;
						if ( '>' != $c )
							while ( '>' != $c = fgetc($tpl) )
								;
						$name = strtolower( $name );
						while ( false !== $end = array_pop($elements) ) {
							$this->end_el( $end );
							if ( strtolower($end) == $name )
								break;
						}

						$passthru = false;
						$next = '<';
						$state = false;
						$bucket = '';
					} else {
						$name = $c;
						$attrib = array();
						$void = false;
						while ( preg_match( '/[a-zA-Z0-9_:]/', $c = fgetc($tpl) ) )
							$name .= $c;
						while ( '>' != $c ) {
							if ( '/' == $c ) {
								$void = true;
							} elseif ( ! in_array( $c, $ws ) ) {
								$key = $c;
								while ( preg_match( '/[a-zA-Z0-9_]/', $c = fgetc($tpl) ) )
									$key .= $c;
								$attrib[$key] = '';
								if ( '=' == $c ) {
									$quote = fgetc($tpl);
									while ( $quote != $c = fgetc($tpl) )
										$attrib[$key] .= $c;
								}
							}
							$c = fgetc($tpl);
						}

						$this->start_el( $name, $attrib );

						if ( $void || in_array( strtolower($name), $this->void_elements ) ) {
							$this->end_el( $name );
						} else {
							$state = false;
							$elements[] = $name;
						}

						$passthru = false;
						$next = '<';
						$bucket = '';
					}
				} elseif ( '>' == $c ) {
					if ( 'DTD' == $state ) {
						$passthru = false;
						$next = '<';
						$state = false;
						$bucket = '';
						echo $c;
					} elseif ( 'PI' == $state ) {
						if ( '?' == substr( $bucket, -1 ) ) {
							$next = '<';
							$state = false;
						}
						$bucket = '';
					} elseif ( 'comment' == $state ) {
						if ( '--' == substr( $bucket, -2 ) ) {
							$passthru = false;
							$next = '<';
							$state = false;
						}
						echo $c;
						$bucket = '';
					}
				}
			} elseif ( $passthru ) {
				$bucket .= $c;
				echo $c;
			} else {
				$bucket .= $c;
			}
		}

		fclose( $tpl );
		ob_end_flush();
		fclose( $this->target );
	}

	protected function get_void_elements() {
		return array(
			'area',
			'base',
			'br',
			'col',
			'command',
			'embed',
			'hr',
			'img',
			'input',
			'keygen',
			'link',
			'meta',
			'param',
			'source',
			'track',
			'wbr'
		);
	}

	function write( $buffer ) {
		fwrite( $this->target, $buffer );
		return '';
	}

	protected function start_el( $name, $attrib = array() ) {
		$this->close_nonvoid_start_tag();

		$el = compact( 'name', 'attrib' );

		if ( $this->mute ) {
			$parents[] = $el;
			return;
		}

		// Call element handler
		$trigger = strtolower( $name );
		if ( isset( $this->handlers['start_el'][$trigger] ) && $h = $this->handlers['start_el'][$trigger]['handler'] ) {
			$resp = call_user_func_array( $h, array( &$this, $el, 'start_el' ) );
			if ( ! is_array( $resp ) )
				$resp = array();
		} else {
			$resp = array();
		}
		$default = array(
			'before_start_el' => '',
			'after_start_el' => '',
			'before_end_el' => '',
			'after_end_el' => '',
			'suppress_tags' => false,
			'suppress_nested' => false
		);
		$el = array_merge( $el, $default, $resp );

		// Call attribute handlers
		if ( ! $el['suppress_tags'] ) {
			$attr = $el['attrib'];
			foreach ( $attr as $attrib_key => $attrib_value ) {
				$trigger = strtolower( $attrib_key );
				if ( isset( $this->handlers['attribute'][$trigger] ) && $h = $this->handlers['attribute'][$trigger]['handler'] ) {
					$resp = call_user_func_array( $h, array( &$this, $el, $attrib_value, $attrib_key ) );
					if ( is_array( $resp ) ) {
						if ( isset( $resp['remove_attrib'] ) ) {
							if ( is_array( $resp['remove_attrib'] ) ) {
								foreach ( $resp['remove_attrib'] as $key )
									if ( isset($el['attrib'][$key]) )
										unset( $el['attrib'][$key] );
							} elseif ( isset($el['attrib'][$resp['remove_attrib']]) ) {
								unset( $el['attrib'][$resp['remove_attrib']] );
							}
							unset( $resp['remove_attrib'] );
						}
						if ( isset( $resp['alter_attrib'] ) ) {
							foreach ( $resp['alter_attrib'] as $key => $val )
								$el['attrib'][$key] = $val;
							unset( $resp['alter_attrib'] );
						}
						if ( isset( $resp['add_attrib'] ) ) {
							foreach ( $resp['add_attrib'] as $key => $val )
								$el['attrib'][$key] = $val;
							unset( $resp['add_attrib'] );
						}
						unset( $resp['attrib'] );
						$el = array_merge( $el, $resp );
					}
				}
			}
		}

		echo $el['before_start_el'];

		if ( ! $el['suppress_tags'] ) {
			echo "<$el[name]";
			foreach ( $el['attrib'] as $key => $value ) {
				if ( is_string( $key ) )
					echo " $key=\"$value\"";
				else
					echo " $value";
			}
			$this->waiting_for_tag_end = true;
		}

		$this->parents[] = $el;

		if ( $el['suppress_nested'] )
			$this->mute = true;
	}

	protected function end_el( $name ) {
		$el = array_pop( $this->parents );

		if ( $this->mute ) {
			if ( empty($el['suppress_nested']) )
				return;
			else
				$this->mute = false;
		}

		// Call element handler
		$trigger = strtolower( $name );
		if ( isset( $this->handlers['end_el'][$trigger] ) && $h = $this->handlers['end_el'][$trigger]['handler'] ) {
			$resp = call_user_func_array( $h, array( &$this, $el, 'end_el' ) );
			if ( is_array( $resp ) ) {
				if ( isset( $resp['before_end_el'] ) )
					$el['before_end_el'] = $resp['before_end_el'];
				if ( isset( $resp['after_end_el'] ) )
					$el['after_end_el'] = $resp['after_end_el'];
			}
		}

		if ( $this->waiting_for_tag_end ) {
			if ( in_array( $el['name'], $this->void_elements ) ) {
				echo " />";
				$closed = true;
			} else {
				echo ">";
				$closed = false;
			}
				
			echo $el['after_start_el'] . $el['before_end_el'];

			if ( ! $closed )
				echo "</$el[name]>";

			$this->waiting_for_tag_end = false;
		} else {
			echo $el['before_end_el'];
			if ( empty( $el['suppress_tags'] ) )
				echo "</$el[name]>";
		}

		echo $el['after_end_el'];
	}

	protected function cdata( $cdata ) {
		if ( $this->mute )
			return;

		$this->close_nonvoid_start_tag();

		// Call cdata handler
		$parent = $this->get_current_element();
		$trigger = strtolower( $parent['name'] );
		if ( isset( $this->handlers['cdata'][$trigger] ) && $h = $this->handlers['cdata'][$trigger]['handler'] )
			$str = call_user_func_array( $h, array( &$this, $parent, $cdata ) );
		else
			$str = false;

		if ( is_string( $str ) ) {
			echo $str;
		} elseif ( is_array( $str ) && isset($str['content']) ) {
			if ( ! isset($str['position']) ) {
				echo $str['content'];
			} elseif ( is_int($str['position']) ) {
				echo substr_replace( $cdata, $str['content'], $str['position'], 0 );
			} elseif ( 'before' == $str['position'] ) {
				echo $str['content'] . $cdata;
			} else {
				echo $cdata . $str['content'];
			}
		} else {
			echo $cdata;
		}
	}

	protected function close_nonvoid_start_tag() {
		if ( ! $this->waiting_for_tag_end || ! ( $parent = $this->get_current_element() ) )
			return;
		echo ">" . $parent['after_start_el'];
		$this->waiting_for_tag_end = false;
	}

	protected function get_current_element() {
		if ( ! $this->parents )
			return false;
		$parent_keys = array_keys( $this->parents );
		$current_key = array_pop( $parent_keys );
		return $this->parents[$current_key];
	}
}
?>