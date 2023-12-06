<?php

namespace ShopifyConnector\api;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use ShopifyConnector\exceptions\ApiException;


/**
 * Client for managing core information about the Shopify API
 */
final class ApiClient {

	/**
	 * @var string The URL format with one sprintf placeholder for the shop
	 */
	const URL = '%s.myshopify.com';

	/**
	 * @var string The REST API version to be used
	 */
	const REST_VERSION = '2022-10';

	/**
	 * @var string The Graphql version to be used
	 */
	const GRAPHQL_VERSION = '2022-10';

	/**
	 * @var ?string Store for the shop code
	 */
	private ?string $shop = null;

	/**
	 * @var ?string Store for the API token
	 */
	private ?string $token = null;

	/**
	 * @var ?string Store for the auth username
	 */
	private ?string $username = null;

	/**
	 * @var ?string Store for the auth password
	 */
	private ?string $password = null;

	/**
	 * @var ?string Store for an optional proxy URL
	 */
	private ?string $proxy = null;

	/**
	 * @var ?GuzzleClient Store for the client to make API calls
	 */
	private ?GuzzleClient $client = null;

	/**
	 * @var array Store for the headers from the last API call
	 */
	private $lastResponseHeaders = [];

	/**
	 * Set the OAuth token for token-based authentication
	 *
	 * @param string $token The OAuth token
	 */
	public function setOauthToken(string $token) : void {
		$this->token = $token;
	}

	/**
	 * Set a username for username/password authentication
	 *
	 * @param string $username The username
	 */
	public function setUsername(string $username) : void {
		$this->username = $username;
	}

	/**
	 * Set a password for username/password authentication
	 *
	 * @param string $password The password
	 */
	public function setPassword(string $password) : void {
		$this->password = $password;
	}

	/**
	 * Set the shop code
	 *
	 * @param string $shop The shop code
	 */
	public function setShop(string $shop) : void {
		$this->shop = urlencode($shop);
	}

	/**
	 * Set a proxy to use when making requests (This is fed as the `proxy`
	 * option in the Guzzle constructor)
	 *
	 * @param string $proxy The full proxy URL (including username/password)
	 * <p>E.G. https://admin:password@proxy.feedonomics.com</p>
	 */
	public function setProxy(string $proxy){
		$this->proxy = $proxy;
	}

	/**
	 * Set the sandbox mode
	 *
	 * <p>Note: This isn't actually used</p>
	 *
	 * @param bool $enabled
	 */
	public function setSandbox(bool $enabled) : void { }

	/**
	 * Make an API request against the Shopify store
	 *
	 * @param string $method The HTTP method
	 * @param string $endpoint The endpoint to call
	 * @param array $payload The payload to pass. If calling GET this will be
	 * treated as query params. All other methods will add this as the POST
	 * body.
	 * @param array $headers Optional extra headers to include
	 * @return mixed The JSON decoded response
	 * @throws ApiException On API errors
	 */
	public function request(
		string $method,
		string $endpoint,
		array $payload = [],
		array $headers = []
	){
		if($this->client === null){
			$apiUrl = sprintf($this::URL, $this->shop);

			if ($this->username || $this->password) {
				$apiUrl = "{$this->username}:{$this->password}@{$apiUrl}";
			}

			$apiUrl = "https://{$apiUrl}";
			$settings = [
				'base_uri' => $apiUrl,
				RequestOptions::HTTP_ERRORS => false,
			];

			if($this->proxy !== null){
				$settings[RequestOptions::PROXY] = $this->proxy;
				$settings[RequestOptions::VERIFY] = false;
			}

			$this->client = new GuzzleClient($settings);
		}

		$params = [
			RequestOptions::HEADERS => array_merge(
				[
					'Content-Type' => 'application/json',
					'X-Shopify-Access-Token' => $this->token,
				],
				$headers
			)
		];

		$request = new Request($method, $endpoint);
		if ($method === 'GET') {
			$params[RequestOptions::QUERY] = $payload;
		} else {
			if ($params[RequestOptions::HEADERS]['Content-Type'] == 'application/graphql') {
				$request = new Request($method, $endpoint, [], $payload['query']);
			} else {
				$params[RequestOptions::JSON] = $payload;
			}
		}

		$response =$this->requestWithExponentialBackoff($request, $params);
		$httpStatus = $response->getStatusCode();

		if ($httpStatus >= 400 && $httpStatus <= 599) {
			throw new ApiException($response->getReasonPhrase(), [
				'status' => $httpStatus,
				'body'   => $response->getBody()->getContents(),
				'request_id' => $response->hasHeader('X-Request-ID') ? $response->getHeader('X-Request-ID') : '',
			]);
		}

		return json_decode($response->getBody()->getContents(), true);
	}

