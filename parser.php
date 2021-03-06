<?php

class FBK_Parser {

	public $handlers;
	public $templater;

	public $data = array();
	public $parents = array();

	protected $waiting_for_tag_end = false;
	protected $mute = false;

	protected $void_elements;

	protected $target, $header;

	protected $header_insertions = array();

	public function __construct( &$templater, $source, $target, $header, $handlers ) {
		$this->templater = $templater;
		$this->handlers = $handlers;
		$this->void_elements = $this->get_void_elements();

		$this->parse( $source, $target );

		$header = fopen( $header, 'wb' );
		ksort( $this->header_insertions );
		foreach ( $this->header_insertions as $inserts )
			foreach ( $inserts as $insert )
				fwrite( $header, $insert );
		fclose( $header );
	}

	public function add_header( $content, $priority = 0 ) {
		$this->header_insertions[$priority][] = $content;
	}

	protected function parse( $source, $target ) {
		$tpl = fopen( $source, 'rb' );
		$this->target = fopen( $target, 'wb' );

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
						fwrite( $this->target, '<!' . $c );
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
						while ( null !== $end = array_pop($elements) ) {
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
								while ( preg_match( '/[a-zA-Z0-9_-]/', $c = fgetc($tpl) ) )
									$key .= $c;
								$attrib[$key] = '';
								if ( '=' == $c ) {
									$quote = fgetc($tpl);
									while ( $quote != $c = fgetc($tpl) )
										$attrib[$key] .= $c;
								} elseif ( '>' == $c ) {
									// empty attribute just before the closing >
									break;
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
						fwrite( $this->target, $c );
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
						fwrite( $this->target, $c );
						$bucket = '';
					}
				}
			} elseif ( $passthru ) {
				$bucket .= $c;
				fwrite( $this->target, $c );
			} else {
				$bucket .= $c;
			}
		}

		fclose( $tpl );
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
			'suppress_nested' => false,
		);
		$override = array(
			'dynamic_handlers' => array(
				'cdata' => array(),
				'end_el' => array()
			)
		);
		$el = array_merge( $el, $default, $resp, $override );

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
						if ( isset( $resp['add_handler'] ) ) {
							foreach ( $resp['add_handler'] as $type => $handler )
								if ( array_key_exists( $type, $el['dynamic_handlers'] ) )
									$el['dynamic_handlers'][$type][] = $handler;
							unset( $resp['add_handler'] );
						}
						unset( $resp['dynamic_handlers'] );
						unset( $resp['attrib'] );
						$el = array_merge( $el, $resp );
					}
				}
			}
		}

		fwrite( $this->target, $el['before_start_el'] );

		if ( ! $el['suppress_tags'] ) {
			$str = "<$el[name]";
			foreach ( $el['attrib'] as $key => $value ) {
				if ( is_string( $key ) )
					$str .= " $key=\"$value\"";
				else
					$str .= " $value";
			}
			$this->waiting_for_tag_end = true;
			fwrite( $this->target, $str );
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

		// Call dynamic handler
		foreach ( $el['dynamic_handlers']['end_el'] as $dyn_handler ) {
			if ( is_callable( $dyn_handler ) ) {
				$resp = call_user_func_array( $dyn_handler, array( &$this, $el, 'end_el' ) );
				if ( is_array( $resp ) ) {
					if ( isset( $resp['before_end_el'] ) )
						$el['before_end_el'] = $resp['before_end_el'];
					if ( isset( $resp['after_end_el'] ) )
						$el['after_end_el'] = $resp['after_end_el'];
				}
			}
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
				$str = " />";
				$closed = true;
			} else {
				$str = ">";
				$closed = false;
			}
				
			$str .= $el['after_start_el'] . $el['before_end_el'];

			if ( ! $closed )
				$str .= "</$el[name]>";

			$this->waiting_for_tag_end = false;
			fwrite( $this->target, $str );
		} else {
			$str = $el['before_end_el'];
			if ( empty( $el['suppress_tags'] ) )
				$str .= "</$el[name]>";
			fwrite( $this->target, $str );
		}

		fwrite( $this->target, $el['after_end_el'] );
	}

	protected function cdata( $cdata ) {
		if ( $this->mute )
			return;

		$this->close_nonvoid_start_tag();

		$parent = $this->get_current_element();

		// Call dynamic handler
		if ( $parent ) {
			foreach ( $parent['dynamic_handlers']['cdata'] as $dyn_handler ) {
				if ( is_callable( $dyn_handler ) ) {
					$str = call_user_func_array( $h, array( &$this, $parent, $cdata ) );
					if ( is_string( $str ) ) {
						$cdata = $str;
					} elseif ( is_array( $str ) && isset($str['content']) ) {
						if ( ! isset($str['position']) ) {
							$cdata = $str['content'];
						} elseif ( is_int($str['position']) ) {
							$cdata = substr_replace( $cdata, $str['content'], $str['position'], 0 );
						} elseif ( 'before' == $str['position'] ) {
							$cdata = $str['content'] . $cdata;
						} else {
							$cdata .= $str['content'];
						}
					}
				}
			}
		}

		// Call cdata handler
		$trigger = strtolower( $parent['name'] );
		if ( isset( $this->handlers['cdata'][$trigger] ) && $h = $this->handlers['cdata'][$trigger]['handler'] )
			$str = call_user_func_array( $h, array( &$this, $parent, $cdata ) );
		else
			$str = false;

		if ( is_string( $str ) ) {
			$out = $str;
		} elseif ( is_array( $str ) && isset($str['content']) ) {
			if ( ! isset($str['position']) ) {
				$out = $str['content'];
			} elseif ( is_int($str['position']) ) {
				$out = substr_replace( $cdata, $str['content'], $str['position'], 0 );
			} elseif ( 'before' == $str['position'] ) {
				$out = $str['content'] . $cdata;
			} else {
				$out = $cdata . $str['content'];
			}
		} else {
			$out = $cdata;
		}
		fwrite( $this->target, $out );
	}

	protected function close_nonvoid_start_tag() {
		if ( ! $this->waiting_for_tag_end || ! ( $parent = $this->get_current_element() ) )
			return;
		fwrite( $this->target, ">" . $parent['after_start_el'] );
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