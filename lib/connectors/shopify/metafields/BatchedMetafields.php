<?php

namespace ShopifyConnector\connectors\shopify\metafields;

use ShopifyConnector\connectors\shopify\graphql\GraphQLQuery;
use ShopifyConnector\connectors\shopify\graphql\GraphQLQueryResponseData;
use ShopifyConnector\connectors\shopify\models\GID;
use ShopifyConnector\connectors\shopify\pullers\BatchedBase;
use ShopifyConnector\connectors\shopify\structs\BulkProcessingResult;
use ShopifyConnector\connectors\shopify\structs\DateRange;

use ShopifyConnector\exceptions\ApiResponseException;
use ShopifyConnector\exceptions\InfrastructureErrorException;

use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\queries\BatchedDataInserter;

/**
 * Batched GraphQL puller for Shopify metafields.
 */
class BatchedMetafields extends BatchedBase
{
	/**
	 * @param MysqliWrapper $cxn
	 * @param BatchedDataInserter $insert_product
	 * @param BatchedDataInserter $insert_variant
	 * @return BulkProcessingResult
	 * @throws InfrastructureErrorException
	 * @throws ApiResponseException
	 */
	public function pull(
		MysqliWrapper $cxn,
		BatchedDataInserter $insert_product,
		BatchedDataInserter $insert_variant
	) : BulkProcessingResult
	{
		$metafield_storage = new MetafieldTemporaryStorage(
			$insert_product,
			$insert_variant,
		);

		foreach ($this->run_batch() as $query_response_data) {
			$this->process_response_page($cxn, $metafield_storage, $query_response_data);
		}

		$result = new BulkProcessingResult();
		$result->result = $metafield_storage->get_unique_metafield_names($this->session->settings->metafields_split_columns);

		return $result;
	}

	/**
	 * Query for metafields in a given date range with specific a set number of each data type.
	 *
	 * @param DateRange $date_range
	 * @return GraphQLQuery
	 */
	protected function build_query_for_range(DateRange $date_range) : GraphQLQuery
	{
		return (new MetafieldsQuery(
			$this->session->settings->product_filters,
			$this->session->settings->meta_filters,
		))
			->with_products($date_range)
			->with_product_metafields()
			->with_variants()
			->with_variant_metafields();
	}

	/**
	 * @param MysqliWrapper $cxn
	 * @param MetafieldTemporaryStorage $metafield_storage
	 * @param GraphQLQueryResponseData $response_data
	 * @return void
	 * @throws ApiResponseException
	 * @throws InfrastructureErrorException
	 */
	private function process_response_page(
		MysqliWrapper $cxn,
		MetafieldTemporaryStorage $metafield_storage,
		GraphQLQueryResponseData $response_data
	) : void
	{
		foreach ($response_data->get_data()['products']['edges'] as ['node' => $product]) {
			$product_id = new GID($product['id']);

			$has_product_metafields = !empty($product['metafields']['edges']);
			if (!$has_product_metafields) {
				$metafield_storage->add_product_metafield_placeholder($cxn, $product_id);
			}

			foreach ($product['metafields']['edges'] as ['node' => $metafield]) {
				$metafield_storage->add_product_metafield($cxn, $product_id, $metafield);
			}

			foreach ($product['variants']['edges'] as ['node' => $variant]) {
				$variant_id = new GID($variant['id']);

				$has_variant_metafields = !empty($variant['metafields']['edges']);
				if (!$has_variant_metafields) {
					$metafield_storage->add_variant_metafield_placeholder($cxn, $product_id, $variant_id);
				}

				foreach ($variant['metafields']['edges'] as ['node' => $metafield]) {
					$metafield_storage->add_variant_metafield($cxn, $product_id, $variant_id, $metafield);
				}
			}
		}

		$metafield_storage->end_batch($cxn);
	}
}