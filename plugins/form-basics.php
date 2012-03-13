<?php

register_plugin( 'form-basics', 'FBK_Form_Basics' );

/**
 * A set of handlers to parse basic form elements (input, select, textarea).
 */
class FBK_Form_Basics extends FBK_Handler_Plugin {

	public $version = '1';

	protected $default_inst_key = false;
	protected $default_parse_key = 'form';

	protected $in_form = false;
	protected $in_select = false;

	function get_handlers() {
		return array(
			array( 'start_el', 'form', 'form' ),
			array( 'end_el', 'form', 'form' ),
			array( 'start_el', 'input', 'input' ),
			array( 'start_el', 'select', 'select' ),
			array( 'end_el', 'select', 'select' ),
			array( 'start_el', 'option', 'option' ),
			array( 'cdata', 'option', 'option_label' ),
			array( 'cdata', 'textarea', 'textarea' )
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

		$data = array(
			'type' => isset($element['attrib']['type']) ? $element['attrib']['type'] : 'text',
			'required' => isset($element['attrib']['required'])
		);
		$name = $element['attrib']['name'];
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
				default => boolean,
				value => $value
			)
			*/
				if ( '[]' == substr( $name, -2 ) ) {
					$name = substr( $name, 0, -2 );
					if ( ! isset($parser->data[$this->parse_key][$name]) ) {
						$data['multiple'] = true;
						$data['options'] = array();
						$data['default'] = array();
						$parser->data[$this->parse_key][$name] = $data;
					}
					$parser->data[$this->parse_key][$name]['options'][ $element['attrib']['value'] ]
					 = isset($element['attrib']['label']) ? $element['attrib']['label'] : $element['attrib']['value'];

					$var = "{$this->inst_var}['$name']";
					$val_check = "in_array( '" . $element['attrib']['value'] . "', $var )";

					if ( isset($element['attrib']['checked']) ) {
						$parser->data[$this->parse_key][$name]['default'][] = $element['attrib']['value'];
						unset( $element['attrib']['checked'] );
						$isset_check = "!isset($var) || !is_array($var) || ";
					} else {
						$isset_check = "isset($var) && is_array($var) && ";
					}

					$data = false;
				} else {
					$data['multiple'] = false;
					$data['default'] = isset($element['attrib']['checked']);
					$data['value'] = $element['attrib']['value'];

					$var = "{$this->inst_var}['$name']";
					$val_check = "'$data[value]'==$var";

					if ( isset($element['attrib']['checked']) ) {
						$data['default'] = true;
						$isset_check = "!isset($var) || ";
						unset( $element['attrib']['checked'] );
					} else {
						$isset_check = "isset($var) && ";
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

		return $element;
	}

	function select( &$parser, $element, $where ) {
		if ( ! $this->in_form || empty($element['attrib']['name']) || isset($element['attrib']['disabled']) )
			return;
		if ( 'start_el' == $where ) {
			$this->in_select = $element['attrib']['name'];
			$parser->data[$this->parse_key][ $element['attrib']['name'] ] = array(
				'type' => 'select',
				'default' => isset($element['attrib']['multiple']) ? array() : false,
				'multiple' => isset($element['attrib']['multiple']),
				'options' => array()
			);
		} else {
			$this->in_select = false;
		}
	}

	function option( &$parser, $element ) {
		if ( ! $this->in_select || ! isset($element['attrib']['value']) )
			return;
		$key = $element['attrib']['value'];
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
		$key = $element['attrib']['value'];
		$parser->data[$this->parse_key][$this->in_select]['options'][$key] = $label;
	}

	function textarea( &$parser, $element, $cdata ) {
		if ( ! $this->in_form || ! $element['attrib']['name'] || isset($element['attrib']['disabled']) )
			return;
		$parser->data[$this->parse_key][ $element['attrib']['name'] ] = array(
			'type' => 'textarea',
			'default' => $cdata,
			'required' => isset($element['attrib']['required'])
		);
		if ( isset($element['attrib']['maxlength']) )
			$parser->data[$this->parse_key][ $element['attrib']['name'] ]['maxlength'] = (int) $element['attrib']['maxlength'];

		$cdata_escaped = htmlspecialchars( $cdata, ENT_QUOTES );
		$var = "{$this->inst_var}['" . $element['attrib']['name'] . "']";
		if ( $this->escape_data )
			return "<?php echo isset($var) ? htmlspecialchars($var) : '$cdata_escaped'; ?>";
		else
			return "<?php echo isset($var) ? $var : '$cdata_escaped'; ?>";
	}
}
?>