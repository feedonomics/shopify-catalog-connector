<?php

namespace ShopifyConnector\exceptions\ftp;

use ShopifyConnector\exceptions\CoreException;


/**
 * Exception for issues accessing Minio resources
 */
class MinioException extends CoreException {

	/**
	 * Exception for issues accessing Minio resources
	 *
	 * @param string $message The error message detailing what went wrong
	 */
	public function __construct(string $message){
		parent::__construct(sprintf(
			'There was an issue accessing the remote resource. Message: %s',
			$message
		));
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_code() : string {
		return 'minio_exception';
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_message() : string {
		return $this->getMessage();
	}

}

