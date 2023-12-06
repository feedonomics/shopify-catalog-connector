<?php

namespace ShopifyConnector\util;

use ShopifyConnector\exceptions\ValidationException;


/**
 * Utility for parsing and managing a list of product filters for pulling
 * product data from Shopify
 */
class ProductFilterManager {

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
	 * @var array Store for the filters
	 */
	private array $filters = [];

	/**
	 * @param array $conn_info The connection_info object from the request to
	 * parse out product filters from
	 * @throws ValidationException Throws a validation error if invalid filters
	 * are requested
	 */
	public function __construct(array $conn_info){
		$bad_filters = [];

		foreach(($conn_info['product_filters'] ?? []) as $filter){
			switch($filter['filter'] ?? 'empty_filter_name'){

				// Basic filters that need no processing
				case self::FILTER_LIMIT:
				case self::FILTER_SINCE_ID:
				case self::FILTER_TITLE:
				case self::FILTER_VENDOR:
				case self::FILTER_PRODUCT_TYPE:
				case self::FILTER_COLLECTION_ID:
				case self::FILTER_PUBLISHED_STATUS:
					$this->filters[$filter['filter']] = $filter['value'];
					break;

				// Filters that are comma separated strings
				// If an array is passed these will be imploded, otherwise
				// assumes they are properly formatted
				case self::FILTER_IDS:
				case self::FILTER_HANDLE:
				case self::FILTER_STATUS:
				case self::FILTER_FIELDS:
				case self::FILTER_PRESENTMENT_CURRENCIES:
					$this->filters[$filter['filter']] = is_array($filter['value'])
						? implode(',', $filter['value'])
						: $filter['value'];
					break;

				default:
					$bad_filters[] = $filter['filter'];
			}
		}

		if(!empty($bad_filters)){
			throw new ValidationException(sprintf(
				'The following are invalid filters: %s',
				implode(',', $bad_filters)
			));
		}

		// Backwards compatibility for the old product publish status filter
		if(isset($conn_info['product_published_status']) && !isset($this->filters[self::FILTER_PUBLISHED_STATUS])){
			$this->filters[self::FILTER_PUBLISHED_STATUS] = $conn_info['product_published_status'];
		}

		// Default to only pull published products unless otherwise specifically requested
		if(!isset($this->filters[self::FILTER_PUBLISHED_STATUS])){
			$this->filters[self::FILTER_PUBLISHED_STATUS] = 'published';
		}
	}

	/**
	 * Get a copy of the stored product filters
	 *
	 * @return array The stored filters
	 */
	public function get_filters() : array {
		return $this->filters;
	}

}

