<?php

namespace ShopifyConnector\util;


/**
 * Utilities for forking and working with child processes
 */
class Parallel {

	/**
	 * Run a task asynchronously
	 *
	 * <p>The parent must pcntl_waitpid($child_pid, $status) if/when it
	 * wants the child's results.</p>
	 *
	 * @param mixed $job First param to pass through to the callback_function
	 * @param callable $callback_function The function/logic to run async
	 * <p>This should take two params: 1) The passed in $job 2) The writable
	 * process socket to output results to</p>
	 * @return array|void Returns info about the child socket if running in the
	 * parent process, otherwise actual output will be written to the process
	 * socket
	 */
	public static function do_async($job, $callback_function) {
		$socket_pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		$pid = pcntl_fork();

		// You're in the parent, return the child's pid
		if ($pid>0) {
			// Close the write portion of the socket
			fclose($socket_pair[0]);
			return array(
				'pid' => $pid,
				'socket' => $socket_pair[1]
			);
		}

		// You're in the child.
		else {
			// Close the read portion of the socket
			fclose($socket_pair[1]);
			$callback_function($job, $socket_pair[0]);
			exit();
		}
	}

	/**
	 * Run a task synchronously in the current process
	 *
	 * <p>It is important to run API/curl calls for parallel processes within
	 * this method because there is a known bug with the curl library where if
	 * an HTTPS call is made in the parent process, the child process with
	 * receive SSL errors every time it tries to make a subsequent call over
	 * HTTPS. Using this wrapper forces curl calls to remain in the child
	 * processes, thus avoiding this issue</p>
	 *
	 * @param mixed $job First param to pass through to the callback_function
	 * @param callable $callback_function The function/logic to run async
	 * <p>This should take two params: 1) The passed in $job 2) The writable
	 * process socket to output results to</p>
	 * @return false|string|void
	 */
	public static function do_sync($job, $callback_function) {
		$socket_pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		$pid = pcntl_fork();

		// You're in the parent
		if ($pid>0) {
			// let any register shutdowns know about pid
			$GLOBALS['is_child'] = 0;

			// Close the write portion of the socket
			fclose($socket_pair[0]);

			// Grab child response, which blocks
			$response = self::fread_stream($socket_pair[1]);
			fclose($socket_pair[1]);

			$status = '';
			// Wait until the child is finished, after read finished
			pcntl_waitpid($pid, $status);

			return $response;

		// You're in the child; you do it and write to the parent
		} else if ($pid == 0) {

			// let any register shutdowns know about pid
			$GLOBALS['is_child'] = 1;

			// Close the read portion of the socket
			fclose($socket_pair[1]);
			$callback_function($job, $socket_pair[0]);
			exit();

		} else {
			return false;
		}

	}

