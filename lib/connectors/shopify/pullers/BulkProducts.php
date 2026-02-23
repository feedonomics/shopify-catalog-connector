<?php

namespace ShopifyConnector\connectors\shopify\pullers;

use ShopifyConnector\connectors\shopify\models\Field;
use ShopifyConnector\connectors\shopify\models\GID;
use ShopifyConnector\connectors\shopify\products\Products;
use ShopifyConnector\connectors\shopify\products\ProductFilterManager;
use ShopifyConnector\connectors\shopify\structs\BulkProcessingResult;

use ShopifyConnector\exceptions\ApiResponseException;
use ShopifyConnector\exceptions\InfrastructureErrorException;

use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\queries\BatchedDataInserter;

/**
 * Bulk GraphQL puller for Shopify products.
 */
class BulkProducts extends BulkBase
{
	/**
	 * @inheritDoc
	 */
	public function get_query() : string
	{
		$prod_search_str = $this->session->settings->product_filters->get_filters_gql();

		# TODO: Handle limited/extra fields

		# Initially, these queries attempt to simply recreate the set of data
		# that was pulled through the REST API. The "id" fields will always be
		# included in the queries and should not be added to the fields arrays.

		# Comments on fields note their names in the REST API when different
		$product_fields = [
			'descriptionHtml', # body_html
			'createdAt', # created_at
			'handle',
			'media', # images
			'options',
			'productType', # product_type
			'publishedAt', # published_at
			'status',
			'tags',
			'templateSuffix', # template_suffix
			'title',
			'updatedAt', # updated_at
			'vendor',
		];

		# Comments on fields note their names in the REST API when different
		$variant_fields = [
			'barcode',
			'compareAtPrice', # compare_at_price
			'createdAt', # created_at
			'fulfillmentService', # fulfillment_service
			'image', # image_id
			'inventoryItem', # inventory_item_id
			'inventoryLevel', # inventory_management
			'inventoryPolicy', # inventory_policy
			'inventoryQuantity', # inventory_quantity
			'selectedOptions', # option
			'contextualPricing', # presentment_prices
			'position',
			'price',
			'product', # product_id
			'sku',
			'taxable',
			'title',
			'updatedAt', # updated_at
			'weight', # (weight_unit now a part of this field)
		];

		# The arrays above were set up for reference and to potentially expand on, but
		# since many fields have sub-parts and that would take more logic to put together
		# dynamically, the query below has fields hardcoded for now.
		#
		# The fields in the query below are a starting point, but may not be exactly what
		# we want ultimately.
		$allowed_extra_parent_fields = '';
		if ($this->session->settings->extra_parent_fields) {
			foreach ($this->session->settings->extra_parent_fields as $field) {
				$allowed_fields = [
					'status',
					'bodyHtml',
				];
				if (in_array($field, $allowed_fields)) {
					$allowed_extra_parent_fields .= "{$field}\n";
				}
			}
			$allowed_extra_parent_fields = trim($allowed_extra_parent_fields);
		}
		$media_filter = '(query: "media_type:IMAGE")';

		$fragments = [];
		if (!empty($this->session->settings->contextual_pricing_countries)) {
			foreach ($this->session->settings->get_contextual_pricing_country_aliases() as $value => $alias) {
				$fragments[] = <<<GQL
					{$alias}: contextualPricing(context: { country: {$value} }) {
						price {
							amount
							currencyCode
						}
						compareAtPrice {
							amount
							currencyCode
						}
					}
				GQL;
			}
		}

		if (!empty($this->session->settings->contextual_pricing_locations)) {
			foreach ($this->session->settings->get_contextual_pricing_location_aliases() as $value => $alias) {
				$fragments[] = <<<GQL
					{$alias}: contextualPricing(context: { locationId: "{$value}" }) {
						price {
							amount
							currencyCode
						}
						compareAtPrice {
							amount
							currencyCode
						}
					}
				GQL;
			}
		}

		$contextual_pricing = implode("\n", $fragments);

		$presentment_prices = '';
		if ($this->session->settings->include_presentment_prices) {
			$currency_filters = $this->session->settings->product_filters->get(ProductFilterManager::FILTER_PRESENTMENT_CURRENCIES);
			$currency_filter_str = empty($currency_filters) ? '' : "(presentmentCurrencies: [{$currency_filters}])";
			$presentment_prices = <<<GQL
				presentmentPrices {$currency_filter_str} {
					edges {
						node {
							price {
								currencyCode
								amount
							}
							compareAtPrice {
								currencyCode
								amount
							}
						}
					}
				}
			GQL;
		}

		$publications = '';
		if ($this->session->get_access_scopes()->has_scope('read_publications')) {
			$publications = <<<GQL
				resourcePublications {
					edges {
						node {
							isPublished
							publication {
								id
								name
								catalog {
									title
								}
							}
						}
					}
				}
			GQL;
		}

		//variant.taxCode is deprecated as of version 2025-10
		//https://shopify.dev/changelog/deprecation-of-tax-code-field
		//https://shopify.dev/docs/api/admin-graphql/latest/objects/ProductVariant#field-ProductVariant.fields.taxCode

		return <<<GQL
			products{$prod_search_str} {
				edges {
					node {
						id
						legacyResourceId

						createdAt
						description
						descriptionHtml
						handle

						media(query: "media_type:IMAGE") {
							edges {
								node {
									id
									mediaContentType
									preview {
										image {
											altText
											height
											width
											url
										}
										status
									}
								}
							}
						}

						onlineStorePreviewUrl
						options {
							name
							position
							values
						}
						productType
						publishedAt
						seo {
							description
							title
						}
						tags
						templateSuffix
						title
						updatedAt
						vendor

						{$publications}
						{$allowed_extra_parent_fields}

						variants {
							edges {
								node {
									id
									legacyResourceId
									{$contextual_pricing}
									{$presentment_prices}
									availableForSale
									barcode
									createdAt
									displayName

									image {
										id
										altText
										height
										width
										url
									}

									inventoryItem {
										id
										measurement {
											weight {
												unit
												value
											}
										}
										requiresShipping
										sku
										tracked
										unitCost {
											amount
											currencyCode
										}
									}

									inventoryQuantity
									inventoryPolicy

									position
									price
									compareAtPrice
									selectedOptions {
										name
										value
									}
									sellableOnlineQuantity
									sku
									taxable
									taxCode
									title
									updatedAt
								}
							}
						}
					}
				}
			}
			GQL;
	}


