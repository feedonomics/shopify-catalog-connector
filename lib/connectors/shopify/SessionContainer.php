<?php

namespace ShopifyConnector\connectors\shopify;

use ShopifyConnector\connectors\shopify\ShopifySettings;
use ShopifyConnector\connectors\shopify\models\AccessScopes;
use ShopifyConnector\connectors\shopify\models\Shop;
use ShopifyConnector\connectors\shopify\structs\PullStats;
use ShopifyConnector\connectors\shopify\services\AccessService;
use ShopifyConnector\connectors\shopify\services\ShopService;

use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\exceptions\ApiResponseException;

use ShopifyConnector\api\ApiClient;
use ShopifyConnector\log\ErrorLogger;

/**
 * Container class for the various dependencies, helpers, etc. that are used in
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

	/**
	 * @var SessionContainer|null The active session instance
	 */
	private static ?self $active_session = null;

	/**
	 * @var Shop|null The data for the shop being pulled from
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
	 * @var array<string, true> Store for all currently-tracked bulk operation IDs
	 */
	private array $current_bulk_ids = [];

	/**
	 * @var int Flag for what stage the run is in
	 */
	private int $run_stage = self::STAGE_SETUP;

	/**
	 * @var AccessScopes The access scopes available to the app/token
	 */
	private AccessScopes $access_scopes;

	/**
	 * Container class for the various dependencies, helpers, etc that are used
	 * in the course of a Shopify import to make it all easy to pass around.
	 *
	 * @param ShopifySettings $settings The Shopify import settings
	 * @param ApiClient $client The API client
	 */
	public function __construct(
		public readonly ShopifySettings $settings,
		public readonly ApiClient   $client
	)
	{
	}

	/**
	 * Initializes a session
	 * @throws ApiResponseException
	 */
	public function initialize() : void
	{
		$this->set_as_active();
		$this->shop = ShopService::get_shop_info_gql($this);
		register_shutdown_function($this->cleanup_bulk_operation(...));
	}

	/**
	 * Track a bulk operation ID for shutdown cleanup.
	 *
	 * @param string $gid The Shopify GID for a running bulk operation
	 */
	public function add_bulk_id(string $gid) : void
	{
		$this->current_bulk_ids[$gid] = true;
	}

	/**
	 * Stop tracking a bulk operation ID (e.g. after it completes normally).
	 *
	 * @param string $gid The Shopify GID to remove
	 */
	public function remove_bulk_id(string $gid) : void
	{
		unset($this->current_bulk_ids[$gid]);
	}

	/**
	 * Cancel a single bulk operation via the Shopify API and stop tracking it.
	 * Failures of the cancel mutation are logged but do not throw, so callers
	 * can use this in cleanup paths without compounding errors.
	 *
	 * @param string $gid The Shopify GID for the bulk operation to cancel
	 */
	public function cancel_bulk_operation(string $gid) : void
	{
		try {
			$this->client->graphql_request(<<<GRAPHQL
				mutation {
					bulkOperationCancel(id: "{$gid}") {
						bulkOperation {
							status
						}
					}
				}
				GRAPHQL
			);
		} catch (\Exception $e) {
			ErrorLogger::log_error('Bulk operation cancel failed for ' . $gid . ': ' . $e->getMessage());
		}
		$this->remove_bulk_id($gid);
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

	/**
	 * @throws ApiException
	 */
	public function get_access_scopes() : AccessScopes
	{
		$this->access_scopes ??= AccessService::get_access_scopes($this);
		return $this->access_scopes;
	}

	/**
	 * Shutdown handler for the run: cancels every still-tracked bulk operation
	 * so a crash or fatal does not leave bulk queries running on the shop.
	 * Cancelling has no effect on completed or failed queries.
	 */
	private function cleanup_bulk_operation() : void
	{
		foreach (array_keys($this->current_bulk_ids) as $bulk_id) {
			$this->cancel_bulk_operation($bulk_id);
		}
	}

}
