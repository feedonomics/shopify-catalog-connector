<?php

namespace ShopifyConnector\connectors\shopify\translations;

use Exception;
use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\interfaces\iModule;
use ShopifyConnector\connectors\shopify\models\Product;
use ShopifyConnector\connectors\shopify\models\ProductVariant;
use ShopifyConnector\connectors\shopify\pullers\BulkTranslations;
use ShopifyConnector\connectors\shopify\structs\PullStats;
use ShopifyConnector\connectors\shopify\traits\StandardModule;
use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\TableHandle;
use ShopifyConnector\util\db\queries\BatchedDataInserter;

use Generator;

/**
 * The Translations module main class.
 */
class Translations implements iModule
{

	use StandardModule;


	const PRODUCT_META_KEY = 'product_meta';
	const VARIANT_META_KEY = 'variant_meta';

	private SessionContainer $session;

	private ?TableHandle $table_product = null;
	private ?TableHandle $table_variant = null;

	private array $translation_names = [];


	public function __construct(SessionContainer $session)
	{
		$this->session = $session;
	}

	public function get_module_name() : string
	{
		return 'translations';
	}

	public function get_output_field_list() : array
	{
		return $this->translation_names;
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

		$puller = new BulkTranslations($this->session);
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
			throw new Exception('Error while retrieving product data: ' . $this->get_module_name());
		}

		if ($row === null) {
			// No more products to retrieve
			return null;
		}

		$product = new Product(['id' => $row['id']]);
		$translations = [];

		for ( ; $row !== null; $row = $result->fetch_assoc()) {
			if ($row === false) {
				throw new Exception('Error while retrieving product data: ' . $this->get_module_name());
			}

			if (empty($row['data'])) {
				continue;
			}

			$decoded_data = json_decode($row['data'], true, 128, JSON_THROW_ON_ERROR);
			$translations[] = $decoded_data;
		}

		foreach ($translations as $translation) {
			foreach ($translation as $translation_field) {
				$identifier = "{$translation_field['locale']}_{$translation_field['key']}";
				$value = $translation_field['value'];
				$product->add_datum($identifier, $value);
			}
		}

		return $product;
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
		$translations = [];

		foreach ($result as $row) {
			if ($row === false) {
				throw new Exception('Error while retrieving data for individual product: ' . $this->get_module_name());
			}

			if (empty($row['data'])) {
				continue;
			}

			$decoded_data = json_decode($row['data'], true, 128, JSON_THROW_ON_ERROR);
			$translations[] = $decoded_data;
		}
		foreach ($translations as $translation) {
			foreach ($translation as $translation_field) {
				$identifier = "{$translation_field['locale']}_{$translation_field['key']}";
				$value = $translation_field['value'];
				$product->add_datum($identifier, $value);
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function add_data_to_variant(MysqliWrapper $cxn, ProductVariant $variant) : void
	{
		// No variants in translations
	}
}

