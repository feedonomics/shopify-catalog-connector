<?php

namespace ShopifyConnector\exceptions\file;

use ShopifyConnector\exceptions\CoreException;


/**
 * Exception for being unable to find a file
 */
class UnableToFindFileException extends CoreException {

	/**
	 * Exception for being unable to find a file
	 */
	public function __construct(){}

	/**
	 * @inheritDoc
	 */
	public function get_error_code() : string {
		return 'file_not_found';
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_message() : string {
		return 'Could not find the requested file.';
	}

}

