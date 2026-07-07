<?php
namespace ShopifyConnector\connectors\shopify\pullers;

use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\exceptions\BulkErrorException;
use ShopifyConnector\connectors\shopify\models\BulkResult;
use ShopifyConnector\connectors\shopify\structs\BulkProcessingResult;
use ShopifyConnector\util\db\MysqliWrapper;
use ShopifyConnector\util\db\queries\BatchedDataInserter;
use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\exceptions\ApiResponseException;
use ShopifyConnector\exceptions\InfrastructureErrorException;
use Exception;
use ShopifyConnector\util\File_Utilities;

/**
 * Base class for bulk GraphQL queries
 */
abstract class BulkBase implements Puller
{

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
	 *   (2300 MAX_POLL_ATTEMPTS) * (25 WAIT_SECONDS)
	 *     => 57,500 s => 15.97 hr of polling
	 * </p>
	 */
	const MAX_POLL_ATTEMPTS = 2300;

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
	const WAIT_SECONDS = 25;

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
	 * Get the BulkOperation GraphQL query.
	 * @return string The GraphQL query
	 */
	abstract public function get_query() : string;

	/**
	 * Process the bulk file response and store data into the appropriate tables.
	 *
	 * @param string $filename The bulk file name
	 * @param BulkProcessingResult $result
	 * @param MysqliWrapper $cxn Connection to execute queries on
	 * @param BatchedDataInserter|null $insert_product The insert statement for products
	 * @param BatchedDataInserter|null $insert_variant The insert statement for variants
	 * @throws InfrastructureErrorException
	 * @throws ApiResponseException
	 */
	abstract public function process_bulk_file(
		string $filename,
		BulkProcessingResult $result,
		MysqliWrapper $cxn,
		?BatchedDataInserter $insert_product,
		?BatchedDataInserter $insert_variant
	) : void;

	/**
	 * @param MysqliWrapper $cxn
	 * @param BatchedDataInserter $insert_product
	 * @param BatchedDataInserter $insert_variant
	 * @return BulkProcessingResult
	 * @throws ApiException
	 * @throws ApiResponseException
	 * @throws BulkErrorException
	 * @throws InfrastructureErrorException
	 */
	public function pull(
		MysqliWrapper $cxn,
		BatchedDataInserter $insert_product,
		BatchedDataInserter $insert_variant
	) : BulkProcessingResult
	{
		return $this->do_bulk_pull(
			$cxn,
			$insert_product,
			$insert_variant
		);
	}

