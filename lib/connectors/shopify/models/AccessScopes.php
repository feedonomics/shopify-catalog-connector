<?php
namespace ShopifyConnector\connectors\shopify\models;

/**
 * Data model for a list of Shopify access scopes.
 * Related Shopify documentation:
 * https://shopify.dev/docs/api/admin-rest/2023-04/resources/accessscope
 * https://shopify.dev/docs/api/admin-graphql/2023-04/objects/AccessScope
 */
final class AccessScopes extends PagedREST
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
	 * @param array $scopeList The access scope data returned by an API
	 * @param array $pageLinks Pagination links as returned by
	 *   {@see ShopifyClient::parseLastPaginationLinkHeader}
	 */
	public function __construct(array $scopeList, array $pageLinks)
	{
		$scopes = [];
		foreach($scopeList['data']['app']['availableAccessScopes'] as $scope) {
			$scopes[] = $scope['handle'];
		}
		$this->scopes = $scopes;

		$this->setPageInfos($pageLinks);
	}

	/**
	 * Check if the given access scope is included in the list contained by
	 * this object.
	 *
	 * @param string $scope The access scope to check for
	 * @return bool TRUE if the scope is in the list, FALSE if not
	 */
	public function hasScope(string $scope) : bool
	{
		return in_array($scope, $this->scopes, true);
	}

}
