<?php
namespace ShopifyConnector\connectors\shopify;

use ShopifyConnector\connectors\shopify\models\FilterManager;
use ShopifyConnector\exceptions\ValidationException;

/**
 * Utility for parsing and managing a list of meta filters for pulling
 * meta data from Shopify
 */
class MetaFilterManager extends FilterManager
{

	/**
	 * @var string Return only meta information within a particular namespace
	 */
	const FILTER_NAMESPACE = 'namespace';


	/**
	 * Utility for parsing and managing a list of meta filters for pulling
	 * meta data from Shopify
	 *
	 * @param array $meta_filters The list of meta filters from the request to parse out
	 * @throws ValidationException Throws a validation error if invalid filters are requested
	 */
	public function __construct(array $meta_filters)
    {
		$bad_filters = [];

		foreach ($meta_filters as $filter) {
			$name = $filter['filter'] ?? '<empty_filter_name>';
			$value = $filter['value'] ?? '';

			switch ($name) {

				// Basic filters that need no processing
				case self::FILTER_NAMESPACE:
					$this->filters[$name] = $value;
					break;

				default:
					$bad_filters[] = $name;
			}
		}

		if (!empty($bad_filters)) {
			throw new ValidationException(sprintf(
				'The following are invalid meta filters: %s',
				implode(',', $bad_filters)
			));
		}
	}

	/**
	 * Get a copy of the stored meta filters
	 *
	 * @return array The stored filters
	 */
	public function get_filters() : array
    {
		return $this->filters;
	}

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
		return [
			self::FILTER_NAMESPACE,
		];
	}

}

