<?php
namespace ShopifyConnector\connectors\shopify\pullers;

use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\exceptions\BulkErrorException;
use ShopifyConnector\connectors\shopify\models\BulkResult;
use ShopifyConnector\connectors\shopify\structs\BulkProcessingResult;


use ShopifyConnector\exceptions\api\UnexpectedResponseException;
use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\queries\BatchedDataInserter;
use ShopifyConnector\exceptions\CustomException;
use ShopifyConnector\exceptions\ApiException;


use Exception;
use ShopifyConnector\util\File_Utilities;

/**
 * Base class for bulk GraphQL queries
 */
abstract class BulkBase
{

	/**
	 * @var string Script identifier to use in errors.
	 */
	const ERR_ID = 'ShopifyBulk';

	/**
	 * @var int Maximum amount of times to retry when a bulk query is blocked.
	 */
	const MAX_RETRIES = 256;
	const MAX_BLOCKED_RETRIES = 30;
	const MAX_THROTTLED_RETRIES = 30;

	/**
	 * @var int Maximum amount of attempts when polling before we assume
	 * something is probably going wrong. Check out the sample math below to
	 * help decide what a reasonable value would be here.
	 * <p>
	 * Sample math (ignoring time taken for network and processing)
	 *   (2000 MAX_POLL_ATTEMPTS) * (10 WAIT_SECONDS + 3 extra)
	 *     => 26,000 s => 7.2 hr of polling
	 * </p>
	 */
	const MAX_POLL_ATTEMPTS = 2000;

	/**
	 * @var int Error count threshold while polling for query completion. Up to
	 * this many erroneous responses are allowed to be swallowed to account for
	 * transient api/comm issues, but too many errors is likely indicative of
	 * something going wrong.
	 */
	const MAX_POLL_ERRORS = 8;

	/**
	 * @var int Number of seconds to wait in between retries when blocked or
	 * polling.
	 */
	const WAIT_SECONDS = 10;

	/**
	 * @var int Upper limit on length of lines when reading file for processing.
	 * This is used as a guard against issues when dealing with a file that isn't
	 * well-formed.
	 */
	const MAX_LINE_LENGTH = 65535 * 20;

	/**
	 * @var string List of fields to request in BulkOperation objects.
	 */
	const BULK_OP_FIELDS = '
		id
		status
		errorCode
		createdAt
		completedAt
		objectCount
		rootObjectCount
		fileSize
		url
		partialDataUrl
	';

	/**
	 * @var SessionContainer Store for the session container
	 */
	protected SessionContainer $session;

	/**
	 * Base class for bulk GraphQL queries
	 *
	 * @param SessionContainer $session The session container
	 */
	public function __construct(SessionContainer $session)
	{
		$this->session = $session;
	}

	/**
	 * Get the GraphQL query.
	 * The parameters that this takes are for chunking and are meant to be passed
	 * into {@see ProductFilterManager::get_filters_gql()} (any additional query
	 * or search terms relevant to the specific bulk puller can be added to these
	 * directly before passing to ProductFilterManager).
	 *
	 * Example use of parameters:
	 *   $prod_search_str = $pfm->get_filters_gql($prod_query_terms, $prod_search_terms);
	 *
	 * @param array $prod_query_terms Params to include in the query string
	 * @param array $prod_search_terms Params to include in the search terms
	 * @return string The GraphQL query
	 */
	public abstract function get_query(array $prod_query_terms = [], array $prod_search_terms = []) : string;

	/**
	 * Process the bulk file response and store data into the appropriate tables.
	 *
	 * @param string $filename The bulk file name
	 * @param MysqliWrapper $cxn Connection to execute queries on
	 * @param BatchedDataInserter $insert_product The insert statement for products
	 * @param BatchedDataInserter $insert_variant The insert statement for variants
	 */
	public abstract function process_bulk_file(
		string $filename,
		BulkProcessingResult $result,
		MysqliWrapper $cxn,
		BatchedDataInserter $insert_product,
		BatchedDataInserter $insert_variant
	) : void;

