<?php

namespace ShopifyConnector\connectors\shopify\products;

use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\interfaces\iModule;
use ShopifyConnector\connectors\shopify\models\Product;
use ShopifyConnector\connectors\shopify\models\ProductVariant;
use ShopifyConnector\connectors\shopify\pullers\BulkBase;
use ShopifyConnector\connectors\shopify\pullers\BulkProducts;
use ShopifyConnector\connectors\shopify\structs\BulkProcessingResult;
use ShopifyConnector\connectors\shopify\structs\PullStats;
use ShopifyConnector\connectors\shopify\traits\StandardModule;

use ShopifyConnector\exceptions\InfrastructureErrorException;

use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\TableHandle;
use ShopifyConnector\util\db\queries\BatchedDataInserter;

use Generator;

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

	private array $variant_names = [];

	private array $product_cache = [];
	private array $variant_cache = [];

	private ?BulkProducts $puller = null;
	private ?BatchedDataInserter $insert_product_batched = null;
	private ?BatchedDataInserter $insert_variant_batched = null;


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
					ProductVariant::get_translated_default_fields(),
					$this->session->settings->extra_parent_fields,
					$this->session->settings->extra_variant_fields
				);
			}

			if ($this->session->settings->use_gmc_transition_id) {
				$output_fields[] = 'gmc_transition_id';
			}

			if ($this->session->settings->tax_rates) {
				$output_fields[] = 'tax_rates';
			}
			if ($this->session->settings->include_contextual_pricing) {
				$output_fields[] = 'contextual_pricing';
			}
			if ($this->session->settings->include_presentment_prices) {
				$output_fields[] = 'presentment_prices';
			}

			if ($this->session->settings->variant_names_split_columns) {
				$output_fields = array_merge(
					$output_fields,
					$this->variant_names,
				);
				if (($key = array_search('variant_names', $output_fields)) !== false) {
					unset($output_fields[$key]);
				}
			}

			$this->output_fields = $output_fields;
		}
		return $this->output_fields;
	}

	public function prepare(MysqliWrapper $cxn) : ?BulkBase
	{
		$prefix = $this->session->settings->get_table_prefix();
		$this->table_product = $this->generate_product_table($cxn, "{$prefix}_products");
		$this->table_variant = $this->generate_variant_table($cxn, "{$prefix}_variants");

		$this->insert_product_batched = new BatchedDataInserter($this->get_product_inserter($cxn, $this->table_product));
		$this->insert_variant_batched = new BatchedDataInserter($this->get_variant_inserter($cxn, $this->table_variant));

		$this->puller = new BulkProducts($this->session);
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
			$this->puller->process_bulk_file($bulk_file, $result, $cxn, $this->insert_product_batched, $this->insert_variant_batched);
			$this->variant_names = $result->result;
		} else {
			$processing_result = $this->puller->do_bulk_pull($cxn, $this->insert_product_batched, $this->insert_variant_batched);
			$this->variant_names = $processing_result->result;
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
			$result = $this->query_next_data($cxn, $this->table_product, $last_retrieved_pid);
			$row = $result->fetch_assoc();
			if ($row === false) {
				throw new InfrastructureErrorException($this->get_error_message('Error while retrieving product data'));
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
				# TODO: Log something? Error?
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
	 * @throws InfrastructureErrorException
	 * @throws \JsonException
	 */
	private function add_variants_to_product(MysqliWrapper $cxn, Product $product) : void
	{
		$result = $this->query_data_by_parent_id($cxn, $this->table_variant, $product->id);
		foreach ($result as $row) {
			if ($row === false) {
				throw new InfrastructureErrorException($this->get_error_message('Error while retrieving variant data'));
			}
			$decoded_data = json_decode($row['data'], true, 128, JSON_THROW_ON_ERROR);
			$variant = new ProductVariant($product, $decoded_data);
			if ($this->session->settings->variant_names_split_columns) {
				$identifier = 'variant_' . strtolower($decoded_data['selectedOptions'][0]['name']);
				$value = $decoded_data['selectedOptions'][0]['value'];
				$product->add_datum($identifier, $value);
			}
			// Data probably includes a GID for id, so set the non-GID id as the variant's id
			$variant->add_datum('id', $row['id']);

			$product->add_variant($variant);
		}
	}

	public function preload_products(MysqliWrapper $cxn, array $product_ids) : void
	{
		if ($this->table_product === null || empty($product_ids)) {
			return;
		}

		$this->product_cache = [];
		$result = $this->query_data_by_ids($cxn, $this->table_product, $product_ids);

		while ($row = $result->fetch_assoc()) {
			if ($row === false) {
				break;
			}
			// Products table has one row per product
			$this->product_cache[(int)$row['id']] = $row['data'];
		}
	}

	public function preload_variants(MysqliWrapper $cxn, array $product_ids) : void
	{
		if ($this->table_variant === null || empty($product_ids)) {
			return;
		}

		$this->variant_cache = [];
		$result = $this->query_data_by_parent_ids($cxn, $this->table_variant, $product_ids);

		while ($row = $result->fetch_assoc()) {
			if ($row === false) {
				break;
			}
			$variant_id = (int)$row['id'];
			$this->variant_cache[$variant_id] = $row;
		}
	}

	public function clear_preload_cache() : void
	{
		$this->product_cache = [];
		$this->variant_cache = [];
	}

	/**
	 * @inheritDoc
	 */
	public function add_data_to_product(MysqliWrapper $cxn, Product $product) : void
	{
		if ($this->table_product === null) {
			throw new InfrastructureErrorException($this->get_error_message('Tried to retrieve data before run()'));
		}

		$data = null;
		if (isset($this->product_cache[$product->id])) {
			$data = $this->product_cache[$product->id];
		} else {
			$result = $this->query_data_by_id($cxn, $this->table_product, $product->id);
			$row = $result->fetch_assoc();
			if ($row === false) {
				throw new InfrastructureErrorException($this->get_error_message('Error while retrieving data for individual product'));
			}
			$data = $row['data'] ?? null;
		}

		if (empty($data)) {
			return;
		}

		$decoded_data = json_decode($data, true, 128, JSON_THROW_ON_ERROR);
		$product->add_data($decoded_data);
	}

	/**
	 * @inheritDoc
	 */
	public function add_data_to_variant(MysqliWrapper $cxn, ProductVariant $variant) : void
	{
		if ($this->table_variant === null) {
			throw new InfrastructureErrorException($this->get_error_message('Tried to retrieve data before run()'));
		}

		$data = null;
		if (isset($this->variant_cache[$variant->id])) {
			$data = $this->variant_cache[$variant->id]['data'] ?? null;
		} else {
			$result = $this->query_data_by_id($cxn, $this->table_variant, $variant->id);
			$row = $result->fetch_assoc();
			if ($row === false) {
				throw new InfrastructureErrorException($this->get_error_message('Error while retrieving data for individual variant'));
			}
			$data = $row['data'] ?? null;
		}

		if (empty($data)) {
			return;
		}
		$decoded_data = json_decode($data, true, 128, JSON_THROW_ON_ERROR);
		$variant->add_data($decoded_data);
	}

}
