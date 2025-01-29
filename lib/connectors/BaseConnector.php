<?php

namespace ShopifyConnector\connectors;

use ShopifyConnector\exceptions\ValidationException;
use ShopifyConnector\exceptions\file\UnableToOpenFileException;
use ShopifyConnector\exceptions\file\UnableToWriteFileException;
use ShopifyConnector\util\File_Utilities;
use ShopifyConnector\util\file\TemporaryFile;
use ShopifyConnector\util\file\TemporaryFileGenerator;
use ShopifyConnector\util\generators\RandomString;
use ShopifyConnector\util\io\InputParser;


abstract class BaseConnector {

	/**
	 * @var string[] Keys to scrub from connection_info when logging
	 */
	const SENSITIVE_KEYS = [
		'password',
		'oauth_token',
		'access_token',
		'client_secret',
		'auth_token',
		'refresh_token',
		'auth_consumer_key',
		'auth_consumer_secret',
		'auth_access_token',
		'auth_access_token_secret',
		'shared_secret',
		'private_key',
		'secret',
		'api_key',
		'token',
		'seller_token',
		'api_secret',
		'sp_api_refresh_token',
	];


	protected array $connection_info;

	private string $unique_request_id;
	private array $file_info;


	public function __construct(array $connection_info, array $file_info){
		$this->set_connection_info($connection_info);
		$this->file_info = $file_info;
		$this->unique_request_id = RandomString::hex(16);

		set_error_handler([$this, 'handle_errors']);

		$this->log_request();

		// Originally present, but can we get away w/o this?
		//ini_set("auto_detect_line_endings", true);
	}


###
### ABSTRACT METHODS

	/**
	 * Process the request for product data and pass the finalized data to the
	 * given callback. This is the heart of the logic for child classes and
	 * what is expected to do the pulling and processing of data.
	 *
	 * @param callable $insert_row_func The callback to pass data to
	 */
	abstract public function export(callable $insert_row_func) : void;


	/**
	 * Generate some summary information about the target Shopify store and
	 * output it to the client. This is used for testing configurations and
	 * confirming that details about the store are as expected while in the
	 * setup phase, before doing any actual data pulls.
	 */
	abstract public function get_api_info() : void;


###
### PRIVATE METHODS

	/**
	 * Validate and normalize the given connection info, and then set it into
	 * the `connection_info` field.
	 *
	 * @param array $ci The set of connection info parameters to use
	 */
	private function set_connection_info(array $ci) : void {
		if(isset($ci['host'])){
			$ci['host'] = rtrim($ci['host'], '/');
		}

		$ci['force_ftp'] = InputParser::extract_boolean($ci, 'force_ftp');

		$this->connection_info = $ci;
	}


	/**
	 * Create an access log entry for the request that this client is for. This
	 * is meant to be called once as part of the client's setup.
	 */
	private function log_request() : void {
		$current_runtime = time();
		$year_month = date('Y_m', $current_runtime);
		$parent_pid = getmypid();
		$cleaned_conn_info = array_diff_key(
			$this->connection_info,
			array_flip(self::SENSITIVE_KEYS)
		);

		file_put_contents(
			"{$GLOBALS['file_paths']['log_path']}/access_log_{$year_month}",
			json_encode([
				'time'        => date(DATE_ATOM, $current_runtime),
				'request_id'  => $this->unique_request_id,
				'process'     => $parent_pid,
				'connection_info' => $cleaned_conn_info,
				//'file_info'   => $file_info,
				//'actions'     => $actions,
				//'export_info' => $export_info,
			]) . "\n",
			FILE_APPEND
		);
	}


	/**
	 * Pull and process data based on the request parameters; this is the main
	 * import entry point. This method sets up a temporary file to hold
	 * processed data, which subclasses will write to in their {@see export()}
	 * method.
	 *
	 * @return TemporaryFile Reference to the file holding the imported data
	 * @throws UnableToOpenFileException On errors in opening the data file
	 */
	private function pull_data() : TemporaryFile {
		$data_file = TemporaryFileGenerator::get('processed_data_');

		// Open the temp file for writing
		$fp = @fopen($data_file->get_absolute_path(), 'w+');
		if (!$fp) {
			throw new UnableToOpenFileException();
		}

		$me = $this;
		$this->export(
			function ($row, $delimit_info = []) use ($fp, $me) {
				File_Utilities::fput_delimited($fp, $row, ...$delimit_info);
			}
		);

		fclose($fp);
		return $data_file;
	}


	/**
	 * Helper to output a file cleanly to the client. This is meant to be used
	 * as the sole source of output on the stream.
	 *
	 * This will destroy any currently active output buffers in order to ensure
	 * data is passed on the actual output stream.
	 *
	 * @param TemporaryFile $file The file to output
	 */
	private function output_file(TemporaryFile $file) : void {
		while(ob_get_level() > 0){
			ob_end_clean();
		}

		$fpath = $file->get_absolute_path();
		if ($this->file_info['output_compressed']) {
			$output_file = TemporaryFileGenerator::get('gzip_', 'gz');
			$fp_out = @gzopen($output_file->get_absolute_path(), 'wb1');
			if ($fp_out === false) {
				throw new UnableToOpenFileException();
			}

			// Open file to compress, read in 512kb blocks
			$fp_in = @fopen($file->get_absolute_path(), 'rb');
			if ($fp_in === false) {
				gzclose($fp_out);
				throw new UnableToOpenFileException();
			}

			while ($block = fread($fp_in, 1024 * 512)) {
				$gzreturn = gzwrite($fp_out, $block);
				if($gzreturn === false){
					break;
				}
			}

			$success = feof($fp_in) && ($gzreturn !== false);
			fclose($fp_in);
			gzclose($fp_out);

			if(!$success){
				throw new UnableToWriteFileException();
			}
			$fpath = $output_file->get_absolute_path();
		}
		$fsize = filesize($fpath);
		if($fsize !== false && !headers_sent()){
			header("Content-Length: {$fsize}");
		}

		readfile($fpath);
	}


###
### PUBLIC METHODS

	/**
	 * Error logging method. This is to be given to `set_error_handler` as
	 * part of the setup for a client. The params to this method are those
	 * laid out in the php docs for `set_error_handler`.
	 */
	public function handle_errors($num, $str, $file, $line) : void {
		file_put_contents(
			"{$GLOBALS['file_paths']['log_path']}/errors",
			json_encode([
				'time'       => date(DATE_ATOM, time()),
				'request_id' => $this->unique_request_id,
				'severity'   => $num,
				'error_info' => $str,
				'line'       => $line,
				'file'       => $file,
				'backtrace'  => print_r(debug_backtrace(2, 5), true)
			]) . "\n",
			FILE_APPEND
		);
	}


	/**
	 * Entry point for running connector logic. This will decide what actions
	 * to take based on the request parameters and will handle running the
	 * logic and delivering the relevant result to the client.
	 *
	 * @throws ValidationException On invalid request type in request params
	 */
	public function run() : void {
		switch($this->file_info['request_type'] ?? 'get'){
			case 'get':
				$this->output_file($this->pull_data());
			break;

			case 'list':
				$this->get_api_info();
			break;

			default:
				throw new ValidationException('Invalid request type specified');
		}
	}

}

