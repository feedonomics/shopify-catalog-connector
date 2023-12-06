<?php

namespace ShopifyConnector\exceptions\ftp;

use ShopifyConnector\exceptions\CoreException;


/**
 * Exception for being unable to upload a file to a remote server
 */
class UnableToPutFileException extends CoreException {

	/**
	 * Exception for being unable to upload a file to a remote server
	 */
	public function __construct(){}

	/**
	 * @inheritDoc
	 */
	public function get_error_code() : string {
		return 'file_put';
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_message() : string {
		return 'Could not push the requested file. Please check permissions.';
	}

}

