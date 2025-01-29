<?php
namespace ShopifyConnector\connectors\shopify\models;

use ShopifyConnector\exceptions\api\UnexpectedResponseException;

/**
 * Container for response data from Shopify concerning the status of a bulk
 * request. Includes fields for all fields specified in `BulkBase::BULK_OP_FIELDS`,
 * along with a field for the `userErrors` array that can be returned when
 * initiating a bulk operation (through `bulkOperationRunQuery`).
 *
 * <p>Possible status values from Shopify:
 * CANCELED, CANCELING, COMPLETED, CREATED, EXPIRED, FAILED, RUNNING</p>
 */
class BulkResult
{

	/**
	 * @var string The bulk result ID
	 */
	public string $id;

	/**
	 * @var string The bulk result status
	 */
	public string $status;

	/**
	 * @var string|null The error code (if set)
	 */
	public ?string $errorCode;

	/**
	 * @var string|null The created-at date (if set)
	 */
	public ?string $createdAt;

	/**
	 * @var string|null The completed-at date (if set)
	 */
	public ?string $completedAt;

	/**
	 * @var string|null The object count (if set)
	 */
	public ?string $objectCount;

	/**
	 * @var string|null The root object count (if set)
	 */
	public ?string $rootObjectCount;

	/**
	 * @var string|null The file size (if set)
	 */
	public ?string $fileSize;

	/**
	 * @var string|null The url (if set)
	 */
	public ?string $url;

	/**
	 * @var string|null The partial data URL (if set)
	 */
	public ?string $partialDataUrl;

	/**
	 * @var string|null The query (if set)
	 */
	public ?string $query;

	/**
	 * @var array The list of user errors (if set)
	 */
	public array $userErrors;

	/**
	 * Container for response data from Shopify concerning the status of a bulk
	 * request
	 *
	 * @param array $res The bulk query response to parse and store
	 * @throws UnexpectedResponseException On invalid response
	 */
	public function __construct(array $res)
	{
		$bop = $this->getBulkOpNode($res);

		$this->id = $bop['id'];
		$this->status = $bop['status'];

		$this->errorCode = $bop['errorCode'] ?? null;
		$this->createdAt = $bop['createdAt'] ?? null;
		$this->completedAt = $bop['completedAt'] ?? null;
		$this->objectCount = $bop['objectCount'] ?? null;
		$this->rootObjectCount = $bop['rootObjectCount'] ?? null;
		$this->fileSize = $bop['fileSize'] ?? null;
		$this->url = $bop['url'] ?? null;
		$this->partialDataUrl = $bop['partialDataUrl'] ?? null;
		$this->query = $bop['query'] ?? null;

		$this->userErrors = $this->getUserErrNode($res);
	}

	/**
	 * Checks various potential paths in the tree for the node containing the
	 * `BulkOperation` node. Returns the node if found, otherwise throws an
	 * exception.
	 *
	 * <p>Sometimes the `BulkOperation` node will be present but NULL. This can
	 * happen when there is an error in the query or when querying for the
	 * `currentBulkOperation` on a shop where a bulk query has never been run
	 * before. In this case, this will error out with the "Unable to find
	 * bulkOp response data" message.</p>
	 *
	 * @param array $tree The response object to inspect
	 * @return array The `BulkOperation` node if found
	 * @throws UnexpectedResponseException When no `BulkOperation` node found
	 */
	private function getBulkOpNode(array $tree) : array
	{
		if (isset($tree['id']) && isset($tree['status'])) {
			return $tree;
		}

		if (isset($tree['data']['node'])) {
			return $tree['data']['node'];
		}

		if (isset($tree['data']['bulkOperationRunQuery']['bulkOperation'])) {
			return $tree['data']['bulkOperationRunQuery']['bulkOperation'];
		}

		if (isset($tree['data']['currentBulkOperation'])) {
			return $tree['data']['currentBulkOperation'];
		}

		$errors = $this->getUserErrNode($tree);
		throw new UnexpectedResponseException(
			'Shopify',
			'Unable to find bulkOp response data. Messages: ' . print_r($errors, true)
		);
	}

	/**
	 * Checks if the path to the `userErrors` node is present in the given
	 * tree, and returns the node if it is found. Returns an empty array if
	 * the node is not found at the expected path.
	 *
	 * @param array $tree The response object to inspect
	 * @return array The `userErrors` node if found, empty array if not
	 */
	private function getUserErrNode(array $tree) : array
	{
		$node = $tree['data']['bulkOperationRunQuery']['userErrors'] ?? null;
		return is_array($node) ? $node : [];
	}

	/**
	 * Checks in the userErrors for indicators that the query run attempt was
	 * blocked by another query currently running (applies when this response
	 * is the result of a bulkOperationRunQuery call). This uses the contents
	 * of `$this->userErrors`, so that should be set properly before invoking
	 * this method.
	 * If there are multiple errors present in the userErrors, then this will
	 * always return FALSE.
	 *
	 * @return bool TRUE if blocked by another running query, FALSE otherwise
	 */
	public function isBlocked() : bool
	{
		if (count($this->userErrors) === 1
			&& stripos($this->userErrors[0]['message'], 'already in progress') !== false
		) {
			# Received a user error because a bulk query is already running
			return true;
		}

		# Received no errors, multiple errors, or a single one for a reason
		# other than because a bulk query is already running
		return false;
	}

	/**
	 * Check if the bulk query has completed
	 *
	 * @return bool True if the status is completed
	 */
	public function isComplete() : bool
	{
		return $this->status === 'COMPLETED';
	}

	/**
	 * Check if the bulk query is still running
	 *
	 * @return bool True if the status is created or running
	 */
	public function isRunning() : bool
	{
		return $this->status === 'CREATED'
			|| $this->status === 'RUNNING';
	}

	/**
	 * Check if the bulk query is canceled
	 *
	 * @return bool True if the status is canceled or canceling
	 */
	public function isCancel() : bool
	{
		return $this->status === 'CANCELED'
			|| $this->status === 'CANCELING';
	}

	/**
	 * Check if the bulk query has died
	 *
	 * @return bool True if the status is canceled, canceling, expired, or failed
	 */
	public function isDead() : bool
	{
		return in_array(
			$this->status,
			['CANCELED', 'CANCELING', 'EXPIRED', 'FAILED'],
			true
		);
	}

}
