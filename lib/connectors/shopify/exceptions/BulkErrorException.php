<?php

namespace ShopifyConnector\connectors\shopify\exceptions;

use ShopifyConnector\connectors\shopify\models\BulkResult;
use ShopifyConnector\exceptions\api\UnexpectedResponseException;


class BulkErrorException extends UnexpectedResponseException
{

	private array $errors;


	public function __construct(array $errors, string $msg = '')
	{
		$this->errors = $errors;
		parent::__construct('Shopify', $msg);
	}

	public function get_first_message() : string
	{
		return $this->errors[0]['message'] ?? '';
	}

	/**
	 * Checks in the errors for indicators that the query run attempt was
	 * blocked by another query currently running (applies when this response
	 * is the result of a bulkOperationRunQuery call). If there are multiple
	 * errors present in the errors, then this will always return FALSE.
	 *
	 * @return bool TRUE if blocked by another running query, FALSE otherwise
	 */
	public function query_is_blocked() : bool
	{
		if (count($this->errors) === 1
			&& stripos($this->get_first_message(), 'already in progress') !== false
		) {
			# Received a user error because a bulk query is already running
			return true;
		}

		# Received no errors, multiple errors, or a single one for a reason
		# other than because a bulk query is already running
		return false;
	}

	public function query_is_throttled() : bool
	{
		if (count($this->errors) === 1
			&& stripos($this->get_first_message(), 'throttled') !== false
		) {
			# Received a user error because the query was throttled
			return true;
		}

		# Received no errors, multiple errors, or a single one for a reason
		# other than being throttled
		return false;
	}

}

