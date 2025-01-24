<?php

namespace ShopifyConnector\connectors\shopify\models;

use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\ShopifyUtilities;
use JsonException;
use ShopifyConnector\exceptions\ApiResponseException;
use ShopifyConnector\util\io\DataUtilities;

/**
 * Model for a Shopify product variant.
 */
final class ProductVariant extends FieldHaver
{

	/**
	 * @var string The key name for variant metafields
	 */
	const VAR_META_KEY = 'variant_meta';

	/**
	 * @var string The human-readable string for variant that is
	 * in-stock/available
	 */
	const STR_AVAILABLE = 'in stock';

	/**
	 * @var string The human-readable string for a variant that is
	 * out-of-stock/not-available
	 */
	const STR_NOT_AVAILABLE = 'out of stock';

	/**
	 * @var string[] List of standard fields to include from a Shopify product
	 * variant.
	 *
	 * <p>This is the base set that will be included, and all others will be
	 * ignored unless specially requested.</p>
	 *
	 * <p>The names in this list should be the names used by Shopify. They will be
	 * mapped to output names using {@see self::FIELD_NAME_MAP}.</p>
	 */
	const DEFAULT_OUTPUT_FIELDS = [
		'id',
		'product_id',
		'title',
		'gtin',
		'requires_shipping',
		'taxable',
		'weight',
		'weight_unit',
		'price',
		'inventory_item_id',
		'inventory_quantity',
		'inventory_management',
		'inventory_policy',
		'sku',
		'link',
		'sale_price',
		'availability',
		'shipping_weight',
		'image_link',
		'color',
		'size',
		'material',
		'additional_variant_image_link',
		'variant_names',

		# Added conditionally:
		'presentment_prices',
		#'gmc_transition_id',
	];

	/**
	 * Map for fields that are referred to by different names in the FDX
	 * ecosystem than in the Shopify ecosystem. The keys of this map are
	 * the Shopify names, and the values are the FDX names:
	 *   'shopify_name' => 'fdx_name'
	 *
	 * <p>This map should only include fields that are a 1-to-1 renaming, not
	 * generated fields, such as "published_status".</p>
	 */
	const FIELD_NAME_MAP = [
		'title' => 'child_title',
		'barcode' => 'gtin',
	];

	/**
	 * @var int The id of this variant
	 * @readonly
	 */
	public int $id;

	/**
	 * Nullable only to support destructor. In usage, this should always be set.
	 * @var ?Product The parent product for this variant
	 */
	public ?Product $product;

	/**
	 * Set the raw variant product data and its parent product data
	 *
	 * @param Product $parent Product that is the parent of this variant
	 * @param array $variant_data Map of data for this variant
	 */
	public function __construct(Product $parent, array $variant_data)
	{
		parent::__construct($variant_data);
		$this->product = $parent;

		$this->id = $this->get('id');
	}

	/**
	 * Clean up any potential circular references.
	 */
	public function __destruct()
	{
		$this->product = null;
	}

	/**
	 * @inheritDoc
	 * @see self::FIELD_NAME_MAP
	 */
	public function translate_field_name(string $field) : string
	{
		return self::FIELD_NAME_MAP[$field] ?? $field;
	}

	public static function get_translated_default_fields() : array
	{
		return DataUtilities::translate_values(self::DEFAULT_OUTPUT_FIELDS, self::FIELD_NAME_MAP);
	}

