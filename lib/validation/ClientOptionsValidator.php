<?php

namespace ShopifyConnector\validation;


/**
 * Validator for the general import settings.
 */
abstract class ClientOptionsValidator extends BaseValidator {

	/**
	 * Validate the general import settings.
	 *
	 * @inheritDoc BaseValidator::check()
	 * @param array $input The user's request settings (e.g. from $_GET)
	 */
	final protected static function check(array $input) : void {
		self::required($input, 'connection_info');

		$ci = $input['connection_info'] ?? [];
		self::required($ci, 'protocol', ['api']);
		self::required($ci, 'client', ['shopify', 'shopifymodular']);
	}

}

