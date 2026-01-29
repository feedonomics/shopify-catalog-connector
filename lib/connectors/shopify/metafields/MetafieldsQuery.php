<?php

namespace ShopifyConnector\connectors\shopify\metafields;

use ShopifyConnector\connectors\shopify\GenericFilterManager;
use ShopifyConnector\connectors\shopify\graphql\CursorManager;
use ShopifyConnector\connectors\shopify\graphql\GraphQLQuery;
use ShopifyConnector\connectors\shopify\graphql\GraphQLQueryHelper;
use ShopifyConnector\connectors\shopify\products\ProductFilterManager;
use ShopifyConnector\connectors\shopify\structs\DateRange;

/**
 * Generates the GraphQL query for pulling product metafields
 */
class MetafieldsQuery implements GraphQLQuery
{
	use GraphQLQueryHelper;

	/**
	 * @var array The list of query terms for products
	 */
	private array $products_query_terms = [];

	/**
	 * @var array The list of search terms for products
	 */
	private array $products_search_terms = [];

	/**
	 * @var array The list of search terms for product metafields
	 */
	private array $products_meta_search_terms = [];

	/**
	 * @var array The list of search terms for variants
	 */
	private array $variant_search_terms = [];

	/**
	 * @var array The list of search terms for variant metafields
	 */
	private array $variant_meta_search_terms = [];

	/**
	 * @var GenericFilterManager The variant filter manager
	 */
	private readonly GenericFilterManager $variant_filters;

	/**
	 * @var CursorManager The cursor manager
	 */
	private readonly CursorManager $cursor_manager;

	/**
	 * @param ProductFilterManager $product_filters
	 * @param MetaFilterManager $meta_filters
	 */
	public function __construct(
		private readonly ProductFilterManager $product_filters,
		private readonly MetaFilterManager    $meta_filters,
	)
	{
		$this->variant_filters = new GenericFilterManager();
		$this->cursor_manager = new CursorManager();
	}

	/**
	 * The map of the query response for navigation
	 *
	 * @return array
	 */
	public function get_connection_map() : array
	{
		return [
			'connection' => ['products'],
			'cursor_name' => 'products',
			'children' => [
				[
					'connection' => ['metafields'],
					'cursor_name' => 'product_metafields',
				],
				[
					'connection' => ['variants'],
					'cursor_name' => 'variants',
					'children' => [
						[
							'connection' => ['metafields'],
							'cursor_name' => 'variant_metafields',
						]
					]
				]
			]
		];
	}

	/**
	 * @inheritDoc
	 */
	public function generate_for_bulk_operation() : string
	{
		$products_search_str = $this->product_filters->get_filters_gql(
			$this->products_query_terms,
			$this->products_search_terms,
		);

		$products_meta_search_str = $this->meta_filters->get_filters_gql(
			[],
			$this->products_meta_search_terms,
		);

		$variants_search_str = $this->variant_filters->get_filters_gql(
			[],
			$this->variant_search_terms,
		);

		$variants_meta_search_str = $this->meta_filters->get_filters_gql(
			[],
			$this->variant_meta_search_terms,
		);

		return $this->generate(
			$products_search_str,
			$products_meta_search_str,
			$variants_search_str,
			$variants_meta_search_str
		);
	}

	/**
	 * @inheritDoc
	 */
	public function generate_for_graphql() : string
	{
		$product_search_terms = $this->products_search_terms;
		if ($this->cursor_manager->has('products')) {
			$product_search_terms[] = $this->after($this->cursor_manager->get('products'));
		}

		$product_meta_search_terms = $this->products_meta_search_terms;
		if ($this->cursor_manager->has('product_metafields')) {
			$product_meta_search_terms[] = $this->after($this->cursor_manager->get('product_metafields'));
		}

		$variant_search_terms = $this->variant_search_terms;
		if ($this->cursor_manager->has('variants')) {
			$variant_search_terms[] = $this->after($this->cursor_manager->get('variants'));
		}

		$variant_meta_search_terms = $this->variant_meta_search_terms;
		if ($this->cursor_manager->has('variant_metafields')) {
			$variant_meta_search_terms[] = $this->after($this->cursor_manager->get('variant_metafields'));
		}

		$products_search_str = $this->product_filters->get_filters_for_graphql(
			$this->products_query_terms,
			$product_search_terms
		);

		$products_meta_search_str = $this->meta_filters->get_filters_gql(
			[],
			$product_meta_search_terms,
		);

		$variants_search_str = $this->variant_filters->get_filters_gql(
			[],
			$variant_search_terms,
		);

		$variants_meta_search_str = $this->meta_filters->get_filters_gql(
			[],
			$variant_meta_search_terms,
		);

		return $this->generate(
			$products_search_str,
			$products_meta_search_str,
			$variants_search_str,
			$variants_meta_search_str,
			$this->page_info()
		);
	}

	/**
	 * Generates the actual query string filling in blanks where needed
	 *
	 * @param string $products_search_str
	 * @param string $products_meta_search_str
	 * @param string $variants_search_str
	 * @param string $variants_meta_search_str
	 * @param string $page_info
	 * @return string
	 */
	private function generate(
		string $products_search_str,
		string $products_meta_search_str,
		string $variants_search_str,
		string $variants_meta_search_str,
		string $page_info = '',
	) : string
	{

		return <<<GQL
			products$products_search_str {
				$page_info
				edges {
					node {
						id
						metafields$products_meta_search_str {
							$page_info
							edges {
								node {
									id
									key
									value
									namespace
									description
								}
							}
						}
						variants$variants_search_str {
							$page_info
							edges {
								node {
									id
									metafields$variants_meta_search_str {
										$page_info
										edges {
											node {
												id
												key
												value
												namespace
												description
											}
										}
									}
								}
							}
						}
					}
				}
			}
			GQL;
	}

	/**
	 * @param DateRange $in_range
	 * @param int $products
	 * @return $this
	 */
	public function with_products(DateRange $in_range, int $products = 250) : static
	{
		$this->products_query_terms[] = $this->in_date_range('created_at', $in_range);
		$this->products_search_terms[] = $this->first(min($products, 250));
		$this->products_search_terms[] = $this->sort_key('CREATED_AT');
		return $this;
	}

	/**
	 * @param int $metafields
	 * @return $this
	 */
	public function with_product_metafields(int $metafields = 50) : static
	{
		$this->products_meta_search_terms[] = $this->first($metafields);
		return $this;
	}

	/**
	 * @param int $int
	 * @return $this
	 */
	public function with_variants(int $int = 50) : static
	{
		$this->variant_search_terms[] = $this->first($int);
		return $this;
	}

	/**
	 * @param int $metafields
	 * @return $this
	 */
	public function with_variant_metafields(int $metafields = 50) : static
	{
		$this->variant_meta_search_terms[] = $this->first($metafields);
		return $this;
	}

	/**
	 * @param string $cursor_name
	 * @param string $cursor_value
	 * @return $this
	 */
	public function with_cursor(
		string $cursor_name,
		string $cursor_value,
	) : static
	{
		$this->cursor_manager->set($cursor_name, $cursor_value);
		return $this;
	}

}