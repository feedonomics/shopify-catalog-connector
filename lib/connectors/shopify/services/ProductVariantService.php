<?php
namespace ShopifyConnector\connectors\shopify\services;

use Exception;
use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\models\ProductVariantPileGQL;
use ShopifyConnector\connectors\shopify\structs\PullerParams;
use ShopifyConnector\api\service\VariantService as clVariantService;
use ShopifyConnector\exceptions\api\UnexpectedResponseException;

/**
 * Service for making product variant related calls
 */
final class ProductVariantService
{

	/**
	 * Get the variant product count via the REST API for the given date ranges
	 *
	 * @param SessionContainer $session The session container
	 * @param string $productId The product ID to get the variant count for
	 * @param string $dateStart TODO: Do we need this filter?
	 * @param string $dateEnd TODO: Do we need this filter?
	 * @param string $publishStatus Filter for the published status
	 * @return int The variant count
	 */
	public static function getCountForRangeREST(
		SessionContainer $session,
		string $productId,
		string $dateStart,
		string $dateEnd,
		string $publishStatus = 'published'
	) : int
	{
		$vs = new clVariantService($session->client);
		$count = $vs->getProductTypeVariantsCount($productId, [
			'created_at_min' => $dateStart,
			'created_at_max' => $dateEnd,
			'published_status' => $publishStatus,
		])['count']; # TODO: Forked?
		$session->set_last_call_limit();
		return (int)$count;
	}

	/**
	 * Get the contextual prices for a product variant via GraphQL
	 *
	 * @param SessionContainer $session The session container
	 * @param string $country_code The country code
	 * @param int $first The `first` GQL filter
	 * @param string|null $after The `after` GQL filter
	 * @return ProductVariantPileGQL The variant contextual prices list
	 * @throws UnexpectedResponseException On invalid data
	 * @throws ApiException On API errors
	 */
	public static function getContextualPrices(
		SessionContainer $session,
		string $country_code,
		int $first = 250,
		?string $after = null
	) : ProductVariantPileGQL
	{
		# TODO: This needs to be revamped to the current way of doing things
		$res = $session->client->graphql_request("query {
			productVariants (first: {$first}, after: {$after}) {
				nodes {
					id
					contextualPricing (context: { country: $country_code }) {
						price {
							amount
							currencyCode
						}
						compareAtPrice {
							amount
							currencyCode
						}
					}
				}
				pageInfo {
					hasNextPage
					endCursor
				}
			}
		}");

		# TODO: Check response for error
		#   - Abstract that higher up?

		return new ProductVariantPileGQL(
			$res['data']['products']['nodes'] ?? null,
			$res['data']['products']['pageInfo'] ?? null
		);
	}

	/**
	 * Get category information at the variant level via GraphQL
	 *
	 * <hr>
	 * TODO: We could also just do this off the Product, which would be more
	 *   efficient. But that may present issues if variants aren't a part of
	 *   the pull as well when it comes time to join everything up in the db
	 *   in the platform. (We should be able to join the data well enough in
	 *   the local db when variants are present by updating rows matching on
	 *   product_id/item_group_id.)
	 *
	 * @param SessionContainer $session The session container
	 * @param PullerParams $params Params to filter the API call
	 * @return ProductVariantPileGQL The list of variant categories
	 * @throws ApiException On API errors
	 * @throws UnexpectedResponseException On invalid data
	 */
	public static function getCategories(
		SessionContainer $session,
		PullerParams $params
	) : ProductVariantPileGQL
	{
		# TODO: "limit" not showing up in params after first page
		$first = $params->params['limit'] ?? 100;
		$after = $params->nextPageInfo !== null
			? ", after: \"{$params->nextPageInfo}\""
			: '';

		$filters = $session->settings->product_filters->get_filters_gql();
		if (strlen($filters) > 0) {
			$filters = ", {$filters}";
		}

		$res = $session->client->graphql_request("query {
			productVariants (first: {$first}{$after}{$filters}) {
				nodes {
					id
					product {
						productCategory {
							productTaxonomyNode {
								id
								fullName
							}
						}
					}
				}
				pageInfo {
					hasNextPage
					endCursor
				}
			}
		}");

		# Check for GQL errors in result
		if (!empty($res['errors'] ?? null)) {
			throw new Exception('Query returned errors: ' . json_encode($res['errors']));
		}

		return new ProductVariantPileGQL(
			$res['data']['productVariants']['nodes'] ?? null,
			$res['data']['productVariants']['pageInfo'] ?? null
		);
	}

}
