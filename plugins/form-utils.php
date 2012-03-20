<?php

require_once( 'form-basics.php' );
register_plugin( 'form-extended', 'FBK_Form_Utils' );

class FBK_Form_Utils extends FBK_Form_Basics {
	public $version = '1b15';

	protected $in_mail = false, $mail_body, $attachments, $insertions;

	protected $recaptcha_privatekey = false;

	protected $pages = array(), $on_page = false;

	function get_handlers() {
		return array_merge( parent::get_handlers(), array(
			array( 'start_el', 'output', 'output' ),
			array( 'start_el', 'csv', 'csv' ),
			array( 'start_el', 'mail', 'mail_start_el' ),
			array( 'cdata', 'mail', 'mail_cdata' ),
			array( 'end_el', 'mail', 'mail_end_el' ),
			array( 'start_el', 'recaptcha', 'recaptcha' ),
			array( 'attribute', 'validate', 'validate' ),
			array( 'attribute', 'ifnotvalid', 'ifnotvalid' ),
			array( 'attribute', 'carry', 'carry' ),
			array( 'attribute', 'page', 'page_start' ),
			array( 'attribute', 'pageorder', 'page_order' ),
			array( 'attribute', 'topage', 'topage' ),
			array( 'attribute', 'spinoff', 'spinoff_attribute' ),
			array( 'start_el', 'spinoff', 'spinoff_element' ),
			array( 'attribute', 'temporal', 'temporal' )
		));
	}

	/**
	 * Output processed data. The <output> element and all nested content will be replaced by the designated value.
	 * The following sets of attributes are supported:
	 *
	 * name: Specifies a form field to output.
	 *  - type (optional): 'plain' | 'translated', how to output the data. If set to 'translated', will check the structure variable for any available
	 *	translations, such as select labels etc. Defaults to 'translated'.
	 *  - sep (optional): string to be used as a separator if the data point is an array. Defaults to ', '.
	 *  - before (optional): string to be inserted before the output if the data point is an array. Defaults to ''.
	 *  - after (optional): As above, but after the output.
	 *
	 * date (required): Output current date, formatted according to the attribute value (see php.net/date). Defaults to 'd.m.Y' if left empty.
	 */
	function output( &$parser, $element ) {
		$element['suppress_tags'] = true;
		$element['suppress_nested'] = true;

		if ( isset($element['attrib']['name']) ) {
			$key = $this->sanitize_name( $element['attrib']['name'] );
			$escape = ( $this->escape_data && ! $this->in_mail ) ? 'true' : 'false';

			$sep = isset($element['attrib']['sep']) ? addcslashes(htmlspecialchars_decode($element['attrib']['sep']),"'\\") : ', ';
			$before = isset($element['attrib']['before']) ? addcslashes(htmlspecialchars_decode($element['attrib']['before']),"'\\") : '';
			$after = isset($element['attrib']['after']) ? addcslashes(htmlspecialchars_decode($element['attrib']['after']),"'\\") : '';

			if ( isset($element['attrib']['type']) && 'plain' == $element['attrib']['type'] ) {
				if ( $this->escape_data && ! $this->in_mail )
					$base_output = "isset({$this->inst_var}['$key']) ? htmlspecialchars({$this->inst_var}['$key']) : ''";
				else
					$base_output = "isset({$this->inst_var}['$key']) ? {$this->inst_var}['$key'] : ''";
			} else {
				$class = get_class();
				if ( $this->on_page ) {
					$parse_key = $this->parse_key_backup;
				} else {
					$parse_key = $this->parse_key;
				}
				$base_output = "$class::translate( '$key', $this->inst_var, \$templater, '$parse_key', '$sep', '$before', '$after', $escape )";
			}
		} elseif ( isset($element['attrib']['date']) ) {
			$format = $element['attrib']['date'] ? addcslashes(htmlspecialchars_decode($element['attrib']['date']),"'\\") : 'd.m.Y';
			$base_output = "date('$format')";
		}

		if ( $this->in_mail )
			$this->mail_insert_code( $base_output );
		else
			$element['before_start_el'] = "<?php echo $base_output; ?>";

		return $element;
	}

	static public function translate( $key, &$data, &$templater, $parse_key, $sep, $before, $after, $escape ) {
		static $structs = array();
		if ( ! isset($structs[$parse_key]) ) {
			$structs[$parse_key] = $templater->data[$parse_key];
			$struct_keys = array();
			if ( ! empty($templater->data[$parse_key]['__pages']) )
				foreach ( $templater->data[$parse_key]['__pages'] as $pageid )
					$struct_keys[] = $parse_key . '_page_' . $pageid;
			foreach ( array_intersect_key( $templater->data, array_flip($struct_keys) ) as $_struct )
				$structs[$parse_key] = array_merge( $structs[$parse_key], $_struct );
		}
		$struct =& $structs[$parse_key];

		if ( ! isset($data[$key]) && ! isset($struct[$key]) )
			return;

		if ( ! isset($data[$key]) ) {
			if ( ! empty($struct[$key]['default']) )
				$dp = $struct[$key]['default'];
			else
				return '';
		} else {
			$dp = $data[$key];
		}

		$struct_is_multi = isset($struct[$key]) && ! empty($struct[$key]['multiple']) && ! empty($struct[$key]['options']);
		$dp_is_array = is_array($dp);

		if ( $dp_is_array && $struct_is_multi ) {
			$translation = array_intersect_key( $struct[$key]['options'], array_flip( $dp ) );
			return $before . implode( $sep, $translation ) . $after;
		} elseif ( $dp_is_array && ! $struct_is_multi ) {
			if ( $escape )
				$dp = array_map( 'htmlspecialchars', $dp );
			return $before . implode( $sep, $dp ) . $after;
		} elseif ( ! $dp_is_array && $struct_is_multi ) {
			return $before . ( isset($struct[$key]['options'][$dp]) ? $struct[$key]['options'][$dp] : $dp ) . $after;
		} elseif ( isset($struct[$key]) && ! empty($struct[$key]['options']) ) {
			return isset($struct[$key]['options'][$dp]) ? $struct[$key]['options'][$dp] : $dp;
		} else {
			return $escape ? htmlspecialchars($dp) : $dp;
		}
	}

