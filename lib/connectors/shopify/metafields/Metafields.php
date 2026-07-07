<?php

namespace ShopifyConnector\connectors\shopify\metafields;

use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\graphql\GraphQLRequest;
use ShopifyConnector\connectors\shopify\graphql\QueryConsumer;
use ShopifyConnector\connectors\shopify\interfaces\iModule;
use ShopifyConnector\connectors\shopify\models\Metafield;
use ShopifyConnector\connectors\shopify\models\Product;
use ShopifyConnector\connectors\shopify\models\ProductVariant;
use ShopifyConnector\connectors\shopify\pullers\BulkBase;
use ShopifyConnector\connectors\shopify\pullers\Puller;
use ShopifyConnector\connectors\shopify\structs\BulkProcessingResult;
use ShopifyConnector\connectors\shopify\structs\PullStats;
use ShopifyConnector\connectors\shopify\traits\StandardModule;
use ShopifyConnector\exceptions\InfrastructureErrorException;
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

	private ?TableHandle $table_product = null;
	private ?TableHandle $table_variant = null;

	private array $metafield_names = [];

	private array $product_cache = [];
	private array $variant_cache = [];

	private ?Puller $stored_puller = null;
	private ?BatchedDataInserter $insert_product_data = null;
	private ?BatchedDataInserter $insert_variant_data = null;

	public function __construct(
		private readonly SessionContainer $session
	)
	{
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

	public function prepare(MysqliWrapper $cxn) : ?BulkBase
	{
		$prefix = $this->session->settings->get_table_prefix();
		$this->table_product = $this->generate_product_table($cxn, "{$prefix}_metafields_prod");
		$this->table_variant = $this->generate_variant_table($cxn, "{$prefix}_metafields_vars");

		$this->insert_product_data = new BatchedDataInserter($this->get_product_inserter($cxn, $this->table_product));
		$this->insert_variant_data = new BatchedDataInserter($this->get_variant_inserter($cxn, $this->table_variant));

		$this->stored_puller = $this->get_puller();
		if ($this->stored_puller instanceof BulkBase) {
			return $this->stored_puller;
		}

		return null; // BatchedMetafields — not a bulk operation
	}

	/**
	 * @inheritDoc
	 */
	public function run(MysqliWrapper $cxn, PullStats $stats, ?string $bulk_file = null) : void
	{
		if ($this->insert_product_data === null) {
			$this->prepare($cxn);
		}

		if ($bulk_file !== null) {
			$puller = ($this->stored_puller instanceof BulkBase) ? $this->stored_puller : new BulkMetafields($this->session);
			$result = new BulkProcessingResult();
			$puller->process_bulk_file($bulk_file, $result, $cxn, $this->insert_product_data, $this->insert_variant_data);
			$this->metafield_names = $result->result;
		} else {
			$puller = $this->stored_puller ?? $this->get_puller();
			$processing_result = $puller->pull($cxn, $this->insert_product_data, $this->insert_variant_data);
			$this->metafield_names = $processing_result->result;
		}

		if ($puller instanceof BulkMetafields) {
			$this->resolve_variant_metafield_parents($cxn);
		}
	}

	/**
	 * Resolve variant metafield rows whose parent_id was deferred during the
	 * bulk scan. {@see BulkMetafields::process_bulk_file()} writes variant
	 * metafield rows with parent_id = 0 because the product owner isn't known
	 * until the corresponding variant line is processed (which may arrive in
	 * any order in the bulk file). Each variant line writes a placeholder row
	 * with data='' carrying the correct variant_id → product_id mapping, so a
	 * self-JOIN against those placeholder rows fills in the missing parent_id
	 * in a single statement, with no in-memory map required.
	 *
	 * Variant metafields whose variant line never appears in the file will
	 * keep parent_id = 0 and be invisible to query_data_by_parent_id.
	 *
	 * @throws InfrastructureErrorException
	 */
	private function resolve_variant_metafield_parents(MysqliWrapper $cxn) : void
	{
		if ($this->table_variant === null) {
			return;
		}
		$table = $this->table_variant->get_table_name();
		$col_id = self::COLUMN_ID;
		$col_parent = self::COLUMN_PARENT_ID;
		$col_data = self::COLUMN_DATA;

		// The column names come from hardcoded constants and the table name is
		// sanitized by TableHandle, so interpolation is safe.
		$cxn->safe_query(<<<SQL
			UPDATE `{$table}` AS mf
			JOIN `{$table}` AS var
				ON var.`{$col_id}` = mf.`{$col_id}`
				AND var.`{$col_data}` = ''
			SET mf.`{$col_parent}` = var.`{$col_parent}`
			WHERE mf.`{$col_parent}` = 0
			SQL
		);
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
		$metafields = [];

		for ( ; $row !== null; $row = $result->fetch_assoc()) {
			if ($row === false) {
				throw new InfrastructureErrorException($this->get_error_message('Error while retrieving product data'));
			}

			if (empty($row['data'])) {
				continue;
			}

			$decoded_data = json_decode($row['data'], true, 128, JSON_THROW_ON_ERROR);
			$metafields[] = new Metafield($decoded_data, Metafield::TYPE_PRODUCT);
		}

		$metafields = array_reverse($metafields);
		if ($this->session->settings->metafields_split_columns) {
			foreach ($metafields as $mf) {
				$product->add_datum($mf->get_identifier(), json_encode($mf));
			}
		} else {
			$product->add_datum(self::PRODUCT_META_KEY, empty($metafields) ? '[]' : json_encode($metafields));
		}

		return $product;
	}

	/**
	 * Pull the data for the variants with the given product as their parent,
	 * and for each, set up a ProductVariant object and add it to the product.
	 *
	 * @param MysqliWrapper $cxn The database connection to query on
	 * @param Product $product The product to pull variants for and attach variants to
	 * @throws InfrastructureErrorException
	 * @throws \JsonException
	 */
	private function add_variants_to_product(MysqliWrapper $cxn, Product $product) : void
	{
		$result = $this->query_data_by_parent_id($cxn, $this->table_variant, $product->id);
		$variant_mfs = [];

		foreach ($result as $row) {
			if ($row === false) {
				throw new InfrastructureErrorException($this->get_error_message('Error while retrieving variant data'));
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

			$metafields = array_reverse($metafields);
			if ($this->session->settings->metafields_split_columns) {
				foreach ($metafields as $mf) {
					$variant->add_datum($mf->get_identifier(), json_encode($mf));
				}
			} else {
				$variant->add_datum(self::VARIANT_META_KEY, empty($metafields) ? '[]' : json_encode($metafields));
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
			throw new InfrastructureErrorException($this->get_error_message('Tried to retrieve data before run()'));
		}

		$metafields = [];

		if (isset($this->product_cache[$product->id])) {
			foreach ($this->product_cache[$product->id] as $data) {
				if (empty($data)) {
					continue;
				}
				$decoded_data = json_decode($data, true, 128, JSON_THROW_ON_ERROR);
				$metafields[] = new Metafield($decoded_data, Metafield::TYPE_PRODUCT);
			}
		} else {
			$result = $this->query_data_by_id($cxn, $this->table_product, $product->id);
			foreach ($result as $row) {
				if ($row === false) {
					throw new InfrastructureErrorException($this->get_error_message('Error while retrieving data for individual product'));
				}
				if (empty($row['data'])) {
					continue;
				}
				$decoded_data = json_decode($row['data'], true, 128, JSON_THROW_ON_ERROR);
				$metafields[] = new Metafield($decoded_data, Metafield::TYPE_PRODUCT);
			}
		}

		$metafields = array_reverse($metafields);
		if ($this->session->settings->metafields_split_columns) {
			foreach ($metafields as $mf) {
				$product->add_datum($mf->get_identifier(), json_encode($mf));
			}
		} else {
			$product->add_datum(self::PRODUCT_META_KEY, empty($metafields) ? '[]' : json_encode($metafields));
		}
	}

	/**
	 * @inheritDoc
	 */
	public function add_data_to_variant(MysqliWrapper $cxn, ProductVariant $variant) : void
	{
		if ($this->table_variant === null) {
			throw new InfrastructureErrorException($this->get_error_message('Tried to retrieve data before run()'));
		}

		$metafields = [];

		if (isset($this->variant_cache[$variant->id])) {
			foreach ($this->variant_cache[$variant->id] as $data) {
				if (empty($data)) {
					continue;
				}
				$decoded_data = json_decode($data, true, 128, JSON_THROW_ON_ERROR);
				$metafields[] = new Metafield($decoded_data, Metafield::TYPE_VARIANT);
			}
		} else {
			$result = $this->query_data_by_id($cxn, $this->table_variant, $variant->id);
			foreach ($result as $row) {
				if ($row === false) {
					throw new InfrastructureErrorException($this->get_error_message('Error while retrieving data for individual variant'));
				}
				if (empty($row['data'])) {
					continue;
				}
				$decoded_data = json_decode($row['data'], true, 128, JSON_THROW_ON_ERROR);
				$metafields[] = new Metafield($decoded_data, Metafield::TYPE_VARIANT);
			}
		}

		$metafields = array_reverse($metafields);
		if ($this->session->settings->metafields_split_columns) {
			foreach ($metafields as $mf) {
				$variant->add_datum($mf->get_identifier(), json_encode($mf));
			}
		} else {
			$variant->add_datum(self::VARIANT_META_KEY, empty($metafields) ? '[]' : json_encode($metafields));
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
			$id = (int)$row['id'];
			if (!isset($this->product_cache[$id])) {
				$this->product_cache[$id] = [];
			}
			$this->product_cache[$id][] = $row['data'];
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
			if (!isset($this->variant_cache[$variant_id])) {
				$this->variant_cache[$variant_id] = [];
			}
			$this->variant_cache[$variant_id][] = $row['data'];
		}
	}

	public function clear_preload_cache() : void
	{
		$this->product_cache = [];
		$this->variant_cache = [];
	}

	/**
	 * @return Puller
	 */
	private function get_puller() : Puller
	{
		return $this->session->settings->pull_with_batched_graphql
			? new BatchedMetafields(
				$this->session,
				new QueryConsumer(
					new GraphQLRequest($this->session->client)
				)
			)
			: new BulkMetafields($this->session);
	}

}
