<?php
namespace ShopifyConnector\api\service;

use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\api\BaseService;

/**
 * Service for interacting with the province endpoints
 */
final class ProvinceService extends BaseService {

	/**
	 * Get the province data for a given country
	 *
	 * @param string $id The country ID
	 * @param array $params Query params to include
	 * @return array The province data
	 * @throws ApiException On API errors
	 */
	public function getProvinces(string $id, array $params) : array {
		$url = sprintf('/admin/api/%s/countries/%s/provinces.json', $this->client::REST_VERSION, $id);
		$resp = $this->client->request('GET', $url, $params);

		$links = $this->client->parseLastPaginationLinkHeader();
		$resp['next_page_token'] = $links['next'] ?? '';
		$resp['prev_page_token'] = $links['prev'] ?? '';

		return $resp;
	}

	/**
	 * Get information for a specific province
	 *
	 * @param string $countryId The country ID
	 * @param string $provinceId The province ID
	 * @param array $params Query params to include
	 * @return array The province's data
	 * @throws ApiException On API errors
	 */
	public function getProvince(string $countryId, string $provinceId, array $params) : array {
		$url = sprintf('/admin/api/%s/countries/%s/provinces/%s.json', $this->client::REST_VERSION, $countryId, $provinceId);
		return $this->client->request('GET', $url, $params);
	}

}