	/**
	 * Print a CSV string with a header and a data line. If inside a <mail> element, will add the string as an attachment (default) or inline.
	 *
	 * @attrib multiseparator    String to use as a separator between multiselects and the like
	 * @attrib replaceseps       Characters used as separators in the escape attribute.
	 *                           The first character separates search/replace sequences from one another,
	 *                           while the second one separates the search term from the replacement term.
	 *                           Defaults to '$='.
	 * @attrib replace           Search/replace strings to allow escaping of certain character sequences.
	 *                           Defaults to '&quot=&quot;&quot;' (i.e. the replacement from " to "" as is CSV-standard).
	 * @attrib include           Which entries from the data array to include. Separate terms with a comma.
	 * @attrib exclude           Which entries from the data array to exclude. Separate terms with a comma.
	 *                           If neither include nor exclude are given, the full data array is included.
	 *                           Otherwise, exclude trumps include.
	 * @attrib inline            If inside a <mail> element, output into the mail message body. By default, this is disabled.
	 * @attrib filename          If inside a <mail> element, the name of the attachment to be generated. Defaults to 'file.csv'.
	 *                           You can insert data values as "$fieldname$". Encode literal '$' as "$$".
	 * @attrib charset           If set, converts output from input-charset to this.
	 * @attrib input-charset     Character set for data and field names. Defaults to UTF-8.
	 * @attrib add-data          Add arbitrary data, separated by add-data-sep.
	 *                           In values, you can insert data values as "$fieldname$". Encode literal '$' as "$$".
	 * @attrib add-data-sep      Separator characters for add-data, used in like kind to replaceseps. Defaults to ',='.
	 *                           Note that the '$' character is not available as a separator.
	 * @attrib special-replace   Like replace, only for specific fields. Formatted as 'fieldname:search=replace$...'. Separators :, = and $
	 *                           are customisable, see below.
	 * @attrib special-replace-sep  Separators for special replace. Defaults to '$=:'.
	 */
	function csv( &$parser, $element ) {
		$element['suppress_tags'] = true;
		$element['suppress_nested'] = true;

		$sep = isset($element['attrib']['multiseparator']) ? addcslashes(htmlspecialchars_decode($element['attrib']['multiseparator']),"'\\") : ',';

		if ( isset($element['attrib']['replace']) ) {
			$r = isset($element['attrib']['replaceseps']) ? htmlspecialchars_decode($element['attrib']['replaceseps']) : '$=';
			$replace_arr = array();
			foreach ( explode( $r[0], htmlspecialchars_decode($element['attrib']['replace']) ) as $snr ) {
				$snr = explode( $r[1], $snr );
				$replace_arr[] = "'" . addcslashes($snr[0],"'\\") . "'=>'"
				 . ( isset($snr[1]) ? addcslashes($snr[1],"'\\") : '' ) . "'";
			}
			$replace = 'array(' . implode( ',', $replace_arr ) . ')';
		} else {
			$replace = 'array(\'"\'=>\'""\')';
		}

		if ( isset($element['attrib']['special-replace']) ) {
			$r = isset($element['attrib']['special-replace-sep']) ? htmlspecialchars_decode($element['attrib']['special-replace-sep']) : '$=:';
			$replace_arr = array();
			foreach ( explode( $r[0], htmlspecialchars_decode($element['attrib']['special-replace']) ) as $snr ) {
				$snr = explode( $r[2], $snr );
				$fieldname = $this->sanitize_name( $snr[0] );
				$snr = explode( $r[1], $snr[1] );
				$replace_arr[] = "'". addcslashes($fieldname,"'\\") . "'=>array('" . addcslashes($snr[0],"'\\") . "'=>'"
				 . ( isset($snr[1]) ? addcslashes($snr[1],"'\\") : '' ) . "')";
			}
			$special_replace = 'array(' . implode( ',', $replace_arr ) . ')';
		} else {
			$special_replace = 'array()';
		}

		if ( isset($element['attrib']['include']) )
			$inc = "'" . implode( ',', array_map( array(&$this, 'sanitize_name'), explode( ',', $element['attrib']['include'] ) ) ) . "', true";
		elseif ( isset($element['attrib']['exclude']) )
			$inc = "'" . implode( ',', array_map( array(&$this, 'sanitize_name'), explode( ',', $element['attrib']['exclude'] ) ) ) . "', false";
		else
			$inc = "'', false";

		if ( isset($element['attrib']['charset']) ) {
			$charset = "'" . $element['attrib']['charset'] . "', ";
			if ( isset($element['attrib']['input-charset']) )
				$charset .= "'" . $element['attrib']['input-charset'] . "'";
			else
				$charset .= "'utf-8'";
		} else {
			$charset = "false,false";
		}

		if ( isset($element['attrib']['add-data']) ) {
			$r = isset($element['attrib']['add-data-sep']) ? htmlspecialchars_decode($element['attrib']['add-data-sep']) : ',=';
			$add_arr = array();
			foreach ( explode( $r[0], $element['attrib']['add-data'] ) as $add ) {
				$add = explode( $r[1], $add );
				$add_arr[] = "'" . addcslashes(htmlspecialchars_decode($add[0]),"'\\") . "'=>'"
				 . ( isset($add[1]) ? $this->parse_variable_string($add[1]) : '' ) . "'";
			}
			$add_data = 'array(' . implode( ',', $add_arr ) . ')';
		} else {
			$add_data = 'array()';
		}

		if ( $this->on_page )
			$parse_key = $this->parse_key_backup;
		else
			$parse_key = $this->parse_key;

		$class = get_class();

		$base_output = "$class::get_csv( $this->inst_var, \$templater, '$parse_key', '$sep', $replace, $special_replace, $inc, $charset, $add_data )";

		if ( $this->in_mail && empty($element['attrib']['inline']) ) {
			$filename = isset($element['attrib']['filename']) ? $this->parse_variable_string( $element['attrib']['filename'] ) : "file.csv";
			$this->attachments[$filename] = $base_output;
		} elseif ( $this->in_mail )
			$this->mail_insert_code( $base_output );
		elseif ( $this->escape_data )
			$element['before_start_el'] = "<?php echo htmlspecialchars($base_output); ?>";
		else
			$element['before_start_el'] = "<?php echo $base_output; ?>";

		return $element;
	}

