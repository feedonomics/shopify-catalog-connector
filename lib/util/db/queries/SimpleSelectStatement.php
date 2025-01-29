<?php
namespace ShopifyConnector\util\db\queries;

use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\TableHandle;

/**
 * Utility for building simple select statements
 *
 * <p>Note: This utility is limited in what it can do:</p>
 *
 * <ol>
 * <li>Multiple WHERE clauses only support AND</li>
 * <li>WHERE clauses only support the equals operator</li>
 * <li>Only single table queries are supported (no joins)</li>
 * </ol>
 *
 * ```php
 * // Examples
 *
 * // SELECT * FROM table
 * $sss = new SimpleSelectStatement(new TableHandle($cxn, 'table'));
 * $sss->get_query($cxn);
 *
 * // SELECT sku, name, desc FROM table WHERE sku = 'asdf'
 * $sss = new SimpleSelectStatement(new TableHandle($cxn, 'table'));
 * $sss->add_column($cxn, 'sku');
 * $sss->add_column($cxn, 'name');
 * $sss->add_column($cxn, 'desc');
 * $sss->add_where_column($cxn, 'sku');
 * $sss->get_query($cxn, ['sku' => 'asdf']);
 *
 * // SELECT * from table ORDER BY sku ASC LIMIT 10 OFFSET 5
 * $sss = new SimpleSelectStatement(new TableHandle($cxn, 'table'));
 * $sss->add_order($cxn, 'sku', false);
 * $sss->add_limit(10);
 * $sss->add_offset(5);
 * $sss->get_query($cxn);
 * ```
 */
class SimpleSelectStatement {

	/**
	 * @var TableHandle Store for the table that will be selected on
	 */
	private TableHandle $table;

	/**
	 * @var string[] Store for the sanitized list of specific column names
	 * to retrieve
	 *
	 * <p>Note: column names are ready to be injected as-is, meaning they are
	 * sanitized and aliased appropriately before being added here</p>
	 */
	private array $columns = [];

	/**
	 * @var string[] Store for the `WHERE = ` condition columns
	 *
	 * <p>Note: The keys are RAW column names, the values are sanitized</p>
	 */
	private array $where = [];

	/**
	 * @var array Store for the ORDER BY conditions
	 *
	 * <p>Note: column names are ready to be injected as-is, meaning they are
	 * sanitized and aliased appropriately before being added here</p>
	 */
	private array $order = [];

	/**
	 * @var int|null Store for the LIMIT condition
	 */
	private ?int $limit = null;

	/**
	 * @var int|null Store for the OFFSET condition
	 */
	private ?int $offset = null;

	/**
	 * Prepare the statement for use
	 *
	 * @param TableHandle $table The handle for the table to be queried on
	 */
	public function __construct(TableHandle $table){
		$this->table = $table;
	}

	/**
	 * Add a specific column to include in the result set
	 *
	 * <p>Note: if this method is not called, the result set will default
	 * to `*`</p>
	 *
	 * @param MysqliWrapper $cxn An existing DB connection to leverage
	 * @param string $column The column name to include in the output
	 * @param string|null $alias An optional alias for the column
	 * @return $this For chaining
	 */
	public function add_column(MysqliWrapper $cxn, string $column, ?string $alias = null) : SimpleSelectStatement {
		$column = $cxn->strip_enclosure_characters($column);
		$this->columns[] = sprintf(
			"`%s` AS `%s`",
			$column,
			$alias ? $cxn->strip_enclosure_characters($alias) : $column
		);
		return $this;
	}

	/**
	 * Add an AND WHERE condition to the query
	 *
	 * <p>Note: Values will be passed in with the `get_query` method</p>
	 *
	 * @param MysqliWrapper $cxn An existing DB connection to leverage
	 * @param string $column The column name
	 * @return $this For chaining
	 */
	public function add_where_column(MysqliWrapper $cxn, string $column) : SimpleSelectStatement {
		$this->where[$column] = $cxn->strip_enclosure_characters($column);
		return $this;
	}

	/**
	 * Add an ORDER BY condition to the query
	 *
	 * <p>Note: Multiple calls to this will maintain the order they are given
	 * in the query</p>
	 *
	 * @param MysqliWrapper $cxn An existing DB connection to leverage
	 * @param string $column The column name to order on
	 * @param bool $desc True for DESC results, false for ASC
	 * @return $this For chaining
	 */
	public function add_order(MysqliWrapper $cxn, string $column, bool $desc = true) : SimpleSelectStatement {
		$this->order[] = sprintf(
			"`%s` %s",
			$cxn->strip_enclosure_characters($column),
			$desc ? 'DESC' : 'ASC'
		);
		return $this;
	}

	/**
	 * Add a LIMIT condition to the query
	 *
	 * @param int $limit The limit value
	 * @return $this For chaining
	 */
	public function add_limit(int $limit) : SimpleSelectStatement {
		$this->limit = $limit;
		return $this;
	}

	/**
	 * Add an OFFSET condition to the query
	 *
	 * @param int $offset The offset value
	 * @return $this For chaining
	 */
	public function add_offset(int $offset) : SimpleSelectStatement {
		$this->offset = $offset;
		return $this;
	}

	/**
	 * Build and return the query
	 *
	 * <p>This will only add conditions if they were set</p>
	 *
	 * <p>If `add_columns` was never called, this will add `*` to the
	 * SELECT condition</p>
	 *
	 * <p>Tip: If `add_where_column` was called but you need to query without
	 * a WHERE condition, omitting the $where_values param will skip adding
	 * the WHERE conditions to the query</p>
	 *
	 * @param MysqliWrapper $cxn An existing DB connection to leverage
	 * @param Array<string, mixed> $where_values The WHERE clause values to add - these should
	 * be keyed by column name matching the columns passed to `add_where_column`
	 * @return string The SQL SELECT string
	 */
	public function get_query(MysqliWrapper $cxn, array $where_values = []) : string {
		$qry = sprintf(
			'SELECT %s FROM `%s`',
			empty($this->columns) ? '*' : implode(', ', $this->columns),
			$this->table->get_table_name()
		);

		if(!empty($this->where) && !empty($where_values)){
			$where = [];
			foreach($this->where as $raw => $sanitized){
				$where[] = sprintf(
					"`%s` = '%s'",
					$sanitized,
					$cxn->real_escape_string($where_values[$raw])
				);
			}
			$qry .= ' WHERE ' . implode(' AND ', $where);
		}

		if(!empty($this->order)){
			$qry .= ' ORDER BY ' . implode(', ', $this->order);
		}

		if($this->limit !== null){
			$qry .= " LIMIT {$this->limit}";

			if($this->offset !== null){
				$qry .= " OFFSET {$this->offset}";
			}
		}

		return $qry;
	}

}
