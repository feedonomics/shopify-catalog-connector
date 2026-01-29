<?php

namespace ShopifyConnector\connectors\shopify\graphql;

use ShopifyConnector\api\ApiClient;

use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\exceptions\ApiResponseException;

/**
 * Executes GraphQL against the Shopify API, handling rate limiting based on query cost.
 */
class GraphQLRequest
{
	/**
	 * The cost of the last executed GraphQL query, used for rate limiting.
	 *
	 * @var GraphQLQueryCost|null
	 */
	private ?GraphQLQueryCost $last_cost = null;

	/**
	 * @param ApiClient $client
	 */
	public function __construct(
		private readonly ApiClient $client
	)
	{
	}

	/**
	 * @param GraphQLQuery $query
	 * @return GraphQLResponse
	 * @throws ApiResponseException
	 */
	public function query(GraphQLQuery $query) : GraphQLResponse
	{
		$query_string = $query->generate_for_graphql();

		return $this->request("query { $query_string }");
	}

	/**
	 * @throws ApiResponseException
	 */
	private function request(string $query_string) : GraphQLResponse
	{
		if ($this->last_cost?->is_in_excess()) {
			$this->last_cost->sleep_until_recovered();
		}

		try {
			$response = $this->client->graphql_request($query_string);
		} catch (ApiException $e) {
			ApiResponseException::throw_from_cl_api_exception($e);
		}

		$query_response = new GraphQLResponse($response);

		if ($query_response->has_errors()) {
			throw new ApiResponseException('GraphQL query returned errors: ' . json_encode($query_response->get_errors()));
		}

		$this->last_cost = $query_response->get_cost();

		return $query_response;
	}
}