	/**
	 * Make a Graphql request against the Shopify store
	 *
	 * @param string $query The graphql query to run
	 * @return mixed
	 * @throws ApiException
	 */
	public function graphqlRequest(string $query){
		return $this->request(
			'POST',
			sprintf('/admin/api/%s/graphql.json', self::GRAPHQL_VERSION),
			['query' => $query],
			['Content-Type' => 'application/graphql']
		);
	}

	/**
	 * Get the parsed list of pagination links from the header of the last
	 * request
	 *
	 * @return array The list of pagination links
	 */
	public function parseLastPaginationLinkHeader() : array {
		$availableLinks = [];
		$links = explode(',', $this->getHeader('link') ?? '');

		foreach ($links as $link) {
			if (preg_match('/<(.*)>;\srel=\\"(.*)\\"/', $link, $matches)) {
				$queryStr = parse_url($matches[1], PHP_URL_QUERY);
				parse_str($queryStr, $queryParams);
				$availableLinks[$matches[2]] = $queryParams['page_info'];
			}
		}

		return $availableLinks;
	}

	/**
	 * Get a header value from the last request
	 *
	 * @param string $header The header key
	 * @return string|null The header value or null if the header was not
	 * present
	 */
	public function getHeader(string $header) : ?string {
		return $this->lastResponseHeaders[strtolower($header)][0] ?? null;
	}

	/**
	 * Helper for automatically retrying requests
	 *
	 * @param Request $request The Guzzle request to use
	 * @param array $params The options to pass through to
	 * {@see GuzzleClient::send()} method
	 * @return Response The Guzzle response
	 */
	private function requestWithExponentialBackoff(Request $request, array $params){
		$response = false;
		$maxAttempts = 8;
		$maxBackOffWindow = 300;
		$initialSleep = 1;
		$currentBackOffWindow = $initialSleep;

		for ($attemptNum = 0; $attemptNum < $maxAttempts; $attemptNum++) {
			$sleepTime = rand($initialSleep, $currentBackOffWindow);
			$response = $this->client->send($request, $params);

			switch((int) $response->getStatusCode()) {
				// Successful request
				case 200: // Ok
				case 201: // Created
				case 202: // Accepted
					break 2;

				// Rate limiting error, use header for sleepTime
				case 429: // Too Many Requests
					$sleepTime = (int) $response->getHeader('Retry-After');
					break;

				// Hard fail errors, skip retrying
				case 303: // See Other
				case 400: // Bad Request
				case 401: // Unauthorized
				case 402: // Payment Required
				case 403: // Forbidden
				case 404: // Not Found
				case 406: // Not Acceptable
				case 422: // Unprocessable Entity
				case 423: // Locked
				case 500: // Internal Server Error
				case 501: // Not Implemented
				case 502: // Bad Gateway
				case 503: // Service Unavailable
					break 2;
			}

			sleep($sleepTime);
			$currentBackOffWindow = min($maxBackOffWindow, $currentBackOffWindow * 2);
		}

		$this->lastResponseHeaders = array_change_key_case($response->getHeaders(), CASE_LOWER);
		return $response;
	}

}

