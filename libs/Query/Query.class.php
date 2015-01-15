<?php
namespace TinyQueries;

require_once( dirname(__FILE__) . '/../QueryDB.class.php' );

/**
 * Query
 *
 * This class represents a TinyQuery
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class Query 
{
	const CREATE 	= 'create';
	const READ 		= 'read';
	const UPDATE 	= 'update';
	const DELETE	= 'delete';
	
	public $params;
	public $defaultParam;
	public $children;
	public $operation;
	
	protected $db;
	protected $keys;
	protected $output;
	protected $root;
	protected $orderBy;
	protected $orderType;
	protected $maxResults;
	protected $paramValues;

	/**
	 * Constructor
	 *
	 * @param {QueryDB} $db Handle to database
	 */
	public function __construct($db)
	{
		$this->db 				= $db;
		$this->orderBy			= array();
		$this->children			= array();
		$this->paramValues 		= array();
		$this->keys				= new \StdClass();
		$this->params			= new \StdClass();
		$this->output			= new \StdClass();
		$this->output->key 		= null;
		$this->output->group	= false;
		$this->output->rows 	= "all";
		$this->output->columns 	= "all";
		$this->output->nested 	= true;
		$this->output->fields 	= new \StdClass();
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
		
		// Check if there is a default param
		if ($this->defaultParam)
		{
			$this->paramValues[ $this->defaultParam ] = $paramValues;
			return $this;
		}
		
		// First try to find a param which has no default value
		$n = 0;
		foreach ($this->params as $name => $def)
			if (!property_exists($def, 'default'))
			{
				$n++;
				$paramName = $name;
			}
		
		if ($n > 1)
			throw new \Exception("Cannot call query with one parameter value; query has $n parameters without default value");

		// If none was found, also take into account params having default values	
		if ($n == 0)
			foreach ($this->params as $name => $def)
			{
				$n++;
				$paramName = $name;
			}
			
		if ($n == 0)
			return $this;
		
		if ($n > 1)
			throw new \Exception("Cannot call query with one parameter value; query has $n parameters which have a default value");

		$this->paramValues[ $paramName ] = $paramValues;
		
		return $this;
	}
	
	/**
	 * Set the order 
	 *
	 * @param {string} $orderBy
	 * @param {string} $orderType
	 */
	public function order( $orderBy, $orderType = 'asc')
	{
		$this->orderBy 		= ($orderBy) ? array( $orderBy ) : array();
		$this->orderType	= $orderType;
		
		return $this;
	}
	
	/**
	 * Sets the fieldname which can be used as a key (for example for merging)
	 *
	 * @param {string} $keyField
	 *
	 * @return {Query}
	 */
	public function key( $keyField )
	{
		$this->output->key = $keyField;
		
		return $this;
	}
	
	/**
	 * Returns the field name in the select-part which corresponds to $key;
	 *
	 * @param {string} $key
	 */
	protected function keyField($key)
	{
		if (!property_exists($this->keys, $key))
			return $key;
			
		return is_array( $this->keys->$key ) 
			? "__" . $key 
			: $this->keys->$key;
	}
	
	/**
	 * Collects the values from $rows corresponding to the $key
	 *
	 * @param {string} $key
	 * @param {array} $rows
	 */
	protected function keyValues($key, &$rows)
	{
		if (!property_exists($this->keys, $key))
			throw new \Exception("Key $key is not present in " . $this->name());
		
		$values		= array();	
		$keyField 	= $this->keys->$key;
		
		if (count($rows)==0)
			return $values;
			
		// Simple case, just select the values from the key-column
		if (!is_array($keyField))
		{
			if (!array_key_exists($keyField, $rows[0]))
				throw new \Exception("Field $keyField is not present in rows");
				
			for ($i=0; $i<count($rows); $i++)
				$values[] = $rows[ $i ][ $keyField ];
				
			return $values;
		}

		// Check existence of each key field
		foreach ($keyField as $field)
			if (!array_key_exists($field, $rows[0]))
				throw new \Exception("Field $field is not present in rows");
		
		// Create an array of arrays
		for ($i=0; $i<count($rows); $i++)
		{
			$value = array();
			foreach ($keyField as $field)
				$value[] = $rows[$i][ $field ];
				
			$values[] = $value;
		}
		
		return $values;
	}
	
	/**
	 * Sets whether the output should be grouped by the key
	 * so you get a structure like: { a: [..], b: [..] }
	 *
	 * @param {boolean} $value
	 */
	public function group($value = true)
	{
		$this->output->group = $value;
		
		return $this;
	}
	
	/**
	 * Set the maximum number of results which should be returned (only applies to merge queries)
	 *
	 * @param {int} $maxResults
	 */
	public function max( $maxResults )
	{
		$this->maxResults = $maxResults;
		
		return $this;
	}
	
	/**
	 * Returns the name of the query
	 *
	 */
	public function name()
	{
		if (count($this->children) == 0)
			return null;
			
		return $this->children[0]->name();
	}
	
	/**
	 * Returns the prefix of the query (first part before the dot)
	 *
	 */
	protected function prefix()
	{
		if ($this->root)
			return $this->root;
			
		$name = $this->name();
		
		if (!$name)
			return null;
			
		$parts = explode(".", $name);
		
		return $parts[0];
	}
	
	/**
	 * Adds a parameter binding to the query
	 *
	 * @param {string} $paramName
	 * @param {string} $fieldName 
	 */
	public function bind($paramName, $fieldName = null)
	{
		return $this;
	}
	
	/**
	 * Generic run function 
	 *
	 * @param {assoc} $paramValues
	 */
	public function run($paramValues = null)
	{
		if ($this->operation == self::READ)
			return $this->select($paramValues);
			
		$this->execute($paramValues);
		
		switch ($this->operation)
		{
			case self::CREATE: return "Created item";
			case self::UPDATE: return "Updated item";
			case self::DELETE: return "Deleted item";
		}
	}
	
	/**
	 * Executes the query and returns the result
	 *
	 * @param {assoc} $paramValues
	 */
	public function execute($paramValues = null)
	{
		if (!is_null($paramValues))
			$this->params( $paramValues );
			
		return null;	
	}
	
	/**
	 * Generic select function
	 *
	 * @param {mixed} $paramValues
	 * @param {string} $key (optional) Key field which can be used to group the output
	 * @param {boolean} $cleanUp Do clean up of columns in query output
	 */
	public function select($paramValues = null, $key = null, $cleanUp = true)
	{
		// If no key is supplied take default from JSON spec
		if (is_null($key))
			$key = $this->output->key;
		
		$data = $this->execute($paramValues);
		
		// Keys should not always be cleaned up
		if ($cleanUp)
			$this->cleanUp($data, 'keys', $key);
		
		// Child defs should always be cleaned up
		$this->cleanUp($data, 'childDefs', $key);
		
		// We are ready if output is not an array of assocs
		if ($this->output->columns != 'all' || $this->output->rows != 'all')
			return $data;
			
		// Apply rows2columns transformation
		if (property_exists($this->output, 'rows2columns'))
			$data = Arrays::rows2columns( 
				$data, 
				$this->output->rows2columns->key,
				$this->output->rows2columns->name,
				$this->output->rows2columns->value );
				
		// Apply grouping transformation
		if ($key && $this->output->group)
			return Arrays::groupBy($data, $key, true);
			
		// Apply key transformation
		if ($key)
			return Arrays::toAssoc($data, $key);
				
		return $data;
	}
	
	/**
	 * Generic select function; selects the first row
	 *
	 * @param {mixed} $paramValues
	 * @param {string} $key (optional) Key field which can be used to group the output
	 * @param {boolean} $cleanUp Do clean up of columns in query output
	 */
	public function select1($paramValues = null, $key = null, $cleanUp = true)
	{
		$output = $this->select($paramValues, $key, $cleanUp);
		
		return ($this->output->rows == 'first')
			? $output
			: ((is_array($output) && count($output)>0) 
				? $output[0] 
				: null
			);
	}
	
	/**
	 * Executes the query and attaches the fields to the given object
	 *
	 * @param {object} $object
	 * @param {assoc} $queryParams
	 */
	public function selectToObject(&$object, $queryParams = null)
	{
		if (!is_object($object))
			throw new \Exception('Query::selectToObject - given parameter is not an object');
			
		if ($this->output->columns == 'first')
			throw new \Exception('Query::selectToObject - query does not select all columns');

		if ($this->output->rows == 'all')
			throw new \Exception('Query::selectToObject - query does select all rows');
		
		$record = $this->select( $queryParams );
		
		if (!$record)
			return false;
			
		foreach ($record as $key => $value)
			$object->$key = $value;
			
		return true;
	}
	
	/**
	 * Imports (a part of) a query definition
	 *
	 * @param {object} $query
	 */
	public function import($query)
	{
		if (property_exists($query, 'root'))			$this->root 		= $query->root;
		if (property_exists($query, 'keys'))			$this->keys 		= $query->keys;
		if (property_exists($query, 'params'))			$this->params 		= $query->params;
		if (property_exists($query, 'defaultParam'))	$this->defaultParam	= $query->defaultParam;
		if (property_exists($query, 'operation')) 		$this->operation 	= $query->operation;
		if (property_exists($query, 'maxResults')) 		$this->maxResults 	= $query->maxResults;
		
		if (property_exists($query, 'output'))
		{
			if ($query->output)
			{
				$fields = array('key', 'group', 'rows', 'columns', 'nested', 'fields', 'rows2columns');
				foreach ($fields as $field)
					if (property_exists($query->output, $field) && $query->output->$field)
						$this->output->$field = $query->output->$field;
			}
			else
				// This means, the query is an insert, update or delete
				$this->output = false;
		}
			
		return $this;
	}
	
	/**
	 * Basic 'explain' function which shows the query-tree
	 *
	 */
	public function explain($depth = 0)
	{
		$expl = '';
	
		for ($i=0;$i<$depth;$i++)
			$expl .= "  ";
			
		$expl .= "- " . $this->name() . " ";
			
		// Remove main namespace from classname
		$class = explode( "\\", get_class( $this ) );
		array_shift($class);
		$class = implode( "\\", $class );
		
		$expl .= "[" . $class . "]\n";
		
		foreach ($this->children as $child)
			$expl .= $child->explain($depth+1);
			
		return $expl;
	}
	
	/**
	 * Cleans up columns which should not be in the query output
	 *
	 * @param {mixed} $data Query output
	 * @param {string} $type Type of cleaning 'keys' or 'childDefs'
	 * @param {string} $key Key field which should be excluded from clean up
	 */
	protected function cleanUp(&$rows, $type, $key = null)
	{
		if ($this->output->columns == 'first')
			return;
			
		if (!$rows || count($rows) == 0)
			return;			

		if ($this->output->rows == 'first')
			$rows = array( $rows );
		
		$columnsToRemove = array();
		
		switch ($type)
		{
			case 'keys':
				$registeredOutputFields = is_array($this->output->fields)
					? $this->output->fields
					: array_keys( get_object_vars($this->output->fields) );

				// Check which columns can be removed	
				foreach (array_keys($rows[0]) as $field)
					if ($field != $key && $field != $this->output->key && preg_match("/^\_\_/", $field) && !in_array( $field, $registeredOutputFields ))
						$columnsToRemove[] = $field;
				break;
				
			case 'childDefs':
				foreach ($rows[0] as $field => $value)
					if (is_null($value) && property_exists($this->output->fields, $field) && property_exists($this->output->fields->$field, 'child'))
						$columnsToRemove[] = $field;
						
				break;
		}
				
		// Do the clean up
		foreach (array_keys($rows[0]) as $field)
			if (in_array($field, $columnsToRemove))
				for ($i=0;$i<count($rows);$i++)
					unset( $rows[$i][$field] ); 
					
		if ($this->output->rows == 'first')
			$rows = $rows[0];
	}
	
	/**
	 * Updates meta info for this query 
	 * (Now only keys & parameters are updated; should be extended with other fields like output-fields etc)
	 */
	protected function update()
	{
		// Only applies for queries with children
		if (count($this->children) == 0)
			return;
		
		// Call update recursively
		foreach ($this->children as $child)
			$child->update();
			
		// Copy parameters from children
		foreach ($this->children as $child)
			foreach ($child->params as $name => $spec)
				$this->params->$name = $spec;
				
		// Copy operation from first child
		$this->operation = $this->children[0]->operation;
	}
	
	/**
	 * Links a list of terms to this query
	 *
	 * @param {array} $terms
	 * @param {boolean} $firstAsRoot
	 */
	protected function linkList($terms, $firstAsRoot)
	{
		$prefix = null;
		
		if ($firstAsRoot)
		{
			$term = array_shift($terms);
			
			// Link first query to get prefix
			$first = $this->link( $term );
			
			$first->update();
			
			$prefix = $first->prefix();
				
			if (!$prefix)
				throw new \Exception("prefix not known for " . $term);
		}
	
		foreach ($terms as $term)
		{
			if ($prefix)
				$term = $prefix . "." . $term;
				
			$this->link( $term );
		}
			
		$this->update();
	}
	
	/**
	 * Connects a query to this query
	 *
	 * @param {string} $term
	 *
	 * @return {Query}
	 */
	protected function link($term)
	{
		$child = $this->db->query($term);

		$this->children[] = $child;

		return $child;
	}

	/**
	 * Checks if the given queries match based on common keys.
	 * If one query is passed, then it is compared with this query
	 *
	 * @param {array|Query} $queries
	 */
	protected function match(&$queries)
	{
		$matching = null;
		
		$list = (is_array($queries))
			? $queries
			: array( $this, $queries );

		foreach ($list as $query)
		{
			$keys = array_keys( get_object_vars( $query->keys ) );
	
			$matching = ($matching)
				? array_intersect( $matching, $keys )
				: $keys;
		}
		
		// Convert back to normal array - array_intersect preserves the indices, which might result in an assoc array
		$matching = array_values($matching);

		if (count($matching) != 1)
			return null;
			
		return $matching[ 0 ];
	}
}
