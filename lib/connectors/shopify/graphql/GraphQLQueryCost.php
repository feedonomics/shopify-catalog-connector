<?php

namespace ShopifyConnector\connectors\shopify\graphql;

use ShopifyConnector\log\ErrorLogger;

/**
 * Represents the cost of a GraphQL query and the current throttle status
 *
 * @link https://shopify.dev/docs/api/usage/limits#graphql-admin-api-rate-limits
 */
class GraphQLQueryCost
{

	/**
	 * @var int
	 */
	private readonly int $requested_query_cost;

	/**
	 * @var int
	 */
	private readonly int $actual_query_cost;

	/**
	 * @var int
	 */
	private readonly int $throttle_currently_available;

	/**
	 * @var int
	 */
	private readonly int $throttle_maximum_available;

	/**
	 * @var int
	 */
	private readonly int $throttle_restore_rate;

	/**
	 * @param array $response_data - GraphQL response data containing cost and throttle status
	 */
	public function __construct(array $response_data)
	{
		$this->requested_query_cost = $response_data['requestedQueryCost'] ?? 0;
		$this->actual_query_cost = $response_data['actualQueryCost'] ?? 0;
		$this->throttle_currently_available = $response_data['throttleStatus']['currentlyAvailable'] ?? 0;
		$this->throttle_maximum_available = $response_data['throttleStatus']['maximumAvailable'] ?? 0;
		$this->throttle_restore_rate = $response_data['throttleStatus']['restoreRate'] ?? 0;
	}

	/**
	 * Determine if this query cost would exceed the currently available throttle
	 *
	 * @return bool
	 */
	public function is_in_excess() : bool
	{
		return $this->actual_query_cost - $this->throttle_currently_available > 0;
	}

	/**
	 * Given the current throttle status, sleep until enough capacity is restored to query again
	 *
	 * @return void
	 */
	public function sleep_until_recovered() : void
	{
		// Calculate the deficit after the current query
		$deficit = $this->actual_query_cost - $this->throttle_currently_available;

		// If we have enough capacity, no need to sleep
		if ($deficit <= 0) {
			return;
		}

		// Calculate seconds needed to restore the deficit
		// Add a small buffer to ensure we have enough capacity
		$seconds_needed = ceil($deficit / $this->throttle_restore_rate);

		ErrorLogger::log_error(sprintf(
			'GraphQL throttle exceeded Max: %d, Requested Cost: %d, Actual Cost: %d, Restore rate: %d/s, Sleeping for: %d seconds',
			$this->throttle_maximum_available,
			$this->requested_query_cost,
			$this->actual_query_cost,
			$this->throttle_restore_rate,
			$seconds_needed
		));

		$this->sleep($seconds_needed);
	}

	/**
	 * @param int $seconds
	 * @return void
	 */
	protected function sleep(int $seconds) : void
	{
		sleep($seconds);
	}
}
