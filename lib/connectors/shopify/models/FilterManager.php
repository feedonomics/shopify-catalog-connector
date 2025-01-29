<?php

namespace ShopifyConnector\connectors\shopify\models;

abstract class FilterManager
{

	protected array $filters = [];


	abstract protected function get_rest_keys() : array;
	abstract protected function get_gql_query_keys() : array;
	abstract protected function get_gql_search_keys() : array;

	/**
	 * Get the value of a single filter by name.
	 * If no filter data is set for the given name, NULL will be returned.
	 *
	 * @param string $name The name of the filter to get
	 * @return ?mixed The filter value if set, NULL if not
	 */
	public function get(string $name)
	{
		return $this->filters[$name] ?? null;
	}

	/**
	 * Get a copy of the stored product filters
	 *
	 * @return array The stored filters
	 */
	public function get_filters() : array
	{
		return $this->filters;
	}

	/**
	 * Get an array of filters and values for use in a REST query. The output
	 * of this can be used when setting up {@see PullParams}, for example.
	 *
	 * TODO: May need this to return a PullParams instead, since some filters
	 *   (such as limit and presentment) need to be included with every request,
	 *   while others (such as pub status) are only included for the first page.
	 *
	 * @return array Filters to use when calling a REST endpoint
	 */
	public function get_filters_rest() : array
	{
		return array_intersect_key(
			$this->filters,
			array_flip($this->get_rest_keys())
		);
	}

	/**
	 * Get string for use in a GQL query that contains search filters.
	 *
	 * <p>Filters in GQL include things like offset, limit, and search options.
	 * To make composing the filter string easier, additional search terms can
	 * be passed in here through the `$addl_query_parts` and `$addl_search_terms`
	 * parameters. These will be included in the output to prevent the need for
	 * hacking apart the result to inject terms not included by the filters.</p>
	 *
	 * <p>`$addl_query_parts` should be an array containing values such as
	 * ['first_name:Bob', 'age:42']. These will be added inside the "query" portion
	 * of the filter string. If it is desired to override any parameters from this
	 * class, the value should be keyed by the filter name so that it can be
	 * excluded when building the output string (see example).</p>
	 *
	 * <p>`$addl_search_terms` should be an array containing values such as
	 * ['first: 42', 'after: xyz']. These will be added alongside the "query" term
	 * as separate terms also enclosed in the search syntax's  parentheses. This
	 * list should NOT include a "query" entry -- anything that would go in the
	 * query should be included through `$addl_query_parts`. Overriding values can
	 * be done the same way as with the query parts.</p>
	 *
	 * <p>The string returned can be added directly to a query with no additional
	 * formatting needed. If the final list of filters to be added is empty, an
	 * empty string will be returned. Example usage:
	 * ```
	 * $pub_status = ProductFilterManager::FILTER_PUBLISHED_STATUS;
	 * $prod_filters = $pfm->get_filters_gql(
	 *    ['title:New*', 'created_at:>'2000-01-01T01:01:01Z', $pub_status => "{$pub_status}:any"],
	 *    ['first: 1', 'sortKey: CREATED_AT']
	 * );
	 * $query = <<<GQL
	 *    products{$prod_filters} {
	 *       id
	 *       title
	 *       createdAt
	 *    }
	 * GQL;
	 * ```
	 * </p>
	 *
	 * <p>For more information on the query piece, see:
	 *   https://shopify.dev/docs/api/usage/search-syntax</p>
	 *
	 * @param array $addl_query_parts Additional values to include in "query" filter
	 * @param array $addl_search_terms Additional non-query search terms
	 * @return string A composed filter string for use in a GQL query
	 */
	public function get_filters_gql(array $addl_query_parts = [], array $addl_search_terms = []) : string
	{
		$search_parts = $addl_search_terms;
		$query_parts = $addl_query_parts;

		foreach ($this->get_gql_query_keys() as $filter_name) {
			// Skip filters indicated by keys in query parts param
			if (isset($addl_query_parts[$filter_name])) {
				continue;
			}

			$value = $this->get($filter_name);
			if ($value !== null) {
				$query_parts[] = "{$filter_name}:{$value}";
			}
		}

		foreach ($this->get_gql_search_keys() as $filter_name) {
			// Skip filters indicated by keys in search terms param
			if (isset($addl_search_terms[$filter_name])) {
				continue;
			}

			$value = $this->get($filter_name);
			if ($value !== null) {
				$search_parts[] = sprintf(
					'%s: %s',
					$filter_name,
					is_string($value) ? "\"{$value}\"" : $value
				);
			}
		}

		if (count($query_parts) > 0) {
			/*
			 * "AND" is the default conjunctive. It could be included by the implode,
			 * but using whitespace and allowing the AND to be implied allows us the
			 * flexibility to exclude filters on demand by passing them with a value
			 * of empty string.
			 *
			 * For example, to exclude published status:
			 * $pfm->get_filters_gql([ProductFilterManager::FILTER_PUBLISHED_STATUS => ''])
			 */
			$query_str = implode(' ', $query_parts);
			$search_parts[] = "query: \"{$query_str}\"";
		}

		if (count($search_parts) > 0) {
			return '(' . implode(', ', $search_parts) . ')';
		}

		return '';
	}

}

