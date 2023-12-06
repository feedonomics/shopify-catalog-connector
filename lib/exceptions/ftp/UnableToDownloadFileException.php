<?php

namespace ShopifyConnector\exceptions\ftp;

use ShopifyConnector\exceptions\CoreException;


/**
 * Exception for being unable to download a remote file
 */
class UnableToDownloadFileException extends CoreException {

	/**
	 * Exception for being unable to download a remote file
	 *
	 * @param string $additional_info Additional details for the error message
	 */
	public function __construct(string $additional_info = 'Please check permissions.'){
		parent::__construct('Could not download the requested file. ' . $additional_info);
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_code() : string {
		return 'file_download';
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_message() : string {
		return $this->getMessage();
	}

}

