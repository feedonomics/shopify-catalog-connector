<?php
namespace ShopifyConnector\connectors\shopify\pullers;

use ShopifyConnector\connectors\shopify\interfaces\iDataList;
use ShopifyConnector\connectors\shopify\PullParams;
use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\models\GID;
use ShopifyConnector\connectors\shopify\services\ProductVariantService;
use ShopifyConnector\connectors\shopify\structs\PullStats;
use ShopifyConnector\exceptions\api\UnexpectedResponseException;
use ShopifyConnector\util\db\ConnectionFactory;
use ShopifyConnector\util\db\queries\InsertStatement;
use ShopifyConnector\exceptions\InfrastructureErrorException;
use JsonException;

/**
 * Puller responsible for retrieving Shopify categories
 */
final class ProductCategoryPuller extends ShopifyPuller
{

	/**
	 * Puller responsible for retrieving Shopify categories
	 *
	 * @param SessionContainer $session The session container
	 */
	public function __construct(SessionContainer $session)
	{
		parent::__construct(
			$session,
			[ProductVariantService::class, 'getCategories'],
			new PullParams([
			], [
				'limit' => 50,
				# TODO: Get from ProductFilters
				'published_status' => 'published',
			])
		);
	}

	/**
	 * @inheritDoc
	 */
	public function pullAndStoreData(
		InsertStatement $inserter,
		PullStats $stats
	) : void
	{
		while ($this->hasNextPage()) {
			$this->storeCategoryData($inserter, $this->pullPage(), $stats);
			++$stats->pages;
		}
	}

	/**
	 * Take a list of variant categories from an API call and write them to the
	 * database
	 *
	 * @param InsertStatement $inserter The insert statement to write with
	 * @param iDataList $variants The list of variant category data
	 * @param PullStats $stats Pull stats for tracking
	 * @throws UnexpectedResponseException On invalid API responses
	 * @throws InfrastructureErrorException On DB errors
	 */
	private function storeCategoryData(
		InsertStatement $inserter,
		iDataList $variants,
		PullStats $stats
	) : void
	{
		$cxn = ConnectionFactory::connect(ConnectionFactory::DB_LOCAL);

		foreach ($variants->getItems(true) as $rawVar) {
			$gid = $rawVar['id'] ?? null;
			$ptn = $rawVar['product']['productCategory']['productTaxonomyNode'] ?? null;

			# $ptn === null is not an error, only $id
			if ($gid === null) {
				++$stats->variant_errors;
				continue;
			}

			$gid = new GID($gid);

			try {
				$cxn->safe_query($inserter->get_upsert_query($cxn, [
					'id' => $gid->get_id(),
					'productTaxonomyNode' => json_encode($ptn, JSON_THROW_ON_ERROR),
				]));
			} catch(JsonException $e){
				throw new UnexpectedResponseException(
					'Shopify',
					'Invalid product taxonomy node. Received: %s' . $ptn
				);
			}

			++$stats->variants;
		}

		$cxn->close();
	}

	/**
	 * @inheritDoc
	 */
	public function getColumns() : array
	{
		return ['id', 'productTaxonomyNode'];
	}

}
