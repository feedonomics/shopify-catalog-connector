<?php

namespace ShopifyConnector\util\io;


/**
 * Utility for creating, storing, and filling a data template so output is
 * always consistent
 *
 * ```php
 * // Example usage
 * $my_template = new OutputTemplate();
 * $my_template->append_to_template(['id' => 'ID', 'sku' => 'SKU');
 * $my_template->append_to_template(['desc' => 'Description']);
 *
 * insert_row_func($my_template->get_template());
 *
 * // $product_1 = [id => 1, sku => abc, desc => bla bla bla]
 * // $product_2 = [desc => bla2 bla2 bla2, id => 2]
 * insert_row_func($my_template->fill_template($product_1));
 * insert_row_func($my_template->fill_template($product_2));
 *
 * // Output file should look like:
 * // ID,SKU,Description
 * // 1,abc,bla bla bla
 * // 2,,bla2 bla2 bla2
 * ```
 */
final class OutputTemplate {

	/**
	 * @var bool Flag for whether the template has been modified and the
	 * template cache needs to be regenerated
	 */
	private bool $dirty = false;

	/**
	 * @var array Cache for the raw keys from the {@see $template} and empty
	 * strings for values
	 */
	private array $template_cache = [];

	/**
	 * @var array Store for the current template
	 * @see append_to_template for a description of the format
	 */
	private array $template = [];

	/**
	 * @var array Store for building output programmatically
	 */
	private array $output_cache = [];

	/**
	 * Prepend a list of key names to the template in the format
	 * [raw_value => display_name] where the 'raw_value' is the key name as-is
	 * from the client api and the 'display_name' is the key name as it should
	 * be displayed to users (e.g. as a csv header)
	 *
	 * <p>These will be added to the beginning of the existing template</p>
	 *
	 * @param string[] $template The key names to prepend
	 */
	public function prepend_to_template(array $template) : void {
		$this->template = array_merge($template, $this->template);
		$this->dirty = true;
	}

	/**
	 * Append a list of key names to the template in the format
	 * [raw_value => display_name] where the 'raw_value' is the key name as-is
	 * from the client api and the 'display_name' is the key name as it should
	 * be displayed to users (e.g. as a csv header)
	 *
	 * <p>These will be added to the end of the existing template</p>
	 *
	 * @param string[] $template The key names to append
	 */
	public function append_to_template(array $template) : void {
		$this->template = array_merge($this->template, $template);
		$this->dirty = true;
	}

	/**
	 * Append a list of field names to the template where the key names and
	 * display names are the same in the output template. This will ignore
	 * the passed in array's keys and use the values for both
	 *
	 * <p>These will be added to the end of the existing template</p>
	 *
	 * @param string[] $template The key names to append
	 */
	public function append_keyless_to_template(array $template) : void {
		foreach($template as $t){
			$this->template[$t] = $t;
		}
		$this->dirty = true;
	}

	/**
	 * Remove an entry in the template by key name
	 *
	 * @param string $key The key name to remove
	 */
	public function remove_key(string $key) : void {
		if(isset($this->template[$key])){
			unset($this->template[$key]);
			$this->dirty = true;
		}
	}

	/**
	 * Get the current template with raw names as keys and display names as
	 * values
	 *
	 * @return array The current template
	 */
	public function get_template() : array {
		return $this->template;
	}

	/**
	 * Merge the given data with the template, filling in any known values and
	 * filling in the rest with empty string placeholders so the return is
	 * consistent every time
	 *
	 * @param array $data The data to fill the template with. $data keys should
	 * match 1-to-1 with the keys defined in the template
	 * @return array The filled template
	 */
	public function fill_template(array $data) : array {
		// Refresh the cached template if necessary and get a copy for filling
		$this->refresh_cache();
		$template = $this->template_cache;

		// Only fill values in the template, anything else is discarded
		foreach($data as $k => $v){
			if(isset($template[$k]))
				$template[$k] = $v;
		}

		return $template;
	}

	/**
	 * Add a set of data to an internally stored template to build on
	 *
	 * @param array $data The data to add to the current data cache
	 * @return OutputTemplate For chaining
	 * @see get_cached_data() For retrieving the data once built
	 */
	public function cache_data(array $data) : OutputTemplate {
		if(empty($this->output_cache)){
			$this->refresh_cache();
			$this->output_cache = $this->template_cache;
		}

		foreach($data as $k => $v){
			if(isset($this->output_cache[$k]))
				$this->output_cache[$k] = $v;
		}

		return $this;
	}

	/**
	 * Get the stored set of data in the template and reset the cache
	 *
	 * @return array The data that has been stored internally so far
	 * @see cache_data() For adding data to the internal cache
	 */
	public function get_cached_data() : array {
		$ret = $this->output_cache;
		$this->output_cache = [];
		return $ret;
	}

	/**
	 * Pull a property out of the current output cache
	 *
	 * @param int|string $key The key to lookup on
	 * @return mixed The value or null if the key is not set
	 */
	public function get_cached_property($key){
		return $this->output_cache[$key] ?? null;
	}

	/**
	 * Check if the template has been modified (via the {@see $dirty} flag) and
	 * update the {@see $template_cache} as necessary
	 */
	private function refresh_cache() : void {
		if(!$this->dirty) return;
		$this->template_cache = array_map(fn($v) => '', $this->template);
		$this->dirty = false;
	}

}

