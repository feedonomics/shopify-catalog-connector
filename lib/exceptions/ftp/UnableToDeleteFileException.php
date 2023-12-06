<?php

namespace ShopifyConnector\exceptions\ftp;

use ShopifyConnector\exceptions\CoreException;


/**
 * Exception for being unable to delete a remote file
 */
class UnableToDeleteFileException extends CoreException {

	/**
	 * Exception for being unable to delete a remote file
	 */
	public function __construct(){}

	/**
	 * @inheritDoc
	 */
	public function get_error_code() : string {
		return 'file_delete';
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_message() : string {
		return 'Could not delete the requested file.';
	}

}

