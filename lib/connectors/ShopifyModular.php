<?php

namespace ShopifyConnector\connectors;

use Exception;

use ShopifyConnector\api\ApiClient;
use ShopifyConnector\api\service\AccessService;
use ShopifyConnector\api\service\ProductService;
use ShopifyConnector\api\service\CountryService;

use ShopifyConnector\api\service\StoreService;
use ShopifyConnector\exceptions\ApiException;
use ShopifyConnector\exceptions\ApiResponseException;
use ShopifyConnector\exceptions\MissingPermissionsException;
use ShopifyConnector\exceptions\CoreException;
use ShopifyConnector\exceptions\InfrastructureErrorException;

use ShopifyConnector\util\File_Utilities;
use ShopifyConnector\util\Parallel;
use ShopifyConnector\util\ProductFilterManager;
use ShopifyConnector\util\RateLimiter;
use ShopifyConnector\util\db\ConnectionFactory;
use ShopifyConnector\util\file\TemporaryFile;
use ShopifyConnector\util\file\TemporaryFileGenerator;
use ShopifyConnector\util\io\InputParser;
use ShopifyConnector\util\io\OutputTemplate;

use ShopifyConnector\validation\ShopifySettingsValidator;


/**
 * Business logic for connecting to a customer's Shopify store, pulling
 * product data, manipulating pulled data, then passing the reformatted
 * data to a callback
 */
class ShopifyModular extends BaseConnector {

	/**
	 * @var string Prefix for the variant names columns if they are being
	 * exploded in the output
	 */
	const PREFIX_VARIANT = 'variant_';

	protected $services = [];

	private $shop;
	private $country_code;
	private $error_thrown = false;
	private $default_header = [];
	private $shopify_client;
	private $rate_limiter;
	private $rate_modifier = 3;
	private $created_at_start = '2006-01-01';
	private $created_at_end;
	private $tables = [];
	private $log_file;
	private $log_string = '';

	# How often to retry when blocked or poll when running bulk query
	private $bulk_wait = 5;

	# Used when determining date ranges for chunks
	private $date_chunks = '1 year';

	private $tax_rates;
	private $bulk_processes = [
		'meta'           => [],
		'collections'    => [],
		'inventory_item' => [],
	];
	private $coll_fields = [
		'id',
		'handle',
		'title',
	];
	private $meta_fields = [
		'key',
		'value',
		'namespace',
		'description',
	];
	private $rest_settings = [
		'current_tier' => [
			'product'    => 0,
			'collection' => 0,
		],
		'limit_backoff' => [
			'product' => [
				0 => 250,
				1 => 150,
				2 => 125,
				3 => 100,
				4 => 75,
				5 => 50,
				6 => 25,
				7 => 10,
			],
			'collection' => [
				0 => 250,
				1 => 150,
				2 => 125,
				3 => 100,
				4 => 75,
				5 => 50,
			]
		]
	];

	/**
	 * @var TemporaryFile Store for Graphql bulk operation ids
	 */
	private TemporaryFile $bulk_id_file;

	/**
	 * @var ProductFilterManager Store for the product filter manager
	 */
	protected ProductFilterManager $product_filters;

	/**
	 * @var OutputTemplate Store for the output template
	 */
	protected OutputTemplate $template;

	/**
	 * Parse the request and get the Shopify client ready to pull data
	 *
	 * @param array $connection_info The user settings
	 * @throws CoreException On validation errors
	 */
	public function __construct(array $connection_info = [], array $file_info = []){
		parent::__construct($connection_info, $file_info);

		// @TODO Check to make sure the shop has access to the things they want to get (inventory_item/inventory_level)
		// Prioritize oauth_token, password is a fallback, if neither is
		// provided default null to error out with the validator
		$token = $connection_info['password'] ?? null;
		$token = $connection_info['oauth_token'] ?? $token;
		$connection_info['oauth_token'] = $token;

		ShopifySettingsValidator::validate($connection_info);

		$this->bulk_id_file = TemporaryFileGenerator::get('bulk_ids_');

		// Delete the tables that are created once the parent finishes.
		$parent_pid = getmypid();
		register_shutdown_function(function() use ($parent_pid){
			if ($parent_pid == getmypid()) {
				$this->cleanup();
			}
		});


		$connection_info['data_types'] = explode(',',$connection_info['data_types'] ?? 'products');
		// @TODO START ----- BACKWARDS COMPATIBILITY UNTIL WE SWITCH EVERYONE OVER. WE WILL TRANSLATE THE OLD OPTIONS TO THE NEW OPTION
		$modules = [];

		$modules['meta'] = InputParser::extract_boolean($connection_info, 'meta');
		$modules['collections'] = InputParser::extract_boolean($connection_info, 'collections');
		$modules['collections_meta'] = InputParser::extract_boolean($connection_info, 'collections_meta');
		$modules['inventory_level'] = InputParser::extract_boolean($connection_info, 'inventory_level');
		$modules['inventory_item'] = InputParser::extract_boolean($connection_info, 'inventory_item');

		// collections meta and inventory level depend on another module
		if ($modules['inventory_level']) {
			$modules['inventory_item'] = true;
		}
		if ($modules['collections_meta']) {
			$modules['collections'] = true;
		}
		foreach($modules as $module => $include) {
			if ($include) {
				$connection_info['data_types'][] = $module;
			}
		}
		// @TODO END ----- BACKWARDS COMPATIBILITY UNTIL WE SWITCH EVERYONE OVER. WE WILL TRANSLATE THE OLD OPTIONS TO THE NEW OPTION

		$this->product_filters = new ProductFilterManager($connection_info);

		$connection_info['metafields_split_columns'] = InputParser::extract_boolean($connection_info, 'metafields_split_columns');
		$connection_info['variant_names_split_columns'] = InputParser::extract_boolean($connection_info, 'variant_names_split_columns');
		$connection_info['inventory_level_explode']	= InputParser::extract_boolean($connection_info, 'inventory_level_explode');
		$connection_info['include_presentment_prices']	= InputParser::extract_boolean($connection_info, 'include_presentment_prices', true);
		$connection_info['extra_parent_fields']	= trim($connection_info['extra_parent_fields'] ?? '');
		if ($connection_info['extra_parent_fields']  != ''){
			$connection_info['extra_parent_fields'] = array_flip(explode(',', $connection_info['extra_parent_fields']));
		}
		$connection_info['extra_variant_fields']	= trim($connection_info['extra_variant_fields'] ?? '');
		if ($connection_info['extra_variant_fields']  != ''){
			$connection_info['extra_variant_fields'] = array_flip(explode(',', $connection_info['extra_variant_fields']));
		}

		$connection_info['delimiter']  = $connection_info['delimiter'] ?? ',';
		$connection_info['enclosure'] = $connection_info['enclosure'] ?? '"';
		$connection_info['escape'] = $connection_info['escape'] ?? '"';
		$connection_info['strip_characters'] = $connection_info['strip_characters'] ?? [];
		$connection_info['replace'] = $connection_info['replace'] ?? '';
		$connection_info['compare_price_override'] = $connection_info['compare_price_override'] ?? true;
		$connection_info['product_published_status'] = $connection_info['product_published_status']
			?? $this->product_filters->get_filters()[ProductFilterManager::FILTER_PUBLISHED_STATUS]
			?? 'published';
		$connection_info['tax_rates'] = $connection_info['tax_rates'] ?? '';
		$connection_info['use_gmc_transition_id'] = InputParser::extract_boolean($connection_info, 'use_gmc_transition_id');

		$this->template = new OutputTemplate();

		$client = new ApiClient();
		$client->setOauthToken($connection_info['oauth_token']);
		$client->setShop($connection_info['shop_name']);
		if(InputParser::extract_boolean($connection_info, 'use_proxy')){
			$client->setProxy($GLOBALS['third_party_credentials']['shopify_proxy']);
		}
		$this->shopify_client = $client;
		// Set the initial rate limiting to the Shopify default (this rate limiting applies to the parent's speed at which is forks
		$this->rate_limiter = new RateLimiter(4,1);
		$this->created_at_end = date(DATE_ATOM, time());

		$connection_info['debug'] = InputParser::extract_boolean($connection_info, 'debug');
		$clean_shop = preg_replace("/[^A-Za-z0-9 ]/", '', $connection_info['shop_name']);
		$this->log_file = $GLOBALS['file_paths']['tmp_path'] .  "/shopify_log_{$clean_shop}_" . bin2hex(openssl_random_pseudo_bytes(7));

		// Shopify graphQL fails on certain stores that have too many products condensed in too short of a span of time
		// This forced bulking will be applied to shops with 50K+ products to increase stability
		$connection_info['force_bulk_pieces'] = $connection_info['force_bulk_pieces'] ?? false;
		$this->connection_info = $connection_info;
	}

	/**
	 * Write log information if an error occurred during the import
	 */
	function __destruct(){
		if ($this->log_string !== '') {
			$force = strpos($this->log_string, 'Threw error') !== false;
			$this->write_log($this->log_string, $force);
		}
	}

	/**
	 * Lazy loader for the access service
	 *
	 * @return AccessService The access service
	 */
	private function get_access_service() : AccessService {
		$this->limit_the_rate();
		if (!isset($this->services['access'])) {
			$this->services['access'] = new AccessService($this->shopify_client);
		}
		return $this->services['access'];
	}

	/**
	 * Lazy loader for the product service
	 *
	 * @return ProductService The product service
	 */
	private function get_product_service() : ProductService {
		$this->limit_the_rate();
		if (!isset($this->services['product'])) {
			$this->services['product'] = new ProductService($this->shopify_client);
		}
		return $this->services['product'];
	}

	/**
	 * Lazy loader for the store service
	 *
	 * @return StoreService The store service
	 */
	private function get_store_service() : StoreService {
		if(!isset($this->services['store'])){
			$this->services['store'] = new StoreService($this->shopify_client);
		}
		return $this->services['store'];
	}

