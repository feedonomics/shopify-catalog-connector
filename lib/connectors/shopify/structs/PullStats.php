<?php

namespace ShopifyConnector\connectors\shopify\structs;

/**
 * Tracking statistics to use when pulling
 */
class PullStats
{

	/**
	 * @var int Counter for how many pages have been pulled
	 */
	public int $pages = 0;

	/**
	 * @var int Counter for how many products have been pulled
	 */
	public int $products = 0;

	/**
	 * @var int Counter for how many variants have been pulled
	 */
	public int $variants = 0;

	/**
	 * @var int Counter for how many warning/notice issues have been encountered
	 */
	public int $warnings = 0;

	/**
	 * @var int Counter for how many general errors have been encountered
	 */
	public int $general_errors = 0;

	/**
	 * @var int Counter for how many product errors have been encountered
	 */
	public int $product_errors = 0;

	/**
	 * @var int Counter for how many variant errors have been encountered
	 */
	public int $variant_errors = 0;

}

