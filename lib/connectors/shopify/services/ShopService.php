<?php

namespace ShopifyConnector\connectors\shopify\services;

use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\models\Shop;
use ShopifyConnector\exceptions\ApiResponseException;

/**
 * Service for making Shopify shop related calls
 */
final class ShopService
{
	/**
	 * Get the shop info from GraphQL
	 *
	 * @param SessionContainer $session The session container
	 * @return Shop Information about the shop
	 * @throws ApiResponseException
	 */
	public static function get_shop_info_gql(SessionContainer $session) : Shop
	{
		try {
			$response = $session->client->graphql_request(<<<GQL
				query {
					shop {
						primaryDomain {
							host
						}
						createdAt
						shopAddress {
							countryCodeV2
						}
					}
					productsCount(limit: null) {
						count
					}
				}
				GQL
			);
		} catch (ApiException $e) {
			ApiResponseException::throw_from_cl_api_exception($e);
		}

		$shop = new Shop([
			'domain' => $response['data']['shop']['primaryDomain']['host'] ?? '',
			'created_at' => $response['data']['shop']['createdAt'] ?? '',
			'country_code' => $response['data']['shop']['shopAddress']['countryCodeV2'] ?? '',
		]);

		$shop->product_catalog_size = $response['data']['productsCount']['count'] ?? 0;

		return $shop;
	}

	/**
	 * Get the expected data for our 'fetch info' feature from GraphQL
	 *
	 * @param SessionContainer $session The session container
	 * @return array Information about the shop
	 * @throws ApiResponseException On invalid data
	 */
	public static function get_shop_fetch_info_gql(SessionContainer $session) : array
	{
		$has_location_access = false;
		try {
			// Grab Access Scopes first
			$access_scopes = AccessService::get_access_scopes($session);
			$shop['access_scopes'] = $access_scopes->getScopes();
			// Check if read_locations scope is available
			$has_location_access = $access_scopes->has_scope('read_locations');
		} catch (ApiException $e) {
			ApiResponseException::throw_from_cl_api_exception($e);
		}

		// Add locations to query if we have access
		$locations_fragment = '';
		if ($has_location_access) {
			$locations_fragment = <<<GQL
					locations(first: 250) {
						edges {
							node {
								id
								name
								isActive
								isPrimary
								fulfillmentService {
									handle
									inventoryManagement
									serviceName
								}
							}
						}
					}
				GQL;
		}

		try {
			// Can't filter productsCount by published products as the filter doesn't work as expected, caps at 10k
			$qry = <<<GQL
				query {
					shop {
						id name email primaryDomain {
							host
						}
						myshopifyDomain createdAt updatedAt shopAddress {
							countryCodeV2 country province provinceCode
						}
						shipsToCountries currencyCode timezoneOffset ianaTimezone shopOwnerName weightUnit taxesIncluded taxShipping plan {
							displayName shopifyPlus
						}
						features {
							giftCards storefront
						}
						enabledPresentmentCurrencies checkoutApiSupported setupRequired
						{$locations_fragment}
					}
					productsCount(limit: null) {
						count
					}
					products(first: 1) {
						edges {
							node {
								id
								title
								handle
								description
								vendor
								productType
								tags
								status
								createdAt
								updatedAt
								publishedAt
								metafields(first: 250) {
									edges {
										node {
											namespace
											key
											value
										}
									}
								}
								variants(first: 1) {
									edges {
										node {
											id
											title
											sku
											price
										}
									}
								}
							}
						}
					}
				}
				GQL;
			$response = $session->client->graphql_request($qry);
		} catch (ApiException $e) {
			ApiResponseException::throw_from_cl_api_exception($e);
		}

		$shop['shop'] = $response['data']['shop'] ?? [];
		$shop['products_count'] = $response['data']['productsCount']['count'] ?? '';
		$shop['product'] = $response['data']['products']['edges'][0]['node'] ?? [];

		if ($has_location_access && isset($response['data']['shop']['locations']['edges'])) {
			$locations = [];
			foreach ($response['data']['shop']['locations']['edges'] as $edge) {
				if (isset($edge['node'])) {
					$locations[] = $edge['node'];
				}
			}
			$shop['locations'] = $locations;
		}

		return $shop;
	}
}
