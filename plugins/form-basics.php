<?php

register_plugin( 'form-basics', 'FBK_Form_Basics' );

/**
 * A set of handlers to parse basic form elements (input, select, textarea).
 */
class FBK_Form_Basics extends FBK_Handler_Plugin {

	public static $version = '1.3';

	protected $default_inst_key = false;
	protected $default_parse_key = 'form';

	protected $in_form = false;
	protected $in_select = false;
	protected $in_textarea = false;

	function get_handlers() {
		return array(
			array( 'start_el', 'form', 'form' ),
			array( 'end_el', 'form', 'form' ),
			array( 'start_el', 'input', 'input' ),
			array( 'start_el', 'select', 'select' ),
			array( 'end_el', 'select', 'select' ),
			array( 'start_el', 'option', 'option' ),
			array( 'cdata', 'option', 'option_label' ),
			array( 'start_el', 'textarea', 'textarea' ),
			array( 'end_el', 'textarea', 'textarea' ),
			array( 'cdata', 'textarea', 'textarea_cdata' )
		);
	}

	function form( &$parser, $element, $where ) {
		if ( 'start_el' == $where ) {
			if ( $this->in_form ) {
				trigger_error( 'Nested forms are not supported by ' . get_class($this) . '!', E_USER_WARNING );
				$this->in_form = false;
			} else {
				$this->in_form = true;
			}
		} else {
			$this->in_form = false;
		}
	}

	function input( &$parser, $element ) {
		if ( ! $this->in_form || isset($element['attrib']['disabled']) || empty($element['attrib']['name']) )
			return;

		$name_original = $element['attrib']['name'];
		$name = $this->sanitize_name( $name_original );

		$data = array(
			'type' => isset($element['attrib']['type']) ? $element['attrib']['type'] : 'text',
			'required' => isset($element['attrib']['required']),
			'name_orig' => $name_original
		);
		switch ( $data['type'] ) {
			case 'text':
			case 'search':
			case 'tel':
			case 'url':
			case 'email':
			case 'password':
				if ( isset($element['attrib']['pattern']) )
					$data['pattern'] = $element['attrib']['pattern'];
			case 'datetime':
			case 'date':
			case 'month':
			case 'week':
			case 'time':
			case 'datetime-local':
			case 'number':
			case 'range':
			case 'color':
			case 'hidden':
			default:
				$data['default'] = isset($element['attrib']['value']) ? $element['attrib']['value'] : '';
				$default = htmlspecialchars( $data['default'], ENT_QUOTES );
				if ( $this->escape_data )
					$element['attrib']['value'] = "<?php echo isset({$this->inst_var}['$name']) ? htmlspecialchars({$this->inst_var}['$name']) : '$default'; ?>";
				else
					$element['attrib']['value'] = "<?php echo isset({$this->inst_var}['$name']) ? {$this->inst_var}['$name'] : '$default'; ?>";
				break;
			case 'checkbox':
			/* target structure (excl. defaults):
			array(
				multiple => true,
				options => array( $value => $label_or_value, ... ),
				default => array( $value, ... )
			) or array(
				multiple => false,
				default => boolean
			)
			*/
				if ( '[]' == substr( $name, -2 ) ) {
					$name_deref = substr( $name, 0, -2 );
					$data['name_orig'] = substr( $name_original, 0, -2 );
					if ( ! isset($parser->data[$this->parse_key][$name_deref]) ) {
						$data['multiple'] = true;
						$data['options'] = array();
						$data['default'] = array();
						$parser->data[$this->parse_key][$name_deref] = $data;
					}
					$parser->data[$this->parse_key][$name_deref]['options'][ $element['attrib']['value'] ]
					 = isset($element['attrib']['label']) ? $element['attrib']['label'] : $element['attrib']['value'];

					$var = "{$this->inst_var}['$name_deref']";
					$val_check = "in_array( '" . $element['attrib']['value'] . "', $var )";

					if ( isset($element['attrib']['checked']) ) {
						$parser->data[$this->parse_key][$name_deref]['default'][] = $element['attrib']['value'];
						unset( $element['attrib']['checked'] );
						$isset_check = "!isset($var) || !is_array($var) || ";
					} else {
						$isset_check = "isset($var) && is_array($var) && ";
					}

					$data = false;
				} else {
					$data['multiple'] = false;
					$data['default'] = isset($element['attrib']['checked']);
					if ( isset($element['attrib']['value'] ) )
						unset( $element['attrib']['value'] );

					$var = "{$this->inst_var}['$name']";

					if ( isset($element['attrib']['checked']) ) {
						$check = "{$this->inst_var}['__cb-$name']";
						$isset_check = "isset($var) || ";
						$val_check = "! isset($check)";
						$element['after_end_el'] = "<input type=\"hidden\" name=\"__cb-$name\" value=\"1\" />";
						unset( $element['attrib']['checked'] );
					} else {
						$isset_check = "isset($var)";
						$val_check = '';
					}
				}
				$element['attrib'][] = "<?php if ( $isset_check $val_check ) echo 'checked=\"checked\"'; ?>";
				break;
			case 'radio':
			/* target structure (excl. defaults):
			array(
				options => array( $value => $label_or_value, ... ),
				default => $value
			)
			*/
				if ( ! isset($parser->data[$this->parse_key][$name]) ) {
					$data['options'] = array();
					$data['default'] = false;
					$parser->data[$this->parse_key][$name] = $data;
				}
				$parser->data[$this->parse_key][$name]['options'][ $element['attrib']['value'] ]
				 = isset($element['attrib']['label']) ? $element['attrib']['label'] : $element['attrib']['value'];

				$var = "{$this->inst_var}['$name']";

				if ( isset($element['attrib']['checked']) && false === $parser->data[$this->parse_key][$name]['default'] ) {
					$parser->data[$this->parse_key][$name]['default'] = $element['attrib']['value'];
					$element['attrib'][] = "<?php if ( !isset($var) || '{$element['attrib']['value']}'==$var ) echo 'checked=\"checked\"'; ?>";
					unset( $element['attrib']['checked'] );
				} else {
					$element['attrib'][] = "<?php if ( isset($var) && '{$element['attrib']['value']}'==$var ) echo 'checked=\"checked\"'; ?>";
				}

				$data = false;
				break;
			case 'file':
				$data['multiple'] = isset($element['attrib']['multiple']);
				break;
			case 'submit':
			case 'image':
			case 'reset':
			case 'button':
				return;
		}

		if ( $data )
			$parser->data[$this->parse_key][$name] = $data;

		$element['attrib']['name'] = $name;
		$element['form_key'] = isset($name_deref) ? $name_deref : $name;
		return $element;
	}

