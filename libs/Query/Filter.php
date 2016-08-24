<?php
namespace TinyQueries;

require_once('Query.php');

/**
 * Filter
 *
 * This class represents a sequence of filter queries
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class QueryFilter extends Query
{
	// Max number of records which can come out of a filter query
	const MAX_SIZE_FILTER = 5000;
	
	/**
	 * Constructor
	 *
	 * @param {DB} $db Handle to database
	 * @param {string} $terms (optional) 
	 */
	public function __construct(&$db, $terms = array())
	{
		parent::__construct($db);

		$this->linkList( $terms, true );
		
		// Get the link key
		list($key) = array_keys( get_object_vars( $this->keys ) );

		// Add key-binding for all children except the last
		for ($i=0; $i<count($this->children)-1; $i++)
			$this->children[$i]->bind($key);
	}

	/**
	 * Sets the query parameter values
	 *
	 * @param {mixed} $paramValues
	 *
	 * @return {Query}
	 */
	public function params( $paramValues )
	{
		// If paramValues is already an assoc, just copy it
		if (Arrays::isAssoc($paramValues) || is_null($paramValues))
		{
			$this->paramValues = $paramValues;
			return $this;
		}
		
		$lastChild = $this->children[ count($this->children) - 1 ];
		
		// Pass the single param to the last child
		// This is needed to prevent that the param cannot be matched because there are more than 1 candidates
		// This is typical for a path like "a/1/b" which is translated to $db->get("b:a", 1)
		// Normally the query "a:b" has two non-default params, while "a.b" has one.
		$lastChild->params( $paramValues );
		
		// Copy the values from the last child back to this
		foreach ($lastChild->paramValues as $key => $value)
			$this->paramValues[ $key ] = $value;
	
		return $this;
	}
	
	/**
	 * Filters the output of the child queries
	 *
	 * @param {assoc} $paramValues
	 */
	public function execute($paramValues = null)
	{
		parent::execute($paramValues);
		
		// Determine the chain key
		$key = ($this->output->key)
			? $this->output->key
			: $this->match( $this->children );
			
		if (!$key)
			throw new \Exception("Cannot chain queries - no common key found");
			
		$n = count($this->children);
		
		if ($n==0)
			return array();
		
		// Take last query as base
		$params		= $this->paramValues;
		$baseQuery	= $this->children[ $n-1 ];
		$rows 		= $baseQuery->select( $params, null, false );
		$fieldBase	= $baseQuery->keyField($key);

		// Attach all other queries
		for ($i=$n-2; $i>=0; $i--)
		{
			// If the number of rows is 0 we can stop
			if (count($rows) == 0)
				return $rows;
			
			// Get all values for $rows[0..n][$key]
			$params[$key] 	= $baseQuery->keyValues($key, $rows);
			$query 			= $this->children[ $i ];
			
			// Check to prevent the SQL query to be blown up in size
			if (count($params[$key]) > self::MAX_SIZE_FILTER)
				throw new \Exception("Cannot apply filter query " . $this->name() . "; number of intermediate results is too large - if possible use 'split' option for the parameter");
				
			// Execute query
			$rows1 = $query->select($params, $query->keyField($key), false );
			
			$j = 0;
			$keyValues = array();
			
			// Do an intersection of $rows & $rows1
			while ($j<count($rows))
			{
				$keyValue = $rows[$j][$fieldBase];
					
				if (array_key_exists($keyValue, $rows1))
				{
					// Attach the fields of $rows1 to $rows
					foreach ($rows1[ $keyValue ] as $name => $value)
						$rows[$j][$name] = $value;
					$j++;
					
					// Remember value (needed for next loop)
					$keyValues[] = $keyValue;
				}
				else
				{
					// Remove elements which are not in the latest query result (rows1)
					array_splice( $rows, $j, 1 );
				}
			}
			
			// Add fields to $rows which were not in $rows yet
			// (in general this will not occur, but there are some exceptions, like aggregate queries)
			foreach ($rows1 as $keyValue => $record)
				if (!in_array($keyValue, $keyValues))
					$rows[] = $record;
		}
		
		return $rows;
	}

	/**
	 * Adds a parameter binding to the query
	 *
	 * @param {string} $paramName
	 * @param {string} $fieldName 
	 */
	public function bind($paramName, $fieldName = null)
	{
		// Only bind to first child ('root')
		$this->children[ 0 ]->bind($paramName, $fieldName);
		
		return $this;
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
		
		// Copy root from first child
		$this->root = $this->children[ 0 ]->root;
		
		// Copy defaultParam from last child
		$lastChild = $this->children[ count($this->children) - 1 ];
		$this->defaultParam = $lastChild->defaultParam;
	}
}

