<?php

namespace ShopifyConnector\exceptions;


/**
 * Exception for missing permissions when trying to access an API endpoint
 */
class MissingPermissionsException extends CoreException {

	/**
	 * Exception for missing permissions when trying to access an API endpoint
	 *
	 * @param string $details Details about the call that is missing permissions
	 */
	public function __construct(string $details){
		parent::__construct('Missing permissions for the following: ' . $details);
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_code() : string {
		return 'auth_permissions_missing';
	}

	/**
	 * @inheritDoc
	 */
	public function get_error_message() : string {
		return $this->getMessage();
	}

}

