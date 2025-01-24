<?php
namespace ShopifyConnector\util\db\queries;

use mysqli_sql_exception;
use ShopifyConnector\exceptions\InfrastructureErrorException;
use ShopifyConnector\log\ErrorLogger;
use ShopifyConnector\util\db\MysqliWrapper;

/**
 * Helper for mass insertion of records in an efficient, flexible, and safe
 * manner
 * <p>
 * This will dynamically keep track of query sizes and execute the query
 * automatically when the query size reaches a reasonable threshold.
 * <p>
 * This class operates on estimations and errs toward the side of undersized
 * queries
 *
 * ```php
 * // Example usage
 * $bdi = new BatchedDataInserter($cxn, $is, false);
 *
 * try {
 *   foreach($data_set as $row){
 *
 *     # This will automatically execute queries when enough are stored
 *     $bdi->add_value_set($cxn, $row);
 *
 *   }
 *
 *   # Call run_query once after the loop to execute any leftovers (will do
 *   # nothing if there are no leftovers)
 *   $bdi->run_query($cxn);
 *
 * } catch(InfrastructureErrorException $e){
 *   // ...
 * }
 * ```
 */
class BatchedDataInserter
{

	/**
	 * @var int The default query size threshold to use if it could not be
	 * retrieved from the database (1MB)
	 */
	const DEFAULT_THRESHOLD = 100000;

	/**
	 * @var int Store for the calculated threshold the query will be
	 * executed before exceeding
	 */
	private int $query_size_threshold;

	/**
	 * @var int Store for the size of the currently stored query
	 */
	private int $query_size = 0;

	/**
	 * @var InsertStatement Store for the insert statement
	 */
	private InsertStatement $insert_statement;

	/**
	 * Helper for mass insertion of records in an efficient, flexible, and safe
	 * manner
	 *
	 * @param MysqliWrapper $cxn A DB connection to leverage
	 * @param InsertStatement $is The insert statement for writing records
	 * @throws InfrastructureErrorException If the max_allowed_packet query
	 * failed
	 */
	public function __construct(MysqliWrapper $cxn, InsertStatement $is)
	{
		$this->insert_statement = $is;

		$res = $cxn->safe_query('SELECT @@global.max_allowed_packet');
		$this->query_size_threshold = (int)($res->fetch_row()[0] ?? self::DEFAULT_THRESHOLD);
		$this->query_size_threshold *= .75; # Add some wiggle room for query sizes (75%)

		if ($is->get_duplicate_flag() === InsertStatement::FLAG_UPDATE_ON_DUP) {
			# Account for extra long queries including `ON DUPLICATE UPDATE`
			# statements (50% of the 75%)
			$this->query_size_threshold *= .5;
		}
	}

	/**
	 * Add the next value set to the insert statement
	 * <p>
	 * If the query size exceeds the calculated query size threshold, the
	 * query will be executed automatically and the passed in dataset will be
	 * added to the next batch
	 *
	 * @param MysqliWrapper $cxn A DB connection to leverage
	 * @param array $values The values to pass through to
	 * {@see InsertStatement::add_value_set()}
	 * @throws InfrastructureErrorException On database errors
	 */
	public function add_value_set(MysqliWrapper $cxn, array $values) : void
	{
		$value_size = 0;
		foreach ($values as $v) {
			$value_size += strlen($v);
		}

		# If the newly added value set is going to exceed the threshold, run
		# and clear the query before adding it
		if (($this->query_size + $value_size) >= $this->query_size_threshold) {
			$this->run_query($cxn);
		}

		$this->insert_statement->add_value_set($cxn, $values);
		$this->query_size += $value_size;
	}

	/**
	 * Run the currently stored query
	 * <p>
	 * If nothing is currently stored this method will safely return without
	 * issue
	 *
	 * @param MysqliWrapper $cxn A DB connection to leverage
	 * @throws InfrastructureErrorException On database errors
	 */
	public function run_query(MysqliWrapper $cxn) : void
	{
		if ($this->query_size === 0) {
			return;
		}

		$this->query_size = 0;
		$qry = $this->insert_statement->get_query();

		try {
			$cxn->query($qry);
			$errno = $cxn->get_errno();
			$err = $cxn->get_error();
		} catch (mysqli_sql_exception $e) {
			$errno = $e->getCode();
			$err = $e->getMessage();
		}

		if ($errno !== 0) {
			ErrorLogger::log_error(sprintf(
				'DB Query error. [Message: %s] [Query: %s]',
				$err,
				$qry
			));
			throw new InfrastructureErrorException();
		}
	}

}
