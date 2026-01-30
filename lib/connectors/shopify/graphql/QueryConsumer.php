<?php

namespace ShopifyConnector\connectors\shopify\graphql;

use ShopifyConnector\exceptions\ApiResponseException;

use Generator;

/**
 * Give me a GraphQL query and I will consume it until the end of time, including all pagination
 */
class QueryConsumer
{
	public function __construct(
		private readonly GraphQLRequest $request
	)
	{
	}

	/**
	 * Consumes the provided query, yielding each page of results as they are retrieved.
	 *
	 * @param GraphQLQuery $query
	 * @return Generator<GraphQLQueryResponseData>
	 * @throws ApiResponseException
	 */
	public function consume(
		GraphQLQuery $query,
	) : Generator
	{
		yield from $this->traverse($query, $query->get_connection_map(), []);
	}

	/**
	 * Recursively traverses the query structure, handling pagination and yielding results.
	 *
	 * @param GraphQLQuery $query
	 * @param array $map
	 * @param array $path_to_edge
	 * @return Generator<GraphQLQueryResponseData>
	 * @throws ApiResponseException
	 */
	private function traverse(GraphQLQuery $query, array $map, array $path_to_edge) : Generator
	{
		$path_to_connection = array_merge($path_to_edge, $map['connection']);

		do {
			$result_data = $this->request->query($query)->get_data();

			// Yield the current page of results for processing
			yield $result_data;

			$edges = $result_data->get_subset(array_merge($path_to_connection, ['edges'])) ?? [];

			// For each edge in the current page, check for child connections to paginate through
			foreach ($edges as $edge_index => $edge) {
				$next_path_to_edge = array_merge($path_to_connection, ['edges', $edge_index, 'node']);
				foreach ($map['children'] ?? [] as $child_map) {
					$path_to_child_connection = array_merge($next_path_to_edge, $child_map['connection']);

					$child_page_info = $result_data->get_page_info($path_to_child_connection);

					if ($child_page_info->has_next_page()) {
						yield from $this->traverse(
							$query->with_cursor($child_map['cursor_name'], $child_page_info->get_next_page_cursor()),
							$child_map,
							$next_path_to_edge
						);
					}
				}
			}

			// Advance to the next page of the current connection, if available
			$result_page_info = $result_data->get_page_info($path_to_connection);
			$has_next_page = $result_page_info->has_next_page();
			if ($has_next_page) {
				$query = $query->with_cursor($map['cursor_name'], $result_page_info->get_next_page_cursor());
			}

		} while ($has_next_page);
	}
}
