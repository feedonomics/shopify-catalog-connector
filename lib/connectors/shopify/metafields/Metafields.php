<?php

namespace ShopifyConnector\connectors\shopify\metafields;

use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\interfaces\iModule;
use ShopifyConnector\connectors\shopify\models\Metafield;
use ShopifyConnector\connectors\shopify\models\Product;
use ShopifyConnector\connectors\shopify\models\ProductVariant;
use ShopifyConnector\connectors\shopify\pullers\BulkMetafields;
use ShopifyConnector\connectors\shopify\structs\PullStats;
use ShopifyConnector\connectors\shopify\traits\StandardModule;

use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\TableHandle;
use ShopifyConnector\util\db\queries\BatchedDataInserter;

use Generator;

/**
 * The Metafields module main class.
 */
class Metafields implements iModule
{

	use StandardModule;


	const PRODUCT_META_KEY = 'product_meta';
	const VARIANT_META_KEY = 'variant_meta';


	private SessionContainer $session;

	private ?TableHandle $table_product = null;
	private ?TableHandle $table_variant = null;

	private array $metafield_names = [];


	public function __construct(SessionContainer $session)
	{
		$this->session = $session;
	}

	public function get_module_name() : string
	{
		return 'meta';
	}

	public function get_output_field_list() : array
	{
		return $this->session->settings->metafields_split_columns ? $this->metafield_names : [
			self::PRODUCT_META_KEY,
			self::VARIANT_META_KEY,
		];
	}

	/**
	 * @inheritDoc
	 */
	public function run(MysqliWrapper $cxn, PullStats $stats) : void
	{
		$prefix = $this->session->settings->get_table_prefix();
		$this->table_product = $this->generate_product_table($cxn, "{$prefix}_metafields_prod");
		$this->table_variant = $this->generate_variant_table($cxn, "{$prefix}_metafields_vars");

		$insert_product = new BatchedDataInserter($cxn, $this->get_product_inserter($cxn, $this->table_product));
		$insert_variant = new BatchedDataInserter($cxn, $this->get_variant_inserter($cxn, $this->table_variant));

		$puller = new BulkMetafields($this->session);
		$processing_result = $puller->do_bulk_pull($cxn, $insert_product, $insert_variant);
		$this->metafield_names = $processing_result->result;
	}

