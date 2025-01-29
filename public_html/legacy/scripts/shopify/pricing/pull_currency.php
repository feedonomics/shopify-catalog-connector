<?php
/**
 * One-off for pulling presentment prices through GraphQL api, since the rest
 * api often returns incorrect data.
 *
 * For issue FP-5533
 *
 * Created 2022-12-19
 */

use ShopifyConnector\api\ApiClient;
use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\exceptions\CustomException;
use ShopifyConnector\exceptions\ValidationException;
use ShopifyConnector\util\File_Utilities;
use ShopifyConnector\util\file\TemporaryFileGenerator;
use ShopifyConnector\util\io\InputParser;

require_once (__DIR__ . '/../../../../../config.php');


const QUERY_PRODUCTS_CONTEXTUAL_FMT = '{
	productVariants {
		edges {
			node {
				id
				contextualPricing%s {
					price {
						amount
						currencyCode
					}
				}
			}
		}
	}
}';

const QUERY_PRODUCTS_PPRICES_FMT = '{
	productVariants {
		edges {
			node {
				id
				presentmentPrices%s {
					edges {
						node {
							price {
								currencyCode
								amount
							}
						}
					}
				}
			}
		}
	}
}';

const QUERY_BULK_DOC_FMT = 'mutation {
	bulkOperationRunQuery(
		query: """
			%s
		"""
	) {
		bulkOperation {
			id
			status
		}
		userErrors {
			field
			message
		}
	}
}';

const QUERY_BULK_STATUS = 'query {
	currentBulkOperation {
		id
		status
		errorCode
		createdAt
		completedAt
		objectCount
		fileSize
		url
		partialDataUrl
	}
}';


final class OneOffPresentmentPuller {

	/**
	 * Gate for extra development output.
	 */
	public static bool $DEV_OUTPUTS = false;

	/**
	 * Script identifier to use in errors.
	 */
	const ERR_ID = 'ShopifyCurrencyPull';

	/**
	 * Maximum amount of times to retry when a bulk query is blocked.
	 */
	const MAX_RETRIES = 256;

	/**
	 * Maximum amount of attempts when polling before we assume something is
	 * probably going wrong. Check out the sample math below to help decide what
	 * a reasonable value would be here.
	 *
	 * Sample math (ignoring time taken for network and processing)
	 *   (120 MAX_POLL_ATTEMPTS) * (8 WAIT_SECONDS)
	 *     => 960 s => 16 min of polling
	 */
	const MAX_POLL_ATTEMPTS = 2000;

	/**
	 * Number of seconds to wait in between retries when blocked or polling.
	 */
	const WAIT_SECONDS = 10;

	/**
	 * Header labels to use in CSV output file.
	 */
	const CSV_HEADER_ID = 'id';
	const CSV_HEADER_PRICE_CTX = 'contextual_price';

	/**
	 * Status constants used when inspecting bulk query responses.
	 */
	const STATUS_COMPLETE = 0;
	const STATUS_WAITING = 1;
	const STATUS_BLOCKED = 2;
	const STATUS_ERROR = 3;
	const STATUS_TIMEOUT = 4;
	const STATUS_UNKNOWN = 255;


	private ?string $shop;
	private ?string $token;
	private ?string $country;
	// private ?string $currencies; // (presentment)

	private ApiClient $client;


	/**
	 * @param array $params Incoming user-specified parameters, likely $_GET
	 */
	function __construct(array $params){
		$this->shop = $params['shop'] ?? null;
		$this->token = $params['token'] ?? null;
		$this->country = $params['country'] ?? null;
		//$this->currencies = $params['currencies'] ?? null; // (presentment)

		$this->validateParams();

		$this->client = new ApiClient();
		$this->client->setOauthToken($this->token);
		$this->client->setShop($this->shop);
		if(InputParser::extract_boolean($params, 'use_proxy')){
			$this->client->setProxy($GLOBALS['third_party_credentials']['shopify_proxy']);
		}
	}


	private function validationException(string $msg){
		(new ValidationException($msg))->end_process();
	}


	private function genericException(string $msg, ?string $ident){
		$ident = self::ERR_ID . (empty($ident) ? '' : "::{$ident}");
		(new CustomException(
			'generic_exception',
			sprintf('An error occurred [%s : %s]', $ident, $msg)
		))->end_process();
	}

