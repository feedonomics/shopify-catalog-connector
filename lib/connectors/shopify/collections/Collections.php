<?php

namespace ShopifyConnector\connectors\shopify\collections;

use Generator;
use ShopifyConnector\connectors\shopify\interfaces\iModule;
use ShopifyConnector\connectors\shopify\models\CollectionPile;
use ShopifyConnector\connectors\shopify\models\Product;
use ShopifyConnector\connectors\shopify\models\ProductVariant;
use ShopifyConnector\connectors\shopify\pullers\BulkBase;
use ShopifyConnector\connectors\shopify\pullers\BulkCollections;
use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\structs\BulkProcessingResult;
use ShopifyConnector\connectors\shopify\structs\PullStats;
use ShopifyConnector\connectors\shopify\traits\StandardModule;
use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\queries\BatchedDataInserter;
use ShopifyConnector\util\db\TableHandle;

use ShopifyConnector\exceptions\InfrastructureErrorException;

/**
 * The Collections module main class
 */
class Collections implements iModule
{

	use StandardModule;


	private SessionContainer $session;

	private ?TableHandle $table_product = null;
	private ?TableHandle $table_variant = null;

	private ?BulkCollections $puller = null;
	private ?BatchedDataInserter $insert_product_data = null;
	private ?BatchedDataInserter $insert_variant_data = null;


	public function __construct(SessionContainer $session)
	{
		$this->session = $session;
	}

	public function get_module_name() : string
	{
		return 'collections';
	}

	public function get_output_field_list() : array
	{
		$output_fields = [
		# For "collections" requested:
		   'item_group_id',
		   'custom_collections_handle',
		   'custom_collections_title',
		   'custom_collections_id',
		   'smart_collections_handle',
		   'smart_collections_title',
		   'smart_collections_id',
		];

		if ($this->session->settings->include_collections_meta) {
			$output_fields = array_merge($output_fields, [
			   'custom_collections_meta',
			   'smart_collections_meta',
			]);
		}

		return $output_fields;
	}

	public function prepare(MysqliWrapper $cxn) : ?BulkBase
	{
		$prefix = $this->session->settings->get_table_prefix();
		$this->table_product = $this->generate_product_table($cxn, "{$prefix}_collections_prod");
		$this->table_variant = $this->generate_variant_table($cxn, "{$prefix}_collections_vars");

		$this->insert_product_data = new BatchedDataInserter($this->get_product_inserter($cxn, $this->table_product));
		$this->insert_variant_data = new BatchedDataInserter($this->get_variant_inserter($cxn, $this->table_variant));

		$this->puller = new BulkCollections($this->session);
		return $this->puller;
	}

	/**
	 * TODO: Make PullStats a globally-accessible singleton or w/"active" like session
	 *
	 * @inheritDoc
	 */
	public function run(MysqliWrapper $cxn, PullStats $stats, ?string $bulk_file = null) : void
	{
		if ($this->puller === null) {
			$this->prepare($cxn);
		}

		if ($bulk_file !== null) {
			$result = new BulkProcessingResult();
			$this->puller->process_bulk_file($bulk_file, $result, $cxn, $this->insert_product_data, $this->insert_variant_data);
		} else {
			$this->puller->do_bulk_pull($cxn, $this->insert_product_data, $this->insert_variant_data);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function get_products(MysqliWrapper $cxn) : Generator
	{
		if ($this->table_product === null) {
			throw new InfrastructureErrorException($this->get_error_message('Tried to retrieve data before run()'));
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
	 * Pull the data for the product that is next after the given last-pulled-id.
	 * Put together the product data into a Product object and return it.
	 *
	 * If no next product is found, NULL will be returned.
	 *
	 * @param MysqliWrapper $cxn The database connection to query on
	 * @param int $last_retrieved_pid The product id to start from when finding this one
	 * @return ?Product A Product representation of the retrieved data or NULL if no more
	 * @throws InfrastructureErrorException
	 * @throws \JsonException
	 */
	private function get_next_product(MysqliWrapper $cxn, int $last_retrieved_pid) : ?Product
	{
		$result = $this->query_next_data($cxn, $this->table_product, $last_retrieved_pid);

		$row = $result->fetch_assoc();
		if ($row === false) {
			throw new InfrastructureErrorException($this->get_error_message('Error while retrieving product data'));
		}

		if ($row === null) {
			// No more products to retrieve
			return null;
		}

		$product = new Product(['id' => $row['id']]);
		$collections = new CollectionPile(json_decode($row['data'], true, 128, JSON_THROW_ON_ERROR));
		$collections->add_collection_data_to_product($product, $this->session->settings->include_collections_meta);

		return $product;
	}

	/**
	 * @inheritDoc
	 */
	public function add_data_to_product(MysqliWrapper $cxn, Product $product) : void
	{
		if ($this->table_product === null) {
			throw new InfrastructureErrorException($this->get_error_message('Tried to retrieve data before run()'));
		}

		$result = $this->query_data_by_id($cxn, $this->table_product, $product->id);
		$row = $result->fetch_assoc();
		if ($row === false) {
			throw new InfrastructureErrorException($this->get_error_message('Error while retrieving data for individual product'));
		}

		if (empty($row['data'])) {
			return;
		}

		$collections = new CollectionPile(json_decode($row['data'], true, 128, JSON_THROW_ON_ERROR));
		$collections->add_collection_data_to_product($product, $this->session->settings->include_collections_meta);
	}

	/**
	 * @inheritDoc
	 */
	public function add_data_to_variant(MysqliWrapper $cxn, ProductVariant $variant) : void
	{
		// Not applicable to variants
	}

}
