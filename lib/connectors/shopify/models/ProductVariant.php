<?php

namespace ShopifyConnector\connectors\shopify\models;

use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\ShopifyUtilities;

use ShopifyConnector\exceptions\api\UnexpectedResponseException;
use ShopifyConnector\util\io\DataUtilities;


use JsonException;

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
		'fulfillment_service',
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

	private array $variant_name_cache = [];
	private array $variant_name_massaged_cache = [];


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
	 * @throws UnexpectedResponseException On invalid data
	 */
	public function get_processed_value(string $field)
	{
		# TODO: Default to '' for ids good or bad?
		switch ($field) {
			/* IDs are currently being handled not as GIDs internally
			case 'id':
				return (new GID($this->get('id', '', false)))->get_id();

			case 'product_id':
				return (new GID($this->get('product_id', '', false)))->get_id();
			*/

			case 'created_at':
				return $this->get('createdAt', '');

			case 'inventory_item_id':
				$iid = $this->get('inventoryItem', [], false)['id'] ?? null;
				return $iid !== null ? (new GID($iid))->get_id() : '';

			case 'inventory_quantity':
				return $this->get('inventoryQuantity', '', false);
				
			case 'inventory_policy':
				return strtolower($this->get('inventoryPolicy', ''));

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
				$req_ship = $this->get('inventoryItem', [])['requiresShipping'] ?? false;
				return $req_ship ? 'true' : 'false';
				
			case 'taxable':
				return $this->get('taxable', false) ? 'true' : 'false';

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
				return json_encode($this->generate_variant_names(), JSON_FORCE_OBJECT);

			case 'gmc_transition_id':
				$ccode = SessionContainer::get_active_session()->shop->country_code ?? 'xxx';
				$pid = $this->product->get('item_group_id', 'xxx', false);
				$vid = $this->get('id', 'xxx', false);
				return "shopify_{$ccode}_{$pid}_{$vid}";

			case 'tax_rates':
				return SessionContainer::get_active_session()->shop->tax_rates_json ?? '';

			// Give metafields priority over any potential "variant_meta" option fields
			case 'variant_meta':
				$vm = $this->get('variant_meta');
				if ($vm !== null) {
					return $vm;
				}
		}

		if (SessionContainer::get_active_setting('variant_names_split_columns')) {
			$variant_split_name_value = $this->get_split_name_value($field);
			if ($variant_split_name_value !== null) {
				return $variant_split_name_value;
			}
		}

		return $this->get($field);
	}

	/**
	 * Generate a link to this variant relative to the parent product.
	 *
	 * @param string $domain The Shopify store's base URL
	 * @return string The product link for this variant
	 * @throws UnexpectedResponseException On invalid data
	 */
	public function get_link(string $domain) : string
	{
		$url_parts = parse_url("https://{$domain}");
		if ($url_parts === false) {
			# TODO: Log info? Add test case
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
	 * @return string The presentment_prices as a JSON-encoded string
	 * @throws UnexpectedResponseException On invalid data
	 */
	public function get_presentment_prices() : string
	{
		$output_prices = [];

		foreach ($this->get('presentment_prices', []) as $price) {
			$compare_price = empty($price['compareAtPrice']) ? null : [
				'amount' => number_format($price['compareAtPrice']['amount'],2,'.',''),
				'currency_code' => $price['compareAtPrice']['currencyCode'],
			];

			$output_prices[] = [
				'price' => [
					'amount' => number_format($price['price']['amount'],2,'.',''),
					'currency_code' => $price['price']['currencyCode'],
				],
				'compare_at_price' => $compare_price,
			];
		}

		return json_encode($output_prices);
	}

	/**
	 * Get the price data for this variant.
	 *
	 * @return string The price as a string
	 * @throws UnexpectedResponseException On invalid data
	 */
	public function get_price() : string
	{
		$compare_at_price = $this->get('compareAtPrice', '') ?? '';
		$display_price = $this->get('price', '') ?? '';
		$cap_override = SessionContainer::get_active_setting('compare_price_override', true);
		if ($display_price !== '' && $compare_at_price !== '' && $cap_override) {
			return $compare_at_price;
		} else {
			return $display_price;
		}
	}

	/**
	 * Get the sale price for this variant.
	 *
	 * @return string The sale price as a string
	 * @throws UnexpectedResponseException On invalid data
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

	public function get_translated_inventory_management() : string
	{
		$value = strtolower($this->get('inventoryManagement', '', false));
		if ($value === 'not_managed') {
			return '';
		}
		return $value;
	}

	/**
	 * Get this variant's availability.
	 *
	 * @return string The availability string for this variant
	 * @throws UnexpectedResponseException On invalid data
	 */
	public function get_availability() : string
	{
		$inv_item = $this->get('inventoryItem', []);
		$tracked_node = $inv_item['tracked'];

		$ip = strtolower($this->get('inventoryPolicy', '', false));
		$iq = $this->get('inventoryQuantity', 0, false);

		return (
			($tracked_node && $iq < 1 && $ip === 'deny')
			||
			$this->get('availableForSale') === false
		)
			? self::STR_NOT_AVAILABLE
			: self::STR_AVAILABLE;
	}

	/**
	 * Get weight value and unit in a consistent container, whether we're working
	 * with the previously used format or the new GQL format. Returns an array with
	 * "unit" and "value" keys.
	 *
	 * @return Array<string, mixed> An array that is guaranteed to conform to the weight node structure
	 * @throws UnexpectedResponseException
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
	 * TODO: This and other image-related things need to be updated for GQL
	 *
	 * @return string The list of image links
	 * @throws UnexpectedResponseException On invalid data
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
	 * @throws UnexpectedResponseException On invalid data
	 */
	public function get_option_value(string $name) : string
	{
		return $this->generate_variant_names_massaged()[strtolower($name)] ?? '';
	}

	/**
	 * Get the variant image links
	 *
	 * @return string The list of additional image links
	 * @throws UnexpectedResponseException On invalid data
	 */
	public function get_additional_image_links() : string
	{
		$images = [];

		$main_image = $this->get('image')['url'] ?? '';
		if(!empty($main_image)){
			$images[] = $main_image;
		}

		foreach ($this->product->get('media', []) ?? [] as $image) {
			if (empty($image['src'])) {
				continue;
			}

			$color_tag = trim(strtolower($this->get_option_value('color')));
			$alt = strtolower($image['altText'] ?? '');

			if (stripos($alt, $color_tag) !== false) {
				$images[] = $image['src'];
			}
		}

		$images = array_unique($images);
		return implode(',', $images);
	}

	/**
	 * Generate and return a list of the variant names for this product.
	 * <hr>
	 * TODO:
	 * - Is this looking in the right place for the option values?
	 *   - Need to get from fields['option'] instead of direct?
	 * - Current seems to default to "Title" when no options set
	 *   - Where/How does this happen?
	 * - Would it be better to iterate variant's option array instead of parent's options
	 *
	 * @return array The variant names
	 * @throws UnexpectedResponseException On errors encoding the variant names
	 */
	private function generate_variant_names() : array
	{
		if (empty($this->variant_name_cache)) {
			foreach ($this->get('selectedOptions', []) as $option) {
				$name = $option['name'] ?? '';
				$this->variant_name_cache[$name] = $option['value'] ?? null;
			}
		}
		return $this->variant_name_cache;
	}

	private function generate_variant_names_massaged() : array
	{
		if (empty($this->variant_name_massaged_cache)) {
			$names = $this->generate_variant_names();
			//$this->variant_name_massaged_cache = array_change_key_case($names, CASE_LOWER);
			foreach ($names as $key => $value) {
				$m_key = strtolower($key);
				// $m_key = ShopifyUtilities::clean_column_name($key, '_');
				$this->variant_name_massaged_cache[$m_key] = $value;
			}
		}
		return $this->variant_name_massaged_cache;
	}

	private function get_split_name_value(string $field) : ?string
	{
		$field_parts = explode('_', $field);
		if ($field_parts[0] !== 'variant' || empty($field_parts[1])) {
			return null;
		}

		$names = $this->generate_variant_names_massaged();
		return $names[$field_parts[1]] ?? null;
	}

}

