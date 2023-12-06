<?php

namespace ShopifyConnector\util;

use ShopifyConnector\exceptions\CoreException;
use ShopifyConnector\exceptions\CustomException;
use ShopifyConnector\exceptions\InfrastructureErrorException;
use ShopifyConnector\log\ErrorLogger;


/**
 * Helper for managing a child process's returns called via {@see proc_open}
 */
class ProcessHandler {

	/**
	 * @var resource Store for the return of {@see proc_open}
	 */
	private $process;

	/**
	 * @var array Store for the pipes returned from {@see proc_open}'s third
	 * param
	 */
	private array $pipes;

	/**
	 * @var bool Store for whether the output will be compressed for sending
	 * the correct header
	 */
	private bool $output_compressed;

	/**
	 * Helper for managing a child process's returns called via {@see proc_open}
	 *
	 * @param resource|false $process The return of {@see proc_open}
	 * @param array $pipes The pipes returned from {@see proc_open}'s third
	 * param
	 * @param bool $output_compressed Flag for whether the output will be
	 * compressed for sending the correct header
	 * @throws InfrastructureErrorException If the passed in {@see $process} was
	 * not a valid resource
	 */
	public function __construct($process, array $pipes, bool $output_compressed){
		if(!is_resource($process)){
			ErrorLogger::log_error('An invalid resource was fed to ProcessHandler::handle_process');
			throw new InfrastructureErrorException();
		}

		$this->process = $process;
		$this->pipes = $pipes;
		$this->output_compressed = $output_compressed;
	}

	/**
	 * Read the output from the STDOUT and STDERR pipes and handle their
	 * responses accordingly
	 *
	 * <p>STDOUT - output will be echoed to the end-user. If output-compressed
	 * was set to true in the constructor this will also set the fdx-compression
	 * header before echoing data</p>
	 *
	 * <p>STDERR - Errors will be collected via the {@see ErrorPipeCollector}
	 * and non-{@see CoreException} errors will be feed to the
	 * {@see ErrorLogger} for data collection. If a valid {@see CoreException}
	 * was collected, it will be thrown</p>
	 *
	 * @throws CustomException If the child process returned a valid
	 * {@see CoreException}
	 */
	public function handle_process() : void {
		$header_sent = false;
		$error_collector = new ErrorPipeCollector();

		while($s = fgets($this->pipes[1])){
			if($this->output_compressed && !$header_sent){
				$header_sent = true;
				header('X-FDX-Compression: gzip');
			}
			echo $s;
		}

		while($err = fgets($this->pipes[2])){
			$error_collector->collect($err);
		}

		$this->close_process();

		foreach($error_collector->get_notifications() as $notification){
			ErrorLogger::log_error($notification);
		}

		if($error_collector->has_collected_exception()){
			throw $error_collector->get_exception();
		}
	}

	/**
	 * Closes all open pipes and terminates the active process
	 */
	private function close_process() : void {
		fclose($this->pipes[0]);
		fclose($this->pipes[1]);
		fclose($this->pipes[2]);
		proc_terminate($this->process);
		proc_close($this->process);
	}

}