	/**
	 * Perform processing for the field specified by name and return the
	 * resulting value.
	 *
	 * @param string $field The name of the field to get the processed value for
	 * @return mixed The processed value for the specified field
	 * @throws ApiResponseException On invalid data
	 */
	public function get_processed_value(string $field)
	{
		switch ($field) {
			/* IDs are currently being handled not as GIDs internally
			case 'id':
				return (new GID($this->get('id', '', false)))->get_id();

			case 'product_id':
				return (new GID($this->get('product_id', '', false)))->get_id();
			*/

			case 'inventory_item_id':
				return (new GID($this->get('inventoryItem', '', false)['id']))->get_id();

			case 'inventory_quantity':
				return $this->get('inventoryQuantity', '', false);
				
			case 'inventory_policy':
				return strtolower($this->get('inventoryPolicy', ''));
				
			case 'inventory_management':
				return $this->get('inventoryManagement', '', false);

			case 'link':
				$domain = SessionContainer::get_active_session()->shop->domain ?? '';
				return $this->get_link($domain);

			case 'presentment_prices':
				return $this->get_presentment_prices();

			case 'price':
				return $this->get_price();

			case 'sale_price':
				return $this->get_sale_price();

			case 'requires_shipping':
				return $this->get('inventoryItem', []) ? 'true' : 'false';
				
			case 'taxable':
				return $this->get('taxable', false,) ? 'true' : 'false';

			case 'availability':
				return $this->get_availability();

			case 'weight':
				return $this->get_normalized_weight();

			case 'weight_unit':
				return $this->get_weight_node()['unit'];

			case 'shipping_weight':
				return $this->get_shipping_weight();

			case 'image_link':
				return $this->get_image_link();

			case 'color':
			case 'size':
			case 'material':
				return $this->get_option_value($field);

			case 'additional_variant_image_link':
				return $this->get_additional_image_links();

			case 'variant_names':
				return $this->generate_variant_names();

			case 'variant_title':
				return ($this->get('selectedOptions')[0]['name'] === 'Title') ? $this->get('selectedOptions')[0]['value'] : '';

			case 'variant_color':
				return ($this->get('selectedOptions')[0]['name'] === 'Color') ? $this->get('selectedOptions')[0]['value'] : '';

			case 'variant_quantity':
				return ($this->get('selectedOptions')[0]['name'] === 'Quantity') ? $this->get('selectedOptions')[0]['value'] : '';

			case 'gmc_transition_id':
				$ccode = SessionContainer::get_active_session()->shop->country_code ?? 'xxx';
				$pid = $this->get('product_id', 'xxx', false);
				$vid = $this->get('id', 'xxx', false);
				return "shopify_{$ccode}_{$pid}_{$vid}";

			case 'tax_rates':
				return SessionContainer::get_active_session()->shop->tax_rates_json ?? '';
		}

		return $this->get($field);
	}

	/**
	 * Generate a link to this variant relative to the parent product.
	 *
	 * @param string $domain The Shopify store's base URL
	 * @return string The product link for this variant
	 * @throws ApiResponseException On invalid data
	 */
	public function get_link(string $domain) : string
	{
		$url_parts = parse_url("https://{$domain}");
		if ($url_parts === false) {
			return '';
		}

		$url_parts['host'] = str_replace('www.', '', $url_parts['host']);
		if (substr_count($url_parts['host'], '.') < 2) {
			$url_parts['host'] = 'www.' . $url_parts['host'];
		}

		return sprintf(
			'%s/products/%s?variant=%s',
			ShopifyUtilities::unparseUrl($url_parts),
			$this->product->get('handle', ''),
			$this->get('id', '', false)
		);
	}

	/**
	 * Get the presentment_prices for this variant.
	 *
	 * @return ?string The presentment_prices as a JSON-encoded string
	 * @throws ApiResponseException On invalid data
	 */
	public function get_presentment_prices() : ?string
	{
		$prices = $this->get('presentment_prices', []);
		return json_encode($prices);
	}

	/**
	 * Get the price data for this variant.
	 *
	 * @return ?string The price as a string
	 * @throws ApiResponseException On invalid data
	 */
	public function get_price() : ?string
	{
		$compare_at_price = $this->get('compareAtPrice', '') ?? '';
		$display_price = $this->get('price', '') ?? '';
		$cap_override = SessionContainer::get_active_setting('compare_price_override', true);
		if ($display_price !== '' && $compare_at_price !== ''  && $cap_override) {
			return $compare_at_price;
		} else {
			return $display_price;
		}
	}

