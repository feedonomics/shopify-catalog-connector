<?php

namespace ShopifyConnector\exceptions;


/**
 * Exception class for invalid user input
 */
class ValidationException extends CoreException {

	/**
	 * Set up the exception with details on what user input was invalid
	 *
	 * @param string $message Details on the invalid user input
	 */
	public function __construct(string $message){
		parent::__construct('Validation Error: ' . $message);
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_code() : string {
		return 'preprocess_validation_error';
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_message() : string {
		return $this->getMessage();
	}

}

