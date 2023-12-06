<?php

namespace ShopifyConnector\util\db;

use ShopifyConnector\exceptions\InfrastructureErrorException;
use ShopifyConnector\log\ErrorLogger;
use ShopifyConnector\util\TemporaryResourceRegister;
use ShopifyConnector\util\generators\RandomString;


/**
 * Factory for generating, tracking, and cleaning up temporary database tables
 */
class TemporaryTableGenerator {

	/**
	 * @var TemporaryResourceRegister The resource register to add temporary
	 * tables resources to
	 */
	private static TemporaryResourceRegister $resource_register;

	/**
	 * Starts the process of a {@see TableBuilder} by initializing it and
	 * setting the table name ahead of time, then returns it after registering
	 * the table name for cleanup
	 *
	 * @param MysqliWrapper $cxn A database connection to leverage
	 * @param string $table_prefix An optional table name prefix (omit the
	 * trailing underscore)
	 * @return TableBuilder An in-progress table builder to finish building the
	 * table with
	 * @throws InfrastructureErrorException On errors generating the table name
	 */
	public static function get(MysqliWrapper $cxn, string $table_prefix) : TableBuilder {
		$table_name = $table_prefix . '_' . RandomString::hex(10);

		if(strlen($table_name) > 64){
			ErrorLogger::log_error("Temporary table name too long: ${table_name}");
			throw new InfrastructureErrorException();
		}

		self::$resource_register->add(new TemporaryTable($cxn, $table_name));
		return (new TableBuilder($cxn))
			->set_table_name($table_name);
	}

	/**
	 * Initialize the temporary resource register for use
	 *
	 * <p><i>This is done on file-load and should not be called again</i></p>
	 */
	public static function init(){
		self::$resource_register = new TemporaryResourceRegister();
	}

}

// Do initialization when this file is loaded
TemporaryTableGenerator::init();