	/**
	 * Get the sale price for this variant.
	 *
	 * @return string The sale price as a string
	 * @throws ApiResponseException On invalid data
	 */
	public function get_sale_price() : string
	{
		$compare_at_price = $this->get('compareAtPrice', '') ?? '';
		$display_price = $this->get('price', '') ?? '';

		if ($display_price !== '' && $compare_at_price !== '') {
			return $display_price;
		}

		return '';
	}

	/**
	 * Get this variant's availability.
	 *
	 * @return string The availability string for this variant
	 * @throws ApiResponseException On invalid data
	 */
	public function get_availability() : string
	{
		if ($this->get('availableForSale') !== null) {
			# Maybe the new GQL way:

			return $this->get('availableForSale') === false
				? self::STR_NOT_AVAILABLE
				: self::STR_AVAILABLE
			;

		} else {
			# The old REST API way:

			$inv_qty = $this->get('inventory_quantity', null);
			if (!is_numeric($inv_qty)) {
				return self::STR_AVAILABLE;
			}
			$inv_qty = (int)$inv_qty;

			$inv_mgmt = $this->get('inventory_management', '');
			$inv_policy = $this->get('inventory_policy', '');

			if (
				$inv_qty < 1
				&& strtolower($inv_mgmt) === 'shopify'
				&& strtolower($inv_policy) === 'deny'
			) {
				return self::STR_NOT_AVAILABLE;
			}

			return self::STR_AVAILABLE;
		}
	}

	/**
	 * Get weight value and unit in a consistent container, whether we're working
	 * with the previously used format or the new GQL format. Returns an array with
	 * "unit" and "value" keys.
	 *
	 * @return Array<string, mixed> An array that is guaranteed to conform to the weight node structure
	 * @throws ApiResponseException
	 */
	private function get_weight_node() : array
	{
		if ($this->get('inventoryItem') !== null) {
			# The new GQL way:

			$inv_item = $this->get('inventoryItem', []);
			$weight_node = $inv_item['measurement']['weight'] ?? [];

			return [
				'value' => $weight_node['value'] ?? '',
				'unit' => match ($weight_node['unit']) {
					'GRAMS' => 'g',
					'OUNCES'=> 'oz',
					'POUNDS'=> 'lb',
					'KILOGRAMS'=> 'kg',
					default => ''
				},
			];

		} else {
			# The old REST API way:

			return [
				'unit' => $this->get('weight_unit', '', false),
				'value' => $this->get('weight', '', false),
			];
		}
	}

	/**
	 * Get the normalized weight value. If a value is passed, it will be normalized,
	 * otherwise the value will be retrieved via {@see get_weight_node()}.
	 *
	 * If the weight does not contain a decimal point, ".0" will be added to
	 * the end so that it always contains a decimal place.
	 *
	 * @param mixed $weight The weight value to normalize
	 * @return string The normalized weight string
	 */
	public function get_normalized_weight($weight = null) : string
	{
		$weight = $weight ?? $this->get_weight_node()['value'];
		if ($weight !== '' && strpos($weight, '.') === false) {
			$weight .= '.0';
		}
		return $weight;
	}

	/**
	 * Get this variant's shipping weight string.
	 *
	 * <p>The shipping weight is formed by joining the values of "weight" and
	 * "weight_unit" from the variant data. Any leading or trailing spaces
	 * will be removed. If either of the constituent values is not present
	 * then the resulting string will include only the present value. When
	 * neither value is present, an empty string will be returned.</p>
	 *
	 * @return string The shipping weight string for this variant
	 */
	public function get_shipping_weight() : string
	{
		$weight_node = $this->get_weight_node();
		$weight = $this->get_normalized_weight($weight_node['value']);
		return trim("{$weight} {$weight_node['unit']}");
	}