	/**
	 * Make sure the parameter fields are holding valid values.
	 */
	private function validateParams() : void {
		if(empty($this->shop) || empty($this->token)){
			$this->validationException(
				'Please provide parameters. (Required: "shop", "token") (Optional: "country")'
			);
		}

		# (Any false-y return from preg_match should trigger this error)
		if(!empty($this->country) && !preg_match('/^[A-Z]{2}$/', $this->country)){
			$this->validationException(
				"Invalid country code: {$this->country}. Must be of the form: US"
			);
		}

		/* // (presentment)
		# (Any false-y return from preg_match should trigger this error)
		if(!empty($this->currencies) && !preg_match('/^[A-Z]{3}(,[A-Z]{3})*$/', $this->currencies)){
			$this->validationException(
				'Invalid currency list. Must be of the form: USD,CAD,EUR'
			);
		}
		*/
	}


	/**
	 * Output the given data if the DEV_OUTPUTS flag is set to true.
	 *
	 * @param mixed $data The data to potentially output
	 * @param bool $printr TRUE to print_r data, FALSE to echo
	 */
	private function devOutput($data, bool $printr = false) : void {
		if(self::$DEV_OUTPUTS !== true){ return; }

		if($printr){
			print_r($data);
		} else {
			echo "$data";
		}
	}


	/**
	 * Helper method for extracting the numeric identifier from a
	 * gid://-formatted id.
	 *
	 * @param string $gid The gid to convert
	 * @return string the numeric identifier
	 */
	private function convertGID(string $gid) : string {
		if(strpos($gid, '/') === false){
			# The gid did not look like expected
			# Just return it rather than erroring
			return $gid;
		}

		return substr($gid, strrpos($gid, '/') + 1);
	}


	/**
	 * Get the query for presentment price grabbing, taking into account the
	 * presence or absence of a list of desired currencies.
	 *
	 * @return string The formatted query string for pulling presentment prices
	 */
	public function getPresentmentQuery() : string {
		$parameter = empty($this->currencies)
			? ''
			: "(presentmentCurrencies: [{$this->currencies}])"
		;
		return sprintf(QUERY_PRODUCTS_PPRICES_FMT, $parameter);
	}


	/**
	 * Get the query for contextual price grabbing, taking into account the
	 * presence or absence of a specific country.
	 *
	 * @return string The formatted query string for pulling contextual prices
	 */
	public function getContextualPriceQuery() : string {
		$parameter = empty($this->country)
			? ''
			: "(context: {country: {$this->country}})"
		;
		return sprintf(QUERY_PRODUCTS_CONTEXTUAL_FMT, $parameter);
	}


	/**
	 * Run a GraphQL query against Shopify's api.
	 *
	 * @param string $query The complete query to send off
	 * @return mixed The resulting response JSON from the api
	 * @throws ApiException
	 */
	public function runQuery(string $query){
		return $this->client->graphqlRequest($query);
	}


	/**
	 * Run a bulk query against Shopify's api.
	 * The given query should not include the bulk query fluff; this will add
	 * that around the given query automatically.
	 *
	 * If another bulk operation is already running, this will stall until it
	 * completes (within the retry limit), then attempt to run ours.
	 *
	 * Once the query is successfully fired off, this will poll to wait for it
	 * to complete. Once the query has successfully completed, the information
	 * about the result will be returned.
	 *
	 * @param string $query The query to be run w/o enclosing bulk query "mutation document"
	 * @return BulkResult Bulk operation details once operation is complete
	 * @throws [??] On error response from api
	 */
	public function runBulkQuery(string $query) : BulkResult {
		$sres = null;
		$bqry = sprintf(QUERY_BULK_DOC_FMT, $query);
		$retries = self::MAX_RETRIES;

		do {
			$res = $this->runQuery($bqry);
			$sres = $this->simplifyBulkResponse($res);

			switch($sres->status){
				# In the blocked case, sleep a little extra, then just leave
				# the switch and carry on
				case self::STATUS_BLOCKED:
					sleep(9);
				break;

				# In the waiting case, record the returned query id and stop querying
				case self::STATUS_WAITING:
				break 2; # Break 2 levels

				# In the error case, it's exception time
				case self::STATUS_ERROR:
					$this->genericException(
						"Error in query:\n" . print_r($sres, true),
						__FUNCTION__
					);
				break; # For consistency

				# In the unknown case, it's different exception time
				default:
					$this->genericException(
						"Entered unknown state:\n" . print_r($sres, true),
						__FUNCTION__
					);
			}

			sleep(self::WAIT_SECONDS);
		} while(--$retries >= 0);

		$this->devOutput('Finished submitting bulk query. Response: ');
		$this->devOutput($res, true);
		$this->devOutput($sres, true);

		# Exceeded max retries and still blocked
		if($sres->status === self::STATUS_BLOCKED){
			$this->genericException(
				'Exceeded max retries waiting to run query while blocked',
				__FUNCTION__
			);
		}

		# If no id received, cannot continue
		if(empty($sres->id)){
			$this->genericException(
				'Bulk query failed, no id received. Messages: ' . print_r($sres->messages, true),
				__FUNCTION__
			);
		}

		# If non-waiting status, probably log, but attempt to continue since there is an id
		if($sres->status !== self::STATUS_WAITING){
			$this->devOutput('Finished bulk query submit with non-waiting status: ');
			$this->devOutput($res, true);
		}

		$rdata = $this->pollForBulkComplete($sres->id);

		return $rdata;
	}


