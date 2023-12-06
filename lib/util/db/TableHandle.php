<?php

namespace ShopifyConnector\util\db;


/**
 * Wrapper and utilities for a database table
 */
class TableHandle {

	/**
	 * @var string The sanitized table name
	 */
	protected string $table_name;

	/**
	 * Sanitize and store the table name
	 *
	 * @param MysqliWrapper $cxn DB connection to sanitize with
	 * @param string $table_name The raw table name
	 */
	public function __construct(MysqliWrapper $cxn, string $table_name){
		$this->table_name = $cxn->strip_enclosure_characters($table_name);
	}

	/**
	 * Get the <i>sanitized</i> table name (without surrounding enclosure
	 * characters)
	 *
	 * @return string Table name
	 */
	public function get_table_name() : string {
		return $this->table_name;
	}

}

