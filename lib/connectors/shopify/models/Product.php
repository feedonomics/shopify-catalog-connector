<?php

namespace ShopifyConnector\connectors\shopify\models;

use ShopifyConnector\exceptions\api\UnexpectedResponseException;
use ShopifyConnector\util\io\DataUtilities;

/**
 * Model for a Shopify product.
 */
final class Product extends FieldHaver
{

	/**
	 * @var string Key name for the product metadata field
	 */
	const PROD_META_KEY = 'product_meta';

	/**
	 * List of fields to include in output by default. If no fields are
	 * explicitly specified when calling {@see get_output_data()}, then
	 * this is the base set that will be used.
	 *
	 * <p>When adding fields to this list, the names should be explicitly
	 * checked for collisions with fields in ProductVariant, and mappings
	 * added to FIELD_NAME_MAP if there are collisions.</p>
	 *
	 * <p>Field names in this list should be names used by Shopify. They will be
	 * mapped to output names using {@see self::FIELD_NAME_MAP}.</p>
	 */
	const DEFAULT_OUTPUT_FIELDS = [
		'id',
		'title',
		'body_html',
		'vendor',
		'product_type',
		'tags',
		#'published_at',

		# Are these things we want to include? Just used internally?
		#'options',
		#'images',

		'published_status',
		'additional_image_link',
		'publications',
	];

	/**
	 * Map for fields that are referred to by different names in the FDX
	 * ecosystem than in the Shopify ecosystem. The keys of this map are
	 * the Shopify names, and the values are the FDX names:
	 *   'shopify_name' => 'fdx_name'
	 *
	 * <p>This map should only include fields that are a 1-to-1 renaming, not
	 * generated fields, such as "published_status" ("id" doesn't count as
	 * generated for the purposes of this map).
	 * This map's purpose is in part to avoid name collisions with fields
	 * from ProductVariant.</p>
	 */
	const FIELD_NAME_MAP = [
		'id' => 'item_group_id',
		'title' => 'parent_title',
		'body_html' => 'description',
		'vendor' => 'brand',

		'created_at' => 'parent_created_at',
		'updated_at' => 'parent_updated_at',
		'admin_graphql_api_id' => 'parent_admin_graphql_api_id',
	];

	/**
	 * @var int The id of this product
	 * @readonly
	 */
	public int $id;

	/**
	 * @var ProductVariant[] List of variants for which this is the parent product
	 */
	private array $variants = [];

	/**
	 * @var ?array Cache for map of options generated by {@see get_options}
	 */
	private ?array $option_map = null;

	/**
	 * Parse and store the raw product data
	 *
	 * @param array $product_data Map of data for this product
	 */
	public function __construct(array $product_data)
	{
		parent::__construct($product_data);

		$this->id = $this->get('item_group_id');
	}

	/**
	 * Clean up any potential circular references.
	 */
	public function __destruct()
	{
		$this->variants = [];
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
		# NOTE: Some of the cases below include both the Shopify and the
		#   output-mapped names. Ideally, this would only include one or the
		#   other but for the sake of reducing friction right now, that's just
		#   how it's gonna be.
		#   (If the reworking to map upon construction sticks, can take out
		#   the non-mapped entries.)

		# TODO: Default to '' for id good or bad?
		switch ($field) {
			case 'product_type':
				return $this->get('productType', '');

			case 'tags':
				return implode(', ', $this->get('tags', []));

			case 'publications':
				return $this->get_publications();

			case 'published_status':
				return $this->get_published_status();

			case 'image_link':
				return $this->get_image_link();

			case 'additional_image_link':
				return $this->get_image_links();

			/* IDs are currently being handled not as GIDs internally
			case 'id':
			case 'item_group_id':
				return $this->get('item_group_id')
					?? (new GID($this->get('id', '', false)))->get_id();
			*/

			case 'product_id':
				return $this->get('item_group_id');

			case 'title':
			case 'parent_title':
				return $this->get('parent_title', '');

			case 'body_html':
			case 'description':
				return $this->get('descriptionHtml', '');

			case 'vendor':
			case 'brand':
				return $this->get('brand', '');

			case 'parent_created_at':
				return $this->get('createdAt', '');
		}

		return $this->get($field);
	}

	/**
	 * Get the published status. If no published status is set this will check
	 * if there is a published-at time set and return the corresponding
	 * published value
	 *
	 * @return string The published status
	 * @throws UnexpectedResponseException On invalid data
	 */
	public function get_published_status() : string
	{
		return $this->get('published_status') ?? is_null($this->get('publishedAt'))
			? 'unpublished'
			: 'published'
		;
	}

	/**
	 * Get publications associated with a product and return them as an array.
	 *
	 * @return string The json encoded publications array
	 * @throws UnexpectedResponseException On invalid data
	 */
	public function get_publications() : string
	{
		$publications = $this->get('publications');
		return $publications ? json_encode($publications) : '';
	}

