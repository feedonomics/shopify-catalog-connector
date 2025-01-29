<?php

namespace ShopifyConnector\connectors;


class ShopifyModular_v2 extends ShopifyModular {

	public function __construct(array $client_options = []){
		$client_options['fields'] = $client_options['fields'] ?? [
				'parent_id',
				'parent_title',
				'body-html',
				'vendor',
				'product-type',
				'handle',
				'published-scope',
				'tags',
				'id',
				'product-id',
				'child-title',
				'price',
				'sku',
				'position',
				'grams',
				'inventory-policy',
				'compare-at-price',
				'fulfillment-service',
				'inventory-management',
				'option1',
				'option2',
				'option3',
				'requires-shipping',
				'taxable',
				'barcode',
				'inventory-quantity',
				'old-inventory-quantity',
				'weight',
				'weight-unit',
				'image_links',
				'parent_image_links',
		];

		$client_options['field_mapping'] = [
			'parent_id'              => 'item_group_id',
			'body-html'              => 'description',
			'vendor'                 => 'brand',
			'product-type'           => 'product_type',
			'published-scope'        => 'published_scope',
			'product-id'             => 'product_id',
			'child-title'            => 'child_title',
			'inventory-policy'       => 'inventory_policy',
			'compare-at-price'       => 'compare_at_price',
			'fulfillment-service'    => 'fulfillment_service',
			'inventory-management'   => 'inventory_management',
			'requires-shipping'      => 'requires_shipping',
			'barcode'                => 'gtin',
			'inventory-quantity'     => 'inventory_quantity',
			'old-inventory-quantity' => 'old_inventory_quantity',
			'weight-unit'            => 'weight_unit',
			'image_links'            => 'image_link',
			'parent_image_links'     => 'additional_image_link',
		];

		parent::__construct($client_options, []);
	}


	protected function get_image_link(array $product, array $variant) : string {
		$images = $product['images'] ?? [];
		foreach ($images as $image) {
			if (!$image['src']) {
				continue;
			}

			if (!empty($image['variant_ids'])) {
				if (in_array($variant['id'], $image['variant_ids'])) {
					return $image['src'];
				}
			}
		}
		return '';
	}


	protected function get_additional_image_links(array $product, array $variant) : string {
		$image_links = [];
		$images = $product['images'] ?? [];

		foreach ($images as $image) {
			if (!$image['src']) {
				continue;
			}
			$image_links[] = $image['src'];
		}

		return implode('@@@', $image_links);
	}

}

