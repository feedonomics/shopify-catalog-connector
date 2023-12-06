<?php
namespace ShopifyConnector\api\service;

use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\api\BaseService;

/**
 * Service for interacting with the variants endpoints
 */
final class VariantService extends BaseService {

	/**
	 * List the variants for a given product
	 *
	 * @param string $id The product ID
	 * @param array $params Optional query params to include
	 * @return array The variants data
	 * @throws ApiException On API errors
	 */
	public function listProductTypeVariants(string $id, array $params = []) : array {
		$url = sprintf('admin/api/%s/products/%s/variants.json', $this->client::REST_VERSION, $id);
		return $this->client->request('GET', $url, $params);
	}

	/**
	 * Get the variant count for a given product
	 *
	 * @param string $id The product ID
	 * @param array $params Optional query params to include
	 * @return array The product count
	 * @throws ApiException On API errors
	 */
	public function getProductTypeVariantsCount(string $id, array $params = []) : array {
		$url = sprintf('admin/api/%s/products/%s/variants/count.json', $this->client::REST_VERSION, $id);
		return $this->client->request('GET', $url, $params);
	}

	/**
	 * Get information about a specific variant
	 *
	 * @param string $id The variant ID
	 * @return array The variant's data
	 * @throws ApiException On API errors
	 */
	public function getProductTypeVariant(string $id) : array {
		$url = sprintf('admin/api/%s/variants/%s.json', $this->client::REST_VERSION, $id);
		return $this->client->request('GET', $url);
	}

}
