<?php

namespace ShopifyConnector\connectors\shopify\products;

use ShopifyConnector\connectors\shopify\models\FilterManager;
use ShopifyConnector\exceptions\ValidationException;

/**
 * Utility for parsing and managing a list of product filters for pulling
 * product data from Shopify
 *
 * Supports two modes:
 * - Legacy mode (default): Uses original filter handling for backwards compatibility
 * - GraphQL mode: Uses enhanced filter translation for proper GraphQL search syntax
 *
 * GraphQL mode is enabled by passing $use_gql_mode = true to the constructor,
 * which happens when 'gql_product_filters' is used instead of 'product_filters'.
 */
class ProductFilterManager extends FilterManager
{

	/**
	 * @var string Return only products specified by a list of
	 * product IDs
	 */
	const FILTER_IDS = 'ids';

	/**
	 * @var string Return up to this many results per page (max 250)
	 */
	const FILTER_LIMIT = 'limit';

	/**
	 * @var string Return only products after the specified ID
	 */
	const FILTER_SINCE_ID = 'since_id';

	/**
	 * @var string Return products by product title
	 */
	const FILTER_TITLE = 'title';

	/**
	 * @var string Return products by product vendor
	 */
	const FILTER_VENDOR = 'vendor';

	/**
	 * @var string Return only products specified by a list of
	 * product handles
	 */
	const FILTER_HANDLE = 'handle';

	/**
	 * @var string Return products by product type
	 */
	const FILTER_PRODUCT_TYPE = 'product_type';

	/**
	 * @var string Return only products specified by a list of
	 * statuses (any/active/archived/draft)
	 */
	const FILTER_STATUS = 'status';

	/**
	 * @var string Return products by product collection ID
	 */
	const FILTER_COLLECTION_ID = 'collection_id';

	/**
	 * Filter by product published status.
	 *
	 * For REST API: @link https://shopify.dev/docs/api/admin-rest/2025-07/resources/product#resource-object
	 *   Valid values: any, published, unpublished
	 *
	 * For GraphQL (when $use_gql_mode is true):
	 *   The GraphQL "published_status" field has different semantics (channel approval status),
	 *   so this filter is translated to use "published_at:*" for published products.
	 *   @link https://shopify.dev/docs/api/admin-graphql/latest/queries/products#argument-query-filter-published_at
	 *
	 * @var string
	 */
	const FILTER_PUBLISHED_STATUS = 'published_status';

	/**
	 * GraphQL-specific filter for publishable status on a channel.
	 *
	 * @link https://shopify.dev/docs/api/admin-graphql/latest/queries/products#argument-query-filter-publishable_status
	 *
	 * Valid values:
	 *   online_store_channel, published, unpublished, visible, unavailable, hidden, intended
	 *
	 * @var string Return products by their publishable status
	 */
	const FILTER_PUBLISHABLE_STATUS  = 'publishable_status';

	/**
	 * @var string Return only certain fields specified by a
	 * list of field names
	 */
	const FILTER_FIELDS = 'fields';

	/**
	 * @var string Return presentment prices in only certain currencies,
	 * specified by a list of ISO 4217 currency codes
	 */
	const FILTER_PRESENTMENT_CURRENCIES = 'presentment_currencies';

	/**
	 * @var bool Flag indicating whether to use enhanced GraphQL filter translation
	 */
	private bool $use_gql_mode;


	/**
	 * @param array $product_filters The list of product filters from the request to parse out
	 * @param string $published_status_fallback Value to use for published_status if not present in filters (compat)
	 * @param bool $use_gql_mode When true, enables enhanced GraphQL filter translation
	 * @throws ValidationException Throws a validation error if invalid filters are requested
	 */
	public function __construct(array $product_filters, string $published_status_fallback, bool $use_gql_mode = false)
	{
		$this->use_gql_mode = $use_gql_mode;
		$bad_filters = [];

		foreach ($product_filters as $filter) {
			$name = $filter['filter'] ?? '<empty_filter_name>';
			$value = $filter['value'] ?? '';

			switch ($name) {

				// Basic filters that need no processing
				case self::FILTER_LIMIT:
				case self::FILTER_SINCE_ID:
				case self::FILTER_TITLE:
				case self::FILTER_VENDOR:
				case self::FILTER_PRODUCT_TYPE:
				case self::FILTER_COLLECTION_ID:
				case self::FILTER_PUBLISHED_STATUS:
					$this->filters[$name] = $value;
					break;

				// Filters that are comma separated strings
				// If an array is passed these will be imploded, otherwise
				// assumes they are properly formatted
				case self::FILTER_IDS:
				case self::FILTER_HANDLE:
				case self::FILTER_STATUS:
				case self::FILTER_FIELDS:
				case self::FILTER_PRESENTMENT_CURRENCIES:
					$this->filters[$name] = is_array($value)
						? implode(',', $value)
						: $value;
					break;

				default:
					$bad_filters[] = $name;
			}
		}

		if (!empty($bad_filters)) {
			throw new ValidationException(
				'The following are invalid product filters: ' .
				implode(',', $bad_filters)
			);
		}

		// Backwards compatibility for the old product publish status filter
		if (!isset($this->filters[self::FILTER_PUBLISHED_STATUS])) {
			$this->filters[self::FILTER_PUBLISHED_STATUS] = $published_status_fallback;
		}
	}

