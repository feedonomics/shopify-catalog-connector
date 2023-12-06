<?php
namespace ShopifyConnector\api\service;

use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\api\BaseService;

/**
 * Service for interacting with the product images endpoints
 */
final class ProductImageService extends BaseService {

	/**
	 * List the image info for a given product
	 *
	 * @param string $id The product ID
	 * @param array $params Optional query params to include
	 * @return array The image info
	 * @throws ApiException On API errors
	 */
	public function listProductImages(string $id, array $params = []) : array {
		$url = sprintf('admin/api/%s/products/%s/images.json', $this->client::REST_VERSION, $id);
		return $this->client->request('GET', $url, $params);
	}

}
