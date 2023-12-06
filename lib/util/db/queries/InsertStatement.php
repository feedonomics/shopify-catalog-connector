<?php

namespace ShopifyConnector\util\db\queries;

use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\TableHandle;


/**
 * Helper for building/reusing insert statements in a clean and easy manner
 *
 * ```php
 * // Example
 * $is = new InsertStatement( (new TableHandle('my_table')), true );
 * $is->add_columns(['sku', 'name', 'desc']);
 *
 * $is->add_value_set(['sku' => 'shirt1', 'name' => 'Shirt One', 'desc' => 'It is a shirt']);
 * $is->add_value_set(['sku' => 'pants1', 'name' => 'Pants One', 'desc' => 'They are pants']);
 * $cxn->query($is->get_query());
 *
 * // Value sets are reset between `get_query` calls for re-usability
 * $is->add_value_set(['sku' => 'boots', 'name' => 'These Boots', 'desc' => 'Make for walkin\'']);
 * $cxn->query($is->get_query());
 * ```
 */
class InsertStatement {

	/**
	 * @var TableHandle Store for the table data is being inserted into
	 */
	private TableHandle $table;

	/**
	 * @var bool Flag for whether to set the `ON DUPLICATE KEY UPDATE` statement
	 */
	private bool $update;

	/**
	 * @var null[] Store for the columns to insert on
	 *
	 * <p>The keys are the sanitized column names, null for values so this can
	 * be duplicated/filled efficiently when building the query</p>
	 */
	private array $columns = [];

	/**
	 * @var string[] Store for each set of VALUES
	 */
	private array $insert_sets = [];

	/**
	 * @var string Store for the `ON DUPLICATE KEY` statement (if set)
	 */
	private string $on_dup = '';

	/**
	 * Set the table and whether to update values on duplicate keys
	 *
	 * @param TableHandle $table The table handle data will be inserted to
	 * @param bool $update Flag to update data on duplicate keys
	 */
	public function __construct(TableHandle $table, bool $update = false){
		$this->table = $table;
		$this->update = $update;
	}

	/**
	 * Add a set of columns to the insert statement
	 *
	 * <p>This is additive</p>
	 *
	 * @param MysqliWrapper $cxn A db connection to leverage for sanitizing
	 * @param string[] $columns The column names to add
	 */
	public function add_columns(MysqliWrapper $cxn, array $columns) : void {
		foreach($columns as $col){
			$c = $cxn->strip_enclosure_characters($col);
			$this->columns[$c] = null;
		}

		// Refresh the ON DUPLICATE string whenever new columns are added
		// if the update flag is set
		if($this->update){
			$dup_cols = [];
			foreach(array_keys($this->columns) as $c){
				$dup_cols[] = "`${c}`=VALUES(`${c}`)";
			}
			$this->on_dup = ' ON DUPLICATE KEY UPDATE ' . implode(', ', $dup_cols);
		}
	}

	/**
	 * Add a set of values to include in the insert statement
	 *
	 * <p>Multiple calls to this are treated as separate VALUE groups</p>
	 *
	 * @param MysqliWrapper $cxn A db connection to leverage for sanitizing
	 * @param string[] $values The values to add - which need to be keyed by
	 * column name
	 */
	public function add_value_set(MysqliWrapper $cxn, array $values) : void {
		$cols = $this->columns;

		foreach($values as $k => $v){
			if(array_key_exists($k, $cols)){
				$v = is_array($v) ? json_encode($v) : $v;
				$cols[$k] = $cxn->real_escape_string($v);
			}
		}

		// Replace null values with null strings and add single quotes around
		// everything else
		$cols = array_map(fn($v) => $v === null ? 'null' : "'${v}'", $cols);
		$set = "(" . implode(", ", $cols) . ")";
		$this->insert_sets[] = $set;
	}

	/**
	 * Builds the insert statement string
	 *
	 * <p>This will clear all stored insert values once called</p>
	 *
	 * @return string The SQL INSERT string
	 */
	public function get_query() : string {
		$cols = array_keys($this->columns);

		$ret = sprintf(
			"INSERT INTO `%s` (%s) VALUES %s%s",
			$this->table->get_table_name(),
			'`' . implode('`, `', $cols) . '`',
			implode(', ', $this->insert_sets),
			$this->on_dup
		);

		$this->insert_sets = [];

		return $ret;
	}

}