	/**
	 * Process the request for product data and pass the finalized data to the
	 * given callback
	 *
	 * @param callable $insert_row_func The callback to pass data to
	 * @throws CoreException On API, database, and internal errors
	 */
	public function export(callable $insert_row_func) : void {

		$this->write_log("{$this->connection_info['shop_name']} catalog creation. Time started:" . date(DATE_ATOM) . PHP_EOL);

		//check to ensure we have all the necessary permissions before starting
		$this->check_permissions();


### SHOP INFO

		// Get the shop information (and rate limiting as a bonus)
		$store_info = Parallel::do_sync([], function($task, $parent_socket){
			try {
				$store_info = $this->get_store_service()->getStoreInfo();
				fwrite($parent_socket, serialize([
					'store_info' => $store_info,
					'rate_limit' => $this->shopify_client->getHeader('X-Shopify-Shop-Api-Call-Limit') ?? '1/40',
				]));
			} catch (ApiException $e) {
				fwrite($parent_socket, serialize([
					'error' => $e->getMessage(),
				]));
			}
		});

		// Set the shop info
		$store_info = unserialize($store_info);
		if (isset($store_info['error'])) {
			throw new ApiResponseException($store_info['error']);
		}
		$this->shop = $store_info['store_info']['shop'] ?? [];
		if (empty($this->shop)){
			throw new ApiResponseException('Shop info is empty. Please contact a developer for assistance.');
		}


		if ($this->connection_info['tax_rates'] != ''){
			$tax_rates = Parallel::do_sync([], function($task, $parent_socket){
				try {
					$this->connection_info['tax_rates'] = explode(',', strtoupper($this->connection_info['tax_rates']));
					$tax_rates = $this->build_tax_rates($this->shopify_client);
					fwrite($parent_socket, serialize([
						'tax_rates' => $tax_rates,
					]));
				} catch (ApiException $e) {
					fwrite($parent_socket, serialize([
						'error' => $e->getMessage(),
					]));
				}
			});

			$tax_rates = unserialize($tax_rates);
			if (isset($tax_rates['error'])) {
				throw new ApiResponseException($tax_rates['error']);
			}
			$this->tax_rates = $tax_rates['tax_rates'];
		}


		// Get the country needed to create the shopify ids
		$this->country_code = $this->shop['country_code'] ?? '';
		if ($this->connection_info['use_gmc_transition_id'] && $this->country_code == ''){
			throw new ApiResponseException('Country code required.');
		}


		// box ourselves to a certain range while running (from the start of the store until right now)
		$this->created_at_start = $this->shop['created_at'] ?? '2006-01-01'; // If for some reason it doesn't exist, we will start at the start year of Shopify, the company
		$this->write_log("Shop active dates: {$this->created_at_start} - {$this->created_at_end}" . PHP_EOL);


### PRODUCT COUNT AND RATE SETUP

		// We need to make a few base changes to gigantic stores (any store with more than 50,000 products)
		$product_count = Parallel::do_sync([], function($task, $parent_socket){
			try {
				$product_count = $this->get_product_service()->getProductCount([
					'created_at_min' => $this->created_at_start,
					'created_at_max' => $this->created_at_end,
					'published_status' => $this->connection_info['product_published_status'],
				])['count'];
				fwrite($parent_socket, $product_count);
			} catch (ApiException $e) {
				fwrite($parent_socket, serialize([
					'error' => $e->getMessage(),
				]));
			}
		});
		if (isset($product_count['error'])) {
			throw new ApiResponseException($store_info['error']);
		}
		$this->write_log("Expected Product Count: {$product_count}" . PHP_EOL);

		// Start at a smaller request rate (since larger stores tend to 502 on max limit requests
		// Set the rate modifier to a higher number (more threads, since the requests usually take much longer than 1 second anyway, we want to minimize the bottleneck)
		if ($product_count > 50000) {
			// 50,000+ have had inconsistent results with bulk queries, so let's force chunk the bulk queries
			$this->connection_info['force_bulk_pieces'] = true;
			$this->rest_settings['current_tier']['product'] = 1;
			$this->rate_modifier = 4;
			$this->bulk_wait = 30;
			$this->date_chunks = '1 week';
			if ($product_count > 100000) {
				$this->date_chunks = '2 days';
				$this->bulk_wait = 60;
			}
		}


		// Get the rate limit for this store/oauth from the shop call
		$burst_rate_limit = (int)(explode('/', $store_info['rate_limit'])[1] ?? 40);
		$rate_limit = $this->get_modified_rate_limit_for_store($burst_rate_limit); // For Shopify Plus (80) the rate limit is 4, for Shopify normal (40), the rate limit is 2

		$this->write_log("Burst Rate Limit: {$burst_rate_limit} | Rate modifier: {$this->rate_modifier} | Modified Rate Limit: {$rate_limit} ((Burst/20) * modifier)" . PHP_EOL);
		//This rate limiter will limit the threads from spinning up too fast
		$this->rate_limiter->setRate($rate_limit);


### DB SETUP

		$rand_string = '_' . bin2hex(openssl_random_pseudo_bytes(3));
		$buffer_len = 2;
		$unique_name = preg_replace("/[^A-Za-z0-9 ]/", '', $this->connection_info['shop_name']) . $rand_string;
		$main_table_name = strtotime($this->created_at_end) . '_s_'. $unique_name;
		if ((strlen($main_table_name) + $buffer_len) > 64) {
			$main_table_name = substr($main_table_name, 0, (64 - $buffer_len - strlen($rand_string))) . $rand_string;
		}

		$this->create_header();

		// Create the initial table for this store
		$local_cxn = ConnectionFactory::connect(ConnectionFactory::DB_LOCAL);
		$tbl = $local_cxn->strip_enclosure_characters($main_table_name);
		$this->tables['main'] = $tbl;
		$fields = '';
		$keys = '';

		foreach ($this->default_header as $index => $header) {
			$this->default_header[$index] = $this->connection_info['field_mapping'][$header] ?? $header;
		}

		foreach ($this->template->get_template() as $header_field) {
			$type = 'TEXT';
			if (in_array($header_field, ['inventory_item_id', 'item_group_id', 'id'])) {
				if ($header_field === 'id') {
					$keys = 'PRIMARY KEY (id)';
					if (array_key_exists('item_group_id', $this->template->get_template())) {
						$keys .= ', KEY `item_group_id_IDX` (`item_group_id`) USING BTREE';
					}
					if (array_key_exists('inventory_item_id', $this->template->get_template())) {
						$keys .= ', KEY `inventory_item_id_IDX` (`inventory_item_id`) USING BTREE';
					}
				}
				$type = 'BIGINT(4)';
			}
			if (in_array($header_field, ['smart_collections_meta', 'custom_collections_meta', 'product_meta', 'variant_meta'])) {
				$type = 'MEDIUMTEXT';
			}
			$header_field = $local_cxn->strip_enclosure_characters($header_field);
			$fields .= "`{$header_field}` {$type},";
		}

		if (empty($keys)) {
			$keys = 'PRIMARY KEY (item_group_id)';
		}

		$fields = rtrim($fields, ',');
		$create_table_qry = "
			CREATE TABLE `{$tbl}` (
				{$fields},
				{$keys}
			) ENGINE MyISAM CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
		";
		$table_created = $local_cxn->query($create_table_qry);
		if (!$table_created) {
			$this->write_log('Error creating table: ' . $tbl . ' Error: ' . $local_cxn->error . PHP_EOL, true);
			$this->write_log("Query: {$create_table_qry}", true);
			throw new InfrastructureErrorException();
		}

		if (in_array('collections',$this->connection_info['data_types'])) {
			$coll_tbl = str_replace('_s_' , '_s_c_', $tbl);
			$coll_tbl = $local_cxn->strip_enclosure_characters($coll_tbl);
			$this->tables['collections'] = $coll_tbl;
			$meta_field = '';
			if (in_array('collections_meta', $this->connection_info['data_types'])) {
				$meta_field = 'metainfo MEDIUMTEXT,';
			}
			$collections_table_create_qry = "
				CREATE TABLE `{$coll_tbl}` (
					product_id BIGINT(4),
					title TEXT,
					handle TEXT,
					collection_id BIGINT(4),
					collection_type VARCHAR(255),
					{$meta_field}
					CONSTRAINT `UN_collections` UNIQUE KEY (product_id,collection_id)
				) ENGINE MyISAM CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
			";
			$table_created = $local_cxn->query($collections_table_create_qry);
			if (!$table_created) {
				$this->write_log('Error creating table: ' . $coll_tbl . ' Error: ' . $local_cxn->error . PHP_EOL, true);
				$this->write_log("Query: {$collections_table_create_qry}", true);
				throw new InfrastructureErrorException();
			}
		}

		$local_cxn->close();


### DATA PROCESSING

		$bulk_ranges = [];
		foreach ($this->bulk_processes as $process_type => $bulk_id) {
			if (in_array($process_type, $this->connection_info['data_types'])){
				sleep(1);
				if ($process_type !== 'collections') {
					if (empty($bulk_ranges)) {
						$bulk_ranges = $this->get_bulk_date_ranges();
					}
					$this->bulk_processes[$process_type] = $this->bulk_process($process_type, $bulk_ranges);
				} else {
					$this->bulk_processes[$process_type] = $this->bulk_process($process_type, [
						[
							'start' => date('Y-m-d', strtotime($this->created_at_start)),
							'end' => date('Y-m-d', strtotime($this->created_at_end)),
						]
					]);
				}
			}
		}

		if (in_array('products',$this->connection_info['data_types'])) {
			$this->get_products();
			$this->write_log('Products processing is completed. Beginning bulk processing...' . PHP_EOL);
		}

		// Wait until the bulk processes are all done before spitting out the completed file.
		foreach ($this->bulk_processes as $task => $p_info) {
			$status = '';
			if (!empty($p_info)) {
				pcntl_waitpid($p_info['pid'], $status);
				if ($status != 0) {
					throw new ApiResponseException('Bulk Process unexpectedly failed.');
				}
				$download_files = [];
				while($serialized_return = fgets($p_info['socket'], 999999)) {
					$this->write_log("Child Returned: {$serialized_return}" . PHP_EOL);
					$bulk_return = unserialize($serialized_return);
					if (isset($bulk_return['error'])) {
						throw new ApiResponseException($bulk_return['error']);
					}
					$download_files[] = $bulk_return['file'];
				}
				fclose($p_info['socket']);
				$all_download_files = json_encode($download_files);
				$this->write_log("All download files: {$all_download_files}" . PHP_EOL);
				foreach($download_files as $file) {
					$this->write_log('Starting download/processing of ' . $task . ' file: '. $file . PHP_EOL);
					call_user_func([$this, "add_{$task}"], $file);
				}
			}
		}

		if($this->connection_info['variant_names_split_columns']){
			$this->template->remove_key('variant_names');
		}

		$insert_row_func(
			$this->template->get_template(),[
				$this->connection_info['delimiter'],
				$this->connection_info['enclosure'],
				$this->connection_info['escape'],
				$this->connection_info['strip_characters']
			]
		);


		$local_cxn = ConnectionFactory::connect(ConnectionFactory::DB_LOCAL);
		$last_id = 0;
		$limit = 1000;
		$primary_key = 'id';
		if (!array_key_exists($primary_key, $this->template->get_template())) {
			$primary_key = 'item_group_id';
		}

		do {
			$query = "SELECT * FROM `{$this->tables['main']}` WHERE {$primary_key} > {$last_id} ORDER BY {$primary_key} ASC LIMIT {$limit};";
			$product_results = $local_cxn->query($query);
			if (!$product_results) {
				$this->write_log('Error querying table: ' . $tbl . ' Error: ' . $local_cxn->error . ' QUERY:' . $query, true);
				throw new InfrastructureErrorException();
			}
			$this->write_log($product_results->num_rows . ' rows gotten from ' . $last_id . PHP_EOL);

			while ($row = $product_results->fetch_assoc()) {
				$last_id = (int)$row[$primary_key];
				$this->template->cache_data($row);

				// If the request wants variant names split into separate columns
				// decode the json blob and add them to the output template
				if($this->connection_info['variant_names_split_columns']){
					$variant_names = [];
					foreach(json_decode($row['variant_names'], true) as $k => $v){
						$variant_names[self::PREFIX_VARIANT . $k] = $v;
					}
					$this->template->cache_data($variant_names);
				}

				$final_row = $this->template->get_cached_data();

				// If the user wants a new row per inventory level, then let's explode the rows by the different levels
				if ($this->connection_info['inventory_level_explode'] && isset($final_row['inventory_level']) && $final_row['inventory_level'] !== '[]' && !empty($final_row['inventory_level'])) {
					$inventory_levels = json_decode($final_row['inventory_level'], true) ?? [];
					foreach($inventory_levels as $inventory_level) {
						$final_row['inventory_level'] = json_encode($inventory_level);
						$insert_row_func(
							$final_row, [
								$this->connection_info['delimiter'],
								$this->connection_info['enclosure'],
								$this->connection_info['escape'],
								$this->connection_info['strip_characters']
							]
						);
					}
				} else {
					$insert_row_func(
						$final_row, [
							$this->connection_info['delimiter'],
							$this->connection_info['enclosure'],
							$this->connection_info['escape'],
							$this->connection_info['strip_characters']
						]
					);
				}
			}
		} while ($product_results->num_rows == $limit);
	}

