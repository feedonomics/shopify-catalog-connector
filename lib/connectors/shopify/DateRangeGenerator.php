<?php
namespace ShopifyConnector\connectors\shopify;

use DateTimeImmutable;
use DateInterval;
use ShopifyConnector\connectors\shopify\structs\DateRange;
use ShopifyConnector\exceptions\InfrastructureErrorException;
use ShopifyConnector\log\ErrorLogger;

/**
 * Manager for splitting date ranges into chunks
 *
 * ```php
 * // Example usage
 * $drg = new DateRangeGenerator(
 *   new DateTimeImmutable('2000-01-01'),
 *   new DateTimeImmutable('2001-01-01'),
 *   new DateInterval(P1M)
 * );
 *
 * while($drg->hasNext()){
 *   echo $drg->getNext;
 * }
 * // Output
 * // 2000-01-01 - 2000-02-01
 * // 2000-02-01 - 2000-03-01
 * // 2000-03-01 - 2000-04-01
 * // 2000-04-01 - 2000-05-01
 * // 2000-05-01 - 2000-06-01
 * // 2000-06-01 - 2000-07-01
 * // 2000-07-01 - 2000-08-01
 * // 2000-08-01 - 2000-09-01
 * // 2000-09-01 - 2000-010-01
 * // 2000-10-01 - 2000-011-01
 * // 2000-11-01 - 2000-012-01
 * // 2000-12-01 - 2001-01-01
 * ```
 */
final class DateRangeGenerator
{

	/**
	 * @var DateTimeImmutable Store for the date cursor, beginning at the start
	 * date passed in the constructor
	 */
	private DateTimeImmutable $cursor;

	/**
	 * @var DateTimeImmutable Store for the end date
	 */
	private DateTimeImmutable $end;

	/**
	 * @var DateInterval Store for the time increment
	 */
	private DateInterval $step;

	/**
	 * Manager for splitting date ranges into chunks
	 *
	 * @param string $start The start date
	 * @param string $end The end date
	 * @param DateInterval $step The time increment
	 * @throws InfrastructureErrorException If a negative value for `$step`
	 * was passed in
	 */
	public function __construct(string $start, string $end, DateInterval $step)
	{
		$this->cursor = new DateTimeImmutable($start);
		$this->end = new DateTimeImmutable($end);
		$this->step = $step;

		# NOTE: This takes DI::invert into account, but won't catch things like
		#   - DI::createFromDateString('-2 days')
		#   - DI::createFromDateString('2 days ago')
		#   as those just put negative numbers into the duration fields
		if ($this->step->invert !== 0) {
			ErrorLogger::log_error(sprintf(
				'Step for date range generator must be positive. Received: %s',
				$step->format('%Y%M%D:%H%I%S')
			));
			throw new InfrastructureErrorException();
		}
	}

	/**
	 * Get the next date range and advance the internal cursor.
	 * If there is no next date range, NULL is returned.
	 *
	 * @return ?DateRange The next date range or NULL if none
	 */
	public function getNext() : ?DateRange
	{
		if (!$this->hasNext()) {
			return null;
		}

		$stepped = $this->cursor->add($this->step);
		if ($stepped->diff($this->end)->invert !== 0) {
			$stepped = $this->end;
		}

		$range = new DateRange($this->cursor, $stepped);
		$this->cursor = $stepped;

		return $range;
	}

	/**
	 * Check if there are more ranges left in the generator's set.
	 *
	 * @return bool TRUE if there are ranges left, FALSE if not
	 */
	public function hasNext() : bool
	{
		return $this->cursor->getTimestamp() < $this->end->getTimestamp();
	}

}
