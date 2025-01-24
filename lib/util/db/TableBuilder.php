<?php

namespace ShopifyConnector\util\db;

use ShopifyConnector\exceptions\InfrastructureErrorException;
use ShopifyConnector\log\ErrorLogger;


/**
 * Helper for building tables in a clean manner
 *
 * <p>The table name and one column must be set, everything else is optional</p>
 *
 * ```php
 * // Example
 * $tb = new TableBuilder($cxn);
 * $th = $tb->set_table_name('tmp_table_22')
 *   ->add_col_int('id')
 *   ->add_col_varchar('sku', 50)
 *   ->add_col_text('desc')
 *   ->set_primary_key('id')
 *   ->add_index('sku', 'id')
 *   ->build();
 * ```
 */
class TableBuilder {

	/**
	 * @var string Table option string we commonly use
	 */
	const TABLE_OPTION_MYISAM_UTF8MB4 = 1;

	/**
	 * @var ?MysqliWrapper Store for a db connection to use
	 */
	private ?MysqliWrapper $cxn;

	/**
	 * @var ?TableHandle The to-be-created table handle
	 */
	private ?TableHandle $table = null;

	/**
	 * @var string[] List of sanitized column clauses
	 */
	private array $columns = [];

	/**
	 * @var string The sanitized primary key clause
	 */
	private string $primary_key = '';

	/**
	 * @var string[] List of sanitized index clauses
	 */
	private array $indexes = [];

	/**
	 * @var string Optional sanitized table options
	 */
	private string $table_options = '';

	/**
	 * @param MysqliWrapper $cxn The database connection to use for creating the table
	 */
	public function __construct(MysqliWrapper $cxn){
		$this->cxn = $cxn;
	}

	/**
	 * Set the table name
	 *
	 * @param string $name Table name
	 * @return $this For chaining
	 */
	public function set_table_name(string $name) : TableBuilder {
		$this->table = new TableHandle($this->cxn, $name);
		return $this;
	}

	/**
	 * Add a CHAR column
	 *
	 * <p>Reminder: char(32) for MD5 hashes</p>
	 *
	 * @param string $name Column name
	 * @param int $size The char size
	 * @return $this For chaining
	 */
	public function add_col_char(string $name, int $size) : TableBuilder {
		$s_name = $this->cxn->strip_enclosure_characters($name);
		$this->columns[] = "`${s_name}` CHAR(${size})";
		return $this;
	}

	/**
	 * Add a VARCHAR column
	 *
	 * @param string $name Column name
	 * @param int $size The varchar size
	 * @return $this For chaining
	 */
	public function add_col_varchar(string $name, int $size = 255) : TableBuilder {
		$s_name = $this->cxn->strip_enclosure_characters($name);
		$this->columns[] = "`${s_name}` VARCHAR(${size})";
		return $this;
	}

	/**
	 * Add a TEXT column
	 *
	 * @param string $name Column name
	 * @return $this For chaining
	 */
	public function add_col_text(string $name) : TableBuilder {
		$s_name = $this->cxn->strip_enclosure_characters($name);
		$this->columns[] = "`${s_name}` TEXT";
		return $this;
	}

	/**
	 * Add a MEDIUMTEXT column
	 *
	 * @param string $name Column name
	 * @return $this For chaining
	 */
	public function add_col_mediumtext(string $name) : TableBuilder {
		$s_name = $this->cxn->strip_enclosure_characters($name);
		$this->columns[] = "`${s_name}` MEDIUMTEXT";
		return $this;
	}

	/**
	 * Add an INT column
	 *
	 * @param string $name Column name
	 * @param bool $unsigned Whether the column is an UNSIGNED INT
	 * @return $this For chaining
	 */
	public function add_col_int(string $name, bool $unsigned = true) : TableBuilder {
		$s_name = $this->cxn->strip_enclosure_characters($name);
		$this->columns[] = $unsigned ? "`${s_name}` INT UNSIGNED" : "`${s_name}` INT";
		return $this;
	}

	/**
	 * Add a BIGINT column
	 *
	 * @param string $name Column name
	 * @param bool $unsigned Whether the column is an UNSIGNED INT
	 * @return TableBuilder For chaining
	 */
	public function add_col_bigint(string $name, bool $unsigned = true) : TableBuilder
	{
		$s_name = $this->cxn->strip_enclosure_characters($name);
		$this->columns[] = $unsigned ? "`{$s_name}` BIGINT UNSIGNED" : "`{$s_name}` BIGINT";
		return $this;
	}

