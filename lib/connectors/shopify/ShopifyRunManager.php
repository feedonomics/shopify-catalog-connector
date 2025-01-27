<?php

namespace ShopifyConnector\connectors\shopify;

use ShopifyConnector\connectors\shopify\interfaces\iModule;
use ShopifyConnector\connectors\shopify\structs\PullStats;
use Generator;
use ShopifyConnector\exceptions\InfrastructureErrorException;
use ShopifyConnector\exceptions\ValidationException;
use ShopifyConnector\util\db\MysqliWrapper;

/**
 * Class to handle processing requested data and putting together everything
 * needed to fulfil the request.
 * <p>
 * This is likely going to start out very simple, but the idea is that it will
 * provide the ability to incorporate much smarter behavior in the future. Such
 * as minimizing the number of operations needed if the requested data can all
 * be obtained by combining what is being requested into a single endpoint call
 * (e.g. instead of doing REST products + GQL prices, combine into GQL alone).
 *
 * <hr>
 *
 * (Old notes)
 * - Put together a list of iDataPuller's
 *   - Parallelization will happen within each iDataPuller
 * - Order the pullers appropriately, e.g. always Products/Variants first
 * - Run through the list, pulling and storing data with each one
 *
 * If possible: Fire off bulk queries early in a parallel process, and have
 * processing methods just take in the filename (url) and go from there. That
 * way, we can get bulk started sooner and it can run alongside the other apis,
 * then the results can still be joined in in order.
 *
 */
final class ShopifyRunManager
{

	const ALWAYS_INCLUDED_FIELDS = [
		'id', # Variant id
		'item_group_id', # Product id
	];

	/**
	 * The order of modules here is the order they will be given precedence as
	 * the primary module when generating output.
	 *
	 * Generally, "products" will make for the best primary module, but since
	 * depending on settings, "inventory" may increase the number of output rows
	 * by splitting some of its data across separate rows for the same product.
	 * For it to be able to do that, it should be primary when it is involved.
	 *
	 * For another example consideration, "collections" pulls data in an atypical
	 * structure and doesn't look at variants, so it should be given the lowest
	 * precedence and only be primary if it is the only module involved.
	 *
	 * @var Array<string, class-string<iModule>> List of available modules
	 */
	const MODULE_MAP = [
		'inventory_item' => inventories\Inventories::class, # Highest precedence: inventories
		'products' => products\Products::class,
		'meta' => metafields\Metafields::class,
		'translations' => translations\Translations::class,
		'collections' => collections\Collections::class, # Lowest precedence: collections
		'collections_meta' => collections\Collections::class, # Lowest precedence: collections_meta
	];


	/**
	 * @var SessionContainer Store for the session container
	 */
	private SessionContainer $session;

	/**
	 * @var iModule[] $modules
	 */
	private array $modules = [];


	/**
	 * Instantiate a new manager. Pieces containing the info needed to pull
	 * requested data and store the results are passed in here, then the run
	 * itself is kicked off using the `run()` method.
	 *
	 * @param SessionContainer $session The container for the active session
	 * @throws ValidationException On invalid pull types
	 */
	public function __construct(SessionContainer $session)
	{
		$this->session = $session;

		foreach ($this->session->settings->get('data_types', []) as $op) {
			$class = self::MODULE_MAP[$op] ?? null;
			if ($class === null) {
				throw new ValidationException("Unsupported operation: {$op}");
			}

			# Not keying this makes life a little easier, plus we may want
			# multiple entries for the same op, depending on how we decide to
			# handle things like chunking
			$this->modules[] = new $class($this->session);
		}
	}

	/**
	 * Get the list of fields that the output will contain. For most accurate
	 * results, this should be called after all data retrieval has occurred.
	 *
	 * @return string[] The column list
	 */
	public function get_output_field_list() : array
	{
		return array_values(array_unique(array_merge(
			self::ALWAYS_INCLUDED_FIELDS,
			...array_map(
				fn($m) => $m->get_output_field_list(),
				$this->modules
			)
		)));
	}

	/**
	 * Execute the manager by iterating and calling the pull and store data
	 * method over the active puller list
	 *
	 * @throws InfrastructureErrorException
	 */
	public function run(MysqliWrapper $cxn) : void
	{
		$this->session->set_run_stage(SessionContainer::STAGE_PULLING);

		foreach ($this->modules as $m) {
			$module_name = $m->get_module_name();
			if (!isset($this->session->pull_stats[$module_name])) {
				$this->session->pull_stats[$module_name] = new PullStats();
			}
			$m->run($cxn, $this->session->pull_stats[$module_name]);
		}
	}