	/**
	 * Check if GraphQL mode is enabled.
	 *
	 * @return bool True if using enhanced GraphQL filter translation
	 */
	public function is_gql_mode() : bool
	{
		return $this->use_gql_mode;
	}

	/**
	 * When we are pulling products via GraphQL directly, we need to modify some filters.
	 * This method maps published_status to publishable_status for legacy compatibility.
	 *
	 * In GraphQL mode, delegates to get_filters_gql_enhanced() which handles all
	 * filter translations including published_status.
	 *
	 * @param array $addl_query_parts
	 * @param array $addl_search_terms
	 * @return string
	 */
	public function get_filters_for_graphql(array $addl_query_parts = [], array $addl_search_terms = []) : string
	{
		// In GraphQL mode, use enhanced filter handling which already handles
		// all filter translations including published_status
		if ($this->use_gql_mode) {
			return $this->get_filters_gql_enhanced($addl_query_parts, $addl_search_terms);
		}

		// Legacy behavior for non-GraphQL mode
		$published_status = $this->get(self::FILTER_PUBLISHED_STATUS);

		if (empty($published_status) || !in_array($published_status, ['published', 'unpublished'], true)) {
			return parent::get_filters_gql($addl_query_parts, $addl_search_terms);
		}

		unset($this->filters[self::FILTER_PUBLISHED_STATUS]);

		// Map the GraphQL publishable_status filter
		$addl_query_parts[] = sprintf('%s:%s', self::FILTER_PUBLISHABLE_STATUS, $published_status);

		$filters = parent::get_filters_gql($addl_query_parts, $addl_search_terms);

		// Restore state
		$this->filters[self::FILTER_PUBLISHED_STATUS] = $published_status;

		return $filters;
	}

	/**
	 * Get string for use in a GraphQL query that contains search filters.
	 *
	 * When GraphQL mode is enabled ($use_gql_mode = true), this method applies
	 * enhanced filter translations:
	 * - published_status=published -> published_at:* (has a published date)
	 * - published_status=unpublished -> (published_status:unset OR published_status:pending OR published_status:'not approved')
	 * - ids -> formatted as "(id:123 OR id:456)"
	 * - handle (comma-separated) -> "(handle:x OR handle:y)"
	 * - since_id -> id:>value
	 * - collection_id -> collection_id:value
	 *
	 * When GraphQL mode is disabled (legacy mode), uses the original behavior
	 * which passes filters through to parent without special translation.
	 *
	 * @param array $addl_query_parts Additional values to include in "query" filter
	 * @param array $addl_search_terms Additional non-query search terms
	 * @return string A filter string for use in a GQL query
	 */
	public function get_filters_gql(array $addl_query_parts = [], array $addl_search_terms = []) : string
	{
		if ($this->use_gql_mode) {
			return $this->get_filters_gql_enhanced($addl_query_parts, $addl_search_terms);
		}

		// Legacy mode: pass through to parent without special translation
		return parent::get_filters_gql($addl_query_parts, $addl_search_terms);
	}

