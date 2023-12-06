<?php
namespace ShopifyConnector\api\service;

use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\api\BaseService;

/**
 * Service for interacting with the countries endpoints
 */
final class CountryService extends BaseService {

	/**
	 * Get the available countries
	 *
	 * @param array $params Query params to include
	 * @return array The countries data
	 * @throws ApiException On API errors
	 */
	public function getCountries(array $params) : array {
		$url = sprintf('/admin/api/%s/countries.json', $this->client::REST_VERSION);
		$resp = $this->client->request('GET', $url, $params);

		$links = $this->client->parseLastPaginationLinkHeader();
		$resp['next_page_token'] = $links['next'] ?? '';
		$resp['prev_page_token'] = $links['prev'] ?? '';

		return $resp;
	}

	/**
	 * Get the info about a specific country
	 *
	 * @param string $countryId The country ID
	 * @param array $params Query params to include
	 * @return array The country's data
	 * @throws ApiException On API errors
	 */
	public function getCountry(string $countryId, array $params) : array {
		$url = sprintf('/admin/api/%s/countries/%s.json', $this->client::REST_VERSION, $countryId);
		return $this->client->request('GET', $url, $params);
	}

}
