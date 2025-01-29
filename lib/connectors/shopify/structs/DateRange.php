<?php
namespace ShopifyConnector\connectors\shopify\structs;

use DateTimeImmutable;

/**
 * Struct for a Shopify date range
 */
final class DateRange
{

	/**
	 * @var DateTimeImmutable Store for the start date
	 */
	public DateTimeImmutable $start;

	/**
	 * @var DateTimeImmutable Store for the end date
	 */
	public DateTimeImmutable $end;

	/**
	 * Struct for a Shopify date range
	 *
	 * @param DateTimeImmutable $start The start date
	 * @param DateTimeImmutable $end The end date
	 */
	public function __construct(DateTimeImmutable $start, DateTimeImmutable $end)
	{
		$this->start = $start;
		$this->end = $end;
	}

	/**
	 * Get formatted string for the start of this date range.
	 * The default format is DateTime*::ATOM.
	 *
	 * @return string Formatted start string
	 */
	public function getFmtStart() : string
	{
		return $this->start->format(DateTimeImmutable::ATOM);
	}

	/**
	 * Get formatted string for the end of this date range.
	 * The default format is DateTime*::ATOM.
	 *
	 * @return string Formatted end string
	 */
	public function getFmtEnd() : string
	{
		return $this->end->format(DateTimeImmutable::ATOM);
	}

}
