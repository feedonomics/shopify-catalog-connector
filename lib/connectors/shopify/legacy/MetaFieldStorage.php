<?php

namespace ShopifyConnector\connectors\shopify\legacy;

use ShopifyConnector\exceptions\InfrastructureErrorException;
use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\queries\BatchedDataInserter;
use ShopifyConnector\util\db\queries\InsertStatement;
use ShopifyConnector\util\db\queries\SimpleSelectStatement;
use ShopifyConnector\util\db\TableHandle;
use ShopifyConnector\util\db\TemporaryTableGenerator;

/**
 * Utility for saving Shopify meta-fields for products and variants
 */
class MetaFieldStorage
{

	/**
	 * @var string Name for the ID DB column
	 */
	const COLUMN_ID = 'p_id';

	/**
	 * @var string Name for the type (product vs variant) DB column
	 */
	const COLUMN_TYPE = 'p_type';

	/**
	 * @var string Name for the meta-field key DB column
	 */
	const COLUMN_KEY = 'meta_key';

	/**
	 * @var string Name for the meta-field value DB column
	 */
	const COLUMN_VAL = 'meta_value';

	/**
	 * @var int Flag for product entries (used for {@see COLUMN_TYPE})
	 */
	const FLAG_TYPE_PRODUCT = 1;

	/**
	 * @var int Flag for variant entries (used for {@see COLUMN_TYPE})
	 */
	const FLAG_TYPE_VARIANT = 2;


	/**
	 * @var TableHandle Store for the table handle to store meta-field data
	 */
	private TableHandle $table;

	/**
	 * @var BatchedDataInserter Store for the batched data inserter to write
	 * records in bulk
	 */
	private BatchedDataInserter $inserter;

	/**
	 * @var SimpleSelectStatement Store for the simple select statement for
	 * getting meta-field data for a given product by ID
	 */
	private SimpleSelectStatement $selector;

	/**
	 * @var bool Flag for if split-to-columns is being requested
	 */
	private bool $split_to_col;

	/**
	 * @var bool Flag for if using namespaces in key names is being requested
	 */
	private bool $use_namespaces;


	/**
	 * Utility for saving Shopify meta-fields for products and variants
	 *
	 * @param MysqliWrapper $cxn An existing DB connection to leverage
	 * @param bool $use_namespaces
	 * @param bool $split_to_col
	 * @throws InfrastructureErrorException
	 */
	public function __construct(MysqliWrapper $cxn, bool $use_namespaces, bool $split_to_col = false)
	{
		$this->use_namespaces = $use_namespaces;
		$this->split_to_col = $split_to_col;

		$this->table = TemporaryTableGenerator::get($cxn, 'shopify_meta')
			->add_col_bigint(self::COLUMN_ID)
			->add_col_int(self::COLUMN_TYPE)
			->add_col_varchar(self::COLUMN_KEY)
			->add_col_text(self::COLUMN_VAL)
			->add_index(self::COLUMN_ID, self::COLUMN_TYPE)
			->add_index(self::COLUMN_KEY)
			->build();

		$insert = new InsertStatement($this->table, InsertStatement::FLAG_UPDATE_ON_DUP);
		$insert->add_columns($cxn, [
			self::COLUMN_ID,
			self::COLUMN_TYPE,
			self::COLUMN_KEY,
			self::COLUMN_VAL,
		]);

		$this->inserter = new BatchedDataInserter($cxn, $insert);
		$this->selector = (new SimpleSelectStatement($this->table))
			->add_where_column($cxn, self::COLUMN_ID)
			->add_where_column($cxn, self::COLUMN_TYPE)
			->add_column($cxn, self::COLUMN_KEY)
			->add_column($cxn, self::COLUMN_VAL);

	}

	/**
	 * Save a list of meta-fields for a given product
	 *
	 * @param MysqliWrapper $cxn An existing DB connection to leverage
	 * @param int $id The product ID
	 * @param array[] $meta_field_list The list of meta-field objects to save
	 * @throws InfrastructureErrorException On database errors
	 */
	public function save_product_metafields(MysqliWrapper $cxn, int $id, array $meta_field_list) : void
	{
		$this->split_to_col
			? $this->save_to_columns($cxn, $id, self::FLAG_TYPE_PRODUCT, $meta_field_list)
			: $this->save_to_blob($cxn, $id, self::FLAG_TYPE_PRODUCT, $meta_field_list);
	}

	/**
	 * Save a list of meta-fields for a given variant
	 *
	 * @param MysqliWrapper $cxn An existing DB connection to leverage
	 * @param int $id The variant ID
	 * @param array $meta_field_list The list of meta-field objects to save
	 * @throws InfrastructureErrorException On database errors
	 */
	public function save_variant_metafields(MysqliWrapper $cxn, int $id, array $meta_field_list) : void
	{
		$this->split_to_col
			? $this->save_to_columns($cxn, $id, self::FLAG_TYPE_VARIANT, $meta_field_list)
			: $this->save_to_blob($cxn, $id, self::FLAG_TYPE_VARIANT, $meta_field_list);
	}

	/**
	 * Callable for when the last expected data comes in to save any remaining
	 * elements in the batched-data-inserter(s)
	 *
	 * @param MysqliWrapper $cxn An existing DB connection to leverage
	 * @throws InfrastructureErrorException On database errors
	 */
	public function done_saving(MysqliWrapper $cxn) : void
	{
		$this->inserter->run_query($cxn);
	}

