<?php

namespace ShopifyConnector\connectors\shopify\traits;

use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\TableBuilder;
use ShopifyConnector\util\db\TableHandle;
use ShopifyConnector\util\db\TemporaryTableGenerator;
use ShopifyConnector\util\db\queries\BatchedDataInserter;
use ShopifyConnector\util\db\queries\InsertStatement;
use ShopifyConnector\exceptions\InfrastructureErrorException;

use mysqli_result;

/**
 * Common behaviors for modules in a reusable package. If modules do not need to handle
 * data in a unique way, this trait centralizes convenient implementations of actions
 * that modules will need to take.
 *
 * This trait is only meant to be used by classes implementing iModule, as it relies
 * on some of the definitions from that interface.
 */
trait StandardModule
{

	/**
	 * @var string The name for the id column
	 */
	const COLUMN_ID = 'id';

	/**
	 * @var string The name for the parent [product] id column
	 */
	const COLUMN_PARENT_ID = 'parent_id';

	/**
	 * @var string The name for the data column
	 */
	const COLUMN_DATA = 'data';


	/**
	 * Create a table for products in the database and return the associated TableHandle.
	 *
	 * @param MysqliWrapper $cxn The database connection to use
	 * @param string $table_name The name to use for the table
	 * @return TableHandle The handle object for the created table
	 * @throws InfrastructureErrorException
	 */
	private function generate_product_table(MysqliWrapper $cxn, string $table_name) : TableHandle
	{
		return $this->generate_data_table($cxn, $table_name, false);
	}

	/**
	 * Create a table for variants in the database and return the associated TableHandle.
	 * This table is like the product table, but will include an additional column for the
	 * parent id (the parent product for the variant).
	 *
	 * @param MysqliWrapper $cxn The database connection to use
	 * @param string $table_name The name to use for the table
	 * @return TableHandle The handle object for the created table
	 * @throws InfrastructureErrorException
	 */
	private function generate_variant_table(MysqliWrapper $cxn, string $table_name) : TableHandle
	{
		return $this->generate_data_table($cxn, $table_name, true);
	}

	/**
	 * Internal backing for the generate_*_table methods. Users of this trait should use
	 * those rather than calling this method directly.
	 *
	 * @param MysqliWrapper $cxn The database connection to use
	 * @param string $table_name The name to use for the table
	 * @param bool $include_parent_col Whether or not an additional parent_id column should be added
	 * @return TableHandle The handle object for the created table
	 * @throws InfrastructureErrorException
	 */
	private function generate_data_table(MysqliWrapper $cxn, string $table_name, bool $include_parent_col) : TableHandle
	{
		$builder = TemporaryTableGenerator::get($cxn, $table_name)
			->add_col_bigint(self::COLUMN_ID)
			->add_col_mediumtext(self::COLUMN_DATA)

			->add_index(self::COLUMN_ID)
			->set_table_options(TableBuilder::TABLE_OPTION_MYISAM_UTF8MB4)
		;

		if ($include_parent_col) {
			$builder
				->add_col_bigint(self::COLUMN_PARENT_ID)
				->add_index(self::COLUMN_PARENT_ID)
			;
		}

		return $builder->build();
	}

	/**
	 * Create and return an InsertStatement for the products table.
	 *
	 * @param MysqliWrapper $cxn The database connection to use
	 * @param TableHandle $table The handle for the products table
	 * @return InsertStatement An inserter for use with the products table
	 */
	private function get_product_inserter(MysqliWrapper $cxn, TableHandle $table) : InsertStatement
	{
		return $this->get_basic_inserter($cxn, $table, false);
	}

	/**
	 * Create and return an InsertStatement for the variants table.
	 * As with the table generator method, the inserter created will include an additional
	 * column for the parent id.
	 *
	 * @param MysqliWrapper $cxn The database connection to use
	 * @param TableHandle $table The handle for the variants table
	 * @return InsertStatement An inserter for use with the variants table
	 */
	private function get_variant_inserter(MysqliWrapper $cxn, TableHandle $table) : InsertStatement
	{
		return $this->get_basic_inserter($cxn, $table, true);
	}

	/**
	 * Internal backing for the get_*_inserter methods. Users of this trait should use
	 * those rather than calling this method directly.
	 *
	 * @param MysqliWrapper $cxn The database connection to use
	 * @param TableHandle $table The table to create an inserter for
	 * @param bool $include_parent_col Whether or not the table includes the parent_id column
	 */
	private function get_basic_inserter(MysqliWrapper $cxn, TableHandle $table, bool $include_parent_col) : InsertStatement
	{
		$inserter = new InsertStatement($table, InsertStatement::FLAG_IGNORE_ON_DUP);
		$inserter->add_columns($cxn, [
			self::COLUMN_ID,
			self::COLUMN_DATA,
		]);

		if ($include_parent_col) {
			$inserter->add_columns($cxn, [
				self::COLUMN_PARENT_ID,
			]);
		}

		return $inserter;
	}

