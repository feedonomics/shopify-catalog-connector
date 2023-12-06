<?php

namespace ShopifyConnector\exceptions\file;

use ShopifyConnector\exceptions\CoreException;


/**
 * Exception when reading a file that has a line that is too long to be read
 */
class InvalidFileLineLengthException extends CoreException {

	/**
	 * Exception when reading a file that has a line that is too long to be read
	 */
	public function __construct(){}

	/**
	 * @inheritDoc
	 */
	public function get_error_code() : string {
		return 'file_line_length_error';
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_message() : string {
		return 'The file was unable to be read because the length of a line was too long. Ensure that the entire file is not condensed onto 1 line.';
	}

}