	static public function get_csv( &$data, &$templater, $parse_key, $sep, $common_replace, $special_replace, $indices, $include, $charset, $input_charset, $add_data ) {
		$indices = explode( ',', $indices );
		if ( ! $include )
			$indices = array_diff( array_keys($data), $indices );

		$struct_keys = array($parse_key);
		if ( isset($templater->data[$parse_key]['__pages']) )
			foreach ( $templater->data[$parse_key]['__pages'] as $p )
				$struct_keys[] = $parse_key . '_page_' . $p;
		$struct = call_user_func_array( 'array_merge', array_intersect_key( $templater->data, array_flip($struct_keys) ) );
		$indices_out = array();
		foreach ( $indices as $i )
			$indices_out[] = isset($struct[$i]['name_orig']) ? $struct[$i]['name_orig'] : $i;

		$csv = '"' . implode( '","', $indices_out ) . '"';
		if ( $add_data )
			$csv .= ',"' . implode( '","', array_keys($add_data) ) . '"';
		$csv .= "\r\n";

		$out = array();
		foreach ( $indices as $index ) {
			if ( ! isset($data[$index]) ) {
				$out[] = '';
			} else {
				if ( isset($special_replace[$index]) )
					$replace = array_merge( $common_replace, $special_replace[$index] );
				else
					$replace = $common_replace;
				if ( is_array($data[$index]) ) {
					$d = array();
					foreach ( $data[$index] as $i )
						$d[] = str_replace( array_keys($replace), $replace, $i );
					$out[] = implode( $sep, $d );
				} else {
					$out[] = str_replace( array_keys($replace), $replace, $data[$index] );
				}
			}
		}

		$csv .= '"' . implode( '","', $out ) . '"';
		if ( $add_data )
			$csv .= ',"' . implode( '","', $add_data ) . '"';

		if ( $charset )
			$csv = iconv( $input_charset, $charset, $csv );

		return $csv;
	}

	protected function parse_variable_string( $str, $quote = "'", $escape_input = true ) {
		if ( $escape_input )
			$str = addcslashes( htmlspecialchars_decode( $str ), "'\\" );
		$p = 0;
		$in_var = false;
		$rep = $pos = $len = array();
		while ( false !== $p = strpos( $str, '$', $p+1 ) ) {
			if ( $in_var ) {
				$in_var = false;
				if ( $p - $pprev == 1 ) {
					$rep[] = '$';
					$pos[] = $pprev;
					$len[] = 2;
				} else {
					$varname = substr( $str, $pprev+1, $p-$pprev-1 );
					$rep[] = "$quote.(isset({$this->inst_var}['$varname'])?{$this->inst_var}['$varname']:'').$quote";
					$pos[] = $pprev;
					$len[] = strlen($varname) + 2;
				}
			} else {
				$in_var = true;
				$pprev = $p;
			}
		}
		$rep = array_reverse( $rep, true );
		foreach ( $rep as $i => $r )
			$str = substr_replace( $str, $r, $pos[$i], $len[$i] );

		return $str;
	}

	/**
	 * Send an email at runtime. The content nested within this element is interpreted as the message body and should consist
	 * only of plain text as well as elements defined within this class, such as <output> and <csv>. Other nested elements, including HTML comments,
	 * will not be processed correctly!
	 *
	 * @attrib to       string  The email recipient
	 * @attrib from     string  The email sender. You can insert data values as "$fieldname$". Encode literal '$' as "$$".
	 * @attrib subject  string  The email subject. You can insert data values as "$fieldname$". Encode literal '$' as "$$".
	 */
	function mail_start_el( &$parser, $element ) {
		$element['suppress_tags'] = true;
		$this->in_mail = true;
		$this->mail_body = '';
		$this->attachments = array();
		$this->insertions = array();
		return $element;
	}

	function mail_cdata( &$parser, $parent, $text ) {
		$this->mail_body .= htmlspecialchars_decode( $text );
		return '';
	}

	function mail_end_el( &$parser, $element ) {
		$this->in_mail = false;

		$args = array(
			'to' => isset($element['attrib']['to']) ? addcslashes(htmlspecialchars_decode( $element['attrib']['to'] ),"'\\") : '',
			'subject' => isset($element['attrib']['subject']) ? $this->parse_variable_string( $element['attrib']['subject'] ) : '',
			'from' => isset($element['attrib']['from']) ? $this->parse_variable_string( $element['attrib']['from'] ) : ''
		);
		foreach ( $args as $key => $arg ) {
			$args[$key] = "'$key'=>'$arg'";
		}
		$args_str = 'array(' . implode( ',', $args ) . ')';

		$insertions = $this->insertions;
		$text = addcslashes( trim($this->mail_body), "'\\" );
		$text = preg_replace( '/__ins_(\d+)__/e', '$insertions[$1]', $text );

		$atts = array();
		foreach ( $this->attachments as $filename => $attachment )
			$atts[] = "'$filename'=>$attachment";
		$attachments = 'array(' . implode( ',', $atts ) . ')';
		
		$class = get_class();
		$element['after_end_el'] = "<?php $class::do_mail( $args_str, '$text', $attachments ); ?>";

		return $element;
	}

	protected function mail_insert_code( $code ) {
		$this->insertions[] = "'." . $code . ".'";
		$this->mail_body .= '__ins_' . (count($this->insertions)-1) . '__';
	}

	static public function do_mail( $args, $body, $attachments ) {
		$parts = array(
			array( 'type' => 'body', 'content' => $body )
		);
		$mime_types = array(
			'csv' => 'text/csv',
			'txt' => 'text/plain',
			'htm' => 'text/html',
			'html' => 'text/html'
		);
		foreach ( $attachments as $filename => $content ) {
			$suffix = substr( $filename, strrpos( $filename, '.' ) + 1 );
			if ( array_key_exists( $suffix, $mime_types ) )
				$type = $mime_types[$suffix];
			else
				$type = 'application/octet-stream';
			$parts[] = array(
				'type' => $type,
				'filename' => $filename,
				'content' => $content
			);
		}

		$mime_boundary = "==Multipart_Boundary_" . md5(time());

		$headers = 'MIME-Version: 1.0'
		. PHP_EOL . 'Content-Type: multipart/mixed; boundary="' . $mime_boundary . '"'
		. PHP_EOL . 'From: ' . $args['from'];

		$message = "";
		foreach ( $parts as $part ) {
			$message .= "--$mime_boundary";
			if ( 'body' == $part['type'] ) {
				$message .= PHP_EOL . 'Content-Type: text/plain; charset="utf-8"';
			} else {
				$message .= PHP_EOL . 'Content-Type: ' . $part['type'] . '; name="' . $part['filename'] . '"'
				. PHP_EOL . 'Content-Disposition: attachment; filename="' . $part['filename'] . '"';
			}
			$message .= PHP_EOL . 'Content-Transfer-Encoding: base64'
			. PHP_EOL . PHP_EOL . chunk_split(base64_encode( $part['content'] )) . PHP_EOL;
		}

		mail( $args['to'], $args['subject'], $message, $headers );
	}