	function select( &$parser, $element, $where ) {
		if ( ! $this->in_form || empty($element['attrib']['name']) || isset($element['attrib']['disabled']) )
			return;
		if ( 'start_el' == $where ) {
			$name_original = $element['attrib']['name'];
			$name = $this->sanitize_name( $name_original );
			$this->in_select = $name;
			$parser->data[$this->parse_key][$name] = array(
				'type' => 'select',
				'default' => isset($element['attrib']['multiple']) ? array() : false,
				'multiple' => isset($element['attrib']['multiple']),
				'options' => array(),
				'name_orig' => $name_original
			);
			$element['attrib']['name'] = $name;
			$element['form_key'] = $name;
			return $element;
		} else {
			$this->in_select = false;
		}
	}

	function option( &$parser, $element ) {
		if ( ! $this->in_select || ! isset($element['attrib']['value']) )
			return;
		$key = htmlspecialchars_decode($element['attrib']['value']);
		$parser->data[$this->parse_key][$this->in_select]['options'][$key] = $key;

		$var = "{$this->inst_var}['$this->in_select']";
		if ( $parser->data[$this->parse_key][$this->in_select]['multiple'] ) {
			if ( isset($element['attrib']['selected']) ) {
				$isset_check = "!isset($var) || !is_array($var) ||";
				unset( $element['attrib']['selected'] );
				$parser->data[$this->parse_key][$this->in_select]['default'][] = $key;
			} else {
				$isset_check = "isset($var) && is_array($var) &&";
			}
			$value_check = "in_array('$key',$var)";
		} else {
			if ( isset($element['attrib']['selected']) ) {
				$isset_check = "!isset($var) ||";
				unset( $element['attrib']['selected'] );
				if ( false === $parser->data[$this->parse_key][$this->in_select]['default'] )
					$parser->data[$this->parse_key][$this->in_select]['default'] = $key;
			} else {
				$isset_check = "isset($var) &&";
			}
			$value_check = "'$key'==$var";
		}
		$element['attrib'][] = "<?php if ( $isset_check $value_check ) echo 'selected=\"selected\"'; ?>";

		return $element;
	}

	function option_label( &$parser, $element, $cdata ) {
		if ( ! $this->in_select || ! isset($element['attrib']['value']) )
			return;
		$label = isset( $element['attrib']['label'] ) ? $element['attrib']['label'] : trim($cdata);
		$key = htmlspecialchars_decode($element['attrib']['value']);
		$parser->data[$this->parse_key][$this->in_select]['options'][$key] = $label;
	}

	function textarea( &$parser, $element, $where ) {
		if ( ! $this->in_form || empty($element['attrib']['name']) || isset($element['attrib']['disabled']) )
			return;

		$name_original = $element['attrib']['name'];
		$name = $this->sanitize_name( $name_original );

		if ( 'end_el' == $where ) {
			if ( $parser->data[$this->parse_key][$name]['default'] )
				$default = htmlspecialchars( $parser->data[$this->parse_key][$name]['default'], ENT_QUOTES );
			else
				$default = '';

			$var = "{$this->inst_var}['$this->in_textarea']";
			if ( $this->escape_data )
				$element['before_end_el'] = "<?php echo isset($var) ? htmlspecialchars($var) : '$default'; ?>";
			else
				$element['before_end_el'] = "<?php echo isset($var) ? $var : '$default'; ?>";

			$this->in_textarea = false;
			return $element;
		} else {
			$this->in_textarea = $name;
		}

		$parser->data[$this->parse_key][$name] = array(
			'type' => 'textarea',
			'default' => '',
			'required' => isset($element['attrib']['required']),
			'name_orig' => $name_original
		);
		if ( isset($element['attrib']['maxlength']) )
			$parser->data[$this->parse_key][$name]['maxlength'] = (int) $element['attrib']['maxlength'];

		$element['attrib']['name'] = $name;
		$element['form_key'] = $name;
		return $element;
	}

	function textarea_cdata( &$parser, $element, $cdata ) {
		if ( ! $this->in_textarea )
			return;

		$parser->data[$this->parse_key][$name]['default'] = htmlspecialchars_decode( $cdata, ENT_QUOTES );
		return '';
	}

	function sanitize_name( $string ) {
		return preg_replace( '/[^\\[\\]a-zA-Z0-9_:-]/', '_', $string );
	}
}
?>