	/**
	 * Take the response from the bulk query endpoint and try to make sense of
	 * it, making it easier to work with elsewhere.
	 *
	 * @param array $res Decoded response from bulk endpoint
	 * @return SimplifiedResponse A plesant breath of fresh air
	 */
	public function simplifyBulkResponse(array $res) : SimplifiedResponse {
		$sres = new SimplifiedResponse();

		if(empty($res['data']['bulkOperationRunQuery'])){
			$this->genericException(
				"Unparsable response from Shopify:\n" . print_r($res, true),
				__FUNCTION__
			);
		}

		$bdata = $res['data']['bulkOperationRunQuery']['bulkOperation'];
		$edata = $res['data']['bulkOperationRunQuery']['userErrors'];

		#
		# Inspect bulkOperation portion first...

		# If we got an id, carry it over regardless of what else is going on
		$sres->id = empty($bdata['id']) ? null : $bdata['id'];

		if(!empty($bdata['status'])){
			switch($bdata['status']){
				case 'CREATED':
					$sres->status = self::STATUS_WAITING;
				break;

				default:
					$sres->status = self::STATUS_UNKNOWN;
			}
		}

		#
		# ...and userErrors second

		if(!empty($edata)){
			if(count($edata) === 1
			&& stripos($edata[0]['message'], 'already in progress') !== false
			){
				# Received a user error because a bulk query is already running
				$sres->status = self::STATUS_BLOCKED;
			} else {
				# Received multiple errors or a single one for a reason other
				# than because a bulk query is already running
				$sres->status = self::STATUS_ERROR;
				$sres->messages = $edata;
			}
		}

		return $sres;
	}


	/**
	 * Poll the current bulk operation endpoint repeatedly until the operation
	 * finishes, successfully or otherwise.
	 *
	 * @param string $gid The id of the bulk operation to query for
	 * @return BulkResult A result set of the finished operation's info
	 */
	public function pollForBulkComplete(string $gid) : BulkResult {
		# Possible shopify operation statuses:
		# CANCELED, CANCELING, COMPLETED, CREATED, EXPIRED, FAILED, RUNNING

		$qcount = 0;
		$ecount = 0;
		$elimit = 4;

		$bres = new BulkResult();
		$bres->status = self::STATUS_WAITING;

		do {
			if($ecount > $elimit){
				$this->genericException(
					"Exceeded {$elimit} errors when polling for query status",
					__FUNCTION__
				);
			}

			# Sleep first
			sleep(3);

			$this->devOutput("\n\nPolling # {$qcount}: ");

			$rstat = $this->checkStatus($gid);
			++$qcount;

			$this->devOutput($rstat, true);

			$base = empty($rstat['data']['node'])
				? $rstat['data']['currentBulkOperation']
				: $rstat['data']['node']
			;

			# Rough check that response has expected structure
			if(empty($base['status'])){
				$this->genericException('Response status empty', __FUNCTION__);
			}

			$shopifyStatus = $base['status'];

			switch($shopifyStatus){
				case 'COMPLETED':
					$bres->status = self::STATUS_COMPLETE;
					$bres->id = $base['id'];
					$bres->objectCount = $base['objectCount'];
					$bres->fileSize = $base['fileSize'];
					$bres->url = $base['url'];
					$bres->partialDataUrl = $base['partialDataUrl'];
				break 2; # Leave the outer loop

				case 'CANCELED': # fallthrough
				case 'CANCELING': # fallthrough
				case 'EXPIRED': # fallthrough
				case 'FAILED':
					# Request is shot and nothing more we can do
					$this->genericException(
						"Bulk request failed with status: {$shopifyStatus})",
						__FUNCTION__
					);
				break; # For consistency

				case 'CREATED': # fallthrough
				case 'RUNNING': # fallthrough
					# Nothing to do here but keep on polling
					# Simple break to carry on
				break;

				default:
					# Unrecognized status -- consider it an error
					++$ecount;
					$this->devOutput("\n\n## UNRECOGNIZED STATUS ({$shopifyStatus}) ##\n");
			}

			sleep(self::WAIT_SECONDS);

		} while($qcount < self::MAX_POLL_ATTEMPTS);

		# Check if polling resulted in a DL URL, error if not
		if(empty($bres->url)){
			if($qcount >= self::MAX_POLL_ATTEMPTS){
				# Ran out of attempts, that's a timeout
				$this->genericException(
					"Timed out while polling after {$qcount} attempts",
					__FUNCTION__
				);
			}

			# Not out of attempts, something else happened
			$this->genericException(
				'Finished polling, but did not receive file url: ' . print_r($bres, true),
				__FUNCTION__
			);
		}

		return $bres;
	}


