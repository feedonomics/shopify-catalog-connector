<?php

namespace ShopifyConnector\connectors;

use ShopifyConnector\connectors\shopify\InfoLister;
use ShopifyConnector\connectors\shopify\ShopifyRunManager;
use ShopifyConnector\connectors\shopify\SessionContainer;
use ShopifyConnector\connectors\shopify\ShopifySettings;
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
	 * @var string[] Map of request actions to required access scopes
	 */
	private const REQUEST_TO_SCOPE_MAP = [
		'inventory_level' => 'read_inventory',
		'inventory_item' => 'read_inventory',
		'products' => 'read_products',
	];

	/**
	 * @var SessionContainer Store for the session container
	 */
	private SessionContainer $session;


	/**
	 * @param array $client_options
	 * @param array $file_info
	 * @throws ValidationException
	 */
	public function __construct(array $client_options, array $file_info)
	{
		#$clientOptions['force_api'] = ShopifySettings::FLAG_API_REST;
		$settings = new ShopifySettings($client_options);

		$client = new ApiClient();
		$client->setShop($settings->get('shop_name'));
		$client->setOauthToken($settings->get('oauth_token'));

		$this->session = new SessionContainer(
			$settings,
			$client
		);
		$this->session->set_as_active();
		parent::__construct($client_options, $file_info);
	}

	/**
	 * @inheritDoc
	 */
	public function transform_data_file(string $path_to_file) : string {
		return $path_to_file;
	}

	/**
	 * @param callable $insert_row_func
	 * @return void
	 * @throws ApiException
	 * @throws ApiResponseException
	 * @throws MissingPermissionsException
	 * @throws ValidationException
	 * @throws InfrastructureErrorException
	 */
	public function export(callable $insert_row_func) : void
	{
		# Make sure we have all the permissions we'll need before starting
		$this->check_access();

		###
		### Gather info about shop and make pre-adjustments
		###
		$this->session->initialize();

		if (empty($this->session->shop->country_code) && $this->session->settings->use_gmc_transition_id) {
			throw new ApiResponseException('Unable to support gmc transition id when country code missing');
		}

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

		$insert_row_func($o_tmpl->get_template());
		foreach ($manager->retrieve_output($cxn, $output_fields) as $output_data) {
			$insert_row_func($o_tmpl->fill_template($output_data));
		}
	}

	/**
	 * Check the grants on the credentials we are using and make sure that access
	 * has been granted for all the scopes that will be accessed in the pull.
	 *
	 * @throws MissingPermissionsException When necessary scopes are missing from grants
	 * @throws ApiException
	 */
	private function check_access() : void
	{
		$needed_scopes = [];

		if ($this->session->settings->include_inventory_level) {
			$needed_scopes[] = 'read_locations';
		}

		if ($this->session->settings->include_product_markets) {
			$needed_scopes[] = 'read_publications';
		}

		foreach ($this->session->settings->get('data_types', []) as $data_type) {
			$scope = self::REQUEST_TO_SCOPE_MAP[$data_type] ?? null;
			if ($scope !== null) {
				$needed_scopes[] = $scope;
			}
		}

		$missing_scopes = array_filter($needed_scopes, fn(string $scope) => !$this->session->get_access_scopes()->has_scope($scope));

		if (!empty($missing_scopes)) {
			throw new MissingPermissionsException(implode(', ', array_unique($missing_scopes)));
		}
	}

	/**
	 * Info lister to provide summary information about the given Shopify site
	 *
	 * @return void Response data is json encoded and printed to STDOUT
	 */
	public function get_api_info() : void
	{
		echo json_encode((new InfoLister($this->session))->get_sample_data());
	}

}