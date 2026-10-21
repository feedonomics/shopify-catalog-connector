<?php

namespace ShopifyConnector\connectors\shopify\services;

use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\connectors\shopify\models\GID;
use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\exceptions\ApiResponseException;

/**
 * Service for publication (sales channel) calls in the Shopify API.
 */
final class PublicationService
{
	/**
	 * @var string Name Shopify gives the Online Store sales channel
	 */
	const ONLINE_STORE_NAME = 'Online Store';

	/**
	 * Find the shop's Online Store publication. Requires read_publications.
	 *
	 * @param SessionContainer $session The session information to use with this request
	 * @return ?GID The Online Store publication, or null if the shop has none
	 * @throws ApiResponseException On API errors or a malformed publication id
	 */
	public static function get_online_store_publication(SessionContainer $session) : ?GID
	{
		$after = null;
		do {
			$after_arg = $after === null ? '' : ', after: ' . json_encode($after);
			try {
				$response = $session->client->graphql_request(<<<GQL
					query {
						publications(first: 250{$after_arg}) {
							edges {
								node {
									id
									name
								}
							}
							pageInfo {
								hasNextPage
								endCursor
							}
						}
					}
					GQL
				);
			} catch (ApiException $e) {
				ApiResponseException::throw_from_cl_api_exception($e);
			}

			// A 200 can still carry errors; reading it as "no Online Store" would drop the column
			if (!empty($response['errors'] ?? null) || !isset($response['data']['publications'])) {
				throw new ApiResponseException(
					'Shopify publications query returned errors: ' . json_encode($response['errors'] ?? null)
				);
			}

			$publications = $response['data']['publications'];
			foreach ($publications['edges'] ?? [] as $edge) {
				if (($edge['node']['name'] ?? null) === self::ONLINE_STORE_NAME) {
					$gid = new GID($edge['node']['id'] ?? '');
					return $gid->is_publication() ? $gid : null;
				}
			}

			$after = !empty($publications['pageInfo']['hasNextPage'])
				? ($publications['pageInfo']['endCursor'] ?? null)
				: null;
		} while ($after !== null);

		return null;
	}
}
