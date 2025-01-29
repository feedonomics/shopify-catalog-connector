<?php

namespace ShopifyConnector\connectors\shopify\legacy;

use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\queries\BatchedDataInserter;
use ShopifyConnector\util\db\queries\InsertStatement;
use ShopifyConnector\util\db\queries\SimpleSelectStatement;
use ShopifyConnector\util\db\TableHandle;
use ShopifyConnector\util\db\TemporaryTableGenerator;
use ShopifyConnector\exceptions\InfrastructureErrorException;

/**
 * Utility for saving Shopify translations for products
 */
class TranslationsStorage
{

	/**
	* @var string Name for the ID DB column
	*/
	const COLUMN_ID = 'p_id';

	/**
	* @var string Name for the translations key DB column
	*/
	const COLUMN_KEY = 'translations_key';

	/**
	* @var string Name for the translations value DB column
	*/
	const COLUMN_VAL = 'translations_value';

	/**
	* @var TableHandle Store for the table handle to store translations data
	*/
	private TableHandle $table;

	/**
	* @var BatchedDataInserter Store for the batched data inserter to write
	* records in bulk
	*/
	private BatchedDataInserter $inserter;

	/**
	* @var SimpleSelectStatement Store for the simple select statement for
	* getting translations data for a given product by ID
	*/
	private SimpleSelectStatement $selector;

	/**
	* Utility for saving Shopify translations for products
	*
	* @param MysqliWrapper $cxn An existing DB connection to leverage
	* @throws InfrastructureErrorException
	*/
	public function __construct(MysqliWrapper $cxn)
	{
		$this->table = TemporaryTableGenerator::get($cxn, 'shopify_translations')
			->add_col_bigint(self::COLUMN_ID)
			->add_col_varchar(self::COLUMN_KEY)
			->add_col_text(self::COLUMN_VAL)
			->add_index(self::COLUMN_ID)
			->add_index(self::COLUMN_KEY)
			->build();

		$insert = new InsertStatement($this->table, InsertStatement::FLAG_UPDATE_ON_DUP);
		$insert->add_columns($cxn, [
			self::COLUMN_ID,
			self::COLUMN_KEY,
			self::COLUMN_VAL,
		]);

		$this->inserter = new BatchedDataInserter($cxn, $insert);
		$this->selector = (new SimpleSelectStatement($this->table))
			->add_where_column($cxn, self::COLUMN_ID)
			->add_column($cxn, self::COLUMN_KEY)
			->add_column($cxn, self::COLUMN_VAL);

	}

	/**
	 * Save a list of translations for a given product
	 *
	 * @param MysqliWrapper $cxn An existing DB connection to leverage
	 * @param int $id The product ID
	 * @param array[] $translations_list The list of translations objects to save
	 * @throws InfrastructureErrorException On database errors
	 */
	public function save_product_translations(MysqliWrapper $cxn, int $id, array $translations_list) : void
	{
		foreach ($translations_list as $d) {
			$this->inserter->add_value_set(
				$cxn,
				[
					self::COLUMN_ID => $id,
					self::COLUMN_KEY => $d['locale'] . '_' . $d['key'],
					self::COLUMN_VAL => $d['value']
				]
			);
		}
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
	 * Get all stored translations key names for products
	 *
	 * @return string[] The list of unique translations keys that were stored
	 * @throws InfrastructureErrorException On database errors
	 */
	public function get_translations_names(MysqliWrapper $cxn) : array
	{
		$res = $cxn->safe_query(sprintf(
			'SELECT DISTINCT(`%s`) AS name FROM `%s`',
			self::COLUMN_KEY,
			$this->table->get_table_name()
		));
		return array_column($res->fetch_all(MYSQLI_ASSOC), 'name');
	}

	/**
	 * Get all translations for the given product ID
	 *
	 * @param MysqliWrapper $cxn An existing DB connection to leverage
	 * @param int $id The product ID
	 * @return array The translations list ready for output
	 * @throws InfrastructureErrorException On database errors
	 */
	public function get_product_translations(MysqliWrapper $cxn, int $id) : array
	{
		$ret = [];
		$res = $cxn->safe_query(
			$this->selector->get_query($cxn, [
				self::COLUMN_ID => $id,
			])
		);

		while ($row = $res->fetch_assoc()) {
			$ret[$row[self::COLUMN_KEY]] = $row[self::COLUMN_VAL];
		}

		return $ret;
	}

}

