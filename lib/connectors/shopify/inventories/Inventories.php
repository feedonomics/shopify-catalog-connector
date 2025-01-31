<?php

namespace ShopifyConnector\connectors\shopify\inventories;

use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\interfaces\iModule;
use ShopifyConnector\connectors\shopify\models\Inventory;
use ShopifyConnector\connectors\shopify\models\Product;
use ShopifyConnector\connectors\shopify\models\ProductVariant;
use ShopifyConnector\connectors\shopify\pullers\BulkInventories;
use ShopifyConnector\connectors\shopify\structs\PullStats;
use ShopifyConnector\connectors\shopify\traits\StandardModule;

use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\queries\BatchedDataInserter;
use ShopifyConnector\util\db\TableHandle;

use Generator;

/**
 * The Inventory module main class.
 *
 * TODO: This seems to have some special output behavior in SM where it can add more rows
 *   to the result depending on settings. To accommodate that in this design, the Run Manger
 *   may need to treat this as the main module when present, and the get_products method
 *   here can then affect that same behavior.
 */
class Inventories implements iModule
{

	use StandardModule;


	private SessionContainer $session;

	private ?TableHandle $table_variant = null;


	public function __construct(SessionContainer $session)
	{
		$this->session = $session;
	}

	public function get_module_name() : string
	{
		return 'inventory_item';
	}

	public function get_output_field_list() : array
	{
		#'inventory_quantity',
		#'inventory_management',
		#'inventory_policy',

		#'inventory_item_id',

		$output_fields = [
			'inventory_item'
		];

		if ($this->session->settings->include_inventory_level) {
			$output_fields = array_merge($output_fields, [
				'inventory_level',
			]);
		}

		return $output_fields;
	}

	/**
	 * @inheritDoc
	 */
	public function run(MysqliWrapper $cxn, PullStats $stats) : void
	{
		$prefix = $this->session->settings->get_table_prefix();
		$this->table_variant = $this->generate_variant_table($cxn, "{$prefix}_inventories_vars");

		$insert_variant = new BatchedDataInserter($cxn, $this->get_variant_inserter($cxn, $this->table_variant));

		$puller = new BulkInventories($this->session);
		// The puller requires 2 inserters. Rather than setting up a dummy table and inserter for
		// products, we will just pass the variant inserter twice and the puller will ignore it.
		$puller->do_bulk_pull($cxn, $insert_variant, $insert_variant);
	}

	/**
	 * @inheritDoc
	 */
	public function get_products(MysqliWrapper $cxn) : Generator
	{
		if ($this->table_variant === null) {
			throw new \Exception('Tried to retrieve data before running: ' . $this->get_module_name());
		}

		$last_retrieved_vid = 0;
		while (true) {
			$result = $this->query_next_variant_data($cxn, $this->table_variant, $last_retrieved_vid);

			$row = $result->fetch_assoc();
			if ($row === false) {
				# TODO: Better error? Log something? Is mysqli set up to throw instead?
				throw new \Exception('Error while retrieving product data: ' . $this->get_module_name());
			}

			if ($row === null) {
				// No more data to retrieve
				return;
			}

			if (empty($row['data'])) {
				continue;
			}

			$last_retrieved_vid = (int)($row['id'] ?? -1);
			if ($last_retrieved_vid <= 0) {
				return;
			}

			$inventory = new Inventory(json_decode($row['data'], true, 128, JSON_THROW_ON_ERROR));
			foreach ($inventory->get_data_for_rows() as $row_data) {
				$product = new Product(['id' => $row['parent_id']]);
				$variant = new ProductVariant($product, ['id' => $row['id']]);
				$variant->add_data($row_data);
				$product->add_variant($variant);

				yield $product;
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function add_data_to_product(MysqliWrapper $cxn, Product $product) : void
	{
		// Inventory does not apply to products
	}

	/**
	 * Inventories should always be the primary module when involved in pulls, so this
	 * implementation shouldn't really be needed, but included in case.
	 *
	 * The `inventory_level_explode` option will have no effect here.
	 *
	 * @inheritDoc
	 */
	public function add_data_to_variant(MysqliWrapper $cxn, ProductVariant $variant) : void
	{
		if ($this->table_variant === null) {
			throw new \Exception('Tried to retrieve data before running: ' . $this->get_module_name());
		}

		$result = $this->query_data_by_id($cxn, $this->table_variant, $variant->id);
		$row = $result->fetch_assoc();
		if ($row === false) {
			# TODO: Better error? Log something? Is mysqli set up to throw instead?
			throw new \Exception('Error while retrieving data for individual variant: ' . $this->get_module_name());
		}

		if ($row === null || empty($row['data'])) {
			return;
		}

		$inventory = new Inventory(json_decode($row['data'], true, 128, JSON_THROW_ON_ERROR));
		$variant->add_data($inventory->get_output_data());
	}

}

