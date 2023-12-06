<?php

namespace ShopifyConnector\exceptions\file;

use ShopifyConnector\exceptions\CoreException;


/**
 * Exception for being unable to find a file through a pattern-based search
 */
class UnableToMatchFileException extends CoreException {

	/**
	 * Exception for being unable to find a file through a pattern-based search
	 *
	 * <p>Generate the user-friendly error message with the search pattern used
	 * that was not able to find a file</p>
	 *
	 * @param string $pattern The failed search pattern
	 */
	public function __construct(string $pattern){
		parent::__construct(sprintf('No files matching `%s` were found.', $pattern));
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_code() : string {
		return 'no_file_match';
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_message() : string {
		return $this->getMessage();
	}

}