	/**
	 * Element <recaptcha publickey="..." privatekey="..." [options="..."] />
	 *
	 * Displays a reCaptcha. Be sure to provide recaptchalib.php in the plugins/inc/ directory.
	 * Once validated, the recaptcha will be hidden automatically. Validation status can be checked
	 * via the '__recaptcha' field. (not validated: does not exist; validated: '1')
	 *
	 * @attrib publickey, privatekey: API keys
	 * @attrib options (optional): Options JSON, e.g. "theme: 'theme_name', lang: 'en'".
	 */
	function recaptcha( &$parser, $element ) {
		$element['suppress_tags'] = true;
		$element['suppress_nested'] = true;

		if ( ! isset($element['attrib']['publickey']) || ! isset($element['attrib']['privatekey']) ) {
			trigger_error( 'Element <recaptcha> missing required attributes publickey and/or privatekey', E_USER_WARNING );
			return $element;
		} elseif ( ! $this->in_form ) {
			trigger_error( 'Suppressed <recaptcha> element outside of a form', E_USER_NOTICE );
			return $element;
		}

		$dir = dirname(__FILE__) . '/inc';
		$parser->add_header( "<?php require_once( '$dir/recaptchalib.php' ); ?>" );
		$parser->data[$this->parse_key]['__recaptcha'] = array(
			'type' => 'recaptcha',
			'privatekey' => $element['attrib']['privatekey']
		);
		$element['before_end_el'] = "<?php echo recaptcha_get_html( '" . $element['attrib']['publickey'] . "' ); ?>";

		if ( isset($element['attrib']['options']) ) {
			$element['after_start_el'] = '<script type="text/javascript">var RecaptchaOptions = {'
			 . htmlspecialchars_decode($element['attrib']['options']) . '};</script>';
		}

		$element['before_start_el'] = <<<PHP
<?php if ( empty({$this->struct_var}['__recaptcha']['printed']) ) :
 {$this->struct_var}['__recaptcha']['printed'] = true;
 if ( empty({$this->inst_var}['__recaptcha']) ) : ?>
PHP;
		$element['after_end_el'] = <<<PHP
<?php else : ?><input type='hidden' name='__recaptcha' value='1' /><?php endif; endif; ?>
PHP;

		return $element;
	}

	/**
	 * Attribute "validate" on <form> elements
	 * Forces form validation on submission.
	 * Specify validate="file" to redirect to a different file upon successful validation. Specify the file in the action attribute.
	 *  Note that the action attribute will be treated as a local path, and the target file is automatically parsed using the previous
	 *  templater settings.
	 * Specify validate="page" to validate in-situ and move to the next page, specified through the "pageorder" attribute or through
	 *  order or appearance in the document.
	 */
	function validate( &$parser, $element, $type ) {
		if ( 'form' != strtolower($element['name']) || ! in_array( $type, array('page','file') ) )
			return;

		$class = get_class();

		if ( 'file' == $type ) {
			$parser->add_header( "<?php \$invalid = $class::do_validate( \$templater, $this->inst_var, '$this->parse_key', '" . $element['attrib']['action'] . "' ); ?>" );
			@$element['before_end_el'] .= '<input type="hidden" name="__validate" value="' . htmlspecialchars($parser->templater->template_file) . '" />';
			$parser->data[$this->parse_key]['__nocarry'][] = '__validate';
		} elseif ( 'page' == $type ) {
			$parser->add_header( "<?php \$invalid = $class::do_validate( \$templater, $this->inst_var, '$this->parse_key' ); ?>" );
		}

		$element['alter_attrib'] = array( 'action' => '' );
		$element['remove_attrib'] = 'validate';

		return $element;
	}

	static public function do_validate( &$templater, &$data, $parse_key, $redirect = false ) {
		$invalid = array();
		$nopaging = false;

		// Do not validate a non-submission
		if ( $redirect && ( empty($data['__validate']) || htmlspecialchars_decode($data['__validate']) != $templater->template_file ) ) {
			return $invalid;
		} elseif ( ! $redirect && empty($data['__page']) ) {
			$data['__page'] = reset($templater->data[$parse_key]['__pages']);
			return $invalid;
		}

		$struct_keys = array( $parse_key );
		if ( ! empty($data['__page']) ) {
			$struct_keys[] = $parse_key . '_page_' . $data['__page'];
			$data['__frompage'] = $data['__page'];
		}

		foreach ( array_intersect_key( $templater->data, array_flip($struct_keys) ) as $struct ) {
			if ( isset($struct['__recaptcha']) && empty($data['__recaptcha']) && isset($data['recaptcha_challenge_field']) ) {
				require_once( dirname(__FILE__) . '/inc/recaptchalib.php' );
				$resp = recaptcha_check_answer(
					$struct['__recaptcha']['privatekey'],
					$_SERVER['REMOTE_ADDR'],
					$data['recaptcha_challenge_field'],
					$data['recaptcha_response_field']
				);
				if ( ! $resp->is_valid )
					$invalid['__recaptcha'] = 'recaptcha';
				else
					$data['__recaptcha'] = 1;
				unset( $data['recaptcha_challenge_field'], $data['recaptcha_response_field'] );
			}

			foreach ( $struct as $field_name => $field ) {
				if ( is_array( $field ) ) {
					if ( ! empty($field['required']) && empty($data[$field_name]) )
						$invalid[$field_name] = 'required';
					elseif ( ! empty($field['pattern']) && ! preg_match( '/^(?:' . addcslashes($field['pattern'],'/') . ')$/' ) )
						$invalid[$field_name] = 'pattern';
				}
			}

			if ( ! $nopaging && isset($struct['__topage_triggers']) && array_intersect_key( $struct['__topage_triggers'], $data ) )
				$nopaging = true;
		}

		if ( empty($invalid) ) {
			if ( $redirect ) {
				$templater->redirect( $extra );
			} else {
				$key = array_search( $data['__page'], $templater->data[$parse_key]['__pages'] );
				if ( isset($templater->data[$parse_key]['__pages'][++$key]) )
					$data['__page'] = $templater->data[$parse_key]['__pages'][$key];
				elseif ( ! $nopaging )
					trigger_error( 'Could not find a following page. Make sure not to submit from the last page!', E_USER_NOTICE );
			}
		}

		return $invalid;
	}