	/**
	 * Retrieve the next set of data after the given `$last_retrieved_id` from the
	 * given table.
	 *
	 * The result set includes "id" and "data" columns.
	 *
	 * @param MysqliWrapper $cxn The database connection to query on
	 * @param TableHandle $table The table to query
	 * @param int $last_retrieved_id The id of the last data retrieved prior to this call
	 * @return mysqli_result The result set from the query
	 * @throws InfrastructureErrorException
	 */
	private function query_next_data(MysqliWrapper $cxn, TableHandle $table, int $last_retrieved_id) : mysqli_result
	{
		$col_id = self::COLUMN_ID;
		$col_data = self::COLUMN_DATA;
		$table_name = $table->get_table_name();

		// The column names come from hardcoded values, thus okay to use
		// The table name is sanitized by TableHandle, thus okay to use
		// $last_retrieved_id is typed as an int (and cast where set), thus okay to use
		$result = $cxn->safe_query(<<<SQL
			SELECT prod.`{$col_id}` AS id, prod.`{$col_data}` AS data
			FROM `{$table_name}` prod
			WHERE prod.`{$col_id}` = (
				SELECT tt.`{$col_id}`
				FROM `{$table_name}` tt
				WHERE tt.`{$col_id}` > {$last_retrieved_id}
				ORDER BY tt.`{$col_id}` ASC
				LIMIT 1
			)
			SQL
		);

		if (!$result) {
			# TODO: Better error? Log something? Is mysqli set up to throw instead?
			throw new \Exception('Error while running next-data query: ' . $this->get_module_name());
		}

		return $result;
	}

	/**
	 * Retrieve the next set of data after the given `$last_retrieved_id` from the
	 * given variants table.
	 *
	 * The result set includes "id", "parent_id", and "data" columns.
	 *
	 * @param MysqliWrapper $cxn The database connection to query on
	 * @param TableHandle $table The variant table to query
	 * @param int $last_retrieved_id The id of the last data retrieved prior to this call
	 * @return mysqli_result The result set from the query
	 * @throws InfrastructureErrorException
	 */
	private function query_next_variant_data(MysqliWrapper $cxn, TableHandle $table, int $last_retrieved_id) : mysqli_result
	{
		$col_id = self::COLUMN_ID;
		$col_data = self::COLUMN_DATA;
		$col_parent_id = self::COLUMN_PARENT_ID;
		$table_name = $table->get_table_name();

		// The column names come from hardcoded values, thus okay to use
		// The table name is sanitized by TableHandle, thus okay to use
		// $last_retrieved_id is typed as an int (and cast where set), thus okay to use
		$result = $cxn->safe_query(<<<SQL
			SELECT var.`{$col_id}` AS id, var.`{$col_parent_id}` AS parent_id, var.`{$col_data}` AS data
			FROM `{$table_name}` var
			WHERE var.`{$col_id}` = (
				SELECT tt.`{$col_id}`
				FROM `{$table_name}` tt
				WHERE tt.`{$col_id}` > {$last_retrieved_id}
				ORDER BY tt.`{$col_id}` ASC
				LIMIT 1
			)
			SQL
		);

		if (!$result) {
			# TODO: Better error? Log something? Is mysqli set up to throw instead?
			throw new \Exception('Error while running next-data query: ' . $this->get_module_name());
		}

		return $result;
	}

	/**
	 * Retrieve data by id from a table set up with the standard id and data
	 * fields, as {@see generate_data_table()} would create.
	 *
	 * The result includes a "data" column.
	 *
	 * @param MysqliWrapper $cxn The database connection to query on
	 * @param TableHandle $table The table to query
	 * @param int $id The id to query for
	 * @return mysqli_result The result set from the query
	 * @throws InfrastructureErrorException
	 */
	private function query_data_by_id(MysqliWrapper $cxn, TableHandle $table, int $id) : mysqli_result
	{
		$col_id = self::COLUMN_ID;
		$col_data = self::COLUMN_DATA;
		$table_name = $table->get_table_name();

		// The column names come from hardcoded values, thus okay to use
		// The table name is sanitized by TableHandle, thus okay to use
		// $id is an int by its param type, thus okay to use
		$result = $cxn->safe_query(<<<SQL
			SELECT `{$col_data}` AS data
			FROM `{$table_name}`
			WHERE `{$col_id}` = {$id}
			SQL
		);

		if (!$result) {
			# TODO: Better error? Return empty set and proceed? Log something?
			throw new \Exception('Error while running by-id query: ' . $this->get_module_name());
		}

		return $result;
	}

	/**
	 * Retrieve data by parent id from a table set up with the standard id, parent id,
	 * and data fields, as {@see generate_variant_table()} would create.
	 *
	 * The result includes "id" and "data" columns.
	 *
	 * @param MysqliWrapper $cxn The database connection to query on
	 * @param TableHandle $table The table to query
	 * @param int $parent_id The parent id to query for
	 * @return mysqli_result The result set from the query
	 * @throws InfrastructureErrorException
	 */
	private function query_data_by_parent_id(MysqliWrapper $cxn, TableHandle $table, int $parent_id) : mysqli_result
	{
		$col_id = self::COLUMN_ID;
		$col_data = self::COLUMN_DATA;
		$col_parent_id = self::COLUMN_PARENT_ID;
		$table_name = $table->get_table_name();

		// The column names come from hardcoded values, thus okay to use
		// The table names are sanitized by TableHandle, thus okay to use
		// $parent_id is an int by its param type, thus okay to use
		$result = $cxn->safe_query(<<<SQL
			SELECT var.`{$col_id}` AS id, var.`{$col_data}` AS data
			FROM `{$table_name}` var
			WHERE var.`{$col_parent_id}` = {$parent_id}
			ORDER BY var.`{$col_id}`
			SQL
		);

		if (!$result) {
			# TODO: Better error? Log something? Is mysqli set up to throw instead?
			throw new \Exception('Error while running by-parent-id query: ' . $this->get_module_name());
		}

		return $result;
	}

}

