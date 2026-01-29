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
	 * @var string|null Store for the current bulk ID
	 */
	private ?string $current_bulk_id = null;

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
	 * Set the current bulk operation id. The value supplied should be a Shopify GID string
	 *
	 * @param ?string $gid The current gid for running bulk operation
	 */
	public function set_current_bulk_id(?string $gid) : void
	{
		$this->current_bulk_id = $gid;
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
	 * This is to clear bulk queries so processes can be retried
	 * Canceling has no effect on completed or failed queries
	 *
	 * @throws ApiException
	 */
	private function cleanup_bulk_operation() : void
	{
		if ($this->current_bulk_id !== null) {
			try {
				$this->client->graphql_request(<<<GRAPHQL
					mutation {
						bulkOperationCancel(id: "{$this->current_bulk_id}") {
							bulkOperation {
								status
							}
						}
					}
					GRAPHQL
				);
			} catch (\Exception $e) {
				ErrorLogger::log_error('Bulk operation cleanup failed: ' . $e->getMessage());
			}
		}
	}
}