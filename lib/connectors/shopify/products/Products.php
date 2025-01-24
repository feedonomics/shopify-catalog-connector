<?php

namespace ShopifyConnector\connectors\shopify\products;

use Exception;
use ShopifyConnector\connectors\shopify\ProductFilterManager;
use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\interfaces\iModule;
use ShopifyConnector\connectors\shopify\models\Product;
use ShopifyConnector\connectors\shopify\models\ProductVariant;
use ShopifyConnector\connectors\shopify\pullers\BulkProducts;
use ShopifyConnector\connectors\shopify\structs\PullStats;
use ShopifyConnector\connectors\shopify\traits\StandardModule;
use Generator;
use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\queries\BatchedDataInserter;
use ShopifyConnector\util\db\TableHandle;

/**
 * The Products module main class.
 *
 * NOTE: An improvement for the queries in this and the other module classes would be to
 *   set them up as prepared statements cached once in class fields and reused as needed.
 *   There may be some additional error handling complexity there though.
 */
class Products implements iModule
{

	use StandardModule;


	const MODULE_NAME = 'products';

	private SessionContainer $session;

	private ?TableHandle $table_product = null;
	private ?TableHandle $table_variant = null;

	private ?array $output_fields = null;


	public function __construct(SessionContainer $session)
	{
		$this->session = $session;
	}

	public function get_module_name() : string
	{
		return self::MODULE_NAME;
	}

	public function get_output_field_list() : array
	{
		if ($this->output_fields === null) {
			# Missing from Product/Variant's consts:
			# - fulfillment_service
			#   - This now lives down a completely different labyrinth under ProductVariant

			$output_fields = $this->session->settings->get_product_filter(ProductFilterManager::FILTER_FIELDS);
			if ($output_fields === null) {
				$output_fields = array_merge(
					Product::get_translated_default_fields(),
					ProductVariant::get_translated_default_fields()
				);
			}

			if ($this->session->settings->use_gmc_transition_id) {
				$output_fields[] = 'gmc_transition_id';
			}

			if ($this->session->settings->tax_rates) {
				$output_fields[] = 'tax_rates';
			}

			if ($this->session->settings->variant_names_split_columns) {
				$output_fields[] = 'variant_title';
				$output_fields[] = 'variant_color';
				$output_fields[] = 'variant_quantity';
				if (($key = array_search('variant_names', $output_fields)) !== false) {
					unset($output_fields[$key]);
				}
			}

			$this->output_fields = $output_fields;
		}

		return $this->output_fields;
	}

	/**
	 * @inheritDoc
	 */
	public function run(MysqliWrapper $cxn, PullStats $stats) : void
	{
		$prefix = $this->session->settings->get_table_prefix();
		$this->table_product = $this->generate_product_table($cxn, "{$prefix}_products");
		$this->table_variant = $this->generate_variant_table($cxn, "{$prefix}_variants");

		$insert_product = new BatchedDataInserter($cxn, $this->get_product_inserter($cxn, $this->table_product));
		$insert_variant = new BatchedDataInserter($cxn, $this->get_variant_inserter($cxn, $this->table_variant));

		$puller = new BulkProducts($this->session);
		$puller->do_bulk_pull($cxn, $insert_product, $insert_variant);
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
			$result = $this->query_next_data($cxn, $this->table_product, $last_retrieved_pid);
			$row = $result->fetch_assoc();
			if ($row === false) {
				throw new Exception('Error while retrieving product data: ' . $this->get_module_name());
			}

			if ($row === null) {
				// No more products to retrieve
				return null;
			}

			$decoded_data = json_decode($row['data'], true, 128, JSON_THROW_ON_ERROR);
			$product = new Product($decoded_data);

			// Data probably includes a GID for id, so set the non-GID id as the product's id
			$product->add_datum('id', $row['id']);

			$this->add_variants_to_product($cxn, $product);

			$last_retrieved_pid = (int)$product->id;
			if ($last_retrieved_pid <= 0) {
				return;
			}

			yield $product;
		}
	}

	/**
	 * Pull the data for the variants with the given product as their parent, and
	 * create a variant for each entry in the data and add it to the product.
	 *
	 * @param MysqliWrapper $cxn The database connection to query on
	 * @param Product $product The product to pull variants for and attach variants to
	 */
	private function add_variants_to_product(MysqliWrapper $cxn, Product $product) : void
	{
		$result = $this->query_data_by_parent_id($cxn, $this->table_variant, $product->id);
		foreach ($result as $row) {
			if ($row === false) {
				throw new Exception('Error while retrieving variant data: ' . $this->get_module_name());
			}

			$decoded_data = json_decode($row['data'], true, 128, JSON_THROW_ON_ERROR);
			$variant = new ProductVariant($product, $decoded_data);

			// Data probably includes a GID for id, so set the non-GID id as the variant's id
			$variant->add_datum('id', $row['id']);

			$product->add_variant($variant);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function add_data_to_product(MysqliWrapper $cxn, Product $product) : void
	{
		if ($this->table_product === null) {
			throw new Exception('Tried to retrieve data before running: ' . $this->get_module_name());
		}

		$result = $this->query_data_by_id($cxn, $this->table_product, $product->id);
		$row = $result->fetch_assoc();
		if ($row === false) {
			throw new Exception('Error while retrieving data for individual product: ' . $this->get_module_name());
		}

		if ($row === null || empty($row['data'])) {
			return;
		}

		$decoded_data = json_decode($row['data'], true, 128, JSON_THROW_ON_ERROR);
		$product->add_data($decoded_data);
	}

	/**
	 * @inheritDoc
	 */
	public function add_data_to_variant(MysqliWrapper $cxn, ProductVariant $variant) : void
	{
		if ($this->table_variant === null) {
			throw new Exception('Tried to retrieve data before running: ' . $this->get_module_name());
		}

		$result = $this->query_data_by_id($cxn, $this->table_variant, $variant->id);
		$row = $result->fetch_assoc();
		if ($row === false) {
			throw new Exception('Error while retrieving data for individual variant: ' . $this->get_module_name());
		}

		if ($row === null || empty($row['data'])) {
			return;
		}

		$decoded_data = json_decode($row['data'], true, 128, JSON_THROW_ON_ERROR);
		$variant->add_data($decoded_data);
	}

}

