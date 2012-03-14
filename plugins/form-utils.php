<?php

require_once( 'form-basics.php' );
register_plugin( 'form-extended', 'FBK_Form_Utils' );

class FBK_Form_Utils extends FBK_Form_Basics {
	public $version = '1a8';

	protected $in_mail = false, $mail_body, $attachments, $insertions;

	protected $recaptcha_privatekey = false;

	function get_handlers() {
		return array_merge( parent::get_handlers(), array(
			array( 'start_el', 'output', 'output' ),
			array( 'start_el', 'csv', 'csv' ),
			array( 'start_el', 'mail', 'mail_start_el' ),
			array( 'cdata', 'mail', 'mail_cdata' ),
			array( 'end_el', 'mail', 'mail_end_el' ),
			array( 'start_el', 'recaptcha', 'recaptcha' ),
			array( 'attribute', 'validate', 'validate' ),
			array( 'attribute', 'carry', 'carry' )
		));
	}

	/**
	 * Output data from the specified form input. The <output> element and all nested content will be replaced by the designated value.
	 * The following attributes are respected:
	 * name (required): Which data point to output.
	 * type (optional): 'plain' | 'translated', how to output the data. If set to 'translated', will check the structure variable for any available
	 *	translations, such as select labels etc. Defaults to 'translated'.
	 * sep (optional): string to be used as a separator if the data point is an array. Defaults to ', '.
	 * before (optional): string to be inserted before the output if the data point is an array. Defaults to ''.
	 * after (optional): As above, but after the output.
	 */
	function output( &$parser, $element ) {
		$element['suppress_tags'] = true;
		$element['suppress_nested'] = true;

		$key = $element['attrib']['name'];
		$escape = $this->escape_data ? 'true' : 'false';

		$sep = isset($element['attrib']['sep']) ? addcslashes(htmlspecialchars_decode($element['attrib']['sep']),"'\\") : ', ';
		$before = isset($element['attrib']['before']) ? addcslashes(htmlspecialchars_decode($element['attrib']['before']),"'\\") : '';
		$after = isset($element['attrib']['after']) ? addcslashes(htmlspecialchars_decode($element['attrib']['after']),"'\\") : '';

		if ( isset($element['attrib']['type']) && 'plain' == $element['attrib']['type'] ) {
			if ( $this->escape_data )
				$base_output = "isset({$this->inst_var}['$key']) ? htmlspecialchars({$this->inst_var}['$key']) : ''";
			else
				$base_output = "isset({$this->inst_var}['$key']) ? {$this->inst_var}['$key'] : ''";
		} else {
			$class = get_class();
			$base_output = "$class::translate( '$key', $this->inst_var, $this->struct_var, '$sep', '$before', '$after', $escape )";
		}

		if ( $this->in_mail )
			$this->mail_insert_code( $base_output );
		else
			$element['before_start_el'] = "<?php echo $base_output; ?>";

		return $element;
	}

