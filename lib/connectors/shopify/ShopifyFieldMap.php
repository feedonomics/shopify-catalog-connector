<?php

namespace ShopifyConnector\connectors\shopify;

/**
 * Map for Shopify fields and their types
 */
class ShopifyFieldMap
{

	/**
	 * @var int Flag for unknown data type
	 */
	const TYPE_UNKNOWN = 1;

	/**
	 * @var int Flag for varchar data type
	 */
	const TYPE_VARCHAR = 2;

	/**
	 * @var int Flag for json string data type
	 */
	const TYPE_JSONSTR = 4;

	/**
	 * @var int Flag for medium text data type
	 */
	const TYPE_MEDTEXT = 8;

	/**
	 * @var int Flag for text data type
	 */
	const TYPE_TEXT = 16;

	/**
	 * @var int Flag for integer data type
	 */
	const TYPE_UINT = 32;

	/**
	 * @var array<int> $map Map of field names to db types
	 * <p>
	 * The keys in this map are our internal names for fields: the names
	 * used in the database and generated output (mostly), these are not
	 * Shopify's names for fields, even though many of them are the same
	 * in practice.
	 * <p>
	 * The values in this map come from constants provided by this class
	 * to represent actual database data types for each column.
	 *
	 * @see Product::FIELD_NAME_MAP
	 */
	private static array $map = [

		## PRODUCT FIELDS -- Special names
		'item_group_id' => self::TYPE_VARCHAR, # id
		'parent_title' => self::TYPE_VARCHAR, # title
		'description' => self::TYPE_TEXT, # body_html
		'brand' => self::TYPE_VARCHAR, # vendor
		'parent_created_at' => self::TYPE_VARCHAR, # created_at
		'parent_updated_at' => self::TYPE_VARCHAR, # updated_at
		'parent_admin_graphql_api_id' => self::TYPE_VARCHAR, # admin_graphql_api_id

		## PRODUCT FIELDS -- Shopify names
		#'created_at' => self::TYPE_VARCHAR,
		'handle' => self::TYPE_VARCHAR,
		'product_type' => self::TYPE_VARCHAR,
		'published_at' => self::TYPE_VARCHAR,
		'published_scope' => self::TYPE_VARCHAR,
		'status' => self::TYPE_VARCHAR,
		# Theoretical max size for tags is 250*255 = 63,750 chars (+ ~250 for comma seps)
		'tags' => self::TYPE_TEXT,
		'template_suffix' => self::TYPE_VARCHAR,
		# Is 255 chars enough for title?
		#'title' => self::TYPE_VARCHAR,
		#'updated_at' => self::TYPE_VARCHAR,

		## PRODUCT FIELDS -- Large fields (json blobs)
		'variants' => self::TYPE_JSONSTR,
		'options' => self::TYPE_JSONSTR,
		'images' => self::TYPE_JSONSTR,

		## VARIANT FIELDS
		'barcode' => self::TYPE_VARCHAR,
		'compare_at_price' => self::TYPE_VARCHAR,
		'created_at' => self::TYPE_VARCHAR,
		'fulfillment_service' => self::TYPE_VARCHAR,
		'grams' => self::TYPE_VARCHAR,
		'id' => self::TYPE_VARCHAR,
		'image_id' => self::TYPE_VARCHAR,
		'inventory_item_id' => self::TYPE_VARCHAR,
		'inventory_management' => self::TYPE_VARCHAR,
		'inventory_policy' => self::TYPE_VARCHAR,
		'inventory_quantity' => self::TYPE_VARCHAR,
		#'inventory_quantity_adjustment'  # deprecated
		#'old_inventory_quantity'  # deprecated
		'option' => self::TYPE_JSONSTR,
		'position' => self::TYPE_VARCHAR,
		'presentment_prices' => self::TYPE_JSONSTR,
		'price' => self::TYPE_VARCHAR,
		'product_id' => self::TYPE_VARCHAR,
		'requires_shipping' => self::TYPE_VARCHAR, # deprecated
		'sku' => self::TYPE_VARCHAR,
		'tax_code' => self::TYPE_VARCHAR,
		'taxable' => self::TYPE_VARCHAR,
		'title' => self::TYPE_VARCHAR,
		'updated_at' => self::TYPE_VARCHAR,
		'weight' => self::TYPE_VARCHAR,
		'weight_unit' => self::TYPE_VARCHAR,

		'productTaxonomyNode' => self::TYPE_JSONSTR,

	];

	/**
	 * Look up a field's type in the map by name. If no mapping is present
	 * for the given field name, TYPE_UNKNOWN will be returned.
	 *
	 * @param string $fieldName The name of the field to get the type for
	 * @return int A value corresponding to one of the TYPE_* constants
	 *  provided by this class
	 */
	public static function getTypeFor(string $fieldName) : int
	{
		return self::$map[$fieldName] ?? self::TYPE_UNKNOWN;
	}

}

