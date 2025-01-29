<?php

namespace ShopifyConnector\connectors\shopify\pullers;

use ShopifyConnector\connectors\shopify\interfaces\iDataList;
use ShopifyConnector\connectors\shopify\PullParams;
use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\models\Product;
use ShopifyConnector\connectors\shopify\models\ProductVariant;
use ShopifyConnector\connectors\shopify\services\ProductService;
use ShopifyConnector\connectors\shopify\structs\PullStats;
use ShopifyConnector\util\db\ConnectionFactory;
use ShopifyConnector\util\db\queries\InsertStatement;
use ShopifyConnector\exceptions\InfrastructureErrorException;
use ShopifyConnector\util\io\DataUtilities;

/**
 * Puller responsible for retrieving Shopify products and variants
 *
 * @deprecated Needs work if wanting to use after changes
 */
final class ProductPuller extends ShopifyPuller
{

	/**
	 * @var string[] Store for the list of product fields being pulled
	 */
	private array $dbFieldsProduct;

	/**
	 * @var string[] Store for the list of variant fields being pulled
	 */
	private array $dbFieldsVariant;

	/**
	 * Puller responsible for retrieving Shopify products and variants
	 *
	 * @param SessionContainer $session The session container
	 */
	public function __construct(SessionContainer $session)
	{
		parent::__construct(
			$session,
			[ProductService::class, 'getProducts'],
			new PullParams([
				#'limit' => 10
			], $session->settings->product_filters->get_filters_rest())
		);

		$this->dbFieldsProduct = array_unique(
			DataUtilities::translate_values(
				Product::DEFAULT_OUTPUT_FIELDS,
				Product::FIELD_NAME_MAP
			)
		);
		$this->dbFieldsVariant = array_unique(ProductVariant::DEFAULT_OUTPUT_FIELDS);
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
			$this->storeProductData($inserter, $this->pullPage(), $stats);
			++$stats->pages;
		}
	}

	/**
	 * Pull and store product fields in the DB
	 *
	 * @param InsertStatement $inserter The insert statement
	 * @param iDataList $products The product list
	 * @param PullStats $stats Pull stats to update
	 * @throws InfrastructureErrorException On DB errors
	 */
	private function storeProductData(
		InsertStatement $inserter,
		iDataList $products,
		PullStats $stats
	) : void
	{
		# Create new connection here to isolate behavior
		$cxn = ConnectionFactory::connect(ConnectionFactory::DB_LOCAL);

		$paramsProd = [
			'mfSplit' => false, # TODO: Where will we do splitting?
			'extra_fields' => [] # TODO: Get from settings
		];
		$paramsVar = [
			'domain' => 'todo', # TODO: Need to work shop into settings?
			'mfSplit' => false, # TODO: See prod's mfSplit
			'extra_fields' => [] # TODO: Get from settings
		];

		foreach ($products->getItems() as $prod) {

			# {@see Product::getVariants_old()} for a note about options when a
			# product is missing its expected variants data
			foreach ($prod->getVariants_old() as $var) {

				$cxn->safe_query(
					$inserter->get_upsert_query($cxn, array_merge(
						$var->get_output_data($paramsVar, $this->dbFieldsVariant),
						$prod->get_output_data($paramsProd, $this->dbFieldsProduct)
					))
				);

				++$stats->variants; # Count even if product w/o variant
			}

			++$stats->products;
		}

		$cxn->close();
	}

	/**
	 * @inheritDoc
	 */
	public function getColumns() : array
	{
		return array_merge(
			$this->dbFieldsProduct,
			$this->dbFieldsVariant
		);
	}

}

