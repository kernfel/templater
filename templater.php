<?php

class FBK_Templater {

	public $template_file;
	public $cache_dir;

	protected $compiled_file, $data_file, $header_file;

	public $data;

	protected $is_parsed = false, $redirect;

	protected $handlers = array();

	public function __construct( $file ) {
		$this->template_file = realpath( $file );
		$this->cache_dir = dirname( __FILE__ ) . '/cache';
		if ( ! is_dir( $this->cache_dir ) )
			mkdir( $this->cache_dir );
	}

	/**
	 * Register a handler to be called at a specific point in the template parsing process. The following handler types are supported:
	 *
	 * start_el:
	 *	Called on an opening tag of each $trigger element.
	 *	The handler is passed the following parameters:
	 *		- a reference to the calling FBK_Parser instance
	 *		- the XML element being handled, as an array( 'name' => $tagname, 'attrib' => array( $attrib_key => $attrib_value ) )
	 *		- the string 'start_el', to disambiguate against the end_el handler.
	 *	The handler is expected to return a (modified, unmodified or stripped-down) version of the XML element.
	 *	Through this, the tagname and attributes (see attribute handler) can be modified.
	 *	The following additional properties can be added to the element:
	 *		- suppress_tags: boolean, disable printing the tag (and its corresponding end tag) and suppress any attribute handlers for this element.
	 *		  Note that this does NOT affect the data contained nested within this element, such as cdata and child elements.
	 *		- suppress_nested: boolean, prevent all data nested within this element from being printed or handled.
	 *		  Note that this does not affect data printed through this element's after_start_el and before_end_el strings.
	 *		- before_start_el, after_start_el, before_end_el, after_end_el: string, will be printed in the appropriate location. For void tags,
	 *		  all but the before_start_el string will be appended after the tag.
	 *	If the handler returns a non-array, the element is not altered.
	 *
	 * attribute:
	 *	Called on each $trigger attribute, irrespective of element type.
	 *	The handler is passed the following parameters:
	 *		- a reference to the calling FBK_Parser instance
	 *		- the XML element on which the attribute resides
	 *		- the attribute value
	 *		- the attribute key, to disambiguate
	 *	The handler is expected to return the element with the following optional properties:
	 *		- add_handler: array( $type => callback ), adds a dynamic end_el or cdata handler to the element, which will be called before the usual handler.
	 *		- add_attrib: array( $attrib_key => $attrib_value, ... ) to be added to the element
	 *		- alter_attrib: alias for add_attrib
	 *		- remove_attrib: array( $attrib_key, ... ) or string, attributes that are to be removed from the element
	 *	Note that handlers are called on all of the attributes of the element as it appears after the start_el handler is processed, and only those.
	 *	Therefore, adding or removing otherwise handled attributes from within an attribute handler does not influence processing.
	 *
	 * end_el:
	 *	Called on the closing tag of each $trigger element (including void elements).
	 *	The handler is passed the following parameters:
	 *		- a reference to the calling FBK_Parser instance
	 *		- the XML element being handled
	 *		- the string 'end_el', to disambiguate against the start_el handler.
	 *	The handler is expected to return an array containing optional 'before_end_el' and 'after_end_el' strings. Any other values will be silently discarded.
	 *
	 * cdata:
	 *	Called on cdata nested as a direct descendant of a $trigger element.
	 *	The handler is passed the following parameters:
	 *		- a reference to the calling FBK_Parser instance
	 *		- the parent XML element
	 *		- the raw cdata
	 *	The handler is expected to return either of the following:
	 *		- a string to replace the current cdata node, or
	 *		- an array( 'content' => $content, 'position' => $position ), where $position can be either
	 *			- an integer, to denote the offset at which $content is to be inserted,
	 *			- the string 'before' to prepend $content, or
	 *			- any other value to append $content,
	 *		 while leaving existing cdata in place.
	 *	Note that this handler may be called multiple times if a cdata string is broken up by nested elements.
	 *
	 */
	public function add_handler( $type, $trigger, $handler = false, $version = false ) {
		if ( ! $handler || ! is_callable( $handler ) )
			$handler = false;
		$this->handlers[$type][ strtolower($trigger) ] = compact( 'handler', 'version' );
	}

