<?php

namespace ShopifyConnector\connectors\shopify\services;

use ShopifyConnector\api\service\AccessService as clAccessService;
use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\models\AccessScopes;

use ShopifyConnector\exceptions\ApiException;

/**
 * Service for making access-related calls in the Shopify API's.
 */
final class AccessService
{

	/**
	 * Get the list of access scopes associated with the app/token as defined
	 * in the provided session settings.
	 *
	 * @param SessionContainer $session The session information to use with this request
	 * @throws ApiException On API errors
	 */
	public static function get_access_scopes(SessionContainer $session) : AccessScopes
	{
		return self::get_access_scopes_gql($session);
	}

	/**
	 * Get the access scopes from GraphQL
	 *
	 * @param SessionContainer $session The session container
	 * @return AccessScopes The access scopes
	 * @throws ApiException On API errors
	 */
	public static function get_access_scopes_gql(SessionContainer $session) : AccessScopes
	{
		return new AccessScopes($session->client->graphql_request('query { app { availableAccessScopes { handle } } }'), []);
	}

	/**
	 * Get the access scopes from the REST API
	 *
	 * @param SessionContainer $session The session container
	 * @return AccessScopes The access scopes
	 */
	public static function get_access_scopes_rest(SessionContainer $session) : AccessScopes
	{
		$as = new clAccessService($session->client);
		$list = $as->getAccess([]);
		$session->set_last_call_limit();
		return new AccessScopes(
			$list,
			$session->client->parseLastPaginationLinkHeader()
		);
	}

}