	/**
	 * Get all stored meta-field key names for products and variants
	 *
	 * @return string[] The list of unique meta-field keys that were stored
	 * @throws InfrastructureErrorException On database errors
	 */
	public function get_meta_field_names(MysqliWrapper $cxn) : array
	{
		if (!$this->split_to_col) {
			return [];
		}

		$res = $cxn->safe_query(sprintf(
			'SELECT DISTINCT(`%s`) AS name FROM `%s`',
			self::COLUMN_KEY,
			$this->table->get_table_name()
		));
		return array_column($res->fetch_all(MYSQLI_ASSOC), 'name');
	}

	/**
	 * Get all meta-fields for the given product ID
	 *
	 * @param MysqliWrapper $cxn An existing DB connection to leverage
	 * @param int $id The product ID
	 * @return array The meta-fields list ready for output
	 * @throws InfrastructureErrorException On database errors
	 */
	public function get_product_meta_fields(MysqliWrapper $cxn, int $id) : array
	{
		$ret = [];
		$res = $cxn->safe_query(
			$this->selector->get_query($cxn, [
				self::COLUMN_ID => $id,
				self::COLUMN_TYPE => self::FLAG_TYPE_PRODUCT,
			])
		);

		while ($row = $res->fetch_assoc()) {
			$ret[$row[self::COLUMN_KEY]] = $row[self::COLUMN_VAL];
		}

		return $ret;
	}

	/**
	 * Get all meta-fields for the given variant ID
	 *
	 * @param MysqliWrapper $cxn An existing DB connection to leverage
	 * @param int $id The variant ID
	 * @return array The meta-fields list ready for output
	 * @throws InfrastructureErrorException On database errors
	 */
	public function get_variant_meta_fields(MysqliWrapper $cxn, int $id) : array
	{
		$ret = [];
		$res = $cxn->safe_query(
			$this->selector->get_query($cxn, [
				self::COLUMN_ID => $id,
				self::COLUMN_TYPE => self::FLAG_TYPE_VARIANT,
			])
		);

		while ($row = $res->fetch_assoc()) {
			$ret[$row[self::COLUMN_KEY]] = $row[self::COLUMN_VAL];
		}

		return $ret;
	}

	/**
	 * Save a set of metafields with the split-to-column functionality
	 *
	 * @param MysqliWrapper $cxn An existing DB connection to leverage
	 * @param int $id The product/variant ID
	 * @param int $type The product or variant flag
	 * @param array[] $meta_field_list The list of meta field data
	 * @throws InfrastructureErrorException On database errors
	 */
	private function save_to_columns(MysqliWrapper $cxn, int $id, int $type, array $meta_field_list) : void
	{
		foreach ($meta_field_list as $d) {
			$this->inserter->add_value_set(
				$cxn,
				[
					self::COLUMN_ID => $id,
					self::COLUMN_TYPE => $type,
					self::COLUMN_KEY => $this->standardize_key($type, $d),
					self::COLUMN_VAL => substr(json_encode([
						'value' => $d['value'] ?? '',
						'namespace' => $d['namespace'] ?? '',
						'description' => $d['description'] ?? '',
					]), 0, 65534),
				]
			);
		}
	}

	/**
	 * Save a set of metafields as a blob
	 *
	 * @param MysqliWrapper $cxn An existing DB connection to leverage
	 * @param int $id The product/variant ID
	 * @param int $type The product or variant flag
	 * @param array[] $meta_field_list The list of meta field data
	 * @throws InfrastructureErrorException On database errors
	 */
	private function save_to_blob(MysqliWrapper $cxn, int $id, int $type, array $meta_field_list) : void
	{
		$trimmed_list = [];
		foreach ($meta_field_list as $d) {
			$trimmed_list[] = [
				'key' => $d['key'] ?? '',
				'value' => $d['value'] ?? '',
				'namespace' => $d['namespace'] ?? '',
				'description' => $d['description'] ?? '',
			];
		}

		$this->inserter->add_value_set($cxn, [
			self::COLUMN_ID => $id,
			self::COLUMN_TYPE => $type,
			self::COLUMN_KEY => ($type === self::FLAG_TYPE_PRODUCT ? 'product_meta' : 'variant_meta'),
			self::COLUMN_VAL => substr(json_encode($trimmed_list), 0, 65534), // Truncate for TEXT max length
		]);
	}

	/**
	 * Helper for standardizing a meta-field key name based on the import
	 * settings
	 *
	 * @param Array<string, string> $meta_field The metafield data to generate the key name from
	 * @return string The full key name
	 */
	private function standardize_key(int $type, array $meta_field) : string
	{
		$key = sprintf(
			'%s_%s%s',
			($type === self::FLAG_TYPE_PRODUCT ? 'parent_meta' : 'variant_meta'),
			$this->use_namespaces ? (($meta_field['namespace'] ?? '') . '_') : '',
			str_replace('-', '_', strtolower($meta_field['key']))
		);

		$key = preg_replace('/[^0-9,a-zA-Z$_\x{0080}-\x{FFFF}]/u', '', $key);
		return substr($key, 0, 254);
	}

}