	/**
	 * Get the parsed data for output, one entry at a time. The yielded data will
	 * be in the form of an array, keyed by the fields names.
	 *
	 * NOTE:
	 * There may be some additional considerations needed to address, either here or
	 * in the modules themselves. The initial plan was that modules may not necessarily
	 * store an entry for every product/variant if they don't have data for that particular
	 * item. This means when going to output, depending on if the module used as the
	 * primary has an entry for every product or not, some entries may be missed. There
	 * are a few ways this could be addressed:
	 *
	 * - One would be to require that modules store an entry for every product and
	 *   variant, even if there is no relevant data to store with it. For now, this
	 *   may be the best solution, even though it would be far more efficient and less
	 *   complicated to only create database entries when there is relevant data.
	 *   - Metafields has been updated to try this out.
	 *
	 * - Another would be to have the run manager gather a list of the tables that the
	 *   modules have used and do something like a union query for distinct ids to cover
	 *   all products and vars from all modules. This would be the most thorough, but
	 *   seems like it could introduce a lot of complications, especially if some modules
	 *   need to do non-standard data handling. This would also lose some of the efficiency
	 *   there is to be had by the modules being able to pull variants in bulk in their
	 *   get_products logics.
	 *
	 * - Another would be to add methods for `get_product/variant_ids() : array` to the
	 *   modules interface, then this would get the list that way and only get output
	 *   the modules via the add_data_to_* methods. This would be the most flexible in
	 *   terms of data handling for the modules internally, but would have the same
	 *   potential efficiency downsides from the point above. This and that would also
	 *   potentially make it more difficult to handle the inventory module's need to
	 *   potentially split data across rows. (Perhaps inventory module can make splitting
	 *   work as part of add_data_to_product() by adding more variants?)
	 *
	 * - Another would be to have a single set of product and variant tables with a column
	 *   in each for each active module. I waffled earlier on if this may be a good way to
	 *   go ultimately, perhaps it would be, but will require some reworking to how modules
	 *   aggregate their data.
	 *
	 *
	 * @param MysqliWrapper $cxn The database connection to query on
	 * @param string[] $output_fields The list of desired output fields
	 * @return Generator<Array<string, mixed>> A generator for the compiled output data
	 * @throws InfrastructureErrorException
	 */
	public function retrieve_output(MysqliWrapper $cxn, array $output_fields) : Generator
	{
		$this->session->set_run_stage(SessionContainer::STAGE_FINAL_OUTPUT);

		if (empty($this->modules)) {
			yield from [];
			return;
		}

		$modules = [];
		$primary_module = $this->determine_primary_module();

		foreach ($this->modules as $m) {
			if ($m === $primary_module) {
				continue;
			}
			$modules[] = $m;
		}

		foreach ($primary_module->get_products($cxn) as $product) {
			foreach ($modules as $m) {
				$m->add_data_to_product($cxn, $product);
			}

			$product_data = $product->get_output_data($output_fields);

			// If a product has no associated variants, still output the product's data alone
			if (empty($product->get_variants())) {
				yield $product_data;
				continue;
			}

			foreach ($product->get_variants() as $variant) {
				foreach ($modules as $m) {
					$m->add_data_to_variant($cxn, $variant);
				}

				yield array_merge($product_data, $variant->get_output_data($output_fields));
			}
		}
	}

	/**
	 * Determine and return the primary module based on the precedence as defined by
	 * the order of the {@see self::MODULE_MAP} constant. The first encountered module
	 * of the highest active precedence will be chosen to be the primary.
	 *
	 * This method makes an assumption that there are modules in the module list. The
	 * caller should ensure that is the case before trying to determine the primary.
	 *
	 * @return iModule The module to use as the primary
	 */
	private function determine_primary_module() : iModule
	{
		foreach (self::MODULE_MAP as $name => $class) {
			foreach ($this->modules as $module) {
				if ($module->get_module_name() === $name) {
					return $module;
				}
			}
		}

		// This probably shouldn't happen, but as a last resort fallback, just grab the
		// first module from the list.
		return $this->modules[0];
	}

}

