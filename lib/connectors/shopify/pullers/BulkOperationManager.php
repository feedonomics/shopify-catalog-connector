<?php

namespace ShopifyConnector\connectors\shopify\pullers;

use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\connectors\shopify\exceptions\BulkErrorException;
use ShopifyConnector\connectors\shopify\models\BulkResult;
use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\exceptions\ApiResponseException;
use Throwable;

/**
 * Orchestrates parallel Shopify bulk operations.
 *
 * Submits multiple bulk operations concurrently (up to Shopify's 5-op limit),
 * polls them in a unified loop, and downloads result files as they complete.
 * Fails fast: if any operation fails, all others are cancelled.
 */
class BulkOperationManager
{

	const MAX_CONCURRENT_OPS = 5;

	const WAIT_SECONDS = 25;

	const MAX_POLL_ATTEMPTS = 2300;

	/**
	 * @var int Maximum attempts to poll for an available slot when every
	 * Shopify bulk operation slot for the shop is occupied at start. Sized
	 * smaller than MAX_POLL_ATTEMPTS because we are waiting on someone else's
	 * operations to finish, not our own. 120 * 25s ~= 50 minutes.
	 */
	const MAX_SLOT_WAIT_ATTEMPTS = 120;

	private SessionContainer $session;

	public function __construct(SessionContainer $session)
	{
		$this->session = $session;
	}

	/**
	 * Query Shopify for how many bulk operation slots are currently available.
	 *
	 * @return int Number of available slots (0 to MAX_CONCURRENT_OPS)
	 * @throws ApiException
	 */
	public function get_available_slots() : int
	{
		$response = $this->session->client->graphql_request(<<<GRAPHQL
            {
                bulkOperations(first: 5, query: "status:RUNNING OR status:CREATED") {
                    edges {
                        node {
                            id
                            status
                        }
                    }
                }
            }
            GRAPHQL
		);

		$active_count = count($response['data']['bulkOperations']['edges'] ?? []);
		return max(0, self::MAX_CONCURRENT_OPS - $active_count);
	}

	/**
	 * Run multiple bulk operations in parallel.
	 *
	 * @param BulkBase[] $pullers Indexed array of pullers to execute
	 * @return string[] Map of puller index to downloaded file path
	 * @throws ApiException
	 * @throws ApiResponseException
	 * @throws BulkErrorException
	 */
	public function run_parallel(array $pullers) : array
	{
		if (empty($pullers)) {
			return [];
		}

		$available = $this->get_available_slots();

		/** @var array<int, BulkResult> */
		$active = [];
		/** @var array<int, BulkBase> */
		$queued = [];
		/** @var array<int, string> */
		$completed_files = [];

		try {
			// If every slot on the shop is occupied (e.g. another process is
			// running bulk operations on the same shop), wait for one to free
			// up. Without this, the polling loop below would never run and we
			// would silently return zero results.
			$slot_wait_attempts = 0;
			$current_sleep = 1;
			while ($available === 0) {
				if (++$slot_wait_attempts > self::MAX_SLOT_WAIT_ATTEMPTS) {
					throw new ApiResponseException(
						"Timed out after {$slot_wait_attempts} attempts waiting for an available bulk operation slot"
					);
				}
				sleep($current_sleep);
				$current_sleep *= 2;
				$current_sleep = min($current_sleep, self::WAIT_SECONDS);
				$available = $this->get_available_slots();
			}

			foreach ($pullers as $index => $puller) {
				if ($available > 0) {
					// submit_bulk_query registers the operation id with the session,
					// so we don't need to call add_bulk_id here.
					$active[$index] = $puller->submit_bulk_query($puller->get_query());
					$available--;
				} else {
					$queued[$index] = $puller;
				}
			}

			$poll_count = 0;
			$current_sleep = 1;

			while (!empty($active)) {
				if (++$poll_count > self::MAX_POLL_ATTEMPTS) {
					throw new ApiResponseException(
						"Timed out after {$poll_count} poll attempts waiting for bulk operations"
					);
				}

				sleep($current_sleep);
				$current_sleep *= 2;
				$current_sleep = min($current_sleep, self::WAIT_SECONDS);

				foreach ($active as $index => $bulk_result) {
					$puller = $pullers[$index];

					try {
						$status = $puller->check_status($bulk_result->id);
					} catch (BulkErrorException $e) {
						if ($e->query_is_throttled()) {
							continue;
						}
						// The op already errored on Shopify's side, so drop it
						// from the cleanup set; the catch below cancels the rest.
						unset($active[$index]);
						$this->session->remove_bulk_id($bulk_result->id);
						throw $e;
					}

					if ($status->isComplete()) {
						$completed_files[$index] = $puller->retrieve_bulk_file($status);
						$this->session->remove_bulk_id($bulk_result->id);
						unset($active[$index]);

						if (!empty($queued)) {
							$queued_index = array_key_first($queued);
							$queued_puller = $queued[$queued_index];
							unset($queued[$queued_index]);

							$active[$queued_index] = $queued_puller->submit_bulk_query($queued_puller->get_query());
						}
					} elseif ($status->isDead()) {
						unset($active[$index]);
						$this->session->remove_bulk_id($bulk_result->id);
						throw new ApiResponseException(
							sprintf(
								'Bulk operation failed (index %d). Status: %s. Error: %s',
								$index,
								$status->status,
								$status->errorCode ?? 'unknown'
							)
						);
					}
				}
			}
		} catch (Throwable $e) {
			// Any failure during orchestration cancels the still-active siblings
			// up front, instead of waiting on the session shutdown handler.
			$this->cancel_all($active);
			throw $e;
		}

		return $completed_files;
	}

	/**
	 * Cancel every active bulk operation in the set, delegating to the session
	 * helper so the cancel mutation lives in one place.
	 *
	 * @param array<int, BulkResult> $active
	 */
	private function cancel_all(array $active) : void
	{
		foreach ($active as $bulk_result) {
			$this->session->cancel_bulk_operation($bulk_result->id);
		}
	}

}