	static public function translate( $key, &$data, &$struct, $sep, $before, $after, $escape ) {
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
			return $before . $struct[$key]['options'][$dp] . $after;
		} elseif ( isset($struct[$key]) && ! empty($struct[$key]['options']) ) {
			return $struct[$key]['options'][$dp];
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
	 *                           Otherwise, include trumps exclude.
	 * @attrib inline            If inside a <mail> element, output into the mail message body. By default, this is disabled.
	 * @attrib filename          If inside a <mail> element, the name of the attachment to be generated. Defaults to 'file.csv'.
	 */
	function csv( &$parser, $element ) {
		$element['suppress_tags'] = true;
		$element['suppress_nested'] = true;

		$sep = isset($element['attrib']['multiseparator']) ? addcslashes(htmlspecialchars_decode($element['attrib']['multiseparator']),"'\\") : ',';

		if ( isset($element['attrib']['replace']) ) {
			$r = isset($element['attrib']['replaceseps']) ? addcslashes(htmlspecialchars_decode($element['attrib']['replaceseps']),"'\\") : '$=';
			$replace_arr = array();
			foreach ( explode( $r[0], addcslashes(htmlspecialchars_decode($element['attrib']['replace']),"'\\") ) as $snr ) {
				$snr = explode( $r[1], $snr );
				$replace_arr[] = "'" . $snr[0] . "'=>'" . ( isset($snr[1]) ? $snr[1] : '' ) . "'";
			}
			$replace = 'array(' . implode( ',', $replace_arr ) . ')';
		} else {
			$replace = 'array(\'"\'=>\'""\')';
		}

		if ( isset($element['attrib']['include']) )
			$inc = "'" . $element['attrib']['include'] . "', true";
		elseif ( isset($element['attrib']['exclude']) )
			$inc = "'" . $element['attrib']['exclude'] . "'";
		else
			$inc = "''";

		$class = get_class();

		$base_output = "$class::get_csv( $this->inst_var, '$sep', $replace, $inc )";

		if ( $this->in_mail && empty($element['attrib']['inline']) )
			$this->attachments[ isset($element['attrib']['filename']) ? $element['attrib']['filename'] : 'file.csv' ] = $base_output;
		elseif ( $this->in_mail )
			$this->mail_insert_code( $base_output );
		elseif ( $this->escape_data )
			$element['before_start_el'] = "<?php echo htmlspecialchars($base_output); ?>";
		else
			$element['before_start_el'] = "<?php echo $base_output; ?>";

		return $element;
	}

	static public function get_csv( &$data, $sep, $replace, $indices, $include = false ) {
		$indices = explode( ',', $indices );
		if ( ! $include )
			$indices = array_diff( array_keys($data), $indices );
		$csv = '"' . implode( '","', $indices ) . '"' . PHP_EOL;
		$out = array();
		foreach ( $indices as $index ) {
			if ( ! isset($data[$index]) ) {
				$out[] = '';
			} elseif ( is_array($data[$index]) ) {
				$d = array();
				foreach ( $data[$index] as $i )
					$d[] = str_replace( array_keys($replace), $replace, $i );
				$out[] = implode( $sep, $d );
			} else {
				$out[] = str_replace( array_keys($replace), $replace, $data[$index] );
			}
		}
		$csv .= '"' . implode( '","', $out ) . '"';
		return $csv;
	}

	/**
	 * Send an email at runtime. The content nested within this element is interpreted as the message body and should consist
	 * only of plain text as well as elements defined within this class, such as <output> and <csv>. Other nested elements, including HTML comments,
	 * will not be processed correctly!
	 *
	 * @attrib to       string  The email recipient
	 * @attrib from     string  The email sender
	 * @attrib subject  string  The email subject
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
			'subject' => isset($element['attrib']['subject']) ? addcslashes(htmlspecialchars_decode( $element['attrib']['subject'] ),"'\\") : '',
			'from' => isset($element['attrib']['from']) ? addcslashes(htmlspecialchars_decode( $element['attrib']['from'] ),"'\\") : ''
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
			'txt' => 'text/plain'
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
	 * Displays a reCaptcha. Be sure to provide the recaptchalib.php in the plugins/inc/ directory.
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
		$parser->data[$this->parse_key]['__recaptcha'] = $element['attrib']['privatekey'];
		$element['before_end_el'] = "<?php echo recaptcha_get_html( '" . $element['attrib']['publickey'] . "' ); ?>";

		if ( isset($element['attrib']['options']) ) {
			$element['after_start_el'] = '<script type="text/javascript">var RecaptchaOptions = {'
			 . htmlspecialchars_decode($element['attrib']['options']) . '};</script>';
		}

		$element['before_start_el'] = "<?php if ( empty({$this->inst_var}['__recaptcha']) ) : ?>";
		$element['after_end_el'] = "<?php else : ?><input type='hidden' name='__recaptcha' value='1' /><?php endif; ?>";

		return $element;
	}

	/**
	 * Attribute "validate" on <form> elements
	 * Forces form validation on submission.
	 * Specify validate="file" to redirect to a different file upon successful validation. Specify the file in the action attribute.
	 *  Note that the action attribute will be treated as a local path, and the target file is automatically parsed using the previous
	 *  templater settings.
	 * [NYI, pending multipage implementation:] Specify validate="page" to validate in-situ and move to the next page. By default,
	 *  pages are sorted by order of appearance in the document. To sort manually, provide an "order" attribute with comma-separated page names.
	 */
	function validate( &$parser, $element, $type ) {
		if ( 'form' != strtolower($element['name']) || ! in_array( $type, array('page','file') ) )
			return;

		$class = get_class();

		if ( 'file' == $type ) {
			$parser->add_header( "<?php \$invalid = $class::do_validate( \$templater, $this->inst_var, $this->struct_var, '" . $element['attrib']['action'] . "', true ); ?>" );
			@$element['before_end_el'] .= '<input type="hidden" name="__validate" value="' . htmlspecialchars($parser->templater->template_file) . '" />';
		} elseif ( 'page' == $type ) {
			$parser->add_header( "<?php \$invalid = $class::do_validate( \$templater, $this->inst_var, $this->struct_var, '" . @$element['attrib']['order'] . "', false ); ?>" );
			// onpage field must be set per page
		}

		$element['alter_attrib'] = array( 'action' => '' );
		$element['remove_attrib'] = 'validate';

		return $element;
	}

	static public function do_validate( &$templater, &$data, $struct, $extra, $redirect ) {
		$invalid = array();

		if ( isset($data['__validate']) ) {
			$validate = $data['__validate'];
			unset( $data['__validate'] );
		}

		if ( $redirect && ( empty($validate) || htmlspecialchars_decode($validate) != $templater->template_file ) ) {
			return $invalid;
#		} elseif ( ! $redirect && ... ) { // Pending page implementation
#			return $invalid;
		}


		if ( isset($struct['__recaptcha']) && empty($data['__recaptcha']) && isset($data['recaptcha_challenge_field']) ) {
			require_once( dirname(__FILE__) . '/inc/recaptchalib.php' );
			$resp = recaptcha_check_answer(
				$struct['__recaptcha'],
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

		if ( empty($invalid) ) {
			if ( $redirect ) {
				$templater->redirect( $extra );
			} else {
				// page++ according to $extra or natural order
			}
		}

		return $invalid;
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
			$element['after_start_el'] .= "<?php $class::insert_carry( $this->struct_var, $this->inst_var ); ?>";
		} elseif ( 'session' == $carry ) {
			if ( isset($element['attrib']['sessname']) ) {
				$parser->add_header( "<?php $class::session_start( $this->inst_var, '" . $element['attrib']['sessname'] . "' ); ?>", -10 );
				$element['remove_attrib'][] = 'sessname';
				@$element['before_end_el'] .= '<input type="hidden" name="' . $element['attrib']['sessname'] . '" value="<?php echo session_id(); ?>" />';
			} else {
				$parser->add_header( "<?php $class::session_start( $this->inst_var ); ?>", -10 );
				@$element['before_end_el'] .= '<input type="hidden" name="<?php echo session_name(); ?>" value="<?php echo session_id(); ?>" />';
			}

			$parser->add_header( "<?php $class::session_write( $this->inst_var ); ?>", 10 );
		}

		return $element;
	}

	static public function insert_carry( $struct_var, $inst_var ) {
		// Pending multipage implementation
		foreach ( array_diff_key( $inst_var, $struct_var ) as $key => $value ) {
			if ( isset( $inst_var[$key] ) ) {
				if ( is_array( $inst_var[$key] ) )
					foreach ( $inst_var[$key] as $value )
						echo '<input type="hidden" name="' . $key . '[]" value="' . htmlspecialchars($value) . '" />';
				else
					echo '<input type="hidden" name="' . $key . '" value="' . htmlspecialchars($inst_var[$key]) . '" />';
			}
		}
	}

	static public function session_start( &$data, $sessname = false ) {
		global $session_started;
		if ( empty($session_started) ) {
			ini_set( 'session.use_trans_sid', false );
			if ( $sessname )
				session_name( $sessname );
			session_start();
			$session_started = true;
		}

		foreach ( $_SESSION as $key => $value )
			if ( ! isset($data[$key]) )
				$data[$key] = $value;
	}

	static public function session_write( &$data ) {
		foreach ( $data as $key => $value )
			$_SESSION[$key] = $value;
	}
}
?>