	/**
	 * Get a comma-separated list of image links for this variant.
	 *
	 * @return string The list of image links
	 * @throws ApiResponseException On invalid data
	 */
	public function get_image_link() : string
	{
		$img_data = $this->get('image') ?? [];
		return $img_data['url'] ?? '';
	}

	/**
	 * Get this variant's value for the named option.
	 *
	 * <p>If no such option exists or no value is set with the given name, an
	 * empty string will be returned.</p>
	 *
	 * @param string $name The name of the option to get the value of
	 * @return string The value of the named option
	 * @throws ApiResponseException On invalid data
	 */
	public function get_option_value(string $name) : string
	{
		$pos = $this->product->get_option_by_name($name)['position'] ?? null;
		return ($pos === null) ? '' : $this->get("option{$pos}", '');
	}

	/**
	 * Get the variant image links
	 *
	 * @return string The list of additional image links
	 * @throws ApiResponseException On invalid data
	 */
	public function get_additional_image_links() : string
	{
		$images = [];
		foreach ($this->product->get('media', []) ?? [] as $image) {
			if (empty($image['src'])) {
				continue;
			}
			if (!empty($image['variant_ids'])) {
				if (in_array($this->get('id'), $image['variant_ids'])) {
					$images[] = $image['src'];
				}
			} else {
				$color_tag_a = "color-{$this->get_option_value('color')}";
				$color_tag_b = $this->get_option_value('color');
				$alt = $image['alt'] ?? '';

				if (stripos($alt, $color_tag_a) !== false
					|| stripos($alt, $color_tag_b) !== false
				) {
					$images[] = $image['src'];
				}
			}
		}

		return implode(',', $images);
	}

	/**
	 * Generate and return a list of the variant names for this product.
	 *
	 * @return string The variant names
	 * @throws ApiResponseException On errors encoding the variant names
	 */
	public function generate_variant_names() : string
	{
		// example "options":[{"name":"Color","position":1,"values":["Charcoal","Periwinkle"]}]
		$vnames = [];
		foreach ($this->product->get('options', []) ?? [] as $opt) {
			$vnames[$opt['name']] = $opt['values'][0] ?? '';
			//$vnames[$opt['name']] = $this->get("option{$opt['position']}", '');
		}

		try {
			return json_encode($vnames, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR);
		} catch(JsonException $e){
			throw new ApiResponseException(sprintf(
				'Could not parse variant names. Reason: %s',
				$e->getMessage()
			));
		}
	}

	/**
	 * @inheritDoc
	 *
	 * @deprecated Review and clean up
	 *
	 * The params expected by this generator are:
	 *   domain - (string) The shop's domain
	 *   mfSplit - (bool) Value of metafields_split_columns option
	 *   extra_fields - (array) Additional fields to include in the output
	 */
	public function get_output_data_old(array $params = [], ?array $baseFields = null) : array
	{
		$only_fields = $baseFields !== null ? $baseFields : self::DEFAULT_OUTPUT_FIELDS;
		$only_fields = array_combine($only_fields, $only_fields);

		$extra_fields = $params['extra_fields'] ?? [];
		$extra_fields = array_combine($extra_fields, $extra_fields);

		$this->active_params = $params;

		#
		# Return any fields explicitly requested by the client, along with
		# a standard set of fields and metafields if requested, performing
		# processing on certain fields.
		#
		# Order of precedence from low to high is:
		#   - additional requested fields
		#   - standard/listed fields with processing
		#   - meta fields
		#
		$ret = array_merge(
			array_map([$this, 'get_processed_value'], $extra_fields),
			array_map([$this, 'get_processed_value'], $only_fields),
			#$this->getMetafieldOutput((bool)($params['mfSplit'] ?? false))
		);

		# Set back to empty array when done because it's not meant to persist
		$this->active_params = [];

		return $ret;
	}

}

