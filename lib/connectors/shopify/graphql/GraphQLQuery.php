<?php

namespace ShopifyConnector\connectors\shopify\graphql;

interface GraphQLQuery
{

	/**
	 * A mapping of the connections and their cursors in this query.
	 *
	 * Anywhere you have an edges -> node is a connection
	 *
	 * e.g. products -> edges -> node -> variants -> edges -> node
	 * The map would be:
	 * $map = [
	 * 		'connection' => ['products'],
	 * 		'cursor_name' => 'products',
	 * 		'children' => [
	 * 			[
	 * 				'connection' => ['variants'],
	 * 				'cursor_name' => 'variants',
	 * 			]
	 * 		]
	 * ];
	 *
	 * connection: required
	 * 	informs the query consumer where to find the pagination data
	 * children: optional
	 * - informs the query consumer of any nested connections that also need to be paginated
	 * cursor_name:
	 * 	optional if there is only a single result set without pagination
	 * 	required if there are multiple pages of results as we need to store the cursor value for the next request
	 *
	 * @return array
	 */
	public function get_connection_map() : array;

	/**
	 * The actual GraphQL query string, including pagination arguments
	 * @return string
	 */
	public function generate_for_graphql() : string;

	/**
	 * The GraphQL query string to be used in a bulk operation, exclusive of pagination arguments
	 * @return string
	 */
	public function generate_for_bulk_operation() : string;

	/**
	 * Each cursor tracked in the query should be set here as you page through results.
	 *
	 * @param string $cursor_name
	 * @param string $cursor_value
	 * @return $this
	 */
	public function with_cursor(string $cursor_name, string $cursor_value) : GraphQLQuery;
}
