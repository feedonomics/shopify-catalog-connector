<?php

namespace ShopifyConnector\exceptions\file;

use ShopifyConnector\exceptions\CoreException;


/**
 * Exception for being unable to create a local temporary file
 */
class UnableToCreateTmpFileException extends CoreException {

	/**
	 * Exception for being unable to create a local temporary file
	 */
	public function __construct(){}

	/**
	 * @inheritDoc
	 */
	public function get_error_code() : string {
		return 'tmp_file_create';
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_message() : string {
		return 'Could not create the file in the temporary directory.';
	}

}

