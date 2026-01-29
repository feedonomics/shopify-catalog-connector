<?php

namespace ShopifyConnector\connectors\shopify\pullers;

use DateInterval;
use DateTimeImmutable;
use ShopifyConnector\connectors\shopify\DateRangeGenerator;
use ShopifyConnector\connectors\shopify\structs\DateRange;
use ShopifyConnector\exceptions\InfrastructureErrorException;
use Generator;

/**
 * Given a total object count, partition size, and a date range, generates date ranges into the largest time interval
 * possible to keep the number of partitions at or below the partition size.
 */
class DateRangePartitioner
{
	private DateRangeGenerator $generator;

	/**
	 * @throws InfrastructureErrorException
	 */
	public function __construct(
		int $total_object_count,
		int $partition_size,
		int $start_timestamp,
		int $end_timestamp,
	)
	{
		$start_time = (new DateTimeImmutable('@'.$start_timestamp))
			->setTime(0, 0);

		$end_time = (new DateTimeImmutable('@'.$end_timestamp))
			->add(new DateInterval('P1D'))
			->setTime(0, 0);

		$days_between = $start_time->diff($end_time)->days;

		// Our target based on partition size
		$desired_partitions = (int) ceil(max($total_object_count, 1) / max($partition_size, 1));

		// Number of days in each partition
		$days_in_partition = max(1, ceil($days_between / $desired_partitions));

		$this->generator = new DateRangeGenerator(
			$start_time->format('Y-m-d'),
			$end_time->format('Y-m-d'),
			new DateInterval(sprintf('P%dD', $days_in_partition))
		);
	}

	/**
	 * Allow for simpler iteration with foreach
	 *
	 * @return Generator<DateRange>
	 */
	public function iterate() : Generator
	{
		while ($next = $this->generator->getNext()) {
			yield $next;
		}
	}
}