	/**
	 * Enhanced GraphQL filter handling with proper search syntax translation.
	 *
	 * @param array $addl_query_parts Additional values to include in "query" filter
	 * @param array $addl_search_terms Additional non-query search terms
	 * @return string A filter string for use in a GQL query
	 */
	private function get_filters_gql_enhanced(array $addl_query_parts = [], array $addl_search_terms = []) : string
	{
		// Handle published_status translation for GraphQL
		// - "published" -> Use published_at:* to find products with a non-null published date
		// - "unpublished" -> Use published_status values that indicate not approved/published
		$published_status = $this->get(self::FILTER_PUBLISHED_STATUS);
		if (!empty($published_status)) {
			if ($published_status === 'published') {
				// Products with a published_at date are published to the online store
				$addl_query_parts[self::FILTER_PUBLISHED_STATUS] = 'published_at:*';
			} elseif ($published_status === 'unpublished') {
				// For unpublished, look for products without approval
				// published_status valid values: unset, pending, approved, not approved
				// We want everything except "approved"
				$addl_query_parts[self::FILTER_PUBLISHED_STATUS] =
					"(published_status:unset OR published_status:pending OR published_status:'not approved')";
			}
			// 'any' or other values = no filter applied
		}

		// Handle IDs list - format as "(id:123 OR id:456)"
		$ids = $this->get(self::FILTER_IDS);
		if (!empty($ids)) {
			$id_list = array_filter(array_map('trim', explode(',', $ids)));
			if (!empty($id_list)) {
				$id_queries = array_map(fn($id) => "id:{$id}", $id_list);
				$addl_query_parts[self::FILTER_IDS] = '(' . implode(' OR ', $id_queries) . ')';
			}
		}

		// Handle handle list - format as "(handle:x OR handle:y)"
		$handles = $this->get(self::FILTER_HANDLE);
		if (!empty($handles)) {
			$handle_list = array_filter(array_map('trim', explode(',', $handles)));
			if (!empty($handle_list)) {
				$handle_queries = array_map(fn($h) => "handle:{$h}", $handle_list);
				$addl_query_parts[self::FILTER_HANDLE] = '(' . implode(' OR ', $handle_queries) . ')';
			}
		}

		// Handle collection_id filter
		$collection_id = $this->get(self::FILTER_COLLECTION_ID);
		if (!empty($collection_id)) {
			$addl_query_parts[self::FILTER_COLLECTION_ID] = "collection_id:{$collection_id}";
		}

		// Handle since_id - use id:>value syntax for "products after this ID"
		$since_id = $this->get(self::FILTER_SINCE_ID);
		if (!empty($since_id)) {
			$addl_query_parts[self::FILTER_SINCE_ID] = "id:>{$since_id}";
		}

		return parent::get_filters_gql($addl_query_parts, $addl_search_terms);
	}

	/**
	 * Get keys for filters that should be included in REST API calls.
	 *
	 * @return array List of filter keys for REST
	 */
	protected function get_rest_keys() : array
	{
		if ($this->use_gql_mode) {
			return [
				self::FILTER_PUBLISHED_STATUS,
				self::FILTER_STATUS,
				self::FILTER_LIMIT,
				self::FILTER_SINCE_ID,
				self::FILTER_TITLE,
				self::FILTER_VENDOR,
				self::FILTER_HANDLE,
				self::FILTER_PRODUCT_TYPE,
				self::FILTER_COLLECTION_ID,
				self::FILTER_IDS,
			];
		}

		return [
			self::FILTER_PUBLISHED_STATUS,
		];
	}

	/**
	 * Get keys for filters that should be included in the GraphQL "query" parameter.
	 *
	 * In GraphQL mode, filters requiring special formatting are handled in
	 * get_filters_gql_enhanced() and should NOT be included here to avoid
	 * duplicate or conflicting filter entries.
	 *
	 * @return array List of filter keys for GraphQL query parameter
	 */
	protected function get_gql_query_keys() : array
	{
		if ($this->use_gql_mode) {
			$filter_list = [];

			// Status filter (active/archived/draft)
			$status = $this->get(self::FILTER_STATUS);
			if (!empty($status) && $status !== 'any') {
				$filter_list[] = self::FILTER_STATUS;
			}

			// Title filter - supports wildcards in Shopify search syntax
			if (!empty($this->get(self::FILTER_TITLE))) {
				$filter_list[] = self::FILTER_TITLE;
			}

			// Vendor filter
			if (!empty($this->get(self::FILTER_VENDOR))) {
				$filter_list[] = self::FILTER_VENDOR;
			}

			// Product type filter
			if (!empty($this->get(self::FILTER_PRODUCT_TYPE))) {
				$filter_list[] = self::FILTER_PRODUCT_TYPE;
			}

			return $filter_list;
		}

		// Legacy mode
		$filter_list = [];

		if ($this->get(self::FILTER_PUBLISHED_STATUS) !== 'any') {
			$filter_list = [
				self::FILTER_PUBLISHED_STATUS,
			];
		}

		return $filter_list;
	}

	/**
	 * Get keys for filters that should be included as separate GraphQL search terms.
	 *
	 * @return array List of filter keys for GraphQL search terms
	 */
	protected function get_gql_search_keys() : array
	{
		return [];
	}

}