	/**
	 * Perform all the steps needed to pull and process the data for this bulk puller.
	 *
	 * @param MysqliWrapper $cxn Connection to execute queries on
	 * @param BatchedDataInserter|null $insert_product The insert statement for products
	 * @param BatchedDataInserter|null $insert_variant The insert statement for variants
	 * @return BulkProcessingResult The processing result data
	 * @throws ApiException
	 * @throws ApiResponseException
	 * @throws BulkErrorException
	 * @throws InfrastructureErrorException
	 */
	final public function do_bulk_pull(
		MysqliWrapper $cxn,
		?BatchedDataInserter $insert_product,
		?BatchedDataInserter $insert_variant
	) : BulkProcessingResult
	{
		$runres = $this->submit_bulk_query($this->get_query());

		// Bulk operations can take hours to complete, close this until we need it later.
		$cxn->close();
		$pollres = $this->poll_for_bulk_complete($runres->id);
		$data_file = $this->retrieve_bulk_file($pollres);

		// Reconnect now that the bulk wait is over
		$cxn->reconnect();
		$this->session->remove_bulk_id($runres->id);

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
	 * <p>If the query has more than 5 total connections or more than two levels of nested connections
	 * it will fail.</p>
	 *
	 * @link https://shopify.dev/docs/api/usage/bulk-operations/queries#operation-restrictions
	 *
	 * @param string $query The query to be run w/o enclosing bulk query
	 * "mutation document"
	 * @return BulkResult Bulk operation details once operation is complete
	 * @throws ApiException On invalid API responses
	 * @throws ApiResponseException
	 * @throws BulkErrorException
	 */
	public function submit_bulk_query(string $query) : BulkResult
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
				$this->session->add_bulk_id($res->id);
			} catch (BulkErrorException $e) {
				$res = null; // Unset any previous response

				if ($e->query_is_blocked()) {
					if ($blocked_retries-- <= 0) {
						# Exceeded allowed retries for blocked case
						throw new ApiResponseException(
							'Another bulk query is already running for this auth token. Error message: ' . $e->get_first_message(),
						);
					}
					# While blocked, sleep then retry
					sleep(self::WAIT_SECONDS);
					continue;
				}

				if ($e->query_is_throttled()) {
					if (--$throttled_retries <= 0) {
						# Exceeded allowed retries for throttled case
						throw new ApiResponseException(
							'Prevented from running query due to api rate limiting. Error message: ' . $e->get_first_message(),
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
				throw new ApiResponseException(
					"Error in query:\n" . json_encode($rawres),
				);
			}

			# Nothing was handled by the expected cases above, so
			# In the unknown case, it's different exception time
			# TODO: Perhaps this should be a retry instead?
			throw new ApiResponseException(
				"Entered unknown state:\n" . json_encode($rawres),
			);

		} while (--$retries > 0);

		# Exceeded retries without a usable response
		if ($res === null) {
			throw new ApiResponseException(
				'An unexpected error occurred while trying to submit api query',
			);
		}

		return $res;
	}

	/**
	 * Standardized error message for bulk errors.
	 *
	 * @param string $message
	 * @return string
	 */
	protected function get_error_message(string $message) : string
	{
		return sprintf(
			'[Shopify] Bulk Error: %s',
			$message
		);
	}

	/**
	 * Poll the current bulk operation endpoint repeatedly until the operation
	 * finishes, successfully or otherwise.
	 *
	 * @param string $gid The id of the bulk operation to query for
	 * @return BulkResult The finished operation's info
	 * @throws ApiException On invalid GQL response
	 * @throws ApiResponseException
	 */
	public function poll_for_bulk_complete(string $gid) : BulkResult
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
				throw new ApiResponseException(
					'Query canceled'
				);

			} else {
				# Failure or unknown state
				# Increment error count; continue polling only if below threshold
				if (++$ecount > self::MAX_POLL_ERRORS) {
					# Error threshold exceeded -- request is shot, nothing more we can do
					throw new ApiResponseException(
						'Exceeded ' . self::MAX_POLL_ERRORS
						. ' errors when polling for query completion.'
						. " Status: {$res->status}. Error code: {$res->errorCode}. "
						. 'Last error messages: '
						. json_encode($res->userErrors, JSON_PRETTY_PRINT)
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
				throw new ApiResponseException(
					"Timed out while waiting after $pcount attempts"
				);
			}

			# Not out of attempts, something else happened
			throw new ApiResponseException(
				'Finished polling, but did not receive file url: ' . json_encode($res),
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
	 * @throws ApiException On invalid API response
	 * @throws BulkErrorException
	 */
	public function check_status(string $gid) : BulkResult
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
	 * @throws ApiResponseException
	 */
	public function retrieve_bulk_file(BulkResult $br) : string
	{
		if (empty($br->url)) {
			throw new ApiResponseException(
				'No url to download from in bulk response',
			);
		}

		try {
			return File_Utilities::download_file($br->url);
		} catch (Exception $e) {
			throw new ApiResponseException(
				"Error occurred while downloading bulk result: {$e->getMessage()}",
			);
		}
	}

	/**
	 * Validate the file is available and can be opened via {@see fopen}
	 *
	 * @param string $filename The path to the file to open
	 * @return resource The resource returned from {@see fopen} if the result
	 * was not false
	 * @throws InfrastructureErrorException
	 */
	final protected function checked_open_file(string $filename)
	{
		$fh = fopen($filename, 'r');
		if (!$fh) {
			throw new InfrastructureErrorException(
				$this->get_error_message(
					sprintf(
						'Unable to open file (%s) for processing in %s',
						$filename,
						__CLASS__
					)
				)
			);
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
	 * @throws InfrastructureErrorException
	 * @throws ApiResponseException
	 */
	final protected function checked_read_line($fh, int $maxlen = self::MAX_LINE_LENGTH) : ?string
	{
		// NB: deliberately NOT fgets($fh, $maxlen). PHP's fgets() with a large
		// length cap is pathologically slow -- ~600x slower than reading the same
		// line without the cap -- and $maxlen is large by design. stream_get_line()
		// reads up to $maxlen bytes or to the newline, whichever comes first, stays
		// fast regardless of $maxlen, and bounds memory the same way. It strips the
		// trailing "\n"; every caller json_decode()s the result, which does not
		// depend on the delimiter. See FINT-727.
		$line = stream_get_line($fh, $maxlen, "\n");

		if ($line === false) {
			if (feof($fh)) {
				return null;
			}

			throw new InfrastructureErrorException(
				$this->get_error_message(
					'Error occurred reading line in ' . static::class
				)
			);
		}

		// Once the stream is exhausted stream_get_line() returns an empty string
		// rather than false; treat that as end-of-file, not a blank line.
		if ($line === '' && feof($fh)) {
			return null;
		}

		// A returned string of exactly $maxlen bytes means the line reached the
		// cap before a newline was found, so it is too long to frame safely.
		// Bail rather than emit a truncated, mis-framed record.
		if (strlen($line) >= $maxlen) {
			throw new ApiResponseException(
				sprintf(
					'Line length exceeded while processing in %s (%s, limit %d).',
					static::class,
					$this->describe_line_entity($line),
					$maxlen
				)
			);
		}

		return $line;
	}

	/**
	 * Best-effort description of the entity referenced at the start of a JSONL
	 * line, for inclusion in diagnostic error messages. Looks for the first
	 * Shopify GID in a bounded prefix of the chunk; if none is found, falls
	 * back to a sanitized printable prefix so the line shape can be inspected.
	 */
	private function describe_line_entity(string $chunk) : string
	{
		$prefix = substr($chunk, 0, 1024);
		if (preg_match('#gid://shopify/[A-Za-z]+/\d+#', $prefix, $m)) {
			return 'entity ' . $m[0];
		}

		# No GID found — surface a sanitized prefix so we can see what kind of
		# line this actually is (malformed response, unexpected field order, etc.)
		$sample = substr($chunk, 0, 200);
		$sanitized = addcslashes($sample, "\0..\37\177..\377\\\"");
		return 'entity unknown; line begins with: "' . $sanitized . '"';
	}

}
