<?php

namespace ShopifyConnector\connectors;

use ShopifyConnector\connectors\shopify\ProductFilterManager;
use ShopifyConnector\connectors\shopify\ShopifyRunManager;
use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\ShopifySettings;
use ShopifyConnector\connectors\shopify\services\AccessService;
use ShopifyConnector\connectors\shopify\services\ProductService;
use ShopifyConnector\connectors\shopify\services\ShopService;
use ShopifyConnector\api\ApiClient;
use ShopifyConnector\exceptions\ApiResponseException;
use ShopifyConnector\exceptions\MissingPermissionsException;
use ShopifyConnector\util\db\ConnectionFactory;
use ShopifyConnector\util\io\OutputTemplate;

/**
 * Shopify integration main class.
 */
class ShopifyModular extends BaseConnector {

	/**
	 * Used in access/permissions checking.
	 * @var array<string> Map of request actions to required access scopes
	 */
	const REQUEST_TO_SCOPE_MAP = [
		'inventory_level' => 'read_inventory',
		'inventory_item' => 'read_inventory',
		'products' => 'read_products',
	];


	private SessionContainer $session;


	/**
	 *
	 */
	public function __construct(array $clientOptions, array $file_info){
		#$clientOptions['force_api'] = ShopifySettings::FLAG_API_REST;
		$settings = new ShopifySettings($clientOptions);

		$client = new ApiClient();
		$client->setShop($settings->get('shop_name'));
		$client->setOauthToken($settings->get('oauth_token'));

		$this->session = new SessionContainer(
			$settings,
			$client
		);
		$this->session->set_as_active();
		parent::__construct($clientOptions, $file_info);
	}

	/**
	 * @inheritDoc
	 */
	public function transform_data_file(string $path_to_file) : string {
		return $path_to_file;
	}

	/**
	 * @inheritDoc
	 */
	public function export(callable $output_func) : void {

		###
		### Gather info about shop and make pre-adjustments
		###

		# used from shop: country_code, created_at, domain
		$shop = ShopService::get_shop_info_gql($this->session);
		$this->session->shop = $shop;

		if (empty($shop->country_code) && $this->session->settings->use_gmc_transition_id) {
			throw new ApiResponseException('Unable to support gmc transition id when country code missing');
		}

		/*
		$this->adjust_settings_for_product_count(ProductService::getCountForRangeREST(
			$this->session,
			$shop->created_at,
			$this->session->run_start_time,
			$this->session->settings->get_product_filter(ProductFilterManager::FILTER_PUBLISHED_STATUS)
		));
		*/


		###
		### Set up everything needed for this run and invoke the manager to pull the data
		###

		$manager = new ShopifyRunManager($this->session);

		$cxn = ConnectionFactory::connect(ConnectionFactory::DB_LOCAL);
		$manager->run($cxn);


		###
		### Prepare output handlers and any other resources -- must be done post run
		###

		$output_fields = $manager->get_output_field_list();
		$o_tmpl = new OutputTemplate();
		$o_tmpl->append_keyless_to_template($output_fields);


		###
		### Iterate through results and output data to client
		###

		$output_func($o_tmpl->get_template());
		foreach ($manager->retrieve_output($cxn, $output_fields) as $output_data) {
			$output_func($o_tmpl->fill_template($output_data));
		}

	}

	/**
	 * Check the grants on the credentials we are using and make sure that access
	 * has been granted for all the scopes that will be accessed in the pull.
	 *
	 * @throws MissingPermissionsException When necessary scopes are missing from grants
	 */
	private function checkAccess() : void {
		$tokenScopes = AccessService::get_access_scopes($this->session);
		$missingScopes = [];

		if ($this->session->settings->include_inventory_level && !$tokenScopes->hasScope('read_locations')) {
			$missingScopes[] = 'read_locations';
		}

		foreach($this->session->settings->get('data_types', []) as $item){
			$neededScope = self::REQUEST_TO_SCOPE_MAP[$item] ?? null;
			if($neededScope === null){
				continue;
			}

			if(!$tokenScopes->hasScope($neededScope)){
				$missingScopes[] = $neededScope;
			}
		}

		if(!empty($missingScopes)){
			throw new MissingPermissionsException(implode(', ', array_unique($missingScopes)));
		}
	}

	/**
	 * Make adjustments to the settings based on the product count for optimization
	 * and/or just plain avoiding things crapping out.
	 *
	 * @param int $productCount The count of the products that need to be pulled
	 * @return bool TRUE if settings were adjusted, FALSE if not
	 */
	private function adjust_settings_for_product_count(int $productCount) : bool {
		if($productCount > 50000){
			$this->session->settings->force_bulk_pieces = true;
			return true;
		}

		return false;
	}

	public function get_api_info() : void
	{
	}

}

