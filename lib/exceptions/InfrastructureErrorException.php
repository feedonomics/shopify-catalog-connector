<?php

namespace ShopifyConnector\exceptions;

use ShopifyConnector\util\log\ErrorLogger;


/**
 * Exception for whenever an internal, sensitive error occurs. This will output
 * a generic message and serves as a flag for where potential errors originated
 * from, but should almost always be coupled with a call to
 * {@see ErrorLogger::log_error()} before throwing
 */
class InfrastructureErrorException extends CoreException {

	/**
	 * Exception for whenever an internal, sensitive error occurs. This will output
	 * a generic message and serves as a flag for where potential errors originated
	 * from, but should almost always be coupled with a call to
	 * {@see ErrorLogger::log_error()} before throwing
	 */
	public function __construct(){}

	/**
	 * @inheritDoc
	 */
	public function get_error_code() : string {
		return 'infrastructure_error';
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_message() : string {
		return 'There was an internal failure. Please contact a developer.';
	}

}

