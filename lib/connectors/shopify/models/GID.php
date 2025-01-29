<?php

namespace ShopifyConnector\connectors\shopify\models;

use ShopifyConnector\exceptions\api\UnexpectedResponseException;

/**
 * Model for a Shopify GID.
 */
final class GID
{

	/**
	 * @var int Identifiers for the various GID types that will be encountered in processing
	 */
	const TYPE_UNKNOWN = 0;
	const TYPE_PRODUCT = 1;
	const TYPE_VARIANT = 2;
	const TYPE_METAFIELD = 3;
	const TYPE_COLLECTION = 4;
	const TYPE_IMAGE = 5;
	const TYPE_TRANSLATION = 6;
	const TYPE_INVENTORY_LEVEL = 7;


	/**
	 * @var string The common GID prefix
	 */
	const GID_PREFIX = 'gid://shopify/';

	/**
	 * @var string Store for the whole GID
	 */
	private string $gid;

	/**
	 * @var string Store for the ID portion of the GID
	 */
	private string $id;

	/**
	 * @var int Store for the GID type
	 */
	private int $type;

	/**
	 * Model for a Shopify GID
	 *
	 * <p>GIDs are typically of the shape: `gid://shopify/<type>/<id>`</p>
	 *
	 * <p>Example: `gid://shopify/Product/632910392`</p>
	 *
	 * @param string $gid The raw GID to parse and store
	 * @throws UnexpectedResponseException On invalid GIDs
	 */
	public function __construct(string $gid)
	{
		$pfx = self::GID_PREFIX;
		if (str_contains($gid, '?')) {
			$parts = explode('?',$gid);
			$gid = $parts[0];
		}
		$fmtMatched = preg_match("-^{$pfx}(\w+)/(\d+)$-", $gid, $matches);
		if ($fmtMatched !== 1) {
			throw new UnexpectedResponseException('Shopify', sprintf(
				'Invalid GID format: %.128s',
				$gid
			));
		}

		$this->gid = $gid;
		$this->type = self::convert_type($matches[1]);
		$this->id = $matches[2];
	}

	/**
	 * Convert the GID type from a string to the int flag matching one of
	 * this class's constants
	 *
	 * @param string $type The type string (e.g. `Product`)
	 * @return int The int value of the corresponding class constant
	 * (constants prefixed with `TYPE_`)
	 */
	public static function convert_type(string $type) : int
	{
		switch (strtolower($type)) {
			case 'product':
				return self::TYPE_PRODUCT;
			case 'productvariant':
				return self::TYPE_VARIANT;
			case 'metafield':
				return self::TYPE_METAFIELD;
			case 'collection':
				return self::TYPE_COLLECTION;
			case 'mediaimage':
				return self::TYPE_IMAGE;
			case 'translation':
				return self::TYPE_IMAGE;
			case 'inventorylevel':
				return self::TYPE_INVENTORY_LEVEL;
		}

		return self::TYPE_UNKNOWN;
	}

	/**
	 * Get the entire GID
	 *
	 * @return string The GID
	 */
	public function get_gid() : string
	{
		return $this->gid;
	}

	/**
	 * Get the ID portion of the GID
	 *
	 * @return string The ID
	 */
	public function get_id() : string
	{
		return $this->id;
	}

	/**
	 * Get the type flag of this GID
	 *
	 * @return int The type flag matching one of the class constants prefixed
	 * with `TYPE_*`
	 */
	public function get_type() : int
	{
		return $this->type;
	}

	/**
	 * Check if this GID is a product type
	 *
	 * @return bool TRUE if this GID is for a product
	 */
	public function is_product() : bool
	{
		return $this->type === self::TYPE_PRODUCT;
	}

	/**
	 * Check if this GID is a variant type.
	 *
	 * @return bool TRUE if this GID is for a variant
	 */
	public function is_variant() : bool
	{
		return $this->type === self::TYPE_VARIANT;
	}

	/**
	 * Check if this GID is a metafield type
	 *
	 * @return bool TRUE if this GID is for a metafield
	 */
	public function is_metafield() : bool
	{
		return $this->type === self::TYPE_METAFIELD;
	}

	/**
	 * Check if this GID is a collection type
	 *
	 * @return bool TRUE if this GID is for a collection
	 */
	public function is_collection() : bool
	{
		return $this->type === self::TYPE_COLLECTION;
	}

	/**
	 * Check if this GID is a media type
	 *
	 * @return bool TRUE if this GID is for a media object
	 */
	public function is_media() : bool
	{
		# NOTE: Add other media-related types into check as they come into use
		return $this->type === self::TYPE_IMAGE;
	}

	/**
	 * Check if this GID is a translation type
	 *
	 * @return bool TRUE if this GID is for a translation object
	 */
	public function is_translation() : bool
	{
		return $this->type === self::TYPE_TRANSLATION;
	}

	/**
	 * Check if this GID is an inventory level type
	 *
	 * @return bool TRUE if this GID is for a inventory level object
	 */
	public function is_inventory_level() : bool
	{
		return $this->type === self::TYPE_INVENTORY_LEVEL;
	}

}

