<?php

namespace ShopifyConnector\connectors\shopify\inventories;

use Exception;
use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\interfaces\iModule;
use ShopifyConnector\connectors\shopify\models\Product;
use ShopifyConnector\connectors\shopify\models\ProductVariant;
use ShopifyConnector\connectors\shopify\pullers\BulkInventories;
use ShopifyConnector\connectors\shopify\structs\PullStats;
use ShopifyConnector\connectors\shopify\traits\StandardModule;
use Generator;
use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\queries\BatchedDataInserter;
use ShopifyConnector\util\db\TableHandle;

/**
 * The Inventory module main class.
 */
class Inventories implements iModule
{

	use StandardModule;


	private SessionContainer $session;

	private ?TableHandle $table_product = null;
	private ?TableHandle $table_variant = null;


	public function __construct(SessionContainer $session)
	{
		$this->session = $session;
	}

	public function get_module_name() : string
	{
		return 'inventories';
	}

	public function get_output_field_list() : array
	{
		#'inventory_quantity',
		#'inventory_management',
		#'inventory_policy',

		#'inventory_item_id',

		return [
			'inventory_item'
		];
	}

	/**
	 * @inheritDoc
	 */
	public function run(MysqliWrapper $cxn, PullStats $stats) : void
	{
		$prefix = $this->session->settings->get_table_prefix();
		$this->table_product = $this->generate_product_table($cxn, "{$prefix}_translations_prod");
		$this->table_variant = $this->generate_variant_table($cxn, "{$prefix}_translations_vars");

		$insert_product = new BatchedDataInserter($cxn, $this->get_product_inserter($cxn, $this->table_product));
		$insert_variant = new BatchedDataInserter($cxn, $this->get_variant_inserter($cxn, $this->table_variant));

		$puller = new BulkInventories($this->session);
		$processing_result = $puller->do_bulk_pull($cxn, $insert_product, $insert_variant);
		$this->translation_names = $processing_result->result;
	}

	/**
	 * @inheritDoc
	 */
	public function get_products(MysqliWrapper $cxn) : Generator
	{
		if ($this->table_product === null) {
			throw new Exception('Tried to retrieve data before running: ' . $this->get_module_name());
		}

		$last_retrieved_pid = 0;
		while (true) {
			$product = $this->get_next_product($cxn, $last_retrieved_pid);
			if ($product === null) {
				// No more products
				return;
			}

			$last_retrieved_pid = (int)$product->id;
			if ($last_retrieved_pid <= 0) {
				return;
			}

			yield $product;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function add_data_to_product(MysqliWrapper $cxn, Product $product) : void
	{
		throw new Exception('Not implemented');
	}

	/**
	 * @inheritDoc
	 */
	public function add_data_to_variant(MysqliWrapper $cxn, ProductVariant $variant) : void
	{
		throw new Exception('Not implemented');
	}

}