	/**
	 * Run a series of tasks in parallel
	 *
	 * <p>The $child_funcs are all unaware of each other, but the $parent_func
	 * is aware of all of them, so the parent is the one who can manage the
	 * state of the entire process</p>
	 *
	 * @param array $jobs An array of params to be passed into the $child_func
	 * in the order they are invoked
	 * @param int $thread_count Max processes to run in parallel at once
	 * @param callable $child_func The child function to run in parallel
	 * <p>This function should call `exit` once it is finished to prevent
	 * zombie processes</p>
	 * <p>This should take two params: 1) The passed in $job[n] 2) The writable
	 * process socket to output results to</p>
	 * @param callable $parent_func A followup callable to process the results
	 * of the $child_func
	 * <p>This should take two params: 1) The passed in results of the
	 * $child_func 2) The $jobs[n] data that was fed to the child_func
	 * @param RateLimiter|null $limiter An optional rate limiter
	 * @return int|void
	 */
	public static function do_parallel(
		$jobs,
		$thread_count,
		$child_func,
		$parent_func,
		RateLimiter $limiter = null
	){
		$status = '';
		$child_pid = -1;
		$num_jobs_initialized = 0;
		$sockets = array();

		// Maintain maps for bookkeeping
		$child_pid_job_id_map = array();
		$socket_child_pid_map = array();
		$child_responses = array();

		while ( true ) {

			// Spawn a new job
			if ($num_jobs_initialized<count($jobs)) {

				if (!is_null($limiter)) {
					$limiter->wait_until_available();
				}

				// Setup child/parent socket
				$socket_pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
				// Set read side of socket to be non-blocking
				stream_set_blocking($socket_pair[1], false);

				// Spawn the new job
				$pid = pcntl_fork();
				// Fork Error
				if ($pid == -1) {
					// let any register shutdowns know about pid
					$GLOBALS['is_child'] = 0;
					return -1;
				}
				// Child
				if ($pid == 0) {
					// let any register shutdowns know about pid
					$GLOBALS['is_child'] = 1;

					// Child writes to $socket_pair[0]
					fclose($socket_pair[1]);

					$child_func($jobs[$num_jobs_initialized], $socket_pair[0]);
					// fwrite_stream($socket_pair[0], $response);
					exit();
				}
				// Parent
				else if ($pid > 0) {
					// DEBUG
					// sleep(2);
					// let any register shutdowns know about pid
					$GLOBALS['is_child'] = 0;

					// Add the entry to the map
					$child_pid_job_id_map[$pid] = $num_jobs_initialized;

					// Parent reads from $sockets[1]
					fclose($socket_pair[0]);
					$sockets[$pid] = $socket_pair[1];

					// Initialize the child_response
					$child_responses[intval($socket_pair[1])] = '';
					$socket_child_pid_map[intval($socket_pair[1])] = $pid;

					$num_jobs_initialized++;

					// echo "Sockets after spawn\n";
					// var_dump($sockets);

				}
			}


			// If we're in the initialization phase, continue
			if (
				(
					$num_jobs_initialized < $thread_count
					&& $num_jobs_initialized < count($jobs)
				)
				&&
				(
					is_null($limiter)
					|| $num_jobs_initialized < $limiter->getRate()
				)
			) {
				// echo "Initialization Phase: $num_jobs_initialized\n";
					continue;
			}

			// Wait for I/O on any of our child sockets
			$needs_read = array_values($sockets);
			$needs_write = NULL;
			$needs_except = NULL;
			// If there aren't any more sockets to wait on we've processed all jobs
			if (count($needs_read) == 0) {
				return;
			}

			while (true) {
				// echo "Entering stream_select\n";
				// var_dump($sockets);
				$num_ready_sockets = stream_select($needs_read, $needs_write, $needs_except, NULL);
				if ($num_ready_sockets===false) {
					// echo 'error on stream_select';
					exit();
				}
				foreach ($needs_read as $read_socket) {
					$tmp = fread($read_socket, 8192);
					// Limit a child response to 100MB (100*1024*1024)
					if (strlen($child_responses[intval($read_socket)]) < 100*1024*1024) {
						$child_responses[intval($read_socket)] .= $tmp;
					}
					// If the socket has been closed by the writer
					if (feof($read_socket)) {
						// The corresponding child fork is *likely* done
						$child_pid = pcntl_waitpid(
							$socket_child_pid_map[intval($read_socket)],
							$status
						);

						// Process the completed $child_pid
						if ($child_pid>0) {
							// echo "Child waited on: {$socket_child_pid_map[intval($read_socket)]}\n";
							// echo "child_pid after wait: $child_pid\n";
							$parent_func(
								$child_responses[intval($read_socket)],
								$jobs[$child_pid_job_id_map[$child_pid]]
							);

							// Clean up the socket
							fclose($sockets[$child_pid]);
							unset($sockets[$child_pid]);

							// echo "Processing completed child\n";
							// var_dump($sockets);

							// Clean up the map entry of child_pid to job_id
							unset($child_pid_job_id_map[$child_pid]);
							unset($child_responses[intval($read_socket)]);

							// Clean up the fd -> pid map
							unset($socket_child_pid_map[intval($read_socket)]);

							break 2;
						}

					}
				}

			}

		}

	}

	/**
	 * Helper for writing complete strings to a stream
	 *
	 * @param resource $fp A stream to write the string to
	 * @param string $string The string to write
	 * @return int The count of characters written to the stream
	 * TODO Is this even used? Might be able to delete this
	 */
	public static function fwrite_stream($fp, $string) {
		for ($written = 0; $written < strlen($string); $written += $fwrite) {
			$fwrite = fwrite($fp, substr($string, $written));
			if ($fwrite === false) {
				return $written;
			}
		}
		return $written;
	}

	/**
	 * Helper for reading complete strings from a stream
	 *
	 * @param resource $fp The stream to read from
	 * @return string The fully read data from the stream as a string
	 */
	public static function fread_stream($fp) {
		$contents = '';
		while (!feof($fp)) {
			$contents .= fread($fp, 8192);
		}
		return $contents;
	}

}

