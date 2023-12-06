<?php

namespace ShopifyConnector\util\db;

use Exception;

use ShopifyConnector\interfaces\TemporaryResource;
use ShopifyConnector\log\ErrorLogger;


/**
 * A temporary table resource that should not live past runtime
 *
 * <p>NOTE: This should not <i>usually</i> be accessed directly, but rather be
 * generated through the {@see TemporaryTableGenerator} or similar factory, so
 * temporary tables can be tracked and cleaned up appropriately</p>
 */
class TemporaryTable extends TableHandle implements TemporaryResource {

	/**
	 * @var MysqliWrapper|null Store for a reusable database connection for
	 * cleaning up tables during shutdown
	 */
	public static ?MysqliWrapper $cxn = null;

	/**
	 * Auto-opens a connection to the `local` db and drops the table being
	 * tracked
	 *
	 * <p>This method is graceful in opening the database connection and with
	 * dropping the table</p>
	 */
	public function cleanup() : void {
		try {
			if(!self::$cxn){
				self::$cxn = ConnectionFactory::connect(ConnectionFactory::DB_LOCAL);
			}
			self::$cxn->safe_query("DROP TABLE IF EXISTS `{$this->get_table_name()}`");
		} catch (Exception $e){
			ErrorLogger::log_error(sprintf(
				"Could not clean up temporary table %s. Reason: %s",
				$this->get_table_name(),
				$e->getMessage()
			));
		}
	}

}

