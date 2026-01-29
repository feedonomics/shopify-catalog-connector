<?php

namespace ShopifyConnector\connectors\shopify\graphql;

/**
 * Represents the response from a GraphQL query to Shopify
 */
readonly class GraphQLResponse
{
	/**
	 * The cost of the query as reported by Shopify
	 * @var GraphQLQueryCost
	 */
	private GraphQLQueryCost $cost;

	/**
	 * The data returned by the query
	 * @var GraphQLQueryResponseData
	 */
	private GraphQLQueryResponseData $data;

	/**
	 * The errors returned by the query, if any
	 * @var array
	 */
	private array $errors;

	/**
	 * @param array $response - The raw response from the Shopify GraphQL API Client
	 */
	public function __construct(
		array $response,
	) {
		$this->errors = $response['errors'] ?? [];
		$this->cost = new GraphQLQueryCost($response['extensions']['cost'] ?? []);
		$this->data = new GraphQLQueryResponseData($response['data'] ?? []);
	}

	/**
	 * @return bool
	 */
	public function has_errors() : bool
	{
		return !empty($this->errors);
	}

	/**
	 * @return array
	 */
	public function get_errors() : array
	{
		return $this->errors;
	}

	/**
	 * @return GraphQLQueryCost
	 */
	public function get_cost() : GraphQLQueryCost
	{
		return $this->cost;
	}

	/**
	 * @return GraphQLQueryResponseData
	 */
	public function get_data() : GraphQLQueryResponseData
	{
		return $this->data;
	}
}
