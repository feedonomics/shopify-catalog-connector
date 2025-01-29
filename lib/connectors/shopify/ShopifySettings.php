<?php

namespace ShopifyConnector\connectors\shopify;

use ShopifyConnector\util\RateLimiter;
use ShopifyConnector\util\io\InputParser;

use ShopifyConnector\exceptions\ValidationException;

/**
 * The Shopify import settings
 */
class ShopifySettings
{

	/**
	 * @var string Flag for the REST API
	 */
	const FLAG_API_REST = 'rest';

	/**
	 * @var string Flag for the GraphQL API
	 */
	const FLAG_API_GRAPHQL = 'graphql';


	/**
	 * Product filters are relevant to all modules, so this filter manager should always
	 * be a part of this primary settings manager.
	 *
	 * (TODO: Make sure all modules are using these filters, not just the products module)
	 *
	 * @var ProductFilterManager Manager for product filter options
	 * @readonly
	 */
	public ProductFilterManager $product_filters;

	/**
	 * NOTE:
	 * This and other non-product filter managers will likely only be relevant to a specific
	 * module, so could be instantiated and managed there rather than here. That would make
	 * modules more self-contained, but for convenience for now, all filter managers will
	 * simply live here in settings.
	 *
	 * @var MetaFilterManager Manager for meta filter options
	 * @readonly
	 */
	public MetaFilterManager $meta_filters;

	/**
	 * @var RateLimiter[] Named array of rate limiters for various apis
	 */
	private array $limiters = [];

	/**
	 * @var string Prefix string to use when creating db tables
	 */
	private string $table_prefix;

	/**
	 * @var bool Flag for compatibility mode
	 * @readonly
	 */
	public bool $compatibility;

	public array $raw_client_options;

	/*
	 * Boolean settings fields
	 */
	public bool $metafields_split_columns;
	public bool $variant_names_split_columns;
	public bool $inventory_level_explode;
	public bool $include_presentment_prices;
	public bool $compare_price_override;
	public bool $use_gmc_transition_id;
	public bool $use_metafield_namespaces;
	public bool $force_bulk_pieces;
	public bool $debug;
	public bool $include_inventory_level;
	public bool $include_collections_meta;

	/*
	 * String settings fields
	 */
	public string $shop_name;
	public string $oauth_token;
	public string $delimiter;
	public string $enclosure;
	public string $escape;
	//public string $strip_characters; // TODO: This doesn't appear to actually be a supported option
	public string $replace;
	public string $tax_rates;

	/*
	 * Array settings fields
	 */
	public array $data_types;

	/**
	 * @var string[] Store for the list of extra parent fields to pull
	 */
	public array $extra_parent_fields = [];

	/**
	 * @var string[] Store for the list of extra variant fields to pull
	 */
	public array $extra_variant_fields = [];

	/**
	 * Parse the given array of client options into a new ShopifySettings
	 * object.
	 *
	 * @param array $client_options The client options to parse
	 * @throws ValidationException On invalid settings
	 */
	public function __construct(array $client_options)
	{
		self::adjust_params($client_options);

		$this->parse_options_into_fields($client_options);
		$this->product_filters = new ProductFilterManager(
			$client_options['product_filters'] ?? [],
			$client_options['product_published_status'] ?? 'published' // Legacy option for fallback, or default "published"
		);
		$this->meta_filters = new MetaFilterManager($client_options['meta_filters'] ?? []);

		$this->raw_client_options = $client_options;

		$this->table_prefix = $this->generate_table_prefix();
	}

	/**
	 * Adjust things in the given client options to support flexibility for
	 * certain parameters.
	 *
	 * The options array will be modified in place.
	 *
	 * Legacy support
	 * TODO: Is there a better way around this? E.g.
	 *   - Is password key ever even used?
	 *   - Can validator be tweaked to require oauth OR password?
	 *
	 * @param array &$client_options The client options to adjust
	 */
	private static function adjust_params(array &$client_options) : void
	{
		if (!isset($client_options['oauth_token'])) {
			$client_options['oauth_token'] = $client_options['password'] ?? null;
		}
	}

	/**
	 * Parse and process the given client options.
	 *
	 * The options array will be modified in place.
	 *
	 * @param array $client_options The client options to parse
	 */
	private function parse_options_into_fields(array $client_options) : void
	{
		# REQUIRED FIELDS

		$this->shop_name = $client_options['shop_name'];
		$this->oauth_token = $client_options['oauth_token'];


		# OTHER FIELDS

		$this->metafields_split_columns = InputParser::extract_boolean($client_options, 'metafields_split_columns');
		$this->variant_names_split_columns = InputParser::extract_boolean($client_options, 'variant_names_split_columns');
		$this->inventory_level_explode = InputParser::extract_boolean($client_options, 'inventory_level_explode');
		$this->include_presentment_prices = InputParser::extract_boolean($client_options, 'include_presentment_prices', true);
		$this->compare_price_override = InputParser::extract_boolean($client_options, 'compare_price_override', true);
		$this->use_gmc_transition_id = InputParser::extract_boolean($client_options, 'use_gmc_transition_id');
		$this->use_metafield_namespaces = InputParser::extract_boolean($client_options, 'use_metafield_namespaces');

		// Shopify is trash, and their graphQL fails on certain stores that have too many products condensed in too short of a span of time
		// Yes, seriously... this forced bulking will be applied to shops with 50K+ products to increase stability.
		$this->force_bulk_pieces = InputParser::extract_boolean($client_options, 'force_bulk_pieces', false);

		$this->debug = InputParser::extract_boolean($client_options, 'debug');

		$this->delimiter = $client_options['delimiter'] ?? ',';
		$this->enclosure = $client_options['enclosure'] ?? '"';
		$this->escape = $client_options['escape'] ?? '"';
		//$this->strip_characters = $client_options['strip_characters'] ?? []; // TODO: Needs to be explode'd
		$this->replace = $client_options['replace'] ?? '';

		$this->tax_rates = $client_options['tax_rates'] ?? '';

		foreach(explode(',', $client_options['extra_parent_fields'] ?? '') as $field){
			$field = trim($field);
			if(!empty($field)){
				$this->extra_parent_fields[] = $field;
			}
		}

		foreach(explode(',', $client_options['extra_variant_fields'] ?? '') as $field){
			$field = trim($field);
			if(!empty($field)){
				$this->extra_variant_fields[] = $field;
			}
		}

		$data_types = array_merge(
			self::get_compat_datatypes($client_options),
			explode(',', $client_options['data_types'] ?? 'products')
		);

		$this->include_inventory_level = in_array('inventory_level', $data_types);
		$this->include_collections_meta = in_array('collections_meta', $data_types);

		$this->data_types = array_diff($data_types, ['inventory_level', 'collections_meta']);
	}

