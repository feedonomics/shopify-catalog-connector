<?php

namespace ShopifyConnector\connectors\shopify\graphql;

use ShopifyConnector\connectors\shopify\structs\DateRange;

/**
 * Trait for common GraphQL argument generation
 */
trait GraphQLQueryHelper
{
	/**
	 * @param int $count
	 * @return string
	 */
	public function first(int $count) : string
	{
		return sprintf('first: %s', $count);
	}

	/**
	 * @param string $string
	 * @return string
	 */
	public function after(string $string) : string
	{
		return sprintf('after: "%s"', $string);
	}

	/**
	 * @param string $key
	 * @return string
	 */
	public function sort_key(string $key) : string
	{
		return sprintf('sortKey: %s', $key);
	}

	/**
	 * @param string $field
	 * @param DateRange $in_range
	 * @return string
	 */
	public function in_date_range(string $field, DateRange $in_range) : string
	{
		return sprintf(
			"%s:>='%s' AND %s:<'%s'",
			$field,
			$in_range->start->format('Y-m-d'),
			$field,
			$in_range->end->format('Y-m-d')
		);
	}

	/**
	 * @return string
	 */
	public function page_info() : string
	{
		return <<<GQL
			pageInfo {
				hasNextPage
				endCursor
			}
		GQL;
	}

}
