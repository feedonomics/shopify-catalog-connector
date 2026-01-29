<?php

namespace ShopifyConnector\connectors\shopify\metafields;

use ShopifyConnector\connectors\shopify\models\GID;
use ShopifyConnector\connectors\shopify\models\Metafield;

use ShopifyConnector\exceptions\InfrastructureErrorException;

use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\queries\BatchedDataInserter;

/**
 * Temporary storage for Shopify metafield data during processing
 */
class MetafieldTemporaryStorage
{
	/**
	 * Used to store the metafield names when `metafields_split_columns` is enabled
	 *
	 * @var array
	 */
	private array $metafield_names = [];

	/**
	 * @param BatchedDataInserter $insert_product
	 * @param BatchedDataInserter $insert_variant
	 */
	public function __construct(
		private readonly BatchedDataInserter $insert_product,
		private readonly BatchedDataInserter $insert_variant,
	) {
	}

	/**
	 * Commit any remaining inserts
	 *
	 * @param MysqliWrapper $cxn
	 * @return void
	 * @throws InfrastructureErrorException
	 */
	public function end_batch(MysqliWrapper $cxn) : void
	{
		$this->insert_product->run_query($cxn);
		$this->insert_variant->run_query($cxn);
	}

	/**
	 * Stores a row of product metafield data
	 *
	 * @param MysqliWrapper $cxn
	 * @param GID $product_id
	 * @param array $metafield_data
	 * @return void
	 * @throws InfrastructureErrorException
	 */
	public function add_product_metafield(MysqliWrapper $cxn, GID $product_id, array $metafield_data) : void
	{
		$metafield = new Metafield($metafield_data, Metafield::TYPE_PRODUCT);

		$this->insert_product->add_value_set($cxn, [
			Metafields::COLUMN_ID => $product_id->get_id(),
			Metafields::COLUMN_DATA => json_encode($metafield),
		]);

		$this->store_metafield_name($metafield);
	}

	/**
	 * Stores an empty row of product metafield data when no metafields are present
	 *
	 * @param MysqliWrapper $cxn
	 * @param GID $product_id
	 * @return void
	 * @throws InfrastructureErrorException
	 */
	public function add_product_metafield_placeholder(MysqliWrapper $cxn, GID $product_id) : void
	{
		$this->insert_product->add_value_set($cxn, [
			Metafields::COLUMN_ID => $product_id->get_id(),
			Metafields::COLUMN_DATA => '',
		]);
	}

	/**
	 * Stores a row of variant metafield data
	 *
	 * @param MysqliWrapper $cxn
	 * @param GID $product_id
	 * @param GID $variant_id
	 * @param array $metafield_data
	 * @return void
	 * @throws InfrastructureErrorException
	 */
	public function add_variant_metafield(MysqliWrapper $cxn, GID $product_id, GID $variant_id, array $metafield_data) : void
	{
		$metafield = new Metafield($metafield_data, Metafield::TYPE_VARIANT);

		$this->insert_variant->add_value_set($cxn, [
			Metafields::COLUMN_ID => $variant_id->get_id(),
			Metafields::COLUMN_PARENT_ID => $product_id->get_id(),
			Metafields::COLUMN_DATA => json_encode($metafield),
		]);

		$this->store_metafield_name($metafield);
	}

	/**
	 * Stores an empty row variant product metafield data when no metafields are present
	 *
	 * @param MysqliWrapper $cxn
	 * @param GID $product_id
	 * @param GID $variant_id
	 * @return void
	 * @throws InfrastructureErrorException
	 */
	public function add_variant_metafield_placeholder(MysqliWrapper $cxn, GID $product_id, GID $variant_id) : void
	{
		$this->insert_variant->add_value_set($cxn, [
			Metafields::COLUMN_ID => $variant_id->get_id(),
			Metafields::COLUMN_PARENT_ID => $product_id->get_id(),
			Metafields::COLUMN_DATA => '',
		]);
	}

	/**
	 * Returns the unique metafield names collected during processing.
	 *
	 * @param bool $split_columns
	 * @return array
	 */
	public function get_unique_metafield_names(bool $split_columns) : array
	{
		return $split_columns
			? array_values(array_keys($this->metafield_names))
			: [];
	}

	/**
	 * @param Metafield $metafield
	 * @return void
	 */
	private function store_metafield_name(Metafield $metafield) : void
	{
		$this->metafield_names[$metafield->get_identifier()] = true;
	}

}