	/**
	 * This method normalizes either a parentID or the most recent product ID - this is a workaround for a variation.
	 *
	 * @throws ApiResponseException
	 * @return string
	 */
	private function generateVariantParent(array $decoded, string $defaultParentID): string
	{
		if (!empty($decoded['__parentId'])) {
			return (new GID($decoded['__parentId']))->get_id();
		}

		return $defaultParentID;
	}


	/**
	 * @inheritDoc
	 * @throws ApiResponseException
	 * @throws InfrastructureErrorException
	 * @throws \JsonException
	 */
	public function process_bulk_file(
		string $filename,
		BulkProcessingResult $result,
		MysqliWrapper $cxn,
		?BatchedDataInserter $insert_product,
		?BatchedDataInserter $insert_variant
	) : void {
		$pull_stats = $this->session->pull_stats[Products::MODULE_NAME];
		$fh = $this->checked_open_file($filename);

		try {
			$product_data = null;
			$variant_data = null;
			$variant_names = [];

			while (!feof($fh)) {
				$line = $this->checked_read_line($fh);
				if ($line === null) {
					break;
				}

				$decoded = json_decode($line, true, 128, JSON_THROW_ON_ERROR);

				if (empty($decoded['id'])) {
					if (!empty($decoded['publication']['id'])) {
						$gid = new GID($decoded['publication']['id']);
					} elseif (!empty($decoded['__parentId'])) {
						$gid = new GID($decoded['__parentId']);
						if ($gid->is_variant()) {
							unset($decoded['__parentId']);
							$variant_data['presentment_prices'][] = $decoded;
						}
						continue;
					} else {
						throw new InfrastructureErrorException(
							$this->get_error_message(
								'Unexpected format in bulk products response (gid); declining to continue',
							)
						);
						//++$pull_stats->general_errors;
						//continue;
					}
				} else {
					$gid = new GID($decoded['id']);
				}

				if ($gid->is_product()) {
					// Onto the next product, commit finished product, if present
					if ($product_data !== null) {
						$insert_product->add_value_set($cxn, [
							Products::COLUMN_ID => $product_data['id'],
							Products::COLUMN_DATA => json_encode($product_data),
						]);
						++$pull_stats->products;

						// Also commit finished variant, if present
						if ($variant_data !== null) {
							$insert_variant->add_value_set($cxn, [
								Products::COLUMN_ID => $variant_data['id'],
								Products::COLUMN_PARENT_ID => $this->generateVariantParent($variant_data, $product_data['id']),
								Products::COLUMN_DATA => json_encode($variant_data),
							]);
							++$pull_stats->variants;
						}
					}

					$variant_data = null;
					$product_data = $decoded;
					$product_data['id'] = $gid->get_id();
					$product_data['media'] = [];
				} elseif ($gid->is_variant()) {
					if ($product_data === null) {
						// Encountered a variant before a product. This really shouldn't
						// happen, so would indicate something pretty weird is going on
						throw new InfrastructureErrorException(
							$this->get_error_message(
								'Unexpected format in bulk products response (v); declining to continue',
							)
						);
					}

					// Onto the next variant; commit finished variant, if present
					if ($variant_data !== null) {
						$insert_variant->add_value_set($cxn, [
							Products::COLUMN_ID => $variant_data['id'],
							Products::COLUMN_PARENT_ID => $this->generateVariantParent($variant_data, $product_data['id']),
							Products::COLUMN_DATA => json_encode($variant_data),
						]);
						++$pull_stats->variants;
					}

					$variant_data = $decoded;
					$variant_data['id'] = $gid->get_id();
					$variant_data['media'] = [];

					if ($this->session->settings->variant_names_split_columns) {
						foreach ($variant_data['selectedOptions'] ?? [] as $variant_option) {
							$identifier = 'variant_' . strtolower($variant_option['name']);
							//$identifier = ShopifyUtilities::clean_column_name($identifier, '_');
							$variant_names[$identifier] = true;
						}
					}
				} elseif ($gid->is_media()) {
					if ($product_data === null) {
						// Encountered a media before a product. This really shouldn't
						// happen, so would indicate something pretty weird is going on
						throw new InfrastructureErrorException(
							$this->get_error_message(
								'Unexpected format in bulk products response (m); declining to continue',
							)
						);
					}

					// TODO: Should make a "Media" model at some point -- just doing
					//   this in-place for now in the interest of simplicity
					$media_data = [
						'height' => $decoded['preview']['image']['height'] ?? null,
						'width' => $decoded['preview']['image']['height'] ?? null,
						// "src" for compatibility; should switch to using "url" naming
						'src' => $decoded['preview']['image']['url'] ?? null,
						'altText' => $decoded['preview']['image']['altText'] ?? null,
					];

					if ($media_data['src'] === null) {
						continue;
					}

					if ($variant_data !== null) {
						$variant_data['media'][] = $media_data;
					} else {
						$product_data['media'][] = $media_data;
					}
				} elseif ($gid->is_publication()) {
					if ($product_data === null) {
						// Encountered a publication before a product. This really shouldn't
						// happen, so would indicate something pretty weird is going on
						throw new InfrastructureErrorException(
							$this->get_error_message(
								'Unexpected format in bulk products response (m); declining to continue',
							)
						);
					} else {
						$product_data[Field::PUBLICATIONS->value][] = $decoded['publication'];
					}
				} else {
					# Not a type we were expecting.
					# I guess just silently skip...
					++$pull_stats->warnings;
				}
			}

			// Store the last product and variant datas
			if ($product_data !== null) {
				$insert_product->add_value_set($cxn, [
					Products::COLUMN_ID => $product_data['id'],
					Products::COLUMN_DATA => json_encode($product_data),
				]);
				++$pull_stats->products;
			}
			if ($variant_data !== null) {
				$insert_variant->add_value_set($cxn, [
					Products::COLUMN_ID => $variant_data['id'],
					Products::COLUMN_PARENT_ID => $this->generateVariantParent($variant_data, $product_data['id']),
					Products::COLUMN_DATA => json_encode($variant_data),
				]);
				++$pull_stats->variants;
			}

			// Commit anything remaining in the batched inserters
			$insert_product->run_query($cxn);
			$insert_variant->run_query($cxn);
		} finally {
			fclose($fh);
		}

		$result->result = array_values(array_unique(array_merge(
			$result->result,
			array_keys($variant_names),
		)));
	}
}