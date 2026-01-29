<?php

namespace ShopifyConnector\connectors\shopify\graphql;

/**
 * A wrapper for the 'pageInfo' portion of a GraphQL response
 */
readonly class GraphQLQueryResponsePageInfo
{
	/**
	 * @param bool $has_next_page
	 * @param string|null $end_cursor
	 */
	public function __construct(
		private bool    $has_next_page,
		private ?string $end_cursor
	)
	{
	}

	/**
	 * The cursor to be used for the next page of results, or null if there are no more pages
	 * @return string|null
	 */
	public function get_next_page_cursor() : ?string
	{
		return $this->end_cursor;
	}

	/**
	 * Indicates if there is another page of results after this one
	 * @return bool
	 */
	public function has_next_page() : bool
	{
		return $this->has_next_page;
	}

}
