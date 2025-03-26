<?php

namespace ShopifyConnector\connectors\shopify\services;

use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\api\service\StoreService;
use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\models\Shop;
use ShopifyConnector\api\service\CountryService as clCountryService;
use ShopifyConnector\api\service\ProductService as clProductService;
use ShopifyConnector\exceptions\api\UnexpectedResponseException;
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
	 * @throws UnexpectedResponseException On invalid data
	 */
	public static function get_shop_info_gql(SessionContainer $session) : Shop
	{
		try {
			$response = $session->client->graphql_request('query { shop { primaryDomain { host } createdAt billingAddress { countryCodeV2 } } }');
		} catch (ApiException $e) {
			ApiResponseException::throw_from_cl_api_exception($e);
		}
		$shop['domain'] = $response['data']['shop']['primaryDomain']['host'] ?? '';
		$shop['created_at'] = $response['data']['shop']['createdAt'] ?? '';
		$shop['country_code'] = $response['data']['shop']['billingAddress']['countryCodeV2'] ?? '';
		$shop = new Shop($shop ?? []);
		$session->set_last_call_limit();

		return $shop;
	}
}

