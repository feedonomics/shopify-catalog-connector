<?php

namespace ShopifyConnector\connectors\shopify\interfaces;

use ShopifyConnector\connectors\shopify\structs\PullStats;
use ShopifyConnector\util\db\queries\InsertStatement;
use ShopifyConnector\connectors\shopify\ShopifyFieldMap;

/*
 * NOTE: Considering moving pullPage/hasNextPage/etc out of this interface and
 *   instead just having a `tick` method that will perform one step (for REST
 *   products, would be to pull and store one page; for bulk gql pullers, would
 *   be first to fire off query, then polls for completion, then to process
 *   results) and return bool indicating whether the puller is finished or has
 *   more to do still. This way, the different pull aspects and api usages could
 *   be interleaved to make better use of cooldowns and downtime while polling.
 *   Another option to handle the different apis, polling, etc. could simply be
 *   to have separate managers for each api (REST/gql/bulk) that each run in their
 *   own thread.
 *   - Addl. note: May need to use separate db connections when generating/using
 *     multiple insert statements across pullers.
 */

/**
 * Interface for base classes responsible for retrieving and storing data
 */
interface iDataPuller
{

	/**
	 * Get the database columns needed by this puller.
	 * <p>
	 * The list should be of the form:
	 * [ 'col_name_1', 'col_name_2', 'col_name_3' ]
	 * <p>
	 * All names used by pullers must be defined and mapped to types in
	 * {@see ShopifyFieldMap}.
	 *
	 * @return string[] List of names for db columns needed
	 */
	public function getColumns() : array;

	/**
	 * Check whether there is a next page to pull
	 *
	 * @return bool True if there is a next page
	 */
	public function hasNextPage() : bool;

	/**
	 * Pull and return the next set of results
	 *
	 * @return iDataList The result set in the form of a data list
	 */
	public function pullPage() : iDataList;

	/**
	 * Pull and store all available pages of data for this puller
	 *
	 * @param InsertStatement $inserter The insert statement used to save the
	 * results to a DB table
	 * @param PullStats $stats Statistics to update while pulling
	 */
	public function pullAndStoreData(InsertStatement $inserter, PullStats $stats) : void;

}