	/**
	 * Get an image links for this product.
	 *
	 *
	 * @return string The image link
	 * @throws UnexpectedResponseException On invalid data
	 */
	public function get_image_link() : string
	{
		$img_data = $this->get('images') ?? $this->get('media') ?? [];
		return $img_data[0]['src'] ?? '';
	}

	/**
	 * Get a comma-separated list of image links for this product.
	 *
	 * TODO: This and other image-related things need to be updated for GQL
	 *
	 * @return string The list of additional links
	 * @throws UnexpectedResponseException On invalid data
	 */
	public function get_image_links() : string
	{
		$img_data = $this->get('images') ?? $this->get('media') ?? [];

		# array_column will exclude missing "src"s from output
		# array_filter will exclude empty "src"s from output
		return implode(',', array_filter(array_column(
			$img_data,
			'src'
		)));
	}

	/**
	 * @inheritDoc
	 *
	 * @deprecated Review and clean up
	 *
	 * When specifying fields for any of the parameters, the names used by
	 * Shopify should be used. Field names will be mapped to their output names
	 * using {@see self::FIELD_NAME_MAP} in the output.
	 * The params expected by this generator are:
	 *   mfSplit - (bool) Value of metafields_split_columns option
	 *   extra_fields - (array) Additional fields to include in the output
	 */
	public function get_output_data_old(array $params = [], ?array $baseFields = null) : array
	{
		$only_fields = $baseFields !== null ? $baseFields : self::DEFAULT_OUTPUT_FIELDS;
		$only_fields = DataUtilities::translate_values($only_fields, self::FIELD_NAME_MAP);
		$only_fields = array_combine($only_fields, $only_fields);

		$extra_fields = $params['extra_fields'] ?? [];
		$extra_fields = DataUtilities::translate_values($extra_fields, self::FIELD_NAME_MAP);
		$extra_fields = array_combine($extra_fields, $extra_fields);

		#
		# Return any fields explicitly requested by the client, along with
		# a standard set of fields and metafields if requested, performing
		# processing on certain fields.
		#
		# Order of precedence from low to high is:
		#   - requested fields
		#   - standard fields with processing
		#   - meta fields
		#
		return array_merge(
			array_map([$this, 'get_processed_value'], $extra_fields),
			array_map([$this, 'get_processed_value'], $only_fields),
			#$this->getMetafieldOutput((bool)($params['mfSplit'] ?? false))
		);
	}

	/**
	 * Associate a product variant with this product. To add a variant here is
	 * to say that this is the parent product for that variant.
	 *
	 * @param ProductVariant $variant The variant to associate with this parent product
	 */
	public function add_variant(ProductVariant $variant) : void
	{
		// Likely excessive, but if this needs to be stricter on ensuring associations
		// are correct, then the variant's product id could be checked before it is added
		$this->variants[] = $variant;
	}

	/**
	 * @return ProductVariant[] The list of variants associated with this product
	 */
	public function get_variants() : array
	{
		return $this->variants;
	}

	/**
	 * Get a list of variant objects representing the variants for this product.
	 *
	 * @deprecated TODO: Remove after ensuring nothing is needed from this
	 *
	 * @return ProductVariant[] The list of this product's variants as ProductVariant objects
	 * @throws UnexpectedResponseException On invalid data
	 */
	public function getVariants_old() : array
	{
		$vars = $this->get('variants');

		#
		# TODO: Special case if no variants or skip?
		# ({@see ProductPuller::storeProductData()})
		#
		# Returning an empty dummy ProductVariant will cause us to keep an
		# entry for this product with only product info, no variant info.
		#
		# Returning `[]` would cause this product to be skipped entirely.
		#
		if (!is_array($vars)) {
			return [new ProductVariant($this, [])];
		}

		return array_map(
			fn($v) => new ProductVariant($this, $v),
			$vars
		);
	}

	/**
	 * Get a product option by name
	 *
	 * @param string $name The name of the option
	 * @return array|null The value of the option
	 * @throws UnexpectedResponseException On invalid data
	 */
	public function get_option_by_name(string $name) : ?array
	{
		return $this->get_options()[strtolower($name)] ?? null;
	}

	/**
	 * Get all options for this product
	 *
	 * @return array The array of product options
	 * @throws UnexpectedResponseException On invalid data
	 */
	public function get_options() : array
	{
		# Generate map of options keyed on option name on demand
		if ($this->option_map === null) {
			$this->option_map = [];

			foreach ($this->get('options', []) ?? [] as $opt) {
				if (!isset($opt['name'])) {
					continue;
				}
				$this->option_map[strtolower($opt['name'])] = $opt;
			}

		}

		return $this->option_map;
	}

}

