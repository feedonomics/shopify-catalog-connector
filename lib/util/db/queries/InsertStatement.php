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
class InsertStatement
{

	/**
	 * @var int Behavior flag to throw when a duplicate entry is encountered
	 */
	const FLAG_THROW_ON_DUP = 0;

	/**
	 * @var int Behavior flag to update records when a duplicate is encountered
	 */
	const FLAG_UPDATE_ON_DUP = 1;

	/**
	 * @var int Behavior flag to ignore new values when a duplicate entry is
	 * encountered
	 */
	const FLAG_IGNORE_ON_DUP = 2;

	/**
	 * @var TableHandle Store for the table data is being inserted into
	 */
	private TableHandle $table;

	/**
	 * @var int Flag for behavior when writing a duplicate entry
	 */
	private int $duplicate_flag;

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
	 * @param int $duplicate_flag Flag for how to handle duplicate entries
	 */
	public function __construct(TableHandle $table, int $duplicate_flag = self::FLAG_THROW_ON_DUP)
	{
		$this->table = $table;
		$this->duplicate_flag = $duplicate_flag;
	}

	/**
	 * Get the set duplicate behavior flag
	 *
	 * @return int True if updating on duplicates is set
	 */
	public function get_duplicate_flag() : int
	{
		return $this->duplicate_flag;
	}

	/**
	 * Add a set of columns to the insert statement
	 *
	 * <p>This is additive</p>
	 *
	 * @param MysqliWrapper $cxn A db connection to leverage for sanitizing
	 * @param string[] $columns The column names to add
	 */
	public function add_columns(MysqliWrapper $cxn, array $columns) : void
	{
		foreach ($columns as $col) {
			$c = $cxn->strip_enclosure_characters($col);
			$this->columns[$c] = null;
		}

		// Refresh the ON DUPLICATE string whenever new columns are added
		// if the update flag is set
		if ($this->duplicate_flag === self::FLAG_UPDATE_ON_DUP) {
			$dup_cols = [];

			foreach (array_keys($this->columns) as $c) {
				$dup_cols[] = "`{$c}`=VALUES(`{$c}`)";
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
	public function add_value_set(MysqliWrapper $cxn, array $values) : void
	{
		$cols = $this->columns;

		foreach ($values as $k => $v) {
			if (array_key_exists($k, $cols)) {
				$v = is_array($v) ? json_encode($v) : $v;
				$cols[$k] = $cxn->real_escape_string($v);
			}
		}

		// Replace null values with null strings and add single quotes around
		// everything else
		$cols = array_map(fn($v) => $v === null ? 'null' : "'{$v}'", $cols);
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
	public function get_query() : string
	{
		$cols = array_keys($this->columns);

		$ret = sprintf(
			"INSERT%s INTO `%s` (%s) VALUES %s%s",
			($this->duplicate_flag === self::FLAG_IGNORE_ON_DUP) ? ' IGNORE' : '',
			$this->table->get_table_name(),
			'`' . implode('`, `', $cols) . '`',
			implode(', ', $this->insert_sets),
			$this->on_dup
		);

		$this->insert_sets = [];

		return $ret;
	}

	/**
	 * Build an insert statement string that includes only the columns
	 * included in the given values array (intersected with the list of
	 * columns that have been added to this InsertStatement -- columns not
	 * in that list will be ignored).
	 *
	 * @param MysqliWrapper $cxn A db connection to leverage for sanitizing
	 * @param string[] $values The values to add - which need to be keyed by column name
	 */
	public function get_upsert_query(MysqliWrapper $cxn, array $values) : string
	{
		$cols = [];
		$update_cols = [];

		foreach (array_keys($this->columns) as $name) {
			// Skip columns not inlcuded in the given value set
			if (!array_key_exists($name, $values)) {
				continue;
			}

			// If data is NULL, propogate it, otherwise sanitize it
			$clean_data = $values[$name] === null
				? null
				: $cxn->real_escape_string(is_array($values[$name])
					? json_encode($values[$name])
					: $values[$name]
				);

			// If data is NULL, use a string of "null", otherwise use the
			// sanitized data enclosed in single quotes
			// NOTE 1: BACKTICKS ON COL NAMES INCLUDED HERE
			$cols["`{$name}`"] = $clean_data === null
				? 'null'
				: "'{$clean_data}'";

			if ($this->duplicate_flag === self::FLAG_UPDATE_ON_DUP) {
				$update_cols[] = "`{$name}`=VALUES(`{$name}`)";
			}
		}

		$update_str = ($this->duplicate_flag === self::FLAG_UPDATE_ON_DUP)
			? ' ON DUPLICATE KEY UPDATE ' . implode(', ', $update_cols)
			: '';

		return sprintf(
			'INSERT INTO `%s` (%s) VALUES (%s)%s',
			$this->table->get_table_name(),
			implode(', ', array_keys($cols)), // SEE NOTE 1 ABOVE
			implode(', ', array_values($cols)),
			$update_str
		);
	}

}
