<?php

namespace ShopifyConnector\connectors\shopify;

use ShopifyConnector\connectors\shopify\services\ShopService;

/**
 * Utility for pulling information data from a client's Shopify store
 */
class InfoLister
{

	/**
	 * @var SessionContainer Store for the session container
	 */
	private SessionContainer $session;

	/**
	 * Prepare the info lister for use
	 *
	 * @param SessionContainer $session A session container for API calls
	 */
	public function __construct(SessionContainer $session)
	{
		$this->session = $session;
	}

	/**
	 * Pull summary info about the given Shopify site
	 *
	 * @return array Info includes:
	 * <ul>
	 *  <li>[permissions] List of granted access scopes</li>
	 *  <li>[store_info] Store summary information as field/value pairs</li>
	 *  <li>[product_row] A sample product row as field/value pairs</li>
	 * </ul>
	 */
	public function get_sample_data(): array
	{
		$info = [
			'permissions' => [],
			'store_info' => [],
			'product_row' => [],
		];

		$shop = ShopService::get_shop_fetch_info_gql($this->session);

		// Get Store Permissions
		foreach ($shop['shop']['access_scopes'] as $scopes) {
			$scope = $scopes['handle'] ?? '';
			if ($scope != '') {
				$info['permissions'][] = $scope;
			}
		}

		// Transform store_info to field/value pairs
		foreach ($shop['shop'] as $key => $value) {
			// Skip numeric keys
			if (is_numeric($key)) {
				continue;
			}

			if ($key === 'shipsToCountries') {
				$info['store_info'][] = [
					'field' => $key,
					'value' => $value
				];
			}

			if ($key === 'locations') {
				$info['store_info'][] = [
					'field' => $key,
					'value' => $value['edges']
				];
				continue;
			}

			if (is_array($value) || is_object($value)) {
				// Response only goes 1 level deep
				foreach ($value as $nested_key => $nested_value) {
					if (is_numeric($nested_key)) {
						continue;
					}

					$info['store_info'][] = [
						'field' => $nested_key,
						'value' => $nested_value
					];
				}
			} else {
				$info['store_info'][] = [
					'field' => $key,
					'value' => $value
				];
			}
		}

		// Add total_products as an additional field
		$info['store_info'][] = [
			'field' => 'total_products',
			'value' => $shop['products_count']
		];

		// Transform product_row to field/value pairs
		foreach ($shop['product'] as $key => $value) {
			if ($key === 'metafields') {
				$info['product_row'][] = [
					'field' => $key,
					'value' => $value['edges']
				];
			} elseif ($key === 'variants') {
				$info['product_row'][] = [
					'field' => $key,
					'value' => $value['edges'][0]['node']
				];
			} else {
				$info['product_row'][] = [
					'field' => $key,
					'value' => $value
				];
			}
		}

		return $info;
	}
}