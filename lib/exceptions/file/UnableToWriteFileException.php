<?php

namespace ShopifyConnector\exceptions\file;

use ShopifyConnector\exceptions\CoreException;


/**
 * Exception for being unable to write a local file
 */
class UnableToWriteFileException extends CoreException {

	/**
	 * Exception for being unable to write a local file
	 */
	public function __construct(){}

	/**
	 * @inheritDoc
	 */
	public function get_error_code() : string {
		return 'file_write';
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_message() : string {
		return 'Unable to write file.';
	}

}

