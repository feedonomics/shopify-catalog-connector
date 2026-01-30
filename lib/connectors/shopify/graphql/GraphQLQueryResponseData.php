<?php

namespace ShopifyConnector\connectors\shopify\graphql;

/**
 * A wrapper for the 'data' portion of a GraphQL response
 */
readonly class GraphQLQueryResponseData
{
	/**
	 * @param array $data - Raw data from GraphQL under the 'data' key
	 */
	public function __construct(
		private array $data
	)
	{
	}

	/**
	 * @return array
	 */
	public function get_data() : array
	{
		return $this->data;
	}

	/**
	 * Given a path to a subset of data return that value or null if it doesn't exist
	 *
	 * e.g. ['products', 'edges', 0, 'node', 'metafields']
	 *
	 * @param array $path
	 * @return array|null
	 */
	public function get_subset(array $path) : ?array
	{
		$current = $this->data;
		foreach ($path as $key) {
			if (!isset($current[$key])) {
				return null;
			}

			$current = $current[$key];
		}
		return $current;
	}

	/**
	 * Retrieves the pagination info for a given connection path
	 * @param array $path
	 * @return GraphQLQueryResponsePageInfo|null
	 */
	public function get_page_info(array $path): ?GraphQLQueryResponsePageInfo
	{
		$subset = $this->get_subset($path);

		if (empty($subset) || empty($subset['pageInfo'])) {
			return null;
		}

		return new GraphQLQueryResponsePageInfo(
			$subset['pageInfo']['hasNextPage'],
			$subset['pageInfo']['endCursor']
		);
	}
}
