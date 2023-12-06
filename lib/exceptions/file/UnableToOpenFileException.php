<?php

namespace ShopifyConnector\exceptions\file;

use ShopifyConnector\exceptions\CoreException;


/**
 * Exception for being unable to open a local file
 */
class UnableToOpenFileException extends CoreException {

	/**
	 * Exception for being unable to open a local file
	 */
	public function __construct(){}

	/**
	 * @inheritDoc
	 */
	public function get_error_code() : string {
		return 'file_open';
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_message() : string {
		return 'Unable to open file.';
	}

}

