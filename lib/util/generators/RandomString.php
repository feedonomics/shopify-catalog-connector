<?php

namespace ShopifyConnector\util\generators;

use Exception;

use ShopifyConnector\exceptions\InfrastructureErrorException;
use ShopifyConnector\log\ErrorLogger;


/**
 * Random string generators
 */
class RandomString {

	/**
	 * Return a randomly generated hexadecimal string
	 *
	 * @param int $length How long to make the string
	 * @return string The random string
	 * @throws InfrastructureErrorException If `random_bytes` failed
	 */
	public static function hex(int $length) : string {
		if($length < 1){
			return '';
		}

		try {
			$full = bin2hex(random_bytes($length));

		// We can't force random_bytes to throw easily, so just ignore
		// the catch in the coverage report
		// @codeCoverageIgnoreStart
		} catch(Exception $e){
			ErrorLogger::log_error(
				'Could not generate random hex string, reason: '
				. $e->getMessage()
			);
			throw new InfrastructureErrorException();
		}
		// @codeCoverageIgnoreEnd

		return substr($full, 0, $length);
	}

}

