<?php

namespace ShopifyConnector\connectors\shopify\services;

use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\models\Shop;
use ShopifyConnector\api\service\CountryService as clCountryService;
use ShopifyConnector\api\service\ProductService as clProductService;
use ShopifyConnector\api\service\StoreService;
use ShopifyConnector\exceptions\ApiResponseException;

/**
 * Service for making Shopify shop related calls
 */
final class ShopService
{

	/**
	 * Get information about the given shop via the REST API
	 *
	 * @param SessionContainer $session The session container
	 * @return Shop Information about the shop
	 * @throws ApiResponseException On invalid data
	 */
	public static function get_shop_info_rest(SessionContainer $session) : Shop
	{
		$ps = new StoreService($session->client);
		$info = $ps->getStoreInfo();
		$session->set_last_call_limit();

		$shop = new Shop($info['shop'] ?? []);

		# This may not be the ideal place for this, but for abstracting
		# to support GraphQL interop, this seems to make sense here
		$requested_rates = $session->settings->tax_rates;
		if (!empty($requested_rates)) {
			$shop->build_tax_rates(
				self::get_country_info_rest($session),
				explode(',', strtoupper($requested_rates))
			);
		}

		return $shop;
	}

	/**
	 * Get the country info about the Shopify shop via the REST API
	 *
	 * @param SessionContainer $session The session container
	 * @return array The country info de-parsed into a basic array
	 */
	public static function get_country_info_rest(SessionContainer $session) : array
	{
		$cs = new clCountryService($session->client);
		$list = $cs->getCountries(['fields' => 'code,name,tax,provinces']);
		$session->set_last_call_limit();

		# De-Resource the results
		return array_map(fn($i) => $i->getItem(), $list);
	}

}

