<?php
namespace TinyQueries;

require_once('Query.php');

/**
 * Merge
 *
 * This class represents a sequence of merge queries
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class QueryMerge extends Query
{
	/**
	 * Constructor
	 *
	 * @param {DB} $db Handle to database
	 * @param {string} $terms (optional) 
	 */
	public function __construct($db, $terms = array())
	{
		parent::__construct($db);
		
		$this->linkList($terms, false);
	}

	/**
	 * Merges the output of the child queries
	 *
	 * @param {assoc} $paramValues
	 */
	public function execute($paramValues = null)
	{
		parent::execute($paramValues);
		
		// Determine the merge key
		$mergeKey = ($this->output->key)
			? $this->output->key
			: $this->match( $this->children );

		$orderBy = (count($this->orderBy) == 1)
					? $this->orderBy[0]
					: null;
		
		$result = ($mergeKey)
			? $this->mergeByKey( $mergeKey, $orderBy )
			: $this->mergePlain( $orderBy );
			
		if ($this->maxResults)
		{
			if (Arrays::isAssoc($result))
				Arrays::spliceAssoc($result, (int) $this->maxResults);
			else
				array_splice($result, $this->maxResults);
		}
		
		// If there is orderBy, the result should be an index-based array
		// However, if there is also an key, the intermediate result should be an associative array, which is needed for correct merging
		// So in this case, the intermediate result should be converted to an index-based array
		if ($this->output->key && count($this->orderBy)==1 && Arrays::isAssoc($result))
			$result = Arrays::toIndexed($result); 
		
		return $result;
	}

	/**
	 * Adds a parameter binding to the query
	 *
	 * @param {string} $paramName
	 * @param {string} $fieldName 
	 */
	public function bind($paramName, $fieldName = null)
	{
		// Do recursive call on children
		foreach ($this->children as $child)
			$child->bind($paramName, $fieldName);
				
		return $this;
	}
	
	/**
	 * Merges the output of the child queries without a key
	 *
	 */
	private function mergePlain($orderBy)
	{
		$result = array();
		
		foreach ($this->children as $query)
		{
			$rows = $query->params( $this->paramValues )->select();
			Arrays::mergeArrays( $result, $rows, $orderBy, $this->orderType );
		}
		
		return $result;
	}
	
	/**
	 * Merges the output of the child queries by using a common key
	 *
	 * @param {string} $key
	 */
	private function mergeByKey($key, $orderBy)
	{
		$result = array();
		
		foreach ($this->children as $query)
		{
			$rows = $query->select( $this->paramValues, $query->keyField($key), false );
				
			Arrays::mergeAssocs( $result, $rows, $orderBy, $this->orderType );
		}

		// Convert 'back' to indexed array in case the main query has no output key
		if (!$this->output->key)
			return Arrays::toIndexed($result);
		
		return $result;
	}
	
	/**
	 * Updates meta info for this query 
	 *
	 */
	protected function update()
	{
		parent::update();
		
		// Copy all keys from all children
		$this->keys = new \StdClass();
		foreach ($this->children as $child)
			foreach ($child->keys as $key => $field)
				$this->keys->$key = $field;
				
		// Copy default param only if every child has the same default param
		$defaultParam = $this->children[0]->defaultParam;
		$diff = false;
		foreach ($this->children as $child)
			if ($child->defaultParam != $defaultParam)
				$diff = true;
		
		if (!$diff)
			$this->defaultParam = $defaultParam;
	}
}