	/**
	 * Perform all the steps needed to pull and process the data for this bulk puller.
	 *
	 * TODO: Currently, this just does everything in a single run. Need to add logic
	 *   to account for the force_bulk_pieces setting -- if it's TRUE, generate date
	 *   ranges for batches and do a pull-and-process for each range.
	 *   - Some pulls should happen w/o chunking? If so, add bool param to indicate that
	 *   - Need to account for start/end dates in any case?
	 *     - In settings? Determined from shop info?
	 *
	 * @param MysqliWrapper $cxn Connection to execute queries on
	 * @param BatchedDataInserter $insert_product The insert statement for products
	 * @param BatchedDataInserter $insert_variant The insert statement for variants
	 * @return BulkProcessingResult The processing result data
	 */
	final public function do_bulk_pull(
		MysqliWrapper $cxn,
		BatchedDataInserter $insert_product,
		BatchedDataInserter $insert_variant
	) : BulkProcessingResult
	{
		# TODO: Proper logic in here, loop for chunks

		if ($this->session->settings->force_bulk_pieces) {
/*
			# TODO: It's what SM does, but are we doubling up some products by using ">=" AND "<=" ?
			$prod_query_terms = array_filter([
				isset($params['start']) ? "created_at:>={$params['start']}" : null,
				isset($params['end']) ? "created_at:<={$params['end']}" : null,
			]);

			$prod_search_terms = empty($prod_query_filters) ? [] : [
				'sortKey: CREATED_AT',
			];
*/
		}

		$prod_query_terms = [];
		$prod_search_terms = [];

		$runres = $this->run_bulk_query($this->get_query($prod_query_terms, $prod_search_terms));
		$pollres = $this->poll_for_bulk_complete($runres->id);
		$data_file = $this->retrieve_bulk_file($pollres);

		$result = new BulkProcessingResult();
		$this->process_bulk_file($data_file, $result, $cxn, $insert_product, $insert_variant);
		return $result;
	}

	/**
	 * Run a bulk query against Shopify's api.
	 *
	 * <p>The given query should not include the bulk query fluff; this will add
	 * that around the given query automatically.</p>
	 *
	 * <p>If another bulk operation is already running, this will stall until it
	 * completes (within the retry limit), then attempt to run ours.</p>
	 *
	 * <p>Once the query is successfully fired off, the information about the
	 * result will be returned.</p>
	 *
	 * @param string $query The query to be run w/o enclosing bulk query
	 * "mutation document"
	 * @return BulkResult Bulk operation details once operation is complete
	 * @throws ApiException On invalid API responses
	 */
	private function run_bulk_query(string $query) : BulkResult
	{
		$fields = self::BULK_OP_FIELDS;
		$bqry = <<<GQL
			mutation {
				bulkOperationRunQuery(
					query: """
			{ {$query} }
					"""
				) {
					bulkOperation {
						{$fields}
					}
					userErrors {
						field
						message
					}
				}
			}
			GQL;

		$rawres = null;
		$res = null;
		$retries = self::MAX_RETRIES;
		$blocked_retries = self::MAX_BLOCKED_RETRIES;
		$throttled_retries = self::MAX_THROTTLED_RETRIES;

		do {
			$rawres = $this->session->client->graphql_request($bqry);

			try {
				$res = new BulkResult($rawres);
			} catch (BulkErrorException $e) {
				$res = null; // Unset any previous response

				if ($e->query_is_blocked()) {
					if ($blocked_retries-- <= 0) {
						# Exceeded allowed retries for blocked case
						$this->generic_exception(
							'Another bulk query is already running for this auth token. Error message: ' . $e->get_first_message(),
							__FUNCTION__
						);
					}
					# While blocked, sleep a little extra, then retry
					sleep(self::WAIT_SECONDS + 10);
					continue;
				}

				if ($e->query_is_throttled()) {
					if (--$throttled_retries <= 0) {
						# Exceeded allowed retries for throttled case
						$this->generic_exception(
							'Prevented from running query due to api rate limiting. Error message: ' . $e->get_first_message(),
							__FUNCTION__
						);
					}
					# While throttled, only wait a little bit before trying again
					sleep(5);
					continue;
				}

				throw $e;
			}

			if ($res->isRunning() || $res->isComplete()) {
				# In the running or complete case, move to next steps
				break; # Break from retry loop

			} elseif ($res->isDead()) {
				# In the error case, it's exception time
				$this->generic_exception(
					"Error in query:\n" . print_r($rawres, true),
					__FUNCTION__
				);
			}

			# Nothing was handled by the expected cases above, so
			# In the unknown case, it's different exception time
			# TODO: Perhaps this should be a retry instead?
			$this->generic_exception(
				"Entered unknown state:\n" . print_r($rawres, true),
				__FUNCTION__
			);
		} while (--$retries > 0);

		# Exceeded retries without a usable response
		if ($res === null) {
			$this->generic_exception(
				'An unexpected error occurred while trying to submit api query',
				__FUNCTION__
			);
		}

		return $res;
	}

