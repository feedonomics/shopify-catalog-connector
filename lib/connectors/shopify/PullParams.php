<?php
namespace ShopifyConnector\connectors\shopify;

/**
 * Params for pullers to use for filtering
 */
class PullParams
{

	/**
	 * @var array Store for the base set of parameters
	 */
	private array $base;

	/**
	 * @var array Store for the initial parameters to only use on the first call
	 */
	private array $initial;

	/**
	 * @var bool Flag for whether this is the first run
	 */
	private bool $firstRun = true;

	/**
	 * The base parameters are the parameters used as a base for every
	 * invocation. The initial parameters are merged with the base parameters
	 * for the initial invocation.
	 *
	 * @param array $base The base set of parameters to use on each call
	 * @param array $initial The initial parameters to use on only the first
	 * call
	 */
	public function __construct(array $base = [], array $initial = [])
	{
		$this->base = $base;
		$this->initial = $initial;
	}

	/**
	 * Convenience method to get parameters in the context of calling this in
	 * a loop. Returns base + initial parameters on the first call, and the
	 * base parameters alone for subsequent calls.
	 *
	 * <p><i>Only reliable when only used by only a single consumer.</i></p>
	 *
	 * @return array The base + initial parameters on the first call, and only
	 * the base parameters on subsequent calls
	 */
	public function getParams() : array
	{
		if ($this->firstRun) {
			$this->firstRun = false;
			return $this->getInitialParams();
		}
		return $this->getBaseParams();
	}

	/**
	 * Get the base set of parameters merged with the initial parameter set.
	 *
	 * @return array The base + initial parameters
	 */
	public function getInitialParams() : array
	{
		return array_merge($this->base, $this->initial);
	}

	/**
	 * Get the base set of parameters.
	 *
	 * @return array The base parameters
	 */
	public function getBaseParams() : array
	{
		return $this->base;
	}

}
