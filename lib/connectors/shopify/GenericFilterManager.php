<?php

namespace ShopifyConnector\connectors\shopify;

use ShopifyConnector\connectors\shopify\models\FilterManager;

/**
 * When user input does not impact filters we can use this manager to generate GraphQL filters and search terms.
 */
class GenericFilterManager extends FilterManager
{
	protected function get_rest_keys() : array
	{
		return [];
	}

	protected function get_gql_query_keys() : array
	{
		return [];
	}

	protected function get_gql_search_keys() : array
	{
		return [];
	}
}
