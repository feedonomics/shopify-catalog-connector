<?php

namespace ShopifyConnector\connectors\shopify\pullers;

use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\connectors\shopify\graphql\GraphQLQuery;
use ShopifyConnector\connectors\shopify\graphql\GraphQLQueryResponseData;
use ShopifyConnector\connectors\shopify\graphql\QueryConsumer;
use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\structs\DateRange;
use ShopifyConnector\exceptions\ApiResponseException;
use ShopifyConnector\exceptions\InfrastructureErrorException;
use Generator;

/**
 * Base class for batched GraphQL pullers
 */
abstract class BatchedBase implements Puller
{
	/**
	 * @param SessionContainer $session
	 * @param QueryConsumer $graphql_query_consumer
	 */
	public function __construct(
		protected readonly SessionContainer $session,
		private readonly QueryConsumer $graphql_query_consumer,
	)
	{
	}

	/**
	 * Build a query for a given date range
	 *
	 * @param DateRange $date_range
	 * @return GraphQLQuery
	 */
	abstract protected function build_query_for_range(DateRange $date_range) : GraphQLQuery;

	/**
	 * Returns the largest possible date partitions based on the store's product catalog size
	 *
	 * This currently only handles full dates, sufficient for stores whose catalog is spread
	 * out over time. With new stores or stores that imported data they likely have many products
	 * created on individual days.
	 *
	 * Potential future enhancements when this becomes asynchronous:
	 *
	 * First, determine what the optimal product count we are trying to target per partition. Then
	 * work towards removing outliers by:
	 *
	 * 1. Removing empty date ranges
	 * 2. Dividing ranges with too many products by individual hours
	 * 3. Merging ranges with too few products
	 *
	 * @return Generator
	 * @throws InfrastructureErrorException
	 */
	protected function get_partitions() : Generator
	{
		$partitioner = new DateRangePartitioner(
			$this->session->shop->product_catalog_size,
			250, //max page size allowed for Shopify GraphQL
			$this->get_oldest_product(),
			time(),
		);
		return $partitioner->iterate();
	}

	/**
	 * Returns the timestamp of the oldest product in the store, or the shop creation date if we can't get it
	 * @return int
	 */
	protected function get_oldest_product() : int
	{
		$oldest_product_date = $this->session->shop->created_at;

		try {
			$response_data = $this->session->client->graphql_request(<<<GQL
			query {
			  products(first: 1, sortKey: CREATED_AT) {
				edges {
				  node {
					createdAt
				  }
				}
			  }
			}
			GQL);

			$oldest_product_date = $response_data['data']['products']['edges'][0]['node']['createdAt'] ?? $oldest_product_date;
		} catch (ApiException) {
			// Ignore and use shop creation date
		}

		return strtotime($oldest_product_date);
	}

	/**
	 * Run a batch of queries
	 * This code can be run asynchronously for faster catalog processing
	 *
	 * @return Generator<GraphQLQueryResponseData>
	 * @throws InfrastructureErrorException
	 * @throws ApiResponseException
	 */
	protected function run_batch() : Generator
	{
		$queries = [];
		foreach ($this->get_partitions() as $date_range) {
			$queries[] = $this->build_query_for_range($date_range);
		}

		foreach ($queries as $query) {
			yield from $this->graphql_query_consumer->consume($query);
		}
	}

}
