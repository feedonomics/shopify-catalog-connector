<?php

namespace ShopifyConnector\exceptions;


/**
 * Exception for API errors
 *
 * <p>This is most useful in converting client library exceptions into an
 * internal exception class using the {@see throw_from_cl_api_exception} method
 */
class ApiResponseException extends CoreException {

	/**
	 * Exception for API errors
	 *
	 * <p>This is most useful in converting client library exceptions into an
	 * internal exception class using the {@see throw_from_cl_api_exception} method
	 *
	 * @param string $info Info about the API error
	 */
	public function __construct(string $info){
		parent::__construct('There was an error calling an API endpoint. Details: ' . $info);
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_code() : string {
		return 'api_response_error';
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_message() : string {
		return $this->getMessage();
	}

	/**
	 * Convert a client-library exception to an instance of this class and throw
	 *
	 * @param ApiException $e The client-library exception
	 * @return void This only throws and will never return
	 * @throws ApiResponseException Throws an instantiated version of this class
	 * using the CL exception message
	 */
	final public static function throw_from_cl_api_exception(ApiException $e) : void {
		throw new static($e->getMessage());
	}

}

