<?php

namespace ShopifyConnector\log;

use Throwable;


/**
 * Utility class for logging and log-related helpers
 */
class ErrorLogger {

	/**
	 * Log an error message
	 *
	 * @param string $message The error message to log
	 */
	public static function log_error(string $message){
		error_log($message);
	}

	/**
	 * Converts the exception to a loggable string and passes that string to the
	 * {@see log_error} method
	 *
	 * @param Throwable $e The exception to log
	 */
	public static function log_exception(Throwable $e){
		$ex_details = sprintf(
			"[Code: %s] [Message: %s] [Stack Trace: \n%s\n]",
			$e->getCode(),
			$e->getMessage(),
			$e->getTraceAsString()
		);
		self::log_error($ex_details);
	}

}

