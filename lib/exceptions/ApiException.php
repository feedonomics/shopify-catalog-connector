<?php

namespace ShopifyConnector\exceptions;

use Exception;


/**
 * Base exception all API related functionality will throw
 */
class ApiException extends Exception {

	private array $data;

	public function __construct(string $msg, array $data){
		parent::__construct($msg);
		$this->data = $data;
	}

	public function getDecodedData() : array {
		return $this->data;
	}

}