	/**
	 * @return mixed Response JSON from current bulk operation endpoint
	 * @throws [??] On error response
	 */
	public function checkStatus($gid = null){
		if(!$gid) $query = QUERY_BULK_STATUS;
		else $query = "{
			node(id: \"{$gid}\") {
				... on BulkOperation {
					id
					status
					errorCode
					createdAt
					completedAt
					objectCount
					fileSize
					url
					partialDataUrl
				}
			}
		}";

		return $this->runQuery($query);
	}


	/**
	 * Download the file specified in the bulk query response.
	 *
	 * @param BulkResult $br A successful bulk query result
	 * @return string The name of the file the data was downloaded to
	 */
	public function retrieveBulkFile(BulkResult $br) : string {
		try {
			if(empty($br->url)){
				$this->genericException(
					'No url to download from in bulk response',
					__FUNCTION__
				);
			}
			return File_Utilities::download_file($br->url);
		} catch(Exception $e) {
			$this->genericException(
				"Error occurred while downloading query result: {$e->getMessage()}",
				__FUNCTION__
			);
		}
	}


	/**
	 * Generate a CSV file suitable for ingestion by the platform based on the
	 * results in file with the given name. The output should consist of two
	 * columns, the first being the id of a product, and the second being a json
	 * blob of its pricing given the specified context.
	 *
	 * @param string $bulkFilename The name of the file where the data can be found
	 * @return string The name of the generated CSV file
	 */
	public function generateCSVFromBulk(string $bulkFilename) : string {
		$bfh = null;
		$cfh = null;
		$cf = null;

		try {
			$bfh = fopen($bulkFilename, 'r');
			if(!$bfh){
				throw new Exception('Unable to open query result file for reading');
			}

			$cf = TemporaryFileGenerator::get("shopify_cxt_prices_{$this->shop}_", 'csv');
			$cfh = fopen($cf->get_absolute_path(), 'w');
			if(!$cfh){
				throw new Exception('Unable to open CSV file for writing');
			}

			$this->convertAndWrite($bfh, $cfh);

		} catch(Exception $e) {
			$this->genericException(
				"Error occurred while generating CSV: {$e->getMessage()}",
				__FUNCTION__
			);
		} finally {
			@fclose($bfh);
			@fclose($cfh);
		}

		return $cf->get_absolute_path();
	}


	/**
	 * Helper method for generateCSVFromBulk to contain processing logic.
	 *
	 * @param resource $fhBulk Open file handle for bulk query result
	 * @param resource $fhCSV Open file handle for CSV destination file
	 * @throws JsonException
	 */
	private function convertAndWrite($fhBulk, $fhCSV) : void {
		# Put header line into CSV file
		fputcsv($fhCSV, [
			self::CSV_HEADER_ID,
			self::CSV_HEADER_PRICE_CTX
		]);

		while( ($line = fgets($fhBulk)) !== false ){
			$decl = json_decode(trim($line), false, 16, JSON_THROW_ON_ERROR);
			fputcsv($fhCSV, [
				$this->convertGID($decl->id),
				json_encode($decl->contextualPricing, JSON_THROW_ON_ERROR, 16)
			]);
		}
	}


	/**
	 * Go through the entire set of steps to make a bulk query to retrieve
	 * contextual prices for all products in a shop, retrieve the result,
	 * process the retrieved data to generate a CSV usable on our platform,
	 * and then deliver the CSV data to the requester.
	 *
	 * This method will terminate execution upon completion to help avoid
	 * junk data getting into the output.
	 */
	public function getContextualPriceCSV(){
		$res = $this->runBulkQuery($this->getContextualPriceQuery());
		$bfname = $this->retrieveBulkFile($res);
		$csvname = $this->generateCSVFromBulk($bfname);

		header('Content-Type: text/csv');
		header('Content-Length: ' . filesize($csvname));
		readfile($csvname);

		die;
	}

}


class SimplifiedResponse {

	public ?string $id;
	public int $status;
	public ?array $messages;

}


class BulkResult {

	# This will be our own status code
	public int $status;

	# This will be values from Shopify
	public ?string $id;
	public ?string $objectCount;
	public ?string $fileSize;
	public ?string $url;
	public ?string $partialDataUrl;

}


###
### Glue it all together
###

if(isset($_GET['debug'])){ OneOffPresentmentPuller::$DEV_OUTPUTS = true; }

(new OneOffPresentmentPuller($_GET))->getContextualPriceCSV();