	/**
	 * Redirect to a different file during the header stage of instantiation.
	 * The old header will run its course, then the new file is parsed with the original parameters and handlers.
	 * Subsequently, the new header and file body are included.
	 * Note that it is entirely possible to run into an infinite redirection loop.
	 */
	public function redirect( $new_file ) {
		$realpath = realpath($new_file);
		if ( $realpath == $this->template_file )
			return;

		$this->is_parsed = false;
		$this->template_file = $realpath;
		$this->redirect = true;
	}

	public function instantiate( $data ) {
		$templater =& $this;

		do {
			if ( ! $this->is_parsed )
				$this->parse();
			$this->redirect = false;
			include( $this->header_file );
		} while ( $this->redirect );

		include( $this->compiled_file );
	}

	public function parse( $reparse = false ) {
		$reparse = $this->build_master() || $reparse;

		if ( ! $reparse ) {
			$data_file_contents = file_get_contents( $this->data_file );
			if ( $data_file_contents ) {
				$this->data = @unserialize( $data_file_contents );
				if ( is_array( $this->data ) ) {
					return $this->is_parsed = true;
				}
			}
		}

		$parser = new FBK_Parser( $this, $this->template_file, $this->compiled_file, $this->header_file, $this->handlers );
		$this->data = $parser->data;
		file_put_contents( $this->data_file, serialize( $this->data ) );
		$this->is_parsed = true;
	}

	protected function build_master() {
		if ( is_readable( $this->cache_dir . '/.master' ) ) {
			$master = @unserialize( file_get_contents( $this->cache_dir . '/.master' ) );
			if ( false === $master ) {
				trigger_error( 'Truncating corrupt master file', E_USER_NOTICE );
				$master = array();
			}
		} else {
			$master = array();
		}

		$handlers_hashable = array();
		foreach ( $this->handlers as $type => $triggers )
			foreach ( $triggers as $trigger => $handler )
				if ( is_array( $handler['handler'] ) && ! is_string( $handler['handler'][0] ) )
					$handlers_hashable[$type][$trigger] = array(
						'handler' => array( get_class( $handler['handler'][0] ), $handler['handler'][1] ),
						'version' => $handler['version']
					);
				else
					$handlers_hashable[$type][$trigger] = $handler;
		$handler_hash = md5( serialize( $handlers_hashable ) );

		if ( isset( $master[$this->template_file] ) ) {
			$m =& $master[$this->template_file];
			$filename = $m[1];
			$this->compiled_file = $this->cache_dir . '/' . $filename . '.compiled';
			$this->data_file = $this->cache_dir . '/' . $filename . '.data';
			$this->header_file = $this->cache_dir . '/' . $filename . '.header';
			$stale =
				   filemtime( $this->template_file ) > $m[0]
				|| ! is_readable( $this->compiled_file )
				|| ! is_readable( $this->data_file )
				|| $handler_hash != $m[2]
			;
		} else {
			$filename = basename( $this->template_file );
			$basenames = array();
			foreach ( $master as $_m ) {
				$basenames[] = $_m[1];
			}
			if ( in_array( $filename, $basenames ) ) {
				for ( $i = 1; in_array( $filename . '.' . $i, $basenames ); $i++ )
					;
				$filename = $filename . '.' . $i;
			}

			$this->compiled_file = $this->cache_dir . '/' . $filename . '.compiled';
			$this->data_file = $this->cache_dir . '/' . $filename . '.data';
			$this->header_file = $this->cache_dir . '/' . $filename . '.header';

			$stale = true;
		}

		if ( $stale ) {
			$master[$this->template_file] = array(
				filemtime( $this->template_file ),
				$filename,
				$handler_hash
			);
			file_put_contents( $this->cache_dir . '/.master', serialize( $master ) );
		}

		return $stale;
	}
}
?>