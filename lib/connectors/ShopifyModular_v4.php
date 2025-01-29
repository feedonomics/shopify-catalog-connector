<?php

namespace ShopifyConnector\connectors;


class ShopifyModular_v4 extends ShopifyModular {

	public function __construct(array $client_options = []){
		$client_options['fields'] = $client_options['fields'] ?? [
			'item_group_id',
			'parent_title',
			'description',
			'brand',
			'product_type',
			'link',
			'tags',
			'id',
			'product_id',
			'child_title',
			'price',
			'sale_price',
			'sku',
			'fulfillment_service',
			'requires_shipping',
			'taxable',
			'gtin',
			'inventory_quantity',
			'inventory_management',
			'inventory_policy',
			'availability',
			'shipping_weight',
			'image_link',
			'additional_image_link',
			'published_status',
			'color',
			'size',
			'material'
		];

		$client_options['delimiter'] = ',';
		$client_options['enclosure'] = '"';
		$client_options['escape'] = "\\";
		$client_options['strip_characters'] = [];

		parent::__construct($client_options, []);
	}


	protected function get_image_link(array $product, array $variant) : string {
		$images = $product['images'] ?? [];
		foreach ($images as $image) {
			if (!$image['src']) {
				continue;
			}
			if (
				$image['id'] == $variant['image_id']
				|| (
						!empty($image['variant_ids'])
						&& in_array($variant['id'], $image['variant_ids'])
					)
			) {
				return $image['src'];
			}
		}
		return '';
	}


	protected function get_additional_image_links(array $product, array $variant) : string {
		$image_links = [];
		$images = $product['images'] ?? [];

		foreach ($images as $image) {
			if (!isset($image['src']) || $image['src'] == '') {
				continue;
			}
			$image_links[] = $image['src'];
		}

		return implode('@@@', $image_links);
	}

}

