<?php

namespace ShopifyConnector\connectors\shopify;

use ShopifyConnector\connectors\shopify\models\Shop;
use ShopifyConnector\connectors\shopify\structs\PullStats;
use ShopifyConnector\api\ApiClient;

/**
 * Container class for the various dependencies, helpers, etc that are used in
 * the course of a Shopify import to make it all easy to pass around.
 *
 * <p>Additionally, when mocks are needed in testing, they will be injected
 * here.</p>
 */
final class SessionContainer
{

	/**
	 * @var int Flags for the run stage to indicate what phase the run is currently in.
	 */
	const STAGE_SETUP = 0;
	const STAGE_PULLING = 1;
	const STAGE_FINAL_OUTPUT = 2;


	private static ?self $active_session = null;

	/**
	 * @var ShopifySettings Store for the Shopify import settings
	 */
	public ShopifySettings $settings;

	/**
	 * @var ApiClient Store for the CL client
	 */
	public ApiClient $client;

	/**
	 * @var ?Shop The data for the shop being pulled from
	 */
	public ?Shop $shop = null;

	/**
	 * The run manager should add an entry here for each active module under the
	 * module's name. Then the pulling logic in the module should reference and
	 * modify only its specific tracker as needed.
	 *
	 * @var Array<string, PullStats> The pull stat trackers for the session, keyed by module name
	 */
	public array $pull_stats = [];

	/**
	 * @var string Flag for the import start time
	 * @readonly
	 */
	public string $run_start_time;

	/**
	 * @var string Store for the current API call limit
	 */
	public string $last_call_limit = '1/40';

	/**
	 * @var int Flag for what stage the run is in
	 */
	private int $run_stage = self::STAGE_SETUP;


	/**
	 * Container class for the various dependencies, helpers, etc that are used
	 * in the course of a Shopify import to make it all easy to pass around.
	 *
	 * @param ShopifySettings $settings The Shopify import settings
	 * @param ApiClient $client The CL client
	 */
	public function __construct(
		ShopifySettings $settings,
		ApiClient $client
	)
	{
		$this->settings = $settings;
		$this->client = $client;

		$this->run_start_time = date(DATE_ATOM);
	}

	/**
	 * Update the last call limit information based on the most recent
	 * response in this session's client.
	 *
	 * @return void This extracts the call limit from the internal client
	 * and stores it in {@see last_call_limit}
	 */
	public function set_last_call_limit() : void
	{
		$this->last_call_limit = $this->client->getHeader('X-Shopify-Shop-Api-Call-Limit') ?? $this->last_call_limit;
	}

	/**
	 * Set the stage that the run is in. The value supplied should be one of the
	 * STAGE_* constants from this class.
	 *
	 * @param int $stage The STAGE_* flag to set for the run
	 */
	public function set_run_stage(int $stage) : void
	{
		$this->run_stage = $stage;
	}

	/**
	 * Is the run in the final output stage?
	 *
	 * @return bool TRUE if so, FALSE if no
	 */
	public function in_final_output_stage() : bool
	{
		return $this->run_stage === self::STAGE_FINAL_OUTPUT;
	}

	/**
	 * Set this instance as the statically-available active session.
	 */
	public function set_as_active() : void
	{
		self::set_active_session($this);
	}

	/**
	 * Get the statically-available active session.
	 *
	 * @return ?self The active session, if set
	 */
	public static function get_active_session() : ?self
	{
		return self::$active_session;
	}

	/**
	 * Set the statically-available active session.
	 *
	 * @param self $session The session to set
	 */
	public static function set_active_session(self $session) : void
	{
		self::$active_session = $session;
	}

	public static function get_active_setting(string $key, $default = null)
	{
		$session = self::$active_session;
		if ($session === null) {
			return $default;
		}
		return $session->settings->get($key, $default);
	}

}

