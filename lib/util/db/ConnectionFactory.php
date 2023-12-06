<?php

namespace ShopifyConnector\util\db;

use ShopifyConnector\exceptions\InfrastructureErrorException;
use ShopifyConnector\log\ErrorLogger;

/**
 * Factory for easily connecting to a database
 */
class ConnectionFactory {

	/**
	 * @var string Connection location for the `local` database
	 */
	const DB_LOCAL = 'local';

	/**
	 * Attempt to connect to the given database and return a prepared
	 * MysqliWrapper
	 *
	 * @param string $db The database name to connect to (use the class
	 * constants prefixed with `DB_`)
	 * @return MysqliWrapper The connected mysqli wrapper
	 * @throws InfrastructureErrorException On errors connecting to the database
	 */
	public static function connect(string $db) : MysqliWrapper {
		$credentials = $GLOBALS['db_credentials'][$db] ?? null;

		if (!$credentials){
			ErrorLogger::log_error("Trying to connect to illegal database [${db}]");
			throw new InfrastructureErrorException();
		}

		//Set default port/socket
		if(empty($credentials['port'])) {
			$credentials['port'] = ini_get("mysqli.default_port");
		}

		if(empty($credentials['socket'])) {
			$credentials['socket'] = ini_get("mysqli.default_socket");
		}

		$cxn = new MysqliWrapper();
		$cxn->set_opt(MYSQLI_OPT_CONNECT_TIMEOUT, 25);
		$cxn->set_opt(MYSQLI_OPT_LOCAL_INFILE, true);

		$success = $cxn->real_connect(
			$credentials['host'],
			$credentials['username'],
			$credentials['password'],
			$credentials['db_name'],
			$credentials['port'],
			$credentials['socket']
		);

		if(!$success){
			ErrorLogger::log_error("Failed to connect to database ${db} - {$cxn->get_connect_error()}");
			throw new InfrastructureErrorException();
		}

		$cxn->set_charset('utf8');

		return $cxn;
	}

}