	/**
	 * Generate an exception and terminate processing.
	 *
	 * TODO: The exception used here probably needs to be updated (and/or the exceptions
	 *   in the bulk stuff overall). Errors going through this may skip being logged.
	 *
	 * @param string $msg The message to use in the exception
	 * @param ?string $ident Identifier for where the error occurred
	 * @return never
	 */
	protected function generic_exception(string $msg, ?string $ident = null)
	{
		$ident = self::ERR_ID . (empty($ident) ? '' : "::{$ident}");
		(new CustomException(
			'generic_exception',
			sprintf("An error occurred [%s : %.2048s]", $ident, $msg)
		))->end_process();
		die; # Double die, snake eyes
	}

	/**
	 * Poll the current bulk operation endpoint repeatedly until the operation
	 * finishes, successfully or otherwise.
	 *
	 * @param string $gid The id of the bulk operation to query for
	 * @return BulkResult The finished operation's info
	 * @throws ApiException On invalid GQL response
	 */
	private function poll_for_bulk_complete(string $gid) : BulkResult
	{
		$res = null;
		$pcount = 0;
		$ecount = 0;

		do {
			# But first, a short nap...
			sleep(5);

			++$pcount;
			try {
				$res = $this->check_status($gid);
			} catch (BulkErrorException $e) {
				if ($e->query_is_throttled()) {
					# When throttled, just try again at the next interval
					sleep(self::WAIT_SECONDS);
					continue;
				}
				throw $e;
			}

			if ($res->isComplete()) {
				# Query has completed, result is ready
				break; # Break from polling loop

			} elseif ($res->isRunning()) {
				# Query is still running
				# Do nothing and continue polling

			} elseif ($res->isCancel()) {
				# Query was canceled, bail out
				# Ideally, might return NULL here, but don't really
				# want a nullable return, so just throw an exception
				$this->generic_exception('Query canceled, bailing out');

			} else {
				# Failure or unknown state
				# Increment error count; continue polling only if below threshold
				if (++$ecount > self::MAX_POLL_ERRORS) {
					# Error threshold exceeded -- request is shot, nothing more we can do
					$this->generic_exception(
						'Exceeded ' . self::MAX_POLL_ERRORS
						. ' errors when polling for query completion.'
						. " Status: {$res->status}. Error code: {$res->errorCode}. "
						. 'Last error messages: '
						. json_encode($res->userErrors, JSON_PRETTY_PRINT),
						__FUNCTION__
					);
				}
			}

			# At last, another nap...
			sleep(self::WAIT_SECONDS);

		} while ($pcount < self::MAX_POLL_ATTEMPTS);

		# Check if polling resulted in a DL URL, error if not
		if (empty($res->url)) {
			if ($pcount >= self::MAX_POLL_ATTEMPTS) {
				# Ran out of attempts, that's a timeout
				$this->generic_exception(
					"Timed out while polling after {$pcount} attempts",
					__FUNCTION__
				);
			}

			# Not out of attempts, something else happened
			$this->generic_exception(
				'Finished polling, but did not receive file url: ' . print_r($res, true),
				__FUNCTION__
			);
		}

		return $res;
	}

