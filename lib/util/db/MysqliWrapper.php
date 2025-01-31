<?php
namespace ShopifyConnector\util\db;

use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use ShopifyConnector\exceptions\InfrastructureErrorException;
use ShopifyConnector\log\ErrorLogger;

/**
 * Wrapper for extended mysqli functionality
 *
 * <p>One important note for this wrapper is to prefer using the `has_error`,
 * `get_errno`, `get_error`, etc. methods instead of accessing properties
 * directly - as these are not true class properties, rather
 * c-level variables that cannot be mocked/overwritten in unit tests</p>
 */
class MysqliWrapper extends mysqli {

	/**
	 * @var string|null Store for the hostname for reconnecting
	 */
	private ?string $hostname;

	/**
	 * @var string|null Store for the username for reconnecting
	 */
	private ?string $username;

	/**
	 * @var string|null Store for the password for reconnecting
	 */
	private ?string $password;

	/**
	 * @var string|null Store for the database for reconnecting
	 */
	private ?string $database;

	/**
	 * @var int|null Store for the port for reconnecting
	 */
	private ?int $port;

	/**
	 * @var string|null Store for the socket for reconnecting
	 */
	private ?string $socket;

	/**
	 * @var int Store for the flags for reconnecting
	 */
	private int $flags = 0;

	/**
	 * Use `real_connect` for setting credentials and connecting
	 */
	public function __construct(){
		parent::__construct();
	}

	/**
	 * @inheritDoc
	 */
	public function real_connect(
		$hostname = null,
		$username = null,
		$password = null,
		$database = null,
		$port = null,
		$socket = null,
		$flags = 0
	) : bool {
		$this->hostname = $hostname;
		$this->username = $username;
		$this->password = $password;
		$this->database = $database;
		$this->port = $port;
		$this->socket = $socket;
		$this->flags = $flags ?? 0;

		return parent::real_connect($hostname, $username, $password, $database, $port, $socket, $flags);
	}

	/**
	 * Reconnect to the database
	 *
	 * @return bool The result of {@see real_connect}
	 */
	public function reconnect() : bool {
		parent::__construct();
		return parent::real_connect(
			$this->hostname,
			$this->username,
			$this->password,
			$this->database,
			$this->port,
			$this->socket,
			$this->flags
		);
	}

	/**
	 * Query helper with built-in error handling
	 *
	 * <p>If the query was successful, the results from {@see mysqli::query}
	 * will be passed back, otherwise this will handle logging detailed error
	 * messages and throwing an infrastructure error</p>
	 *
	 * @param string $query The query to pass to {@see mysqli::query}
	 * @param int $result_mode The result mode to pass to {@see mysqli::query}
	 * @return bool|mysqli_result The result from {@see mysqli::query} if there
	 * was no errors
	 * @throws InfrastructureErrorException If there were errors in the query
	 */
	public function safe_query(string $query, int $result_mode = MYSQLI_STORE_RESULT){
		try {
			$res = $this->query($query, $result_mode);
		} catch (mysqli_sql_exception $e) {
			ErrorLogger::log_error("DB Query error. [Message: {$e->getMessage()}] [Query: {$query}]");
			throw new InfrastructureErrorException();
		}

		return $res;
	}

	/**
	 * @inheritDoc
	 */
	public function close() : void
	{
		try {
			parent::close();
		} catch (mysqli_sql_exception $e) {
			// Do nothing, just don't error out
		}
	}

	/**
	 * Check if the last run query had an error
	 *
	 * @return bool True if there was an error
	 */
	public function has_error() : bool {
		return $this->errno !== 0;
	}

	/**
	 * Get the error number for the last run query
	 *
	 * <p>See {@see MysqlErrorConstants} for a more friendly version of the
	 * error numbers being returned</p>
	 *
	 * @return int The error number
	 */
	public function get_errno() : int {
		return $this->errno;
	}

	/**
	 * Get the error message for the last run query
	 *
	 * @return string The error message
	 */
	public function get_error() : string {
		return $this->error ?? 'Empty mysqli error message';
	}

	/**
	 * Get the connection error, if set
	 *
	 * @return string The connection error
	 */
	public function get_connect_error() : string {
		return $this->connect_error ?? '';
	}

	/**
	 * Sanitization helper for column or table names which will remove
	 * backticks, double quotes, or slashes so the value can be injected into
	 * an SQL string
	 *
	 * <p>Note: You <b>must</b> surround your column and table names with
	 * backticks or double quotes for the injection to be safe</p>
	 *
	 * <p>Example:</p>
	 * ```php
	 * $sql = sprintf(
	 *   'SELECT * FROM `%s`',
	 *   $cxn->strip_enclosure_characters($my_table_name)
	 * );
	 * $cxn->safe_query($sql);
	 * ```
	 *
	 * @param string $val
	 * @return string
	 */
	public function strip_enclosure_characters(string $val) : string {
		return preg_replace('/[`"\\\\]/', '', $val);
	}

}
