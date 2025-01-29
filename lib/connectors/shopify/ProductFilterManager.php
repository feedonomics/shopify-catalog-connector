<?php

namespace ShopifyConnector\connectors\shopify;

use ShopifyConnector\connectors\shopify\models\FilterManager;
use ShopifyConnector\connectors\shopify\models\Product;

use ShopifyConnector\exceptions\ValidationException;

/**
 * Utility for parsing and managing a list of product filters for pulling
 * product data from Shopify
 *
 * TODO: See note on FILTER_PUBLISHED_STATUS !!!
 *
 * TODO: Move this into products/ directory?
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
	 * TODO: This may use different values now (or maybe it always did with gql?)
	 *   See the values here for reference:
	 *   https://shopify.dev/docs/api/admin-graphql/2024-10/objects/Product#query-products-query-query-filter-published_status
	 *
	 * @var string Return products by their published status
	 * (any/published/unpublished)
	 */
	const FILTER_PUBLISHED_STATUS = 'published_status';

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
	 * @param array $product_filters The list of product filters from the request to parse out
	 * @param string $published_status_fallback Value to use for published_status if not present in filters (compat)
	 * @throws ValidationException Throws a validation error if invalid filters are requested
	 */
	public function __construct(array $product_filters, string $published_status_fallback)
	{
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

	protected function get_rest_keys() : array
	{
		return [
			self::FILTER_PUBLISHED_STATUS,
		];
	}

	protected function get_gql_query_keys() : array
	{
		return [
			self::FILTER_PUBLISHED_STATUS,
		];
	}

	protected function get_gql_search_keys() : array
	{
		return [];
	}

}

