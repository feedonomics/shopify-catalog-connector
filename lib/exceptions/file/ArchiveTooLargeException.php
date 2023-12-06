<?php

namespace ShopifyConnector\exceptions\file;

use ShopifyConnector\exceptions\CoreException;


/**
 * Exception for attempting to decompress a file that is way too big
 * uncompressed for our systems
 */
class ArchiveTooLargeException extends CoreException {

	/**
	 * Exception for attempting to decompress a file that is way too big
	 * uncompressed for our systems
	 */
	public function __construct(){}

	/**
	 * @inheritDoc
	 */
	public function get_error_code() : string {
		return 'file_too_large';
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_message() : string {
		return 'The requested file(s) are too large. Please trim your files if possible or contact support for assistance.';
	}

}