	/**
	 * @inheritDoc
	 */
	public function get_products(MysqliWrapper $cxn) : Generator
	{
		if ($this->table_product === null) {
			throw new \Exception('Tried to retrieve data before running: ' . $this->get_module_name());
		}

		$last_retrieved_pid = 0;
		while (true) {
			$product = $this->get_next_product($cxn, $last_retrieved_pid);
			if ($product === null) {
				// No more products
				return;
			}
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
	 * Pull the data for the product that is next after the given last-pulled-id.
	 * Put together the product data into a Product object and return it.
	 *
	 * If no next product is found, NULL will be returned.
	 *
	 * @param MysqliWrapper $cxn The database connection to query on
	 * @param int $last_retrieved_pid The product id to start from when finding this one
	 * @return ?Product A Product representation of the retrieved data or NULL if no more
	 */
	private function get_next_product(MysqliWrapper $cxn, int $last_retrieved_pid) : ?Product
	{
		$result = $this->query_next_data($cxn, $this->table_product, $last_retrieved_pid);

		$row = $result->fetch_assoc();
		if ($row === false) {
			# TODO: Better error? Log something? Is mysqli set up to throw instead?
			throw new \Exception('Error while retrieving product data: ' . $this->get_module_name());
		}

		if ($row === null) {
			// No more products to retrieve
			return null;
		}

		$product = new Product(['id' => $row['id']]);
		$metafields = [];

		for ( ; $row !== null; $row = $result->fetch_assoc()) {
			if ($row === false) {
				# TODO: Better error? Log something? Is mysqli set up to throw instead?
				throw new \Exception('Error while retrieving product data: ' . $this->get_module_name());
			}

			if (empty($row['data'])) {
				continue;
			}

			$decoded_data = json_decode($row['data'], true, 128, JSON_THROW_ON_ERROR);
			$metafields[] = new Metafield($decoded_data, Metafield::TYPE_PRODUCT);
		}

		if ($this->session->settings->metafields_split_columns) {
			foreach ($metafields as $mf) {
				$product->add_datum($mf->get_identifier(), json_encode($mf));
			}
		} else {
			$product->add_datum(self::PRODUCT_META_KEY, empty($metafields) ? '' : json_encode($metafields));
		}

		return $product;
	}

	/**
	 * Pull the data for the variants with the given product as their parent,
	 * and for each, set up a ProductVariant object and add it to the product.
	 *
	 * @param MysqliWrapper $cxn The database connection to query on
	 * @param Product $product The product to pull variants for and attach variants to
	 */
	private function add_variants_to_product(MysqliWrapper $cxn, Product $product) : void
	{
		$result = $this->query_data_by_parent_id($cxn, $this->table_variant, $product->id);
		$variant_mfs = [];

		foreach ($result as $row) {
			if ($row === false) {
				# TODO: Better error? Log something? Is mysqli set up to throw instead?
				throw new \Exception('Error while retrieving variant data: ' . $this->get_module_name());
			}

			$var_id = $row['id'];
			if (empty($variant_mfs[$var_id])) {
				$variant_mfs[$var_id] = [];
			}

			if (empty($row['data'])) {
				continue;
			}

			$decoded_data = json_decode($row['data'], true, 128, JSON_THROW_ON_ERROR);
			$variant_mfs[$var_id][] = new Metafield($decoded_data, Metafield::TYPE_VARIANT);
		}

		foreach ($variant_mfs as $var_id => $metafields) {
			$variant = new ProductVariant($product, ['id' => $var_id]);

			if ($this->session->settings->metafields_split_columns) {
				foreach ($metafields as $mf) {
					$variant->add_datum($mf->get_identifier(), json_encode($mf));
				}
			} else {
				$variant->add_datum(self::VARIANT_META_KEY, empty($metafields) ? '' : json_encode($metafields));
			}

			$product->add_variant($variant);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function add_data_to_product(MysqliWrapper $cxn, Product $product) : void
	{
		if ($this->table_product === null) {
			throw new \Exception('Tried to retrieve data before running: ' . $this->get_module_name());
		}

		$result = $this->query_data_by_id($cxn, $this->table_product, $product->id);
		$metafields = [];

		foreach ($result as $row) {
			if ($row === false) {
				# TODO: Better error? Log something? Is mysqli set up to throw instead?
				throw new \Exception('Error while retrieving data for individual product: ' . $this->get_module_name());
			}

			if (empty($row['data'])) {
				continue;
			}

			$decoded_data = json_decode($row['data'], true, 128, JSON_THROW_ON_ERROR);
			$metafields[] = new Metafield($decoded_data, Metafield::TYPE_PRODUCT);
		}

		if ($this->session->settings->metafields_split_columns) {
			foreach ($metafields as $mf) {
				$product->add_datum($mf->get_identifier(), json_encode($mf));
			}
		} else {
			$product->add_datum(self::PRODUCT_META_KEY, empty($metafields) ? '' : json_encode($metafields));
		}
	}

	/**
	 * @inheritDoc
	 */
	public function add_data_to_variant(MysqliWrapper $cxn, ProductVariant $variant) : void
	{
		if ($this->table_variant === null) {
			throw new \Exception('Tried to retrieve data before running: ' . $this->get_module_name());
		}

		$result = $this->query_data_by_id($cxn, $this->table_variant, $variant->id);
		$metafields = [];

		foreach ($result as $row) {
			if ($row === false) {
				# TODO: Better error? Log something? Is mysqli set up to throw instead?
				throw new \Exception('Error while retrieving data for individual variant: ' . $this->get_module_name());
			}

			if (empty($row['data'])) {
				continue;
			}

			$decoded_data = json_decode($row['data'], true, 128, JSON_THROW_ON_ERROR);
			$metafields[] = new Metafield($decoded_data, Metafield::TYPE_VARIANT);
		}

		if ($this->session->settings->metafields_split_columns) {
			foreach ($metafields as $mf) {
				$variant->add_datum($mf->get_identifier(), json_encode($mf));
			}
		} else {
			$variant->add_datum(self::VARIANT_META_KEY, empty($metafields) ? '' : json_encode($metafields));
		}
	}

}

