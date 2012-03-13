<?php
	require_once( 'default-handlers.php' );

class FBK_Form_Utils extends FBK_Form_Handler {
	public $version = '1a1';

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
	 * sep (optional): string to be used as a separator if the data point is an array. Will be html_entity_decode()d before insertion. Defaults to ','.
	 * before (optional): string to be inserted before the output if the data point is an array. Will be decoded. Defaults to ''.
	 * after (optional): As above, but after the output.
	 */
	function output( &$parser, $element ) {
		$element['suppress_tags'] = true;
		$element['suppress_nested'] = true;

		$key = $element['attrib']['name'];
		$escape = $this->escape_data ? 'true' : 'false';

		// The XML parser returns attributes already processed with html_entity_decode().
		$sep = isset($element['attrib']['sep']) ? htmlspecialchars($element['attrib']['sep']) : ',';
		$before = isset($element['attrib']['before']) ? htmlspecialchars($element['attrib']['before']) : '';
		$after = isset($element['attrib']['after']) ? htmlspecialchars($element['attrib']['after']) : '';

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

	function csv( &$parser, $element ) {
		$element['suppress_tags'] = true;
		$element['suppress_nested'] = true;

		$sep = isset($element['attrib']['multiseparator']) ? $element['attrib']['multiseparator'] : ',';
		if ( isset($element['attrib']['include']) )
			$inc = "'" . $element['attrib']['include'] . "', true";
		elseif ( isset($element['attrib']['exclude']) )
			$inc = "'" . $element['attrib']['exclude'] . "'";
		else
			$inc = "''";

		$class = get_class();

		$element['before_start_el'] = <<<PHP
<?php echo $class::get_csv( $this->inst_var, "$sep", $inc ); ?>
PHP;

		return $element;
	}

	static public function get_csv( &$data, $sep, $indices, $include = false ) {
		$indices = explode( ',', $indices );
		if ( ! $include )
			$indices = array_diff( array_keys($data), $indices );
		echo '"' . implode( '","', $indices ) . '"' . PHP_EOL;
		$out = array();
		foreach ( $indices as $index ) {
			if ( ! isset($data[$index]) )
				$out[] = '';
			elseif ( is_array($data[$index]) )
				$out[] = implode( $sep, $data[$index] );
			else
				$out[] = $data[$index];
		}
		echo '"' . implode( '","', $out ) . '"';
	}
}



























?>