	/**
	 * Add a TINYINT column
	 *
	 * @param string $name Column name
	 * @param bool $unsigned Whether the column is an UNSIGNED INT
	 * @return $this For chaining
	 */
	public function add_col_tinyint(string $name, bool $unsigned = true) : TableBuilder {
		$s_name = $this->cxn->strip_enclosure_characters($name);
		$this->columns[] = $unsigned ? "`${s_name}` TINYINT UNSIGNED" : "`${s_name}` TINYINT";
		return $this;
	}

	/**
	 * Add an auto-incrementing int column
	 *
	 * <p>Note: This column is of type `INT` and is always unsigned</p>
	 *
	 * @param string $name Column name
	 * @return $this For chaining
	 */
	public function add_col_incremental_int(string $name) : TableBuilder {
		$s_name = $this->cxn->strip_enclosure_characters($name);
		$this->columns[] = "`{$s_name}` INT UNSIGNED AUTO_INCREMENT";
		return $this;
	}

	/**
	 * Set the PRIMARY KEY
	 *
	 * @param string ...$column The column name (or names for multi-column
	 * indexing)
	 * @return $this For chaining
	 */
	public function set_primary_key(string ...$column) : TableBuilder {
		$this->primary_key = sprintf(
			'PRIMARY KEY (`%s`)',
			implode('`, `', $this->sanitize_column_list($column))
		);
		return $this;
	}

	/**
	 * Add an INDEX
	 *
	 * @param string ...$column The column name (or names for multi-column
	 * indexing)
	 * @return $this For chaining
	 */
	public function add_index(string ...$column) : TableBuilder {
		$this->indexes[] = sprintf(
			'INDEX (`%s`)',
			implode('`, `', $this->sanitize_column_list($column))
		);
		return $this;
	}

	/**
	 * Set the custom table options
	 *
	 * @param int $flag The flag for which table option to apply (see class
	 * constants)
	 * @return TableBuilder For chaining
	 * @throws InfrastructureErrorException If a non-whitelisted option was passed in
	 */
	public function set_table_options(int $flag) : TableBuilder {
		switch($flag){
			case self::TABLE_OPTION_MYISAM_UTF8MB4:
				$this->table_options = ' ENGINE MyISAM CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
				break;

			default:
				ErrorLogger::log_error("Failed to set table options, invalid flag provided ({$flag}).");
				throw new InfrastructureErrorException();
		}

		return $this;
	}

	/**
	 * Create the physical table in the database using whatever has been set so
	 * far and drop the database connection reference (does not close it)
	 *
	 * <p><i>This builder should not be used after this has been called</i></p>
	 *
	 * @return TableHandle The table handle for the created table
	 * @throws InfrastructureErrorException If the table name and/or one column was not
	 * added before building
	 */
	public function build() : TableHandle {
		if($this->table === null || empty($this->columns)){
			ErrorLogger::log_error('Failing building table, missing table name and/or columns');
			throw new InfrastructureErrorException();
		}

		// If the connection was unassigned then we can assume the table's
		// already built and should throw an error since it's an error in
		// logic to be calling this twice
		if($this->cxn === null){
			ErrorLogger::log_error('Called TableBuilder::build() twice on ' . $this->table->get_table_name());
			throw new InfrastructureErrorException();
		}

		$qry = sprintf(
			'CREATE TABLE `%s` (%s',
			$this->table->get_table_name(),
			implode(', ', $this->columns)
		);

		if($this->primary_key !== ''){
			$qry .= ", {$this->primary_key}";
		}

		if(!empty($this->indexes)){
			$qry .= ', ';
			$qry .= implode(', ', $this->indexes);
		}

		$qry .= "){$this->table_options}";

		if(!$this->cxn->query($qry)){
			$err = $this->cxn->get_error();
			ErrorLogger::log_error("Failed to build table. Reason: {$err}. Create statement: ${qry}");
			throw new InfrastructureErrorException();
		}

		// Drop the internal reference to the db connection so it
		// doesn't live on, but don't close it
		$this->cxn = null;

		return $this->table;
	}

	/**
	 * Helper for sanitizing a list of column names
	 *
	 * @param array $columns The raw column names
	 * @return array The sanitized column names
	 */
	private function sanitize_column_list(array $columns) : array {
		return array_map(fn($c) => $this->cxn->strip_enclosure_characters($c), $columns);
	}

}