	/**
	 * Attribute "ifnotvalid" universal
	 * Display the element only if validation failed.
	 * Optionally supply one or more field names (separated by commas) in the attribute value to specify which fields to watch.
	 * Optionally supply one or more failure reasons (separated by commas), e.g. "required", "pattern" or "recaptcha" to specify
	 *  which failure modes to watch. If fields are given, only those fields will be watched.
	 * If at least one of the given fields AND at least one of the given reasons applies, the element in question will be displayed.
	 */
	function ifnotvalid( &$parser, $element, $field ) {
		$element['remove_attrib'] = array( 'ifnotvalid' );

		$condition = "!empty(\$invalid)";
		if ( $field ) {
			$fields = array_map( 'trim', explode( ',', $field ) );
			$ff = "array_flip(array('" . implode("','",$fields) . "'))";
			$invalid_selection = "array_intersect_key(\$invalid,$ff)";
			$condition .= "&&$invalid_selection";
		} else {
			$invalid_selection = '$invalid';
		}

		if ( isset($element['attrib']['reason']) ) {
			$reasons = array_map( 'trim', explode( ',', $element['attrib']['reason'] ) );
			$condition .= "&&array_intersect( $invalid_selection, array('" . implode("','",$reasons) . "') )";
			$element['remove_attrib'][] = 'reason';
		}

		@$element['before_start_el'] .= "<?php if( $condition ) : ?>";
		$element['after_end_el'] = "<?php endif; ?>" . @$element['after_end_el'];

		return $element;
	}

	/**
	 * Attribute "carry" on <form> elements
	 * Specify carry="hidden" to carry data across several pages or files in hidden inputs.
	 * Specify carry="session" to carry data into $_SESSION (and thence back). Optionally, add an attribute "sessname" to name the session.
	 * Note that carry="session" conflicts with any other sessions. Use with caution.
	 * Note also that all data accessible to this plugin will be carried over. This may be more or less than you expected.
	 */
	function carry( &$parser, $element, $carry ) {
		if ( 'form' != strtolower($element['name']) || ! in_array( $carry, array('hidden','session') ) )
			return;

		$element['remove_attrib'] = array( 'carry' );

		$class = get_class();
		if ( 'hidden' == $carry ) {
			$element['after_start_el'] .= "<?php $class::insert_carry( \$templater, '$this->parse_key', $this->inst_var ); ?>";
		} elseif ( 'session' == $carry ) {
			if ( isset($element['attrib']['sessname']) ) {
				$parser->add_header( "<?php $class::session_start( $this->inst_var, '" . $element['attrib']['sessname'] . "' ); ?>", -10 );
				$element['remove_attrib'][] = 'sessname';
				@$element['before_end_el'] .= '<input type="hidden" name="' . $element['attrib']['sessname'] . '" value="<?php echo session_id(); ?>" />';
			} else {
				$parser->add_header( "<?php $class::session_start( $this->inst_var ); ?>", -10 );
				@$element['before_end_el'] .= '<input type="hidden" name="<?php echo session_name(); ?>" value="<?php echo session_id(); ?>" />';
			}

			$parser->add_header( "<?php $class::session_write( $this->inst_var, \$templater, '$this->parse_key' ); ?>", 10 );
		}

		if ( ! isset($parser->data[$this->parse_key]['__nocarry']) )
			$parser->data[$this->parse_key]['__nocarry'] = array();

		return $element;
	}

	static public function insert_carry( &$templater, $parse_key, $inst_var ) {
		$struct_keys = array( $parse_key );
		if ( isset($inst_var['__page']) )
			$struct_keys[] = $parse_key . '_page_' . $inst_var['__page'];

		$carry_keys = array_keys( $inst_var );
		$carry_keys = array_diff( $carry_keys, $templater->data[$parse_key]['__nocarry'] );
		foreach ( array_intersect_key( $templater->data, array_flip($struct_keys) ) as $struct_var )
			$carry_keys = array_diff( $carry_keys, array_keys($struct_var) );
		foreach ( $carry_keys as $key ) {
			$value = $inst_var[$key];
			if ( is_array( $value ) )
				foreach ( $value as $v )
					echo '<input type="hidden" name="' . $key . '[]" value="' . htmlspecialchars($v) . '" />';
			else
				echo '<input type="hidden" name="' . $key . '" value="' . htmlspecialchars($value) . '" />';
		}
	}

	static public function session_start( &$data, $sessname = false ) {
		global $session_started;
		if ( empty($session_started) ) {
			ini_set( 'session.use_trans_sid', false );
			if ( $sessname )
				session_name( $sessname );
			if ( headers_sent() ) // Prevent warning about sent headers; they most likely originate from a reparse.
				@session_start();
			else
				session_start();
			$session_started = true;
		}

		foreach ( $_SESSION as $key => $value )
			if ( ! isset($data[$key]) )
				$data[$key] = $value;
	}

	static public function session_write( &$data, &$templater, $parse_key ) {
		$docarry = isset($templater->data[$parse_key]['__nocarry']) ? array_diff_key( $data, array_flip($templater->data[$parse_key]['__nocarry']) ) : $data;
		foreach ( $docarry as $key => $value )
			$_SESSION[$key] = $value;
	}