	/**
	 * Query the bulk api to inquire about the status of the query with the
	 * given id.
	 *
	 * @param string $gid ID of the query to check the status for
	 * @return BulkResult Response info for specified bulk operation
	 * @throws ApiException|UnexpectedResponseException On invalid API response
	 */
	private function check_status(string $gid) : BulkResult
	{
		$fields = self::BULK_OP_FIELDS;
		return new BulkResult($this->session->client->graphql_request("{
			node(id: \"{$gid}\") {
				... on BulkOperation {
					{$fields}
				}
			}
		}"));
	}

	/**
	 * Download the results file specified in the given bulk query response.
	 *
	 * @param BulkResult $br A (ideally successful) bulk query result
	 * @return string The name of the file the data was downloaded to
	 */
	private function retrieve_bulk_file(BulkResult $br) : string
	{
		if (empty($br->url)) {
			$this->generic_exception(
				'No url to download from in bulk response',
				__FUNCTION__
			);
		}

		try {
			return File_Utilities::download_file($br->url);
		} catch (Exception $e) {
			$this->generic_exception(
				"Error occurred while downloading query result: {$e->getMessage()}",
				__FUNCTION__
			);
		}
	}

	/**
	 * Pull the info for the current / most recent query running for the shop
	 * that this bulk puller is set up with.
	 * This is primarily meant for dev utility/debugging insights. Normal runs
	 * will use the internal `check_status()` method for getting query info.
	 *
	 * @return BulkResult The info for the current / most recent query
	 * @throws ApiException On invalid API response
	 */
	final public function check_current_query_for_shop(bool $withQuery = false) : BulkResult
	{
		$fields = self::BULK_OP_FIELDS;
		if ($withQuery) {
			# BULK_OP_FIELDS already ends with a newline
			$fields .= "query\n";
		}

		return new BulkResult($this->session->client->graphql_request("
			query {
				currentBulkOperation {
					{$fields}
				}
			}
		"));
	}

	/**
	 * Validate the file is available and can be opened via {@see fopen}
	 *
	 * @param string $filename The path to the file to open
	 * @return resource The resource returned from {@see fopen} if the result
	 * was not false
	 */
	final protected function checked_open_file(string $filename)
	{
		$fh = fopen($filename, 'r');
		if (!$fh) {
			$this->generic_exception(sprintf(
				'Unable to open file (%s) for processing in %s',
				$filename,
				__CLASS__
			));
		}

		return $fh;
	}

	/**
	 * Read and validate the next available line in the file
	 *
	 * <p>If the end of file is reached, this will return null</p>
	 *
	 * <p>If the line length exceeds the `$maxlen` or a failure occurrs in reading, this will attempt
	 * to advance the file pointer to the next line and throw an exception. If the pointer cannot be
	 * advanced to the next line, this will throw a RuntimeException, which should be allowed to bubble
	 * up beyond any immediate processing logic.</p>
	 *
	 * @param resource $fh Valid file handle resource opened for reading
	 * @param int $maxlen Line length limit
	 * @return ?string Next line from file, NULL if at EOF
	 */
	final protected function checked_read_line($fh, int $maxlen = self::MAX_LINE_LENGTH) : ?string
	{
		$line = fgets($fh, $maxlen);

		if ($line === false) {
			if (feof($fh)) {
				return null;
			}

			$this->generic_exception(
				'Error occured reading line in ' . __CLASS__
			);
		}

		if ($line[strlen($line) - 1] !== "\n") {
			# Advance to next line, skip rest of too-long line
			$max_skip_tries = 250; # 250 * 4kB => 1MB
			while ($line !== false && $line[strlen($line) - 1] !== "\n") {
				$line = fgets($fh, 4096);
				if (--$max_skip_tries <= 0) {
					# Something is going horribly wrong or a result line is just atrociously long.
					# Either way, things won't be able to proceed gracefully, so throw up something
					# lower than generic_exception would. Perhaps this could be handled more formally
					# with something like a FatalException type.
					throw new \RuntimeException('Line too long in bulk processing, and unable to proceed to next line');
				}
			}

			$this->generic_exception(
				'Line length exceeded while processing in ' . __CLASS__
			);
		}

		return $line;
	}

}

