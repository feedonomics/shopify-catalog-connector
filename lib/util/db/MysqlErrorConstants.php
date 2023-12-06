<?php

namespace ShopifyConnector\util\db;


/**
 * Map MYSQL error codes to constant names
 */
class MysqlErrorConstants {

	/**
	 * @var int Query was successful, no errors
	 */
	const ERROR_NONE = 0;

	/**
	 * @var int Error failed trying to insert on a duplicate unique index
	 */
	const ERROR_DUPLICATE = 1062;

}

