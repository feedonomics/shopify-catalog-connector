<?php
namespace ShopifyConnector\connectors\shopify\structs;

use DateTimeImmutable;

/**
 * Struct for a Shopify date range
 */
final class DateRange
{
	/**
	 * Struct for a Shopify date range
	 *
	 * @param DateTimeImmutable $start The start date
	 * @param DateTimeImmutable $end The end date
	 */
	public function __construct(
		public DateTimeImmutable $start,
		public DateTimeImmutable $end
	)
	{
	}

}
