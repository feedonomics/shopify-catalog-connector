<?php
namespace ShopifyConnector\exceptions\api;

use ShopifyConnector\exceptions\CoreException;

/**
 * Exception for getting unexpected responses from an integration/API
 */
class UnexpectedResponseException extends CoreException {

	/**
	 * Exception for getting unexpected responses from an integration/API
	 *
	 * @param string $api_name The API name
	 * @param string $details Additional details of the unexpected response
	 * that occurred
	 */
	public function __construct(string $api_name, string $details){
		parent::__construct("We received an unexpected response from {$api_name}. {$details}");
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_code() : string {
		return 'unexpected_integration_response';
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_message() : string {
		return $this->getMessage();
	}

}
