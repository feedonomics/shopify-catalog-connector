<?php

namespace ShopifyConnector\connectors\shopify\publications;

use ShopifyConnector\connectors\shopify\interfaces\iModule;
use ShopifyConnector\connectors\shopify\models\Field;
use ShopifyConnector\connectors\shopify\models\Product;
use ShopifyConnector\connectors\shopify\models\ProductVariant;
use ShopifyConnector\connectors\shopify\pullers\BulkBase;
use ShopifyConnector\connectors\shopify\pullers\BulkMarkets;
use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\structs\BulkProcessingResult;
use ShopifyConnector\connectors\shopify\structs\PullStats;
use ShopifyConnector\connectors\shopify\traits\StandardModule;
use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\queries\BatchedDataInserter;
use ShopifyConnector\util\db\TableHandle;
use ShopifyConnector\exceptions\InfrastructureErrorException;
use Generator;

class Markets implements iModule
{
	use StandardModule;

	/**
	 * Temporary table used to hold product market data
	 * @var TableHandle|null
	 */
	private ?TableHandle $product_table = null;

	private ?BulkMarkets $puller = null;
	private ?BatchedDataInserter $insert_product_data = null;

	/**
	 * @param SessionContainer $session
	 */
	public function __construct(
		private readonly SessionContainer $session
	)
	{
	}

	/**
	 * @return string
	 */
	public function get_module_name() : string
	{
		return 'markets';
	}

	/**
	 * Adds 'markets' field
	 * @return array
	 */
	public function get_output_field_list() : array
	{
		return [Field::MARKETS->value];
	}

	public function prepare(MysqliWrapper $cxn) : ?BulkBase
	{
		$this->set_product_table_handle($this->generate_product_table($cxn, "{$this->session->settings->get_table_prefix()}_markets_prod"));
		$this->insert_product_data = new BatchedDataInserter($this->get_product_inserter($cxn, $this->product_table));

		$this->puller = new BulkMarkets($this->session);
		return $this->puller;
	}

	/**
	 * @inheritDoc
	 */
	public function run(MysqliWrapper $cxn, PullStats $stats, ?string $bulk_file = null) : void
	{
		if ($this->puller === null) {
			$this->prepare($cxn);
		}

		if ($bulk_file !== null) {
			$result = new BulkProcessingResult();
			$this->puller->process_bulk_file($bulk_file, $result, $cxn, $this->insert_product_data, null);
		} else {
			$this->puller->do_bulk_pull($cxn, $this->insert_product_data, null);
		}
	}

	/**
	 * This is never a primary module so get_products is never called
	 *
	 * @param MysqliWrapper $cxn
	 * @return Generator
	 */
	public function get_products(MysqliWrapper $cxn) : Generator
	{
		yield [];
	}

	/**
	 * Adds markets to the product's data array
	 *
	 * @param MysqliWrapper $cxn
	 * @param Product $product
	 * @return void
	 * @throws InfrastructureErrorException
	 */
	public function add_data_to_product(MysqliWrapper $cxn, Product $product) : void
	{
		if ($this->product_table === null) {
			throw new InfrastructureErrorException($this->get_error_message('Tried to retrieve data before run()'));
		}

		$result = $this->query_data_by_id($cxn, $this->product_table, $product->id);
		$row = $result->fetch_assoc();

		if ($row === false) {
			throw new InfrastructureErrorException($this->get_error_message('Error while retrieving data for individual product'));

		}

		if ($row === null || empty($row['data'])) {
			return;
		}

		$data = json_decode($row['data'] ?? '[]', true);

		if (empty($data) || empty($data[Field::MARKETS->value])) {
			return;
		}

		$product->add_datum(
			Field::MARKETS->value,
			$data[Field::MARKETS->value]
		);
	}

	/**
	 * No variants exist for markets
	 *
	 * @param MysqliWrapper $cxn
	 * @param ProductVariant $variant
	 * @return void
	 */
	public function add_data_to_variant(MysqliWrapper $cxn, ProductVariant $variant) : void
	{

	}

	/**
	 * @param TableHandle $table
	 * @return void
	 */
	public function set_product_table_handle(TableHandle $table) : void
	{
		$this->product_table = $table;
	}
}