	/**
	 * Attribute "page" universal
	 * Display an element only on the given page(s).
	 * Warning: If used after the form end tag, the page(s) will not be added automatically.
	 *  Make sure you mention all pages before the form end tag, either through direct use in a page attribute, or in the form's pageorder attribute.
	 * Warning: Using this attribute on input elements may cause unexpected behaviour. Specifically, fields that require validation will
	 *  be treated as lying outside a page (although not displayed), which may make it impossible to proceed through the form.
	 */
	function page_start( &$parser, $element, $value ) {
		$element['remove_attrib'] = 'page';

		if ( $this->on_page ) {
			trigger_error( 'Nested page elements are not supported and may lead to unexpected behaviour', E_USER_WARNING );
			return $element;
		} elseif ( ! $value ) {
			trigger_error( 'Always provide at least one valid page ID with the "page" attribute', E_USER_WARNING );
			return $element;
		}

		$pages = array_map( 'trim', explode( ',', $value ) );
		foreach ( $pages as $page ) {
			if ( ! $page ) {
				trigger_error( "Invalid page ID '$page'" );
				return $element;
			}
			if ( ! in_array( $page, $this->pages ) )
				$this->pages[] = $page;
		}

		$this->on_page = $pages;
		$element['add_handler']['end_el'] = array(&$this, 'page_end');
		$this->parse_key_backup = $this->parse_key;
		$this->parse_key .= '_page_' . reset($pages);
		$this->set_struct_var();

		@$element['before_start_el'] .= "<?php if ( isset({$this->inst_var}['__page']) && in_array( {$this->inst_var}['__page'], array('" . implode( "','", $pages ) . "') ) ) : ?>";
		$element['after_end_el'] = "<?php endif; ?>" . @$element['after_end_el'];

		return $element;
	}

	function page_end( &$parser, $element ) {
		$this->on_page = false;
		$this->parse_key = $this->parse_key_backup;
		$this->set_struct_var();
	}

	/**
	 * Attribute "pageorder" on <form> elements
	 * Declare as comma-separated list of page IDs.
	 * Specifies the order of pages in the document. By default, pages will be ordered by appearance in the template.
	 * Pages that occur in the template but aren't listed in the pageorder attribute will be appended.
	 */
	function page_order( &$parser, $element, $value ) {
		if ( 'form' == strtolower($element['name']) )
			$this->pages = array_map( 'trim', explode( ',', $value ) );
		$element['remove_attrib'] = 'pageorder';
		return $element;
	}

	function form( &$parser, $element, $where ) {
		$r = parent::form( $parser, $element, $where );
		if ( 'end_el' == $where ) {
			$parser->data[$this->parse_key]['__pages'] = $this->pages;
			return $r;
		} else {
			if ( $r )
				$element = $r;
			@$element['before_end_el'] .= '<input type="hidden" name="__page" value="<?php echo ' . $this->inst_var . '["__page"]; ?>" />';
			$parser->data[$this->parse_key]['__nocarry'][] = '__page';
			$parser->data[$this->parse_key]['__nocarry'][] = '__frompage';
			return $element;
		}
	}

	/**
	 * Attribute "topage" universal
	 * Convert element into a paging control.
	 * You can provide either a page ID in the attribute value, or leave the attribute value blank
	 *  to let the element's value decide (eg for text inputs or selects as versatile page switchers).
	 * Instead of a page ID, you can also provide relative values, eg "+2" or "-1".
	 * When added to an input of sorts (<input> of any type except reset, <select>) nested inside a <form>,
	 *  the presence of the input's submit value triggers the relevant page change.
	 *  This means that non-submitting inputs need to provide their own submission mechanism (eg an "onblur" attribute).
	 * When added to any other element type, or outside of a <form> element, an "onclick" handler is added to the element.
	 *  If there are multiple forms in the document, specify the name of the relevant form via the additional "form" parameter.
	 */
	function topage( &$parser, $element, $value ) {
		static $unnamed_index = 0;

		$element['remove_attrib'] = array( 'topage' );

		if (
		 $this->in_form
		 && in_array( strtolower($element['name']), array('input', 'select') )
		 && ! ( 'input' == strtolower($element['name']) && isset($element['attrib']['type']) && 'reset' == strtolower($element['attrib']['type']) )
		) {
			if ( isset($element['attrib']['name']) ) {
				$name = $this->sanitize_name( $element['attrib']['name'] );
			} else {
				$name = '__topage_f' . $unnamed_index++;
				$element['add_attrib'] = array( 'name' => $name );
			}
		} else {
			$name = '__topage';
			if ( isset($element['attrib']['form']) ) {
				$form = "'" . $element['attrib']['form'] . "'";
				$element['remove_attrib'][] = 'form';
			} else {
				$form = 0;
			}
			$element['add_attrib'] = array( 'onclick' => "return toPage('$value',$form);" );
			// Hack into <body>'s dynamic handlers to add a footer script
			foreach ( $parser->parents as &$p ) {
				if ( 'body' == strtolower($p['name']) ) {
					$f = false;
					foreach ( $p['dynamic_handlers']['end_el'] as $h ) {
						if ( $this === $h[0] && 'insert_topage_script' == $h[1] ) {
							$f = true;
							break;
						}
					}
					if ( ! $f )
						$p['dynamic_handlers']['end_el'][] = array( &$this, 'insert_topage_script' );
					unset($p);
					break;
				}
			}

			$value = '';
		}

		$parser->data[ $this->on_page ? $this->parse_key_backup : $this->parse_key ]['__nocarry'][] = $name;

		$class = get_class();

		if ( $this->on_page ) {
			$pkey_bk = $this->parse_key;
			$this->parse_key = $this->parse_key_backup;
			$this->set_struct_var();
			$pages = $this->struct_var . "['__pages']";
			foreach ( $this->on_page as $p ) {
				$this->parse_key = $this->parse_key_backup . '_page_' . $p;
				$this->set_struct_var();
				$parser->data[$this->parse_key]['__topage_triggers'][$name] = $value;
				$parser->add_header( "<?php $class::do_topage($this->struct_var, $this->inst_var, $pages, \$invalid); ?>", 5 );
			}
			$this->parse_key = $pkey_bk;
			$this->set_struct_var();
		} else {
			$parser->data[$this->parse_key]['__topage_triggers'][$name] = $value;
			$parser->add_header( "<?php $class::do_topage($this->struct_var, $this->inst_var, {$this->struct_var}['__pages'], \$invalid); ?>", 5 );
		}

		return $element;
	}

	function insert_topage_script( &$parser, $element ) {
		$element['before_end_el'] .= <<<JS
<script type="text/javascript">
	function toPage(p,f){
		h=document.createElement('input');
		h.name='__topage';
		h.value=p;
		h.type='hidden';
		document.forms[f].appendChild(h);
		document.forms[f].submit();
		return false;
	}
</script>
JS;
		return $element;
	}

