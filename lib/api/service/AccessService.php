<?php
namespace ShopifyConnector\api\service;

use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\api\BaseService;

/**
 * Service for interacting with the OAuth access endpoints
 */
final class AccessService extends BaseService {

	/**
	 * Get the access scopes
	 *
	 * @param array $params Query params to include
	 * @return array The access scopes data
	 * @throws ApiException On API errors
	 */
	public function getAccess(array $params) : array {
		return $this->client->request('GET', '/admin/oauth/access_scopes.json', $params);
	}

}
