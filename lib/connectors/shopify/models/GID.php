<?php

namespace ShopifyConnector\connectors\shopify\models;

use ShopifyConnector\exceptions\ApiResponseException;

/**
 * Model for a Shopify GID.
 */
final class GID
{
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
	 * @var ShopifyType Store for the GID type
	 */
	private ShopifyType $type;

	/**
	 * Model for a Shopify GID
	 *
	 * <p>GIDs are typically of the shape: `gid://shopify/<type>/<id>`</p>
	 *
	 * <p>Example: `gid://shopify/Product/632910392`</p>
	 *
	 * @param string $gid The raw GID to parse and store
	 * @throws ApiResponseException On invalid GIDs
	 */
	public function __construct(string $gid)
	{
		$pfx = self::GID_PREFIX;
		if (str_contains($gid, '?')) {
			$parts = explode('?', $gid);
			$gid = $parts[0];
		}
		$fmtMatched = preg_match("-^{$pfx}(\w+)/(\d+)$-", $gid, $matches);
		if ($fmtMatched !== 1) {
			throw new ApiResponseException(sprintf(
				'Invalid Shopify GID format: %.128s',
				$gid
			));
		}

		$this->gid = $gid;
		$this->type = self::convert_type($matches[1]);
		$this->id = $matches[2];
	}

	/**
	 * Convert the GID type from a string to the ShopifyType enum.
	 *
	 * This supports both GID types e.g. InventoryLevel and the
	 * GraphQL type key e.g. inventoryLevel.
	 *
	 * @param string $type The type string (e.g. `Product`)
	 * @return ShopifyType
	 */
	private static function convert_type(string $type) : ShopifyType
	{
		return ShopifyType::tryFrom($type)
			?? ShopifyType::tryFrom(ucfirst($type))
			?? ShopifyType::UNKNOWN;
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
	 * Check if this GID is a product type
	 *
	 * @return bool TRUE if this GID is for a product
	 */
	public function is_product() : bool
	{
		return $this->type === ShopifyType::PRODUCT;
	}

	/**
	 * Check if this GID is a variant type.
	 *
	 * @return bool TRUE if this GID is for a variant
	 */
	public function is_variant() : bool
	{
		return $this->type === ShopifyType::PRODUCT_VARIANT;
	}

	/**
	 * Check if this GID is a metafield type
	 *
	 * @return bool TRUE if this GID is for a metafield
	 */
	public function is_metafield() : bool
	{
		return $this->type === ShopifyType::METAFIELD;
	}

	/**
	 * Check if this GID is a collection type
	 *
	 * @return bool TRUE if this GID is for a collection
	 */
	public function is_collection() : bool
	{
		return $this->type === ShopifyType::COLLECTION;
	}

	/**
	 * Check if this GID is a media type
	 *
	 * @return bool TRUE if this GID is for a media object
	 */
	public function is_media() : bool
	{
		# MediaImage only; other Media interface types are handled by is_video()
		return $this->type === ShopifyType::MEDIA_IMAGE;
	}

	/**
	 * Check if this GID is a non-image media type (video, external video,
	 * or 3D model). Image media is handled separately via {@see is_media()};
	 * the is_media()/is_video() split mirrors how Shopify separates MediaImage
	 * from the other Media interface types.
	 *
	 * @return bool TRUE if this GID is for non-image media
	 */
	public function is_video() : bool
	{
		return in_array($this->type, [
			ShopifyType::VIDEO,
			ShopifyType::EXTERNAL_VIDEO,
			ShopifyType::MODEL_3D,
		], true);
	}

	/**
	 * Check if this GID is a translation type
	 *
	 * @return bool TRUE if this GID is for a translation object
	 */
	public function is_translation() : bool
	{
		return $this->type === ShopifyType::TRANSLATION;
	}

	/**
	 * Check if this GID is an inventory level type
	 *
	 * @return bool TRUE if this GID is for an inventory level object
	 */
	public function is_inventory_level() : bool
	{
		return $this->type === ShopifyType::INVENTORY_LEVEL;
	}

	/**
	 * Check if this GID is a publication type
	 *
	 * @return bool TRUE if this GID is for a publication object
	 */
	public function is_publication() : bool
	{
		return $this->type === ShopifyType::PUBLICATION;
	}

	/**
	 * Check if this GID is a Location type
	 *
	 * @return bool TRUE if this GID is for a location object
	 */
	public function is_location() : bool
	{
		return $this->type === ShopifyType::LOCATION;
	}

	/**
	 * Check if the GID is a market catalog type
	 *
	 * @return bool
	 */
	public function is_market_catalog() : bool
	{
		return $this->type === ShopifyType::MARKET_CATALOG;
	}
}