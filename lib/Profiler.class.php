<?php
namespace TinyQueries;

/**
 * Profiler
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class Profiler
{
	private $start;
	private $nodes;
	private $current;
	
	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->start 	= microtime(true);
		$this->nodes	= array();
		$this->current	= &$this->nodes;
	}
	
	/**
	 * Create a new node
	 *
	 * @param {string} $node
	 */
	public function begin($node)
	{
		$this->current[ $node ] = array
		(
			"_start" 	=> microtime(true),
			"_parent"	=> &$this->current
		);
		
		$this->current = &$this->current[ $node ];
	}

	/**
	 * End the current node
	 */
	public function end()
	{
		if (!$this->current)
			return;
			
		$parent = &$this->current['_parent'];
			
		$time = microtime(true) - $this->current[ "_start" ];
		
		// If the current node does not have children, just set the node to the total time
		if (count( array_keys($this->current) ) <= 2)
			$this->current = $time;
		else	
		{
			// Otherwise add a field _total
			$this->current[ "_total" ] = $time;
			unset( $this->current[ "_start" ] );
			unset( $this->current[ "_parent" ] );
		}
			
		$this->current = &$parent;
	}
	
	/**
	 * Get the profiling results
	 */
	public function results()
	{
		$results = $this->nodes;
			
		$results['_total'] = microtime(true) - $this->start;
			
		return $results;
	}
}