	/**
	 * Start an async while loop that attempt to start a Bulk GraphQL operation
	 * Once started, then waits until bulk process is done
	 * Once process is done, process the bulk file and loads it into the main table
	 *
	 * @param string $process
	 * @param array $date_ranges
	 * @return array
	 */
	private function bulk_process ($process, $date_ranges) : array {
		return Parallel::do_async($process, function($task, $parent_socket) use ($date_ranges){

			$bulk_fh = fopen($this->bulk_id_file->get_absolute_path(), 'a');
			if ($this->connection_info['force_bulk_pieces']) {
				$this->bulk_wait = 1;
			}
			$ranges_tried = [];
			$max_tries = 5;
			for($i = 0; $i < count($date_ranges); $i++) {
				$date_range = $date_ranges[$i];
				$formatted_start_date = date('Y-m-d', strtotime($date_range['start']));
				$formatted_end_date = date('Y-m-d', strtotime($date_range['end']));
				$options_json = json_encode($date_range);
				do {
					// No matter what bulk we do, it's going to be based on the product
					$query = 'products(query: "published_status:' . $this->connection_info['product_published_status'] . ' AND created_at:>=' . $formatted_start_date . ' AND created_at:<=' . $formatted_end_date . '") {
										edges {
											node {
												id
												';
					switch ($task) {
						case 'collections':
							{
								$meta_fields = '';
								if (in_array('collections_meta', $this->connection_info['data_types'])) {
									$meta_fields = 'metafields {
															edges {
																node {
																	id
																	key
																	value
																	namespace
																	description
																}
															}
														}';
								}
								$query = 'collections {
											edges {
												node {
													id
													handle
													title
													ruleSet {
														appliedDisjunctively
													}
													' . $meta_fields . '
													products {
														edges {
															node {
																id
															}
														}
													}';
								break;
							}
						case 'meta':
							{
								$query .= 'metafields{
											edges {
												node {
													key
													value
													namespace
													description
												}
											}
										}
										variants{
											edges {
												node {
													id
													metafields {
														edges {
															node {
																key
																value
																namespace
																description
															}
														}
													}
												}
											}
										}';
								break;
							}
						case 'inventory_item':
							{

								$inventory_levels = '';
								if (in_array('inventory_level', $this->connection_info['data_types'])) {
									$inventory_levels = '
							inventoryLevels {
								edges {
									node {
										id
										available
										location {
											id
											name
										}
									}
								}
							}';
								}
								// This is a bulk process that does not have products as a root, so overwrite the first part as well
								$query = '
						productVariants (query: "published_status:' . $this->connection_info['product_published_status'] . ' AND created_at:>=' . $formatted_start_date . ' AND created_at:<=' . $formatted_end_date . '"){
								edges {
									node {
										id
										inventoryItem {
											id
											sku
											unitCost {
												amount
												currencyCode
											}
											' . $inventory_levels . '
										}';
								break;
							}
					}

					//close the query off
					$query .= '
						}
					}
				}';
					// Set up a bulk process to get the collection information that we want
					$qry = 'mutation {
							bulkOperationRunQuery(
								query:"""
								{
									' . $query . '
								}
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
					try {
							$decoded_response = $this->shopify_client->graphqlRequest($qry);
					} catch (ApiException $e) {
						$error_data = $e->getDecodedData();
						if (!isset($error_data['status']) || !in_array($error_data['status'],[502,503])) {
							fwrite($parent_socket, serialize(['error' => $e->getMessage()]) . PHP_EOL);
							exit;
						}
					}
					if (!empty($decoded_response['data']['bulkOperationRunQuery']['userErrors']) || isset($decoded_response['errors'][0]) && $decoded_response['errors'][0] == 'Throttled') {
						// If there is already a bulk going, just keep waiting a minute until we can start our bulk operation.
						if (strpos($decoded_response['data']['bulkOperationRunQuery']['userErrors'][0]['message'], 'already in progress') !== false) {
							$this->write_log('Waiting for another Bulk Operation to finish before starting the ' . $task . ' (Options: ' . $options_json . ') bulk operation.' . PHP_EOL);
							sleep($this->bulk_wait);
							continue;
						}
					}
					$bulk_id = $decoded_response['data']['bulkOperationRunQuery']['bulkOperation']['id'] ?? '';
					if (!empty($bulk_id)) {
						$this->write_log("The bulk process for {$task} (Options: {$options_json}) has been started...Bulk id is {$bulk_id}" . PHP_EOL);
						fwrite($bulk_fh, $bulk_id . PHP_EOL);
					}
					$this->write_log(json_encode($decoded_response ?? []) . PHP_EOL);
				} while (empty($bulk_id));
				// Now that we have set up a bulk process, we will check on that process until it's done, then process the file
				$download_file = '';
				while($download_file === '' && !empty($bulk_id)) {
					$qry = '
				{
					node(id: "'.$bulk_id.'") {
						... on BulkOperation {
							id
							status
							errorCode
							createdAt
							completedAt
							objectCount
							rootObjectCount
							fileSize
							url
						}
					}
				}';
					try {
						$decoded_response = $this->shopify_client->graphqlRequest($qry);
					} catch (ApiException $e) {
						$error_data = $e->getDecodedData();
						if (!isset($error_data['status']) || !in_array($error_data['status'],[502,503])) {
							fwrite($parent_socket, serialize(['error' => $e->getMessage()]));
							exit;
						}
					}
					if (!empty($decoded_response['data']['errors']) || !empty($decoded_response['data']['node']['errorCode'])) {
						// If there is already a bulk going, just keep waiting a minute until we can start our bulk operation.
						$error = $decoded_response['data']['errors']['message'] ?? $decoded_response['data']['node']['errorCode'] ?? 'There was an unknown error with GraphQL.';

						$ranges_tried[$i] = ($ranges_tried[$i] ?? 0) + 1;
						$this->write_log('Unexpected error. Message: ' . $error . PHP_EOL);
						if ($ranges_tried[$i] < $max_tries) {
							$this->write_log("Adding the date range back to the list for another go.. attempt {$ranges_tried[$i]}/{$max_tries}" . PHP_EOL);
							// throw the date range
							$date_ranges[] = $date_range;
							break;
						}
						$this->write_log(json_encode($error), true);
						fwrite($parent_socket, serialize([
							'error' => $error
						]) . PHP_EOL);
						exit;
					}
					if (isset($decoded_response['data']['node']['status']) && $decoded_response['data']['node']['status'] !== 'RUNNING' && $decoded_response['data']['node']['status'] !== 'CREATED') {
						$download_file = $decoded_response['data']['node']['url'] ?? false;
						$force_log = ($decoded_response['data']['node']['status'] ?? '') !== 'COMPLETED';
						$this->write_log(json_encode($decoded_response) . PHP_EOL, $force_log);
						$this->write_log('The '. $task . ' (Options: '.$options_json.') bulk operation completed with status '.$decoded_response['data']['node']['status'].'. Queued download/processing of following URL: ' . $download_file . PHP_EOL .PHP_EOL);
					} else {
						$this->write_log('Waiting for the '. $task . ' (Options: '.$options_json.') bulk operation to complete... Current status: ' . ($decoded_response['data']['node']['status'] ?? 'No status given.') . PHP_EOL);
						sleep($this->bulk_wait);
					}
				}
				$bulk_id = null;
				if (!empty($download_file)) {
					fwrite($parent_socket, serialize([
						'file' => $download_file,
					]) . PHP_EOL);
				}
			}
		});
	}

	/**
	 * Pull and store product data
	 *
	 * @throws CoreException On API or DB errors
	 */
	private function get_products() : void {
		$all_date_ranges = $this->get_date_ranges();

		// Absolute maximum of 50 threads, so we don't kill the servers
		$thread_count  = min($this->rate_limiter->getRate(), count($all_date_ranges), 50);
		$this->write_log("Thread count (max: 50): {$thread_count}" . PHP_EOL);

		// Create as many forks as we have rate/second and have each fork only allow 1 call per second
		Parallel::do_parallel(
			$all_date_ranges,
			$thread_count,
			function($task_info, $parent_socket){
				$local_cxn = ConnectionFactory::connect(ConnectionFactory::DB_LOCAL);
				$this->log_string .= 'Task Started: GET PRODUCTS | ' . json_encode($task_info) . PHP_EOL;
				$this->rate_limiter = new RateLimiter(1,$this->rate_modifier);
				$this->limit_the_rate();
				$initial_params = array_merge(
					$this->product_filters->get_filters(),
					[
						'created_at_min' => $task_info['start'],
						'created_at_max' => $task_info['end'],
						'order' => 'created_at ASC',
					]
				);
				$child_data = [
					'status' => 'success',
					'variant_names' => [],
				];


				try {
					$this->paginate_endpoint_results(
						function ($params) use ($local_cxn, &$child_data) {
							$this->log_string .= '  --Params: ' . json_encode($params) . PHP_EOL;
							$headers = [];
							if ($this->connection_info['include_presentment_prices']) {
								$headers['X-Shopify-Api-Features'] = 'include-presentment-prices';
							}
							$products = $this->get_product_service()->listProducts(
								$params,
								$headers
							);
							$page_info = $this->shopify_client->parseLastPaginationLinkHeader();
							$next_page_cursor = $page_info['next'] ?? '';
							$total_products = 0;
							$skipped_products = 0;
							$total_exploded = 0;
							foreach ($products['products'] as $product) {
								$base_product_row = $this->populate_base_row($product);

								// Skip if no variants
								if (empty($product['variants'])) {
									$skipped_products++;
									continue;
								}
								$total_products++;
								// Build variant rows
								foreach ($product['variants'] as $variant) {
									$total_exploded++;
									$variant_data = $this->populate_variant_row($product, $variant);

									$ordered_row = [];
									$comma_fields = '';
									$comma_values = '';
									$on_duplicate = '';
									foreach ($this->template->get_template() as $field_name) {
										$clean_field = $local_cxn->strip_enclosure_characters($field_name);
										$comma_fields .= "`{$clean_field}`,";
										$field_to_get = $field_name;
										if (isset($this->connection_info['field_mapping'])) {
											$field_to_get = $this->connection_info['field_mapping'][$field_to_get] ?? $field_to_get;
										}
										$ordered_row[$field_name] = '';
										if (array_key_exists($field_to_get, $product)) {
											$ordered_row[$field_name] = $product[$field_to_get];
										}
										if (array_key_exists($field_to_get, $base_product_row)) {
											$ordered_row[$field_name] = $base_product_row[$field_to_get];
										}
										if (array_key_exists($field_to_get, $variant)) {
											$ordered_row[$field_name] = $variant[$field_to_get];
										}
										if (array_key_exists($field_to_get, $variant_data)) {
											$ordered_row[$field_name] = $variant_data[$field_to_get];
										}
										$clean_value = $local_cxn->real_escape_string($ordered_row[$field_name]);
										$comma_values .= "'{$clean_value}',";
										$on_duplicate .= "`{$clean_field}`='{$clean_value}',";
									}
									$comma_fields = rtrim($comma_fields, ',');
									$comma_values = rtrim($comma_values, ',');
									$on_duplicate = rtrim($on_duplicate, ',');
									$qry = "INSERT INTO `{$this->tables['main']}` ({$comma_fields}) VALUES ({$comma_values}) ON DUPLICATE KEY UPDATE {$on_duplicate};";
									$insert_result = $local_cxn->query($qry);
									if (!$insert_result) {
										$this->write_log($local_cxn->error . PHP_EOL, true);
										$this->write_log($qry . PHP_EOL, true);
										exit();
									}

									foreach(array_keys(json_decode($variant_data['variant_names'], true)) as $vn){
										if(!empty($vn)){
											$child_data['variant_names'][self::PREFIX_VARIANT . $vn] =  self::PREFIX_VARIANT . strtolower($vn);
										}
									}
								}
							}
							$this->log_string .= '    --Skipped Products For Request ( no variants): ' . $skipped_products . PHP_EOL;
							$this->log_string .= '    --Total Products For Request: ' . $total_products . PHP_EOL;
							$this->log_string .= '    --Total Exploded Products For Request: ' . $total_exploded . PHP_EOL;
							return $next_page_cursor;
						},
						$initial_params
					);
				} catch (Exception $e) {
					fwrite($parent_socket, serialize([
						'status'	=> 'fail',
						'error'		=> $e->getMessage(),
					]));
					exit();
				}
				fwrite($parent_socket, serialize($child_data));
				exit();
			},
			function($child_return){
				$child_response = unserialize($child_return);

				if (isset($child_response['error']) && !$this->error_thrown) {
					$this->error_thrown = true;
					$child_error = json_decode($child_response['error'], true);
					throw new ApiResponseException(
						$child_error['display_message'] ?? $child_response['error']
					);
				}

				if($this->connection_info['variant_names_split_columns'] && !empty($child_response['variant_names'])){
					$this->template->append_to_template($child_response['variant_names']);
				}
			},
			$this->rate_limiter
		);
	}

	/**
	 * Convert a GID to an ID
	 *
	 * @param string $gid The GID to convert
	 * @return int The ID
	 */
	private function shopify_gid_to_id(string $gid) : int {
		$split = explode('/', $gid);
		return (int) array_pop($split);
	}

	/**
	 * Generate a link to the product's child variant
	 *
	 * @param array $product The parent product data
	 * @param array $variant The variant product
	 * @return string The variant's product link
	 */
	private function get_link(array $product, array $variant) : string {
		$url_parts = parse_url(sprintf("https://%s", $this->shop['domain']));
		$url_parts['host'] = str_replace('www.', '', $url_parts['host']);
		if (substr_count($url_parts['host'], '.') < 2) {
			$url_parts['host'] = 'www.'.$url_parts['host'];
		}
		$final_url = $this->unparse_url($url_parts);
		return sprintf(
			'%s/products/%s?variant=%s',
			$final_url ?? '',
			$product['handle'],
			$this->shopify_gid_to_id($variant['id'] ?? '')
		);
	}

	/**
	 * Clean up and normalize a URL
	 *
	 * @param array $parsed_url The result of `parse_url`
	 * @return string The normalized URL
	 */
	private function unparse_url(array $parsed_url) : string {
		$scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
		$host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
		$port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
		$user     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
		$pass     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
		$pass     = ($user || $pass) ? "$pass@" : '';
		$path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
		$query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
		$fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';
		return "$scheme$user$pass$host$port$path$query$fragment";
	}

	/**
	 * Get the price data for a variant product
	 *
	 * @param array $variant The variant product data
	 * @return string The price as a string
	 */
	private function get_price(array $variant) : string {
		$compare_at_price = $variant['compare_at_price'] ?? '';
		$display_price = $variant['price']               ?? '';

		if ($display_price !== '' && $compare_at_price !== ''  && $this->connection_info['compare_price_override']) {
			return $compare_at_price;
		} else {
			return $display_price;
		}

	}

	/**
	 * Get the sale price for a variant
	 *
	 * @param array $variant The variant product data
	 * @return string The sale price as a string
	 */
	private function get_sale_price(array $variant) : string {
		$compare_at_price = $variant['compare_at_price'] ?? '';
		$display_price = $variant['price']               ?? '';

		if ($display_price !== '' && $compare_at_price !== '') {
			return $display_price;
		}

		return '';
	}

	/**
	 * Get a variant's availability
	 *
	 * @param array $variant The variant product data
	 * @return string The availability string
	 */
	private function get_availability(array $variant) : string {
		$inventory_management = $variant['inventory_management'] ?? '';
		$inventory_policy = $variant['inventory_policy']         ?? '';
		$inventory_quantity = $variant['inventory_quantity']     ?? '';

		if (strtolower($inventory_management) === 'shopify' && $inventory_quantity < 1 && strtolower($inventory_policy) === 'deny') {
			return 'out of stock';
		}

		return 'in stock';
	}

	/**
	 * Get additional image links from the product
	 *
	 * @param array $product The parent product data
	 * @param array $variant (ignored) The variant product data
	 * @return string The imploded additional links list
	 */
	protected function get_additional_image_links(array $product, array $variant) : string {
		$image_links = [];
		$images = $product['images'] ?? [];

		foreach ($images as $image) {
			if (!$image['src']) {
				continue;
			}
			$image_links[] = $image['src'];
		}

		return implode(',' , $image_links);
	}

	/**
	 * Get the product's published status
	 *
	 * @param array $product The product data
	 * @return string The published status
	 */
	private function get_published_status(array $product) : string {
		$published_status = $product['published_at'] ?? null;
		return is_null($published_status) ? 'unpublished' : 'published';
	}

	/**
	 * Get a variant option's value by name
	 *
	 * @param array $product The product data
	 * @param array $variant The variant data
	 * @param string $option_name The option name as it appears in the product
	 * options array
	 * @return string The variant option's value
	 */
	private function get_option(array $product, array $variant, string $option_name) : string {
		foreach ($product['options'] as $option) {
			if ($option_name === strtolower($option['name'])) {
				return $variant['option'.$option['position']] ?? '';
			}
		}

		return '';
	}

	/**
	 * Get the product image links
	 *
	 * @param array $product The product data
	 * @param array $variant The variant data
	 * @return string The imploded image links
	 */
	protected function get_image_link(array $product, array $variant) : string {
		$image_links = [];
		$images = $product['images'] ?? [];

		foreach ($images as $image) {
			if (!$image['src']) {
				continue;
			}

			if (!empty($image['variant_ids'])) {
				if (in_array($variant['id'], $image['variant_ids'])) {
					$image_links[] = $image['src'];
				}
			} else {
				$color_identifier = sprintf('color-%s', strtolower($this->get_option($product, $variant, 'color')));
				if (stripos($image['alt'], $color_identifier) !== false) {
					$image_links[] = $image['src'];
				}
			}
		}

		return implode(',', $image_links);
	}

	/**
	 * Get the variant image links
	 *
	 * @param array $product The product data
	 * @param array $variant The variant data
	 * @return string The imploded image links
	 */
	protected function get_additional_variant_image_links(array $product, array $variant) : string {
		$image_links = [];
		$images = $product['images'] ?? [];

		foreach ($images as $image) {
			if (!$image['src']) {
				continue;
			}

			if (!empty($image['variant_ids'])) {
				if (in_array($variant['id'], $image['variant_ids'])) {
					$image_links[] = $image['src'];
				}
			} else {
				$color_identifier = sprintf('color-%s', strtolower($this->get_option($product, $variant, 'color')));
				$color_identifier_2 = strtolower($this->get_option($product, $variant, 'color'));
				if (
					!empty($color_identifier_2)
					&& !empty($image['alt'])
					&& (
						stripos(strtolower($image['alt']), $color_identifier) !== false
						|| stripos(strtolower($image['alt']), $color_identifier_2) !== false
					)
				) {
					$image_links[] = $image['src'];
				}
			}
		}

		return implode(',', $image_links);
	}

	/**
	 * Rate limiter calculator to prevent hitting the Shopify API rate limit
	 */
	private function limit_the_rate() : void {
		$rate_limit_string = $this->shopify_client->getHeader('X-Shopify-Shop-Api-Call-Limit') ?? '40/40';
		$rate_limit_exploded = explode('/', $rate_limit_string);
		$used = (int)$rate_limit_exploded[0];
		$total = (int)$rate_limit_exploded[1];
		$rate_limit = $total === 80 ? 4 : 2;

		// Make a buffer of twice the number of threads we can run
		$buffer = ($rate_limit * $this->rate_modifier * 3);

		if ($used >= ($total - $buffer)) {
			// Consume the rate to maintain the bucket/slowly get back down
			$this->rate_limiter->wait_until_available(1);
		} else {
			// Consume faster than allowed, since there is room in the bucket
			$this->rate_limiter->wait_until_available(0);
		}
	}

	/**
	 * Helper for the `get_products` parallel pull to pull paginated
	 * result sets
	 *
	 * @param callable $endpoint_callback Callback to run the pull and save
	 * the result set once the next-page params are generated
	 * @param array $initial_params The default/initial API call params
	 * @param string $limit_type The limit type
	 * @throws CoreException On API errors
	 */
	private function paginate_endpoint_results(
		callable $endpoint_callback,
		array $initial_params = [],
		string $limit_type = 'product'
	) : void {
		// make sure to limit each fork to 1 request per second
		$next_page_cursor = '';
		do {
			try {
				$params = [];
				if ($next_page_cursor != '') {
					// Only limit and fields are valid to use with page_info
					// cursors, anything else will error out the API call
					$params = array_intersect_key($initial_params, [
						'limit' => 1,
						'fields' => 1,
						'presentment_currencies' => 1,
					]);
					$params['page_info'] = $next_page_cursor;
				} else {
					$params = array_merge($initial_params, $params);
				}
				// We will attempt to get the max of 250. If that fails (as has happened often) we will step down the pagination until it works
				$limit = $this->rest_settings['limit_backoff'][$limit_type][$this->rest_settings['current_tier'][$limit_type]];
				$params['limit'] = $limit;


				$next_page_cursor = $endpoint_callback($params);
				$keep_looping = !empty($next_page_cursor);
			} catch (Exception $e) {
				$this->log_string .= '** Hit Error: ' . $e->getMessage() . PHP_EOL;
				$shopify_error = json_decode($e->getMessage(), true) ?? [];
				$shopify_error_data = $shopify_error['data'] ?? [];
				$status = $shopify_error_data['status'] ?? -1;
				$new_tier = $this->rest_settings['current_tier'][$limit_type] + 1;
				$error_message = $shopify_error['message'] ?? $e->getMessage();
				if (
					(
						!in_array($status, [500,502,503]) //We want to retry these status codes
						&& strpos($error_message,'cURL error 18:') === false // We want to retry this cURL error
						&& strpos($error_message,'cURL error 56:') === false // We want to retry this cURL error
						&& strpos($error_message,'cURL error 35:') === false // We want to retry this cURL error
					)
						|| !isset($this->rest_settings['limit_backoff'][$limit_type][$new_tier]) // We only want to try again if we have not hit the limit
				) {
					$this->log_string .= '** Threw error!' . json_encode($shopify_error_data) . PHP_EOL;
					throw new ApiResponseException($e->getMessage());
				}
				$this->rest_settings['current_tier'][$limit_type] = $new_tier;
				$keep_looping = true;
				$this->log_string .= '**Continuing!' . PHP_EOL;
			}
		} while ($keep_looping);
	}

	/**
	 * Take the bulk file for Meta info and adds it to the DB. It's called
	 * using call_user_func
	 *
	 * @param string $url The URL with metadata to get
	 * @throws Exception
	 */
	private function add_meta(string $url) : void {
		$fn = File_Utilities::download_file($url);
		$local_cxn = ConnectionFactory::connect(ConnectionFactory::DB_LOCAL);
		$cxp_fh = fopen($fn, 'r');

		$keyed_meta_fields = array_flip($this->meta_fields);
		$metas = [];
		$product_to_variant = [];
		foreach(File_Utilities::rfgets($cxp_fh, 2) as $line) {
			$row = json_decode($line, true);
			if (!isset($row['__parentId'])) {
				// This is a product, since we are at the top of the tree

				$product_meta = $metas[$row['id']] ?? [];
				if ($this->connection_info['metafields_split_columns']){
					$parent_meta = [];
					foreach($product_meta as $metafield){
						$parent_metafield_name = "parent_meta_" . str_replace('-', '_', strtolower($metafield['key']));
						// make sure the metafield key isn't messy
						$parent_metafield_name = $this->clean_column_name($parent_metafield_name);
						$parent_meta[$parent_metafield_name] = json_encode([
							'value'			=> $metafield['value'],
							'namespace'		=> $metafield['namespace'],
							'description'	=> $metafield['description'],
						]);
						if (!array_key_exists($parent_metafield_name, $this->template->get_template())){
							$this->template->append_keyless_to_template([$parent_metafield_name]);
							$clean_parent_metafield_name = $local_cxn->strip_enclosure_characters($parent_metafield_name);
							$alter_col_qry = "ALTER TABLE `{$this->tables['main']}` ADD `{$clean_parent_metafield_name}` LONGTEXT;";
							$result = $local_cxn->query($alter_col_qry);
							if(!$result) {
								$this->write_log("There was a MySQl Error: {$local_cxn->error}" . PHP_EOL, true);
								$this->write_log("Query: {$alter_col_qry}" . PHP_EOL, true);
							} else {
								$this->write_log("Success." . PHP_EOL);
							}
						}
					}
				}
				$clean_product_meta = $local_cxn->real_escape_string(json_encode($product_meta));
				unset($metas[$row['id']]);
				foreach($product_to_variant[$row['id']] as $variant_id) {
					$variant_meta = $metas[$variant_id] ?? [];
					$clean_variant_meta = $local_cxn->real_escape_string(json_encode($variant_meta));
					unset($metas[$variant_id]);
					$variant_id_info = $this->get_id_info_from_gid($variant_id);
					$clean_id = (int)$variant_id_info['id'];
					$product_id_info = $this->get_id_info_from_gid($row['id']);
					$clean_product_id = (int) $product_id_info['id'];
					$this->write_log("Add meta information to product/variant {$clean_product_id}/{$clean_id}..." . PHP_EOL);

					if ($this->connection_info['metafields_split_columns']){
						$variant_product_meta = [];
						foreach($variant_meta as $metafield){
							$variant_metafield_name = "variant_meta_" . str_replace('-', '_', strtolower($metafield['key']));
							$variant_metafield_name = $this->clean_column_name($variant_metafield_name);
							$variant_product_meta[$variant_metafield_name] = json_encode([
								'value'			=> $metafield['value'],
								'namespace'		=> $metafield['namespace'],
								'description'	=> $metafield['description'],
							]);
							if (!array_key_exists($variant_metafield_name, $this->template->get_template())){
								$this->template->append_keyless_to_template([$variant_metafield_name]);
								$clean_variant_metafield_name = $local_cxn->strip_enclosure_characters($variant_metafield_name);
								$alter_col_qry = "ALTER TABLE `{$this->tables['main']}` ADD `{$clean_variant_metafield_name}` LONGTEXT;";
								$result = $local_cxn->query($alter_col_qry);
								if(!$result) {
									$this->write_log("There was a MySQl Error: {$local_cxn->error}" . PHP_EOL, true);
									$this->write_log("Query: {$alter_col_qry}" . PHP_EOL, true);
								} else {
									$this->write_log("Success." . PHP_EOL);
								}
							}
						}
						$all_metafields = array_merge($parent_meta, $variant_product_meta);

						$clean_metafield_values = "";
						$cleaned_dupe_update_fields = "";
						if (!empty($all_metafields)){

							$clean_metafield_keys = ", " . implode(', ', array_keys($all_metafields));

							foreach(array_values($all_metafields) as $meta_value){
								$clean_metafield_values .= "'" . $local_cxn->real_escape_string($meta_value) . "', ";
							}
							$clean_metafield_values = ', ' . trim($clean_metafield_values, ', ');

							foreach($all_metafields as $key => $value){
								$cleaned_dupe_update_fields .= '`' . $local_cxn->strip_enclosure_characters($key) . "`='" . $local_cxn->real_escape_string($value) . "', ";
							}
							$cleaned_dupe_update_fields = trim($cleaned_dupe_update_fields, ', ');

							$metafields_insert_query = "INSERT INTO `{$this->tables['main']}` (id, item_group_id {$clean_metafield_keys}) VALUES ({$clean_id}, {$clean_product_id} {$clean_metafield_values}) ON DUPLICATE KEY UPDATE {$cleaned_dupe_update_fields}";
							$result = $local_cxn->query($metafields_insert_query);

							if(!$result) {
								$this->write_log("There was a MySQl Error: {$local_cxn->error}" . PHP_EOL, true);
								$this->write_log("$metafields_insert_query", true);
							} else {
								$this->write_log("Success." . PHP_EOL);
							}
						}

					} else {
						$result = $local_cxn->query("INSERT INTO `{$this->tables['main']}` (id, item_group_id, variant_meta, product_meta) VALUES ({$clean_id}, {$clean_product_id}, '{$clean_variant_meta}', '{$clean_product_meta}') ON DUPLICATE KEY UPDATE product_meta='{$clean_product_meta}', variant_meta='{$clean_variant_meta}'");
						if(!$result) {
							$this->write_log("There was a MySQl Error: {$local_cxn->error}" . PHP_EOL, true);
						} else {
							$this->write_log("Success." . PHP_EOL);
						}
					}
				}
			} else {
				$parent_info = $this->get_id_info_from_gid($row['__parentId']);
				if ($parent_info['type'] == 'Product' && isset($row['id'])) {
					// Variant
					if (!isset($product_to_variant[$row['__parentId']])) {
						$product_to_variant[$row['__parentId']] = [];
					}
					$product_to_variant[$row['__parentId']][] = $row['id'];
				} else {
					// This is meta
					if (!isset($metas[$row['__parentId']])) {
						$metas[$row['__parentId']] = [];
					}
					$meta_info = array_intersect_key($row, $keyed_meta_fields);
					$final_meta_info = [];
					foreach($meta_info as $key => $value) {
						$final_meta_info[$key] = strval($value);
					}
					$metas[$row['__parentId']][] = $final_meta_info;
				}
			}
		}
	}

	/**
	 * Take the bulk file for collections info and adds it to the DB
	 *
	 * @param string $url The URL with collections data to get
	 * @throws Exception
	 */
	private function add_collections(string $url) : void {
		$fn = File_Utilities::download_file($url);
		$local_cxn = ConnectionFactory::connect(ConnectionFactory::DB_LOCAL);
		$cxp_fh = fopen($fn, 'r');
		$keyed_meta_fields = array_flip($this->meta_fields);
		$collections = [];
		foreach(File_Utilities::rfgets($cxp_fh, 2) as $line) {
			$row = json_decode($line, true);
			if (!isset($row['__parentId'])) {
				// This is a collection, since we are at the top of the tree
				$id_info = $this->get_id_info_from_gid($row['id']);
				$clean_id = (int)$id_info['id'];
				if (empty($collections[$id_info['id']]['products'])) {
					unset($collections[$id_info['id']]);
					$this->write_log("No products for collection {$clean_id}... skipping insert" . PHP_EOL);
					continue;
				}
				$base_row = [
					'product_id'			=> 0,
					'collection_id' 	=> $clean_id,
					'title'						=> '\'' . $local_cxn->real_escape_string($row['title']) . '\'',
					'handle'					=> '\'' . $local_cxn->real_escape_string($row['handle']) . '\'',
					'collection_type'	=> is_null($row['ruleSet']) ? '\'custom\'' : '\'smart\'',
				];
				if (in_array('collections_meta', $this->connection_info['data_types'])) {
					$base_row['metainfo'] = '\'' . $local_cxn->real_escape_string(json_encode($collections[$id_info['id']]['meta'] ?? [])) . '\'';
				}
				$fields = implode(',', array_keys($base_row));
				foreach($collections[$id_info['id']]['products'] as $product_id) {
					$insert_row = $base_row;
					$insert_row['product_id'] = (int)$product_id;
					$values = implode(',', $insert_row);
					$this->write_log("Adding products for collection id: {$clean_id}..." . PHP_EOL);
					$query = "INSERT IGNORE INTO `{$this->tables['collections']}` ({$fields}) VALUES ({$values});";
					$result = $local_cxn->query($query);
					if (!$result) {
						$this->write_log("There was a MySQl Error: {$local_cxn->error}" . PHP_EOL, true);
						$this->write_log("Query: {$query}" . PHP_EOL, true);
					} else {
						$this->write_log("Success!" . PHP_EOL);
					}
				}
				// Clean up the product info to save memory
				unset($collections[$id_info['id']]);
				// reset all the arrays to get next batch
			} else {
				$id_info = $this->get_id_info_from_gid($row['id']);
				$parent_info = $this->get_id_info_from_gid($row['__parentId']);

				// Initialize an array for this collection if it doesn't exist
				if (!isset($collections[$parent_info['id']])) {
					$collections[$parent_info['id']] = [
						'products'	=> [],
						'meta'			=> [],
					];
				}

				// This is a product
				if ($id_info['type'] == 'Product') {
					$collections[$parent_info['id']]['products'][] = $id_info['id'];
				} else if ($id_info['type'] == 'Metafield') {
					// This is a metafield
					$meta_info = array_intersect_key($row, $keyed_meta_fields);
					$final_meta = [];
					foreach($meta_info as $key => $value) {
						$final_meta[$key] = strval($value);
					}
					$collections[$parent_info['id']]['meta'][] = $final_meta;
				}
			}
		}

		// Now that all the collection are loaded into the collections table, let's join the tables together
		$insert = true;
		if (array_key_exists('id', $this->template->get_template())) {
			$insert = false;
		}


		$query = "SELECT DISTINCT(product_id) FROM `{$this->tables['collections']}`;";
		$product_id_results = $local_cxn->query($query);
		if (!$product_id_results) {
			$this->write_log('Error selecting from collections table: ' . $local_cxn->error . ' QUERY: ' . $query, true);
			throw new InfrastructureErrorException();
		}
		while($product_id = $product_id_results->fetch_row()) {
			$product_id = (int)$product_id[0];
			$fields_we_want = [
				'id',
				'title',
				'handle'
			];
			$product_collections = [
				'smart' 	=> array_fill_keys($fields_we_want, ''),
				'custom'	=> array_fill_keys($fields_we_want, ''),
			];
			if (in_array('collections_meta', $this->connection_info['data_types'])) {
				$product_collections['smart']['meta'] = [];
				$product_collections['custom']['meta'] = [];
			}
			$query = "
			SELECT
				*
			FROM
				`{$this->tables['collections']}`
			WHERE
				product_id = {$product_id}
			ORDER BY
				collection_id ASC;";
			$select_result = $local_cxn->query($query);
			if (!$select_result) {
				$this->write_log('Error selecting from collections table: ' . $local_cxn->error . ' QUERY: ' . $query, true);
				throw new InfrastructureErrorException();
			}
			$field_map = [
				'collection_id' 	=> 'id',
				'metainfo'				=> 'meta',
			];
			while ($row = $select_result->fetch_assoc()) {
				$type = $row['collection_type'];
				unset($row['collection_type']);
				unset($row['product_id']);
				foreach($row as $field => $value) {
					$field_name = $field_map[$field] ?? $field;
					if ($field_name != 'meta') {
						$product_collections[$type][$field_name] .= $value . '|';
					} else {
						$meta_info = json_decode($value, true) ?? [];
						$product_collections[$type][$field_name][$row['collection_id']] = $meta_info;
					}
				}
				$value_string = '';
				$field_string = '';
				$update_string = '';
				foreach ($product_collections as $type => $data) {
					foreach ($data as $field => $value) {
						if ($field == 'meta') {
							$value = json_encode($value);
						} else {
							$value = rtrim($value, '|');
						}
						$field = $local_cxn->strip_enclosure_characters("{$type}_collections_{$field}");
						$value = '\'' . $local_cxn->real_escape_string($value) . '\'';
						$value_string .= "{$value},";
						$field_string .= "`{$field}`,";
						$update_string .= "`{$field}`={$value},";
					}
				}
				$value_string = rtrim($value_string, ',');
				$field_string = rtrim($field_string, ',');
				$update_string = rtrim($update_string, ',');
				if ($insert) {
					$query = "INSERT INTO `{$this->tables['main']}` (item_group_id, {$field_string}) VALUES ({$product_id},{$value_string}) ON DUPLICATE KEY UPDATE {$update_string}";
				} else {
					$query = "UPDATE `{$this->tables['main']}` SET {$update_string} WHERE item_group_id={$product_id}";
				}
				$result = $local_cxn->query($query);
				if (!$result) {
					$this->write_log("There was a MySQl Error: {$local_cxn->error}" . PHP_EOL, true);
					$this->write_log("Query: {$query}" . PHP_EOL, true);
				} else {
					$this->write_log("Successfully updated collections for {$product_id}!" . PHP_EOL);
				}
			}
		}
	}

	/**
	 * Take the bulk file for inventory info and adds it to the DB
	 *
	 * @param string $url The URL with inventory data to get
	 * @throws Exception
	 */
	private function add_inventory_item($url) {
		$fn = File_Utilities::download_file($url);
		$local_cxn = ConnectionFactory::connect(ConnectionFactory::DB_LOCAL);
		$cxp_fh = fopen($fn, 'r');

		$inventory_levels = [];
		foreach(File_Utilities::rfgets($cxp_fh,2) as $line) {
			$row = json_decode($line, true);
			if (!isset($row['__parentId'])) {
				// This is a variant, since we are at the top of the tree
				$id_info = $this->get_id_info_from_gid($row['id']);
				$clean_id = (int)$id_info['id'];
				$inv_item = [
					'id' 				=> (int)$this->get_id_info_from_gid($row['inventoryItem']['id'])['id'],
					'sku'				=> $row['inventoryItem']['sku'],
					'cost'			=> $row['inventoryItem']['unitCost']['amount'] ?? null,
					'currency'	=> $row['inventoryItem']['unitCost']['currencyCode'] ?? null,
				];
				$clean_inv_item = $local_cxn->real_escape_string(json_encode($inv_item));

				$fields = ['id','inventory_item'];
				$values = ["'{$clean_id}'","'{$clean_inv_item}'"];
				if (in_array('inventory_level', $this->connection_info['data_types'])) {
					$inv_level = $inventory_levels[$id_info['id']] ?? [];
					$clean_inv_level = $local_cxn->real_escape_string(json_encode($inv_level));
					unset($inventory_levels[$id_info['id']]);
					$fields[] = 'inventory_level';
					$values[] = "'{$clean_inv_level}'";
				}
				$update_string = '';
				$fields_string = '';
				$values_string = '';
				foreach($fields as $index => $field) {
					if ($field != 'id') {
						$update_string .= "{$field}={$values[$index]},";
					}
					$fields_string .= "{$field},";
					$values_string .= "{$values[$index]},";
				}
				$update_string = rtrim($update_string,',');
				$fields_string = rtrim($fields_string,',');
				$values_string = rtrim($values_string,',');

				$this->write_log("Adding inventory item/levels to variant id: {$clean_id}..." . PHP_EOL);
				$result = $local_cxn->query("INSERT INTO `{$this->tables['main']}` ({$fields_string}) VALUES ({$values_string}) ON DUPLICATE KEY UPDATE {$update_string};");
				if (!$result) {
					$this->write_log("There was a MySQl Error: {$local_cxn->error}" . PHP_EOL, true);
				} else {
					$this->write_log("Success" . PHP_EOL);
				}
				// reset all the arrays to get next batch
			} else {
				// This is an Inventory Level
				$parent_info = $this->get_id_info_from_gid($row['__parentId']);
					// If the product has not been seen yet, let's add an array for it
					if (!isset($inventory_levels[$parent_info['id']])) {
						$inventory_levels[$parent_info['id']] = [];
					}
					$id_info = $this->get_id_info_from_gid($row['id']);
					$pos = strpos($id_info['id'], '=');
					$inventory_item_id = substr($id_info['id'],$pos +1) ;
					$inventory_levels[$parent_info['id']][] = [
						'inventory_item_id'	=> (int)$inventory_item_id,
						'location_id'				=> isset($row['location']['id']) ? (int)$this->get_id_info_from_gid($row['location']['id'])['id'] : null,
						'available'					=> (int)$row['available'] ?? null,
						'location_name'			=> $row['location']['name'] ?? '',
					];
			}
		}
	}

	/**
	 * Pull and build the tax rate info
	 *
	 * @param ApiClient $client A client for making Shopify API calls
	 * @return false|string The tax rate info, or false on failure json encoding
	 * @throws ApiException
	 */
	private function build_tax_rates(ApiClient $client){
		$country_service = new CountryService($client);

		$params = [
			'fields' => "name,code,provinces,tax"
		];
		$tax_rates = [];
		$countries = $country_service->getCountries($params);
		foreach($countries['countries'] as $country){
			if (in_array($country['code'] ?? '', $this->connection_info['tax_rates'])){
				$tax_rates[$country['code']] = [
					'name' 	=> $country['name'],
					'tax' 	=> $country['tax']
				];
				$provinces = [];
				foreach ($country['provinces'] ?? [] as $province){
					$provinces[$province['code']] = [
						'name' => $province['name'] ?? '',
						'tax' => $province['tax'] ?? '',
						'tax_name' => $province['tax_name'] ?? '',
					];
				}
				$tax_rates[$country['code']]['provinces'] = $provinces;
			}
		}
		return json_encode($tax_rates);
	}

	/**
	 * Generate the date ranges needed to pull product data
	 *
	 * @return array The date ranges
	 */
	private function get_date_ranges() : array {
		// Create a job for every day from the start until tomorrow
		$created_at_start_batch = $this->created_at_start;
		do {
			$created_at_end_batch = date(DATE_ATOM, strtotime("+{$this->date_chunks}", strtotime($created_at_start_batch)));
			// We want only products since we started the run to simplify the collection matching
			if (strtotime($created_at_end_batch) > strtotime($this->created_at_end)) {
				$created_at_end_batch = $this->created_at_end;
			}
			$all_date_ranges[] = [
				'start' => $created_at_start_batch,
				'end'		=> $created_at_end_batch,
			];
			$created_at_start_batch = $created_at_end_batch;
		} while(strtotime($created_at_end_batch) !== strtotime($this->created_at_end));
		return $all_date_ranges;
	}

	/**
	 * Generate the date ranges need to pull product data in bulk
	 *
	 * @return array The date ranges
	 */
	private function get_bulk_date_ranges() : array {
		// Create a job for every day from the start until tomorrow
		$bulk_start = date('Y-m-d', strtotime('-1 day',strtotime($this->created_at_start)));
		$bulk_end		= date('Y-m-d', strtotime('+1 day',strtotime($this->created_at_end)));
		$batch_start = $bulk_start;
		$all_date_ranges = [];
		$remaining_days_shop_active = ceil((strtotime($bulk_end) - strtotime($bulk_start))/ (60 * 60 * 24));
		$day_range = $remaining_days_shop_active;
		do {
			$this->rate_limiter->wait_until_available(1);
			$batch_end = date('Y-m-d', strtotime("+{$day_range} days", strtotime($batch_start)));
			if (strtotime($batch_end) > strtotime($bulk_end)) {
				$batch_end = date('Y-m-d', strtotime($bulk_end));
			}
			$product_count = Parallel::do_sync([], function($task, $parent_socket) use ($batch_start, $batch_end) {
				try {
					$product_count = $this->get_product_service()->getProductCount([
						'created_at_min' => date(DATE_ATOM,strtotime($batch_start)),
						'created_at_max' => date(DATE_ATOM,strtotime($batch_end)),
						'published_status' => $this->connection_info['product_published_status'],
					])['count'];
					fwrite($parent_socket, $product_count);
				} catch (ApiException $e) {
					fwrite($parent_socket, serialize([
						'error' => $e->getMessage(),
					]));
				}
			});
			$this->write_log("Date range:({$batch_start}-{$batch_end}) - {$product_count}" . PHP_EOL);
			if ($product_count > 0) {
				if ($product_count < 20000 || $day_range == 1) {
					$all_date_ranges[] = [
						'start' => $batch_start,
						'end' => $batch_end,
					];
				} else {
					$day_range = ceil($day_range / 2);
					$this->write_log("Too many products in range, lowering range to {$day_range} days." . PHP_EOL);
					$batch_end = $batch_start;
					continue;
				}
			}
			// reset date range to the remaining number of days to check
			$remaining_days_shop_active = $remaining_days_shop_active - $day_range;
			$day_range = $remaining_days_shop_active;
			$batch_start = date('Y-m-d', strtotime($batch_end));
		} while (strtotime($batch_end) < strtotime($bulk_end));
		return $all_date_ranges;
	}

	/**
	 * Cleanup method for the `register_shutdown_function` function
	 *
	 * @throws CoreException On DB errors
	 */
	private function cleanup() : void {
		if ($this->connection_info['debug'] != true) {
			$this->write_log('cleaning up...' . PHP_EOL);
			$local_cxn = ConnectionFactory::connect(ConnectionFactory::DB_LOCAL);
			foreach ($this->tables as $table) {
				$this->write_log("Dropping table:{$table}" . PHP_EOL);
				$local_cxn->query("DROP TABLE `{$table}`;");
			}

			if($this->bulk_id_file->file_exists()){
				$bulk_fh = fopen($this->bulk_id_file->get_absolute_path(), 'r');
				while ($bulk_id = fgets($bulk_fh)) {
					$this->write_log("Cancelling bulk graphql:{$bulk_id}" . PHP_EOL);
					$this->cancel_bulk_operation($bulk_id);
				}
				fclose($bulk_fh);
				$this->bulk_id_file->delete();
			}

		} else {
			$this->write_log('Skipping clean up because of debug flag...' . PHP_EOL);
		}
	}

	/**
	 * Write info/data to the log file
	 *
	 * @param string $string The log data to write
	 * @param false $force_log Flag to force-write data if the debug flag was
	 * not set
	 */
	private function write_log(string $string, bool $force_log = false) : void {
		if ($this->connection_info['debug'] === true || $force_log) {
			$fh = fopen($this->log_file, 'a');
			flock($fh, LOCK_EX);
			fwrite($fh, $string);
			flock($fh, LOCK_UN);
			fclose($fh);
		}
	}

	/**
	 * Get the ID information from a GID
	 *
	 * @param string $string The GID to pull info from
	 * @return string[] The ID info with the following keys:
	 * <ul>
	 *   <li>id</li>
	 *   <li>type</li>
	 * </ul>
	 */
	private function get_id_info_from_gid($string) : array {
		$matches = [];
		preg_match('/gid\:\/\/shopify\/(.*)\/(.*)/', $string, $matches);

		return [
			'type'	=> $matches[1] ?? '',
			'id'		=> $matches[2] ?? '',
		];
	}

	/**
	 * Send a GraphQL request to cancel a bulk operation
	 *
	 * @param string $bulk_id The ID of the bulk operation to cancel
	 * @throws CoreException On API errors
	 */
	private function cancel_bulk_operation($bulk_id) : void {
		$qry = '
		mutation {
			bulkOperationCancel(id: "'.$bulk_id.'") {
				bulkOperation {
					status
				}
				userErrors {
					field
					message
				}
			}
		}';
		try {
			$this->shopify_client->graphqlRequest($qry);
		} catch (ApiException $e) {
			ApiResponseException::throw_from_cl_api_exception($e);
		}
	}

	/**
	 * Generate the header for the CSV output file
	 */
	private function create_header() : void {
		if (in_array('products', $this->connection_info['data_types'])) {
			// If we are getting product info, add the product fields we care about
			$fields = $this->connection_info['fields'] ?? [
				'item_group_id',
				'parent_title',
				'description',
				'brand',
				'product_type',
				'tags',
				'link',
				'id',
				'product_id',
				'child_title',
				'price',
				'sale_price',
				'sku',
				'fulfillment_service',
				'requires_shipping',
				'taxable',
				'gtin',
				'inventory_quantity',
				'inventory_management',
				'inventory_policy',
				'availability',
				'weight',
				'weight_unit',
				'shipping_weight',
				'image_link',
				'published_status',
				'color',
				'size',
				'material',
				'additional_image_link',
				'additional_variant_image_link',
				'inventory_item_id',
				'variant_names',
			];

			$this->template->append_keyless_to_template($fields);

			if ($this->connection_info['include_presentment_prices']) {
				$this->template->append_keyless_to_template(['presentment_prices']);
			}

			if ($this->connection_info['use_gmc_transition_id']){
				$this->template->append_keyless_to_template(['gmc_transition_id']);
			}

			// Add extra options to header. These are only applicable to the products
			$this->connection_info['extra_options'] = InputParser::extract_array($this->connection_info, 'extra_options');
			foreach ($this->connection_info['extra_options'] as $col) {
				$col = trim($col);
				if ($col !== '') {
					$this->template->append_keyless_to_template(["extra_option_${col}"]);
				}
			}

			// Extra Parent Fields if requested
			if (!empty($this->connection_info['extra_parent_fields'])) {
				foreach($this->connection_info['extra_parent_fields'] as $extra_field => $value){
					if (array_key_exists($extra_field, $this->template->get_template())){
						$header_value = $extra_field . "_extra";
						$this->template->append_keyless_to_template([$header_value]);
						$this->connection_info['extra_parent_fields'][$extra_field] = $header_value;
					} else{
						$this->template->append_keyless_to_template([$extra_field]);
						$this->connection_info['extra_parent_fields'][$extra_field] = $extra_field;
					}
				}
			}

			// Extra Variant Fields if requested
			if (!empty($this->connection_info['extra_variant_fields'])) {
				foreach($this->connection_info['extra_variant_fields'] as $extra_field => $value){
					if (array_key_exists($extra_field, $this->template->get_template())){
						$header_value = $extra_field . "_v_extra";
						$this->template->append_keyless_to_template([$header_value]);
						$this->connection_info['extra_variant_fields'][$extra_field] = $header_value;
					} else{
						$this->template->append_keyless_to_template([$extra_field]);
						$this->connection_info['extra_variant_fields'][$extra_field] = $extra_field;
					}
				}
			}
		}

		// Add product and variant meta columns if we are getting meta information
		if (in_array('meta', $this->connection_info['data_types'])) {
			$this->template->append_keyless_to_template(['id', 'item_group_id']);
			if (!$this->connection_info['metafields_split_columns']){
				$this->template->append_keyless_to_template(['product_meta', 'variant_meta']);
			}
		}

		// Add Inventory Item if requested
		if (in_array('inventory_item', $this->connection_info['data_types'])) {
			$this->template->append_keyless_to_template(['id', 'inventory_item']);
			// Add Inventory Levels if requested
			if (in_array('inventory_level', $this->connection_info['data_types'])) {
				$this->template->append_keyless_to_template(['inventory_level']);
			}
		}

		// Add collections headers if requested
		if (in_array('collections', $this->connection_info['data_types'])) {
			$this->template->append_keyless_to_template([
				'item_group_id',
				'custom_collections_handle',
				'custom_collections_title',
				'custom_collections_id',
				'smart_collections_handle',
				'smart_collections_title',
				'smart_collections_id',
			]);

			// Add collections metafields if requested
			if (in_array('collections_meta', $this->connection_info['data_types'])) {
				$this->template->append_keyless_to_template([
					'custom_collections_meta',
					'smart_collections_meta',
				]);
			}
		}

		// Add Tax Rates if Requested
		if ($this->connection_info['tax_rates'] != ''){
			$this->template->append_keyless_to_template(['tax_rates']);
		}
	}

	/**
	 * Makes the string an acceptable column name
	 *
	 * <ol>
	 *   <li>Strip all bad characters</li>
	 *   <li>Make sure it's less than 64 characters long</li>
	 * </ol>
	 *
	 * @param string $string The column name
	 * @return false|string The cleaned up column name or false on failure
	 */
	private function clean_column_name(string $string) : string {
		$string = preg_replace('/[^0-9,a-z,A-Z$_,\x{0080}-\x{FFFF}]/u', '', $string);
		return substr($string, 0, 64);
	}

	/**
	 * Checks the permissions of the oauth token. Ensures we have all the
	 * permissions to do our job!
	 *
	 * @throws CoreException On invalid permissions
	 */
	private function check_permissions() : void {
		$needed_permissions = [];
		if(in_array('inventory_level', $this->connection_info['data_types'])) {
			$needed_permissions['read_inventory'] = true;
		}
		if(in_array('inventory_item', $this->connection_info['data_types'])) {
			$needed_permissions['read_inventory'] = true;
		}
		if(in_array('products', $this->connection_info['data_types'])) {
			$needed_permissions['read_products'] = true;
		}
		$permission_items = Parallel::do_sync([], function($task, $parent_socket){
			try {
				$permission_items = $this->get_access_service()->getAccess([])['access_scopes'];
				fwrite($parent_socket, serialize([
					'permissions' => $permission_items
				]));
			} catch (ApiException $e) {
				fwrite($parent_socket, serialize([
					'error' => $e->getMessage(),
				]));
			}
		});
		$permission_items = unserialize($permission_items);
		if (isset($permission_items['error'])) {
			throw new ApiResponseException($permission_items['error']);
		}
		$authorized_permissions = [];
		foreach(($permission_items['permissions'] ?? []) as $permission_item) {
			$permission_name = $permission_item['handle'] ?? '';
			if (!empty($permission_name)) {
				$authorized_permissions[$permission_name] = true;
			}
		}
		$missing_permissions = array_diff_key($needed_permissions, $authorized_permissions);
		if (!empty($missing_permissions)) {
			throw new MissingPermissionsException('`'. implode('`,`', array_keys($missing_permissions)) . '`');
		}
	}

	/**
	 * Populate a product's base information
	 *
	 * @param array $product The product data
	 * @return array The base product data
	 */
	private function populate_base_row(array $product) : array {
		return [
			'item_group_id' => $this->shopify_gid_to_id($product['id'] ?? ''),
			'parent_title' => $product['title'] ?? '',
			'description' => $product['body_html'] ?? '',
			'brand' => $product['vendor'] ?? '',
			'product_type' => $product['product_type'] ?? '',
			'tags' => $product['tags'] ?? '',
		];
	}

	/**
	 * Parse and populate a row of variant product data
	 *
	 * @param array $product The parent product data
	 * @param array $variant The variant row data
	 * @return array The parsed variant data
	 */
	private function populate_variant_row(array $product, array $variant) : array {
		$variant_data = [
			'link' => $this->get_link($product, $variant),
			'id' => $this->shopify_gid_to_id($variant['id'] ?? ''),
			'product_id' => $this->shopify_gid_to_id($variant['product_id'] ?? ''),
			'child_title' => $variant['title'] ?? '',
			'price' => $this->get_price($variant),
			'sale_price' => $this->get_sale_price($variant),
			'sku' => $variant['sku'] ?? '',
			'fulfillment_service' => $variant['fulfillment_service'] ?? '',
			'requires_shipping' => array_key_exists('requires_shipping', $variant) && $variant['requires_shipping'] == true ? 'true' : 'false',
			'taxable' => array_key_exists('taxable', $variant) && $variant['taxable'] == true ? 'true' : 'false',
			'gtin' => $variant['barcode'] ?? '',
			'inventory_quantity' => $variant['inventory_quantity'] ?? '',
			'inventory_management' => $variant['inventory_management'] ?? '',
			'inventory_policy' => $variant['inventory_policy'] ?? '',
			'availability' => $this->get_availability($variant),
			'weight' => $variant['weight'] ?? '',
			'weight_unit' => $variant['weight_unit'] ?? '',
			'image_link' => $this->get_image_link($product, $variant),
			'published_status' => $this->get_published_status($product),
			'color' => $this->get_option($product, $variant, 'color'),
			'size' => $this->get_option($product, $variant, 'size'),
			'material' => $this->get_option($product, $variant, 'material'),
			'additional_image_link' => $this->get_additional_image_links($product, $variant),
			'additional_variant_image_link' => $this->get_additional_variant_image_links($product, $variant),
			'inventory_item_id' => $variant['inventory_item_id'] ?? '',
			'variant_names' => '',
		];

		if ($this->connection_info['include_presentment_prices']) {
			$variant_data['presentment_prices'] = json_encode($variant['presentment_prices'] ?? []);
		}
		if ($this->connection_info['use_gmc_transition_id']){
			$variant_data['gmc_transition_id'] = "shopify_{$this->country_code}_{$variant_data['product_id']}_{$variant_data['id']}";
		}

		// Normalize the data
		if (strpos($variant_data['weight'], '.') === false) {
			$variant_data['weight'] = $variant_data['weight'] . '.0';
		}
		// Some derived data for backwards compatibility
		$variant_data['shipping_weight'] = trim($variant_data['weight'] . ' ' . $variant_data['weight_unit']);

		// Add extra options from variant
		if (!empty($this->connection_info['extra_options'])) {
			foreach ($this->connection_info['extra_options'] as $extra_option) {
				$col = trim($extra_option);
				$col = sprintf('extra_option_%s', $col);
				$variant_data[$col] = $this->get_option($product, $variant, strtolower($extra_option));
			}
		}

		if (!empty($this->connection_info['extra_parent_fields'])) {
			foreach ($this->connection_info['extra_parent_fields'] as $field => $header_value) {
				if (isset($product[$field]) && is_array($product[$field])) {
					$variant_data[$header_value] = json_encode($product[$field] ?? []);
				} else {
					$variant_data[$header_value] = $product[$field] ?? '';
				}
			}
		}

		if (!empty($this->connection_info['extra_variant_fields'])) {
			foreach ($this->connection_info['extra_variant_fields'] as $field => $header_value) {
				if (isset($variant[$field]) && is_array($variant[$field])) {
					$variant_data[$header_value] = json_encode($variant[$field] ?? []);
				} else {
					$variant_data[$header_value] = $variant[$field] ?? '';
				}
			}
		}

		if ($this->connection_info['tax_rates']  != '') {
			$variant_data['tax_rates'] = $this->tax_rates;
		}

		// Add extra options from variant
		if (!empty($this->connection_info['extra_options'])) {
			foreach ($this->connection_info['extra_options'] as $extra_option) {
				$col = trim($extra_option);
				$col = sprintf('extra_option_%s', $col);
				$variant_data[$col] = $this->get_option($product, $variant, strtolower($extra_option));
			}
		}

		// Add variant names
		$variant_names = [];
		foreach ($product['options'] as $option) {
			$name = $option['name'];
			$position = $option['position'];
			$variant_names[$name] = $variant["option{$position}"] ?? '';
		}
		$variant_data['variant_names'] = json_encode($variant_names, JSON_FORCE_OBJECT);
		return $variant_data;
	}

	/**
	 * Takes the Shopify burst rate, and adjusts the forking to coincide with it
	 *
	 * @param int $burst_rate The burst rate
	 * @return int The modified rate limit
	 */
	private function get_modified_rate_limit_for_store(int $burst_rate) : int {
		return (int)(($burst_rate / 20) * $this->rate_modifier);
	}

	/**
	 * Info lister to provide summary information about the given Shopify site
	 *
	 * @return void Response data is json encoded and printed to STDOUT
	 * @throws CoreException On errors pulling info
	 */
	public function get_api_info() : void {
		$info = [
			'permissions' 		=> [],
			'store_info' 		=> [],
			'product_row' 		=> [],
		];

		// Get Store Permissions
		try {
			$permissions = $this->get_access_service()->getAccess([])['access_scopes'];
			foreach ($permissions as $permission){
				$handle = $permission['handle'] ?? '';
				if ($handle != ''){
					$info['permissions'][] = $handle;
				}
			}
		} catch (ApiException $e){
			throw new MissingPermissionsException($e->getMessage());
		}

		// Get Store Info
		$shop_keys = [
			'name',
			'domain',
			'province',
			'country',
			'primary_locale',
			'country_code',
			'country_name',
			'currency',
			'customer_email',
			'timezone',
			'weight_unit',
			'province_code',
			'plan_display_name',
			'plan_name',
			'myshopify_domain',
			'has_storefront',
			'primary_location_id',
			'checkout_api_supported',
			'multi_location_enabled',
		];
		try{
			$store_info = $this->get_store_service()->getStoreInfo();
		} catch (ApiException $e){
			throw new MissingPermissionsException($e->getMessage());
		}
		$shop = $store_info['shop'] ?? [];
		$this->country_code = $shop['country_code'] ?? '';
		if (empty($shop)){
			throw new ApiResponseException('Shop info is empty. Please contact a developer for assistance.');
		}
		$store_info = array_intersect_key($shop, array_flip($shop_keys));
		foreach($store_info as $field => $value){
			$info['store_info'][] = [
				'field' => $field,
				'value' => $value
			];
		}

		// Get Product Count
		$created_at_start = $shop['create'] ?? $this->created_at_start;
		try {
			$total_products = $this->get_product_service()->getProductCount([
				'created_at_min' => $created_at_start,
				'created_at_max' => $this->created_at_end,
				'published_status' => 'published',
			])['count'] ?? '';
			$info['store_info'][] = [
				'field' => 'total_products',
				'value' => $total_products
			];
		} catch (ApiException $e){
			ApiResponseException::throw_from_cl_api_exception($e);
		}

		// Get Single Product Row
		try {
			$headers = [];
			if ($this->connection_info['include_presentment_prices']) {
				$headers['X-Shopify-Api-Features'] = 'include-presentment-prices';
			}
			$products = $this->get_product_service()->listProducts([
				'limit'            => 1,
				'since_id'         => 0,
				'published_status' => 'published',
			],
			$headers)['products'];
		} catch (ApiException $e){
			ApiResponseException::throw_from_cl_api_exception($e);
		}
		if (empty($products)){
			throw new ApiResponseException('Products Response is Empty. Please contact a developer for assistance.');
		}
		$product = $products[0];
		$base_product_row = $this->populate_base_row($product);

		$variant = $product['variants'][0] ?? [];
		$variant_data = [];
		if (!empty($variant)){
			$variant_data = $this->populate_variant_row($product, $variant);
		}
		$product_row = array_merge($base_product_row, $variant_data);
		foreach($product_row as $field => $value){
			$info['product_row'][] = [
				'field' => $field,
				'value' => $value
			];
		}

		echo json_encode($info);
	}

}
