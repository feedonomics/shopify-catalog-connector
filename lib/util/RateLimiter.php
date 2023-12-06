<?php

namespace ShopifyConnector\util;


class RateLimiter {

	private $rate;
	private $per_seconds;
	private $last_check;

	/**
	 * @var int $allowance
	 * How many we still have in this time frame
	 */
	private $allowance;

	public function __construct($rate = 1, $per_seconds = 1){
		$this->rate = $rate;
		$this->per_seconds = $per_seconds;
		$this->allowance = $rate;
		$this->last_check = microtime(true);
	}

	/**
	 * @return int
	 */
	public function getRate() : int {
		return $this->rate;
	}

	public function setRate($rate){
		$this->rate = $rate;
	}

	public function setSeconds($seconds){
		$this->per_seconds = $seconds;
	}

	public function get_sleep_time($number_consumed){
		$current = microtime(True);
		$time_passed = $current - $this->last_check;
		$this->last_check = $current;

		$this->allowance += $time_passed * ($this->rate / $this->per_seconds);
		if ($this->allowance > $this->rate)
			$this->allowance = $this->rate;

		if ($this->allowance < $number_consumed) {
			$duration = ($number_consumed - $this->allowance) * ($this->per_seconds / $this->rate);
			return $duration * 1000000;
		}
		else {
			$this->allowance -= $number_consumed;
			return 0;
		}
	}

	public function wait_until_available($number_consumed = 1){
		do {
			$time_to_wait = $this->get_sleep_time($number_consumed);
			if ($time_to_wait == 0) {
				break;
			}
			usleep($time_to_wait);
		} while ($time_to_wait != 0);
	}

	public function setAllowance(int $allowance){
		$this->allowance = $allowance;
	}

	public function getAllowance() : int {
		return $this->allowance;
	}

}

