<?php

namespace ShopifyConnector\connectors\shopify\models;

/**
 * Data model for a list of Shopify access scopes.
 * Related Shopify documentation:
 * @link https://shopify.dev/docs/api/admin-graphql/latest/objects/AccessScope
 */
final class AccessScopes
{

	/**
	 * @var array Store for the list of available scopes
	 */
	private array $scopes;

	/**
	 * Instantiate an AccessScopes model using the given API response data.
	 * The passed in data should be of the following base form as returned by
	 * both the Shopify REST and GraphQL APIs:
	 *
	 * ```php
	 * [
	 *   ['handle' => 'read_products'],
	 *   ['handle' => 'read_inventory']
	 * ]
	 * ```
	 *
	 * @param array $scope_list The access scope data returned by an API
	 */
	public function __construct(array $scope_list)
	{
		$scopes = [];
		foreach ($scope_list['data']['currentAppInstallation']['accessScopes'] as $scope) {
			$scopes[] = $scope['handle'];
		}
		$this->scopes = $scopes;
	}

	/**
	 * Check if the given access scope is included in the list contained by
	 * this object.
	 *
	 * @param string $scope The access scope to check for
	 * @return bool TRUE if the scope is in the list, FALSE if not
	 */
	public function has_scope(string $scope) : bool
	{
		return in_array($scope, $this->scopes, true);
	}

	/**
	 * Get all access scopes contained in this object.
	 *
	 * @return array The list of access scopes
	 */
	public function getScopes() : array
	{
		return $this->scopes;
	}
}