	/**
	 * Compatibility method for translating old-format options to new.
	 *
	 * Original note:
	 * "BACKWARDS COMPATIBILITY UNTIL WE SWITCH EVERYONE OVER. WE WILL TRANSLATE THE OLD OPTIONS TO THE NEW OPTION."
	 *
	 * @param array $client_options The client options to check for compatibility options
	 * @return array The list of additional data types to be included
	 */
	private static function get_compat_datatypes(array $client_options) : array
	{
		$modules = [];

		$modules['meta'] = InputParser::extract_boolean($client_options, 'meta');
		$modules['collections'] = InputParser::extract_boolean($client_options, 'collections');
		$modules['collections_meta'] = InputParser::extract_boolean($client_options, 'collections_meta');
		$modules['inventory_level'] = InputParser::extract_boolean($client_options, 'inventory_level');
		$modules['inventory_item'] = InputParser::extract_boolean($client_options, 'inventory_item');

		// collections meta and inventory level depend on another module
		if ($modules['inventory_level']) {
			$modules['inventory_item'] = true;
		}
		if ($modules['collections_meta']) {
			$modules['collections'] = true;
		}

		return array_keys(array_filter($modules));
	}

	/**
	 * Get the value for the named parameter or NULL if nothing is set for
	 * the given key.
	 *
	 * NOTE: Best to just use the fields of this class directly as much as
	 *   possible. Keeping this for now for fallback for missed options and
	 *   supporting defaults as they were.
	 *
	 * @param string $key The name of the parameter to get
	 * @param mixed $default Default value to use if param is NULL or unset
	 * @return mixed The value or default for the named param
	 */
	public function get(string $key, $default = null)
	{
		return $this->{$key} ?? $this->raw_client_options[$key] ?? $default;
	}

	/**
	 * @param string $name The name of the filter to get, from constants in ProductFilterManager
	 * @return ?mixed The filter value if set, NULL if not
	 */
	public function get_product_filter(string $name)
	{
		return $this->product_filters->get($name);
	}

	/**
	 * @param string $name The name of the filter to get, from constants in MetaFilterManager
	 * @return ?mixed The filter value if set, NULL if not
	 */
	public function get_meta_filter(string $name)
	{
		return $this->meta_filters->get($name);
	}

	/**
	 * Use shop name and microtime to build a unique table prefix. Non-alphanum chars
	 * will be stripped out and the end result will be limited to 32 chars max, leaving
	 * up to 32 for the table tag (e.g. "_metafields_prod", "_metafields_vars")).
	 *
	 * @return string The generated prefix for db table names
	 */
	private function generate_table_prefix() : string {
		return substr(preg_replace('/[^[:alnum:]]/', '',
			$this->shop_name . microtime(true)
		), -32);
	}


	/**
	 * Get the prefix string to use when creating table names. The prefix string is
	 * generated by {@see generate_table_prefix()}, and it generates with an upper
	 * limit on the length. Module authors should reference the db docs for the max
	 * allowed table name length and ensure it's not exceeded. For MariaDB, the max
	 * identifier length is 64 characters.
	 *
	 * If using {@see TemporaryTableGenerator}, remember that it will add some additional
	 * characters as well.
	 *
	 * @return string The prefix to prepend db table names with
	 */
	public function get_table_prefix() : string
	{
		return $this->table_prefix;
	}

	/**
	 * Get the named rate limiter. If no limiter exists under the given name,
	 * one will be created and returned. The rate and per_sec parameters will
	 * be passed to the RateLimiter constructor in the case of creation, but
	 * ignored if the named limiter already exists.
	 *
	 * @param string $name The name of the limiter to retrieve
	 * @param int $rate Param to pass for "rate" if constructing
	 * @param int $per_sec Param to pass for per_seconds if constructing
	 * @return RateLimiter The rate limiter for the given name
	 */
	public function get_limiter(string $name, int $rate = 1, int $per_sec = 1) : RateLimiter
	{
		if (empty($this->limiters[$name])) {
			$this->limiters[$name] = new RateLimiter($rate, $per_sec);
		}
		return $this->limiters[$name];
	}

	/**
	 *
	 */
	public function includes_data_type(string $type) : bool
	{
		return array_search(
				$type,
				$this->get('data_types') ?? [],
				true
			) !== false;

		/*
		# Doing a simple, simple check using commas for delimiters
		# This does not need to be optimized whatsoever
		return strpos(
			",{$this->get('data_types', '')},",
			",{$type},"
		) !== false;
		*/
	}

}