	static public function do_topage( $struct, &$data, $pages, &$invalid ) {
		static $paging_done = false;
		if ( $paging_done )
			return;

		foreach ( $struct['__topage_triggers'] as $trigger => $value ) {
			if ( isset( $data[$trigger] ) ) {
				if ( ! $value )
					$value = $data[$trigger];
				if ( preg_match( '/^([+-])(\d+)$/', $value, $matches ) ) {
					$i = array_search( $data['__frompage'], $pages );
					if ( '+' == $matches[1] )
						$i += $matches[2];
					else
						$i -= $matches[2];
					if ( isset($pages[$i]) ) {
						$page = $pages[$i];
					} else {
						trigger_error( "Invalid page index $i caused by paging to '$value' from page '$data[__page]'", E_USER_NOTICE );
						$page = $data['__page'];
					}
				} else {
					if ( in_array( $value, $pages ) ) {
						$page = $value;
					} else {
						trigger_error( "Page '$value' not found", E_USER_NOTICE );
						$page = $data['__page'];
					}
				}

				// Don't produce non-validation when going backwards
				if ( array_search( $page, $pages ) < array_search( $data['__frompage'], $pages ) && $invalid )
					$invalid = array();
				// But do prevent going forward if validation failed. Note that skipped pages aren't validated, however!
				elseif ( $invalid )
					return;

				$data['__page'] = $page;
				$paging_done = true;
				break;
			}
		}
	}

	/**
	 * Spinoff form creation
	 *
	 * Create a form with the entered values to be sent along with an email and used e.g. for deferred manual submission
	 * Inputs with the "spinoff" attribute will be carried over. If an attribute value is given, it is used as the key in the spinoff form.
	 *  If, additionally, a "spinoff-translate" attribute is given, select and multi-checkbox fields will translate their values accordingly.
	 *  The input format for spinoff-translate is "originalvalue=value_in_spinoff" with entries separated by ";".
	 *
	 * The <spinoff> element is to be used only as a child of <mail>. The following attributes are supported:
	 * @attrib filename      Name of the spinoff file. Variables can be entered as "$fieldname$". Defaults to "spinoff.htm".
	 * @attrib action        Target URL of the spinoff form
	 * @attrib method        Spinoff form's submission method. Defaults to "post".
	 * @attrib charset       Target character set. Defaults to UTF-8.
	 * @attrib input-charset Character set used for incoming data. Defaults to UTF-8.
	 * @attrib add-data      Data to add to the spinoff form, separated by add-data-sep (e.g. "key1=value1,key2=value2").
	 *                       Prefix keys with an asterisk (*) to pass them as hidden inputs.
	 *                       In values, you can insert data values as "$fieldname$". Encode literal '$' as "$$".
	 * @attrib add-data-sep  Separator characters for add-data (see csv documentation). Defaults to ',='.
	 *                       Note that the '$' character is not available as a separator.
	 */
	function spinoff_attribute( &$parser, $element, $value ) {
		$element['remove_attrib'] = array( 'spinoff' );
		if ( isset($element['form_key']) ) {
			$parser->data[$this->parse_key][$element['form_key']]['spinoff'] = $value;
			if ( isset( $element['attrib']['spinoff-translate'] ) ) {
				$parser->data[$this->parse_key][$element['form_key']]['spinoff-translate'] = array();
				$element['remove_attrib'][] = 'spinoff-translate';
				foreach ( explode( ';', htmlspecialchars_decode($element['attrib']['spinoff-translate']) ) as $p ) {
					$p = explode( '=', $p );
					$parser->data[$this->parse_key][$element['form_key']]['spinoff-translate'][$p[0]] = isset($p[1]) ? $p[1] : '';
				}
			}
		}
		return $element;
	}

	function spinoff_element( &$parser, $element ) {
		$element['suppress_tags'] = $element['suppress_nested'] = true;
		if ( ! $this->in_mail )
			return $element;

		if ( empty($element['attrib']['filename']) )
			$filename = 'spinoff.htm';
		else
			$filename = $this->parse_variable_string( $element['attrib']['filename'] );

		$c_out = isset($element['attrib']['charset']) ? $element['attrib']['charset'] : 'utf-8';
		$c_in = isset($element['attrib']['input-charset']) ? $element['attrib']['input-charset'] : 'utf-8';

		if ( isset($element['attrib']['add-data']) ) {
			$r = isset($element['attrib']['add-data-sep']) ? htmlspecialchars_decode($element['attrib']['add-data-sep']) : ',=';
			$add_arr = array();
			foreach ( explode( $r[0], $element['attrib']['add-data'] ) as $add ) {
				$add = explode( $r[1], $add );
				$add_arr[] = "'" . addcslashes(htmlspecialchars_decode($add[0]),"'\\") . "'=>'"
				 . ( isset($add[1]) ? $this->parse_variable_string($add[1]) : '' ) . "'";
			}
			$add_data = 'array(' . implode( ',', $add_arr ) . ')';
		} else {
			$add_data = 'array()';
		}

		$action = $element['attrib']['action'];
		$method = isset($element['attrib']['method']) ? $element['attrib']['method'] : 'POST';
		$escape = $this->escape_data ? 'true' : 'false';

		$parse_key = $this->on_page ? $this->parse_key_backup : $this->parse_key;

		$class = get_class();
		$this->attachments[$filename] = "$class::get_spinoff( \$templater, '$parse_key', $this->inst_var, '$c_out', '$c_in', '$action', '$method', $escape, $add_data )";

		return $element;
	}

