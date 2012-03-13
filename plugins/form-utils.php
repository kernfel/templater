<?php

require_once( 'form-basics.php' );
register_plugin( 'form-extended', 'FBK_Form_Utils' );

class FBK_Form_Utils extends FBK_Form_Basics {
	public $version = '1a3';

	function get_handlers() {
		return array_merge( parent::get_handlers(), array(
			array( 'start_el', 'output', 'output' ),
			array( 'start_el', 'csv', 'csv' )
		));
	}

	/**
	 * Output data from the specified form input. The <output> element and all nested content will be replaced by the designated value.
	 * The following attributes are respected:
	 * name (required): Which data point to output.
	 * type (optional): 'plain' | 'translated', how to output the data. If set to 'translated', will check the structure variable for any available
	 *	translations, such as select labels etc. Defaults to 'translated'.
	 * sep (optional): string to be used as a separator if the data point is an array. Encode htmlspecialchars (&amp; &lt; &gt; &apos; &quot;)! Defaults to ', '.
	 * before (optional): string to be inserted before the output if the data point is an array. Will be decoded. Defaults to ''.
	 * after (optional): As above, but after the output.
	 */
	function output( &$parser, $element ) {
		$element['suppress_tags'] = true;
		$element['suppress_nested'] = true;

		$key = $element['attrib']['name'];
		$escape = $this->escape_data ? 'true' : 'false';

		$sep = isset($element['attrib']['sep']) ? $element['attrib']['sep'] : ', ';
		$before = isset($element['attrib']['before']) ? $element['attrib']['before'] : '';
		$after = isset($element['attrib']['after']) ? $element['attrib']['after'] : '';

		if ( isset($element['attrib']['type']) && 'plain' == $element['attrib']['type'] ) {
			if ( $this->escape_data )
				$element['before_start_el'] = <<<PHP
<?php if ( isset({$this->inst_var}['$key']) ) echo htmlspecialchars({$this->inst_var}['$key']); ?>
PHP;
			else
				$element['before_start_el'] = <<<PHP
<?php if ( isset({$this->inst_var}['$key']) ) echo {$this->inst_var}['$key']; ?>
PHP;
		} else {
			$class = get_class();
			$element['before_start_el'] = <<<PHP
<?php $class::translate( '$key', $this->inst_var, $this->struct_var, "$sep", "$before", "$after", $escape ); ?>
PHP;
		}

		return $element;
	}

	static public function translate( $key, &$data, &$struct, $sep, $before, $after, $escape ) {
		if ( ! isset($data[$key]) && ! isset($struct[$key]) )
			return;

		if ( ! isset($data[$key]) ) {
			if ( ! empty($struct[$key]['default']) )
				$dp = $struct[$key]['default'];
			else
				return;
		} else {
			$dp = $data[$key];
		}

		$struct_is_multi = isset($struct[$key]) && ! empty($struct[$key]['multiple']) && ! empty($struct[$key]['options']);
		$dp_is_array = is_array($dp);

		if ( $dp_is_array && $struct_is_multi ) {
			$translation = array_intersect_key( $struct[$key]['options'], array_flip( $dp ) );
			echo htmlspecialchars_decode($before), implode( htmlspecialchars_decode($sep), $translation ), htmlspecialchars_decode($after);
		} elseif ( $dp_is_array && ! $struct_is_multi ) {
			if ( $escape )
				$dp = array_map( 'htmlspecialchars', $dp );
			echo htmlspecialchars_decode($before), implode( htmlspecialchars_decode($sep), $dp ), htmlspecialchars_decode($after);
		} elseif ( ! $dp_is_array && $struct_is_multi ) {
			echo htmlspecialchars_decode($before), $struct[$key]['options'][$dp], htmlspecialchars_decode($after);
		} elseif ( isset($struct[$key]) && ! empty($struct[$key]['options']) ) {
			echo $struct[$key]['options'][$dp];
		} else {
			echo $escape ? htmlspecialchars($dp) : $dp;
		}
	}

	/**
	 * Print a CSV string with a header and a data line.
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
	 */
	function csv( &$parser, $element ) {
		$element['suppress_tags'] = true;
		$element['suppress_nested'] = true;

		$sep = isset($element['attrib']['multiseparator']) ? $element['attrib']['multiseparator'] : ',';

		if ( isset($element['attrib']['replace']) ) {
			$r = isset($element['attrib']['replaceseps']) ? $element['attrib']['replaceseps'] : '$=';
			$replace_arr = array();
			foreach ( explode( $r[0], $element['attrib']['replace'] ) as $snr ) {
				$snr = explode( $r[1], $snr );
				$snr = array_map( 'htmlspecialchars_decode', $snr );
				$replace_arr[] = "'" . str_replace( "'", "\\'", $snr[0] ) . "'=>'"
				 . ( isset($snr[1]) ? str_replace( "'", "\\'", $snr[1] ) : '' ) . "'";
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

		$element['before_start_el'] = <<<PHP
<?php echo $class::get_csv( $this->inst_var, "$sep", $replace, $inc ); ?>
PHP;

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
}
?>