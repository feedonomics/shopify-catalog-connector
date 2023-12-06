<?php

namespace ShopifyConnector\exceptions\io;

use ShopifyConnector\exceptions\CoreException;


/**
 * Exception for being unable to decode response data
 */
class UnableToDecodeResponseException extends CoreException {

	/**
	 * Exception for being unable to decode response data
	 *
	 * @param string $details Optional details about why the response was not
	 * able to be decoded
	 */
	public function __construct(string $details = ''){
		parent::__construct('Unable to decode response from server. ' . $details );
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_code() : string {
		return 'response_decode';
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_message() : string {
		return $this->getMessage();
	}

}

