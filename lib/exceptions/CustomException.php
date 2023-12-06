<?php

namespace ShopifyConnector\exceptions;


/**
 * Functions as a custom CoreException for one-off instances where you need a
 * custom error without writing an entirely new exception class
 *
 * <p>Please use responsibly</p>
 */
class CustomException extends CoreException {

	/**
	 * @var string Store for the error code string
	 */
	private string $_code;

	/**
	 * Functions as a custom CoreException for one-off instances where you need a
	 * custom error without writing an entirely new exception class
	 *
	 * <p>Please use responsibly</p>
	 *
	 * @param string $code The error code (e.g. `validation_error`)
	 * @param string $message The error message
	 */
	public function __construct(string $code, string $message){
		$this->_code = $code;
		parent::__construct($message);
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_code() : string {
		return $this->_code;
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_message() : string {
		return $this->getMessage();
	}

}

