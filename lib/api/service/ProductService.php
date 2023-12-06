<?php
namespace ShopifyConnector\api\service;

use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\api\BaseService;

/**
 * Service for interacting with the products endpoints
 */
final class ProductService extends BaseService {

	/**
	 * List products
	 *
	 * @param array $params Optional query params to include
	 * @param array $headers Optional headers to include
	 * @return array The products data
	 * @throws ApiException On API errors
	 */
	public function listProducts(array $params = [], array $headers = []) : array {
		$url = sprintf('admin/api/%s/products.json', $this->client::REST_VERSION);
		return $this->client->request('GET', $url, $params, $headers);
	}

	/**
	 * Get the product count
	 *
	 * @param array $params Optional query params to include
	 * @return array The product count
	 * @throws ApiException On API errors
	 */
	public function getProductCount(array $params = []) : array {
		$url = sprintf('admin/api/%s/products/count.json', $this->client::REST_VERSION);
		return $this->client->request('GET', $url, $params);
	}

	/**
	 * Get information about a specific product
	 *
	 * @param string $id The product ID
	 * @param array $headers Optional headers to include
	 * @return array The product's data
	 * @throws ApiException On API errors
	 */
	public function getProduct(string $id, array $headers = []) : array {
		$url = sprintf('admin/api/%s/products/%s.json', $this->client::REST_VERSION, $id);
		return $this->client->request('GET', $url, [], $headers);
	}

}