	static public function get_spinoff( &$templater, $parse_key, &$data, $charset, $input_charset, $action, $method, $escape, $add_data ) {
		$str = <<<HTML
<!DOCTYPE html>
<html>
 <head><title>Spinoff form</title><meta charset="$charset"></head>
 <body>
  <form action="$action" method="$method">
   <table>
    <thead><tr><th>Original key</th><th>Receiver's key</th><th>Value</th></tr></thead>
    <tbody>
HTML;

		$struct_keys = array( $parse_key );
		if ( isset($templater->data[$parse_key]['__pages']) )
			foreach ( $templater->data[$parse_key]['__pages'] as $pageid )
				$struct_keys[] = $parse_key . '_page_' . $pageid;
		foreach ( array_intersect_key( $templater->data, array_flip($struct_keys) ) as $struct ) {
			foreach ( $struct as $fkey => $field ) {
				if ( isset( $field['spinoff'] ) ) {
					if ( $field['spinoff'] )
						$skey = $field['spinoff'];
					else
						$skey = $fkey;
					$str .= "<tr><td>$field[name_orig]</td><td>$skey</td><td>";
					if ( 'textarea' == $field['type'] ) {
						$str .= "<textarea name='$skey'>";
						if ( isset($data[$fkey]) ) {
							$v = $escape ? htmlspecialchars($data[$fkey]) : $data[$fkey];
							if ( $charset != $input_charset )
								$v = iconv( $input_charset, $charset, $v );
							$str .= $v;
						}
						$str .= "</textarea>";
					} elseif ( 'select' == $field['type'] && ! $field['multiple'] || 'radio' == $field['type'] ) {
						$str .= "<select name='$skey'>";
						foreach ( $field['options'] as $okey => $oval ) {
							$selected = isset($data[$fkey]) && $data[$fkey]==$okey || !isset($data[$fkey]) && $field['default']==$okey ? "selected='selected'" : "";
							$tkey = isset($field['spinoff-translate'][$okey]) ? $field['spinoff-translate'][$okey] : $okey;
							$str .= "<option value='$tkey' $selected>$oval</option>";
						}
						$str .= "</select>";
					} elseif ( !empty($field['multiple']) && in_array( $field['type'], array( 'select', 'checkbox' ) ) ) {
						$str .= "<select name='$skey' multiple='multiple'>";
						foreach ( $field['options'] as $okey => $oval ) {
							$selected = isset($data[$fkey]) && in_array($okey,$data[$fkey]) || !isset($data[$fkey]) && in_array($okey,$field['default']) ? "selected='selected'" : "";
							$tkey = isset($field['spinoff-translate'][$okey]) ? $field['spinoff-translate'][$okey] : $okey;
							$str .= "<option value='$tkey' $selected>$oval</option>";
						}
						$str .= "</select>";
					} elseif ( 'checkbox' == $field['type'] ) {
						$str .= "<input type='checkbox' name='$skey' " . ( empty($data[$fkey]) ? "" : "checked='checked'" ) . ">";
					} else {
						$str .= "<input type='$field[type]' name='$skey' value='";
						if ( isset($data[$fkey]) ) {
							$v = $escape ? htmlspecialchars($data[$fkey],ENT_QUOTES) : $data[$fkey];
							if ( $charset != $input_charset )
								$v = iconv( $input_charset, $charset, $v );
							$str .= $v;
						}
						$str .= "'>";
					}
					$str .= "</td></tr>";
				}
			}
		}

		$hidden = array();
		foreach ( $add_data as $key => $value ) {
			$value = htmlspecialchars( $value, ENT_QUOTES );
			if ( $charset != $input_charset )
				$value = iconv( $input_charset, $charset, $value );

			if ( 0 === strpos( $key, '*' ) )
				$hidden[ substr($key, 1 ) ] = $value;
			else
				$str .= "<tr><td>&ndash;</td><td>$key</td><td><input type='text' name='$key' value='$value'></td></tr>";
		}

		if ( $hidden ) {
			$str .= "<tr><td colspan='3'>";
			foreach ( $hidden as $key => $value )
				$str .= "<input type='hidden' name='$key' value='$value'>";
			$str .= "</td></tr>";
		}

		$str .= <<<HTML
   </tbody>
   <tfoot><tr><td colspan="3"><input type="submit"></td></tr></tfoot>
  </table>
 </body>
</html>
HTML;
		return $str;
	}

	/**
	* Attribute "temporal" on <select> elements
	*
	* Adds a set of dynamic, date-dependent options to a select element.
	* The attribute value contains a number of parameters given in the structure "param_key:param_value;param_key2:param_value2;..."
	* The following parameters are supported:
	* @param value   The date format string for the option value
	* @param label   The date format string for the option label
	* @param from    A relative integer marking the first of the added options.
	*                0 essentially means "today", while other values (positive or negative) are calculated according to the "step" parameter.
	* @param to      A relative integer marking the last of the added options.
	* @param step    The iterating unit. Can be any of the units supported by relative date/time formats,
	*                see http://php.net/manual/en/datetime.formats.relative.php
	* @param prepend If set, options will be prepended to the select. By default, they will be appended instead.
	*/
	function temporal( &$parser, $element, $value ) {
		$element['remove_attrib'] = 'temporal';

		if ( ! 'select' == strtolower($element['name']) || ! $this->in_select )
			return $element;

		$options = explode( ';', $value );
		$params = array();
		foreach ( $options as $opt ) {
			$opt = explode( ':', $opt );
			$params[$opt[0]] = isset($opt[1]) ? $opt[1] : true;
		}
		$required_params = array( 'value', 'label', 'from', 'to', 'step' );
		if ( array_diff_key( array_flip($required_params), $params ) ) {
			trigger_error( "Missing argument(s) for temporal select '$element[form_key]'", E_USER_WARNING );
			return $element;
		}

		$class = get_class();

		$parser->add_header( "<?php $class::temporal_init( {$this->struct_var}['$element[form_key]'], '$params[value]', '$params[label]', $params[from], $params[to], '$params[step]' ); ?>" );

		$element[ empty($params['prepend']) ? 'before_end_el' : 'after_start_el' ] = "<?php $class::temporal_printopts( {$this->struct_var}['$element[form_key]'] ); ?>";

		return $element;
	}

	static public function temporal_init( &$struct, $value, $label, $from, $to, $step ) {
		$struct['options_orig'] = $struct['options'];
		$d = new DateTime();
		if ( 'month' == $step )
			$d->modify( ( 15 - $d->format('j') ) . ' days' );
		if ( $from )
			$d->modify( $from . ' ' . $step );
		$incr = $from < $to;
		for ( $i = $from; $incr ? $i <= $to : $i >= $to; $incr ? $i++ : $i-- ) {
			$struct['options'][ $d->format($value) ] = $d->format($label);
			$d->modify( ( $incr ? '1' : '-1' ) . ' ' . $step );
		}
	}

	static public function temporal_printopts( &$struct ) {
		foreach ( array_diff_key( $struct['options'], $struct['options_orig'] ) as $key => $label )
			echo "<option value=\"$key\">$label</option>";
	}
}
?>