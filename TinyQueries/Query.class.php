<?php
/**
 * TinyQueries - Framework for merging and nesting relational data
 *
 * @author      Wouter Diesveld <wouter@tinyqueries.com>
 * @copyright   2012 - 2014 Diesveld Query Technology
 * @link        http://www.tinyqueries.com
 * @version     1.0.2
 * @package     TinyQueries
 *
 * License
 *
 * This software is licensed under Apache License 2.0
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
 * LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
 * OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
 * WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */
namespace TinyQueries;

require_once('QuerySQL.class.php');

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
	public $id;
	public $compiled;
	public $params;
	
	private $db;
	private $children;
	private $loadChildren;
	
	private $keys;
	private $output;
	private $select;
	private $from;
	private $where;
	private $groupBy;
	private $having;
	private $orderBy;
	private $orderType;
	private $maxResults;
	
	private $querySql;
	private $paramValues;

	/**
	 * Constructor
	 *
	 * @param {QueryDB} $db Handle to database
	 * @param {string} $idList (optional) ID of the query, or a list of ID's like "a,b,c" (a merge), 
	 *										or a nested structure of ID's like "a(b(c),d)" 
	 *										or a combination like "a,b(c)"
	 */
	public function __construct($db, $idList = null)
	{
		$this->db 			= $db;
		$this->select		= array();
		$this->where		= array();
		$this->groupBy		= array();
		$this->having		= array();
		$this->orderBy		= array();
		$this->children		= array();
		$this->paramValues 	= array();
		$this->loadChildren = false;
		$this->keys			= new \StdClass();
		$this->params		= new \StdClass();
		$this->output		= new \StdClass();

		$this->output->key 		= null;
		$this->output->group	= false;
		$this->output->rows 	= "all";
		$this->output->columns 	= "all";
		$this->output->nested 	= true;
		$this->output->fields 	= new \StdClass();
		
		// Parse $idList
		$this->parseList($idList);
	}

	/**
	 * Sets the query parameter values
	 *
	 * @param {assoc} $params
	 *
	 * @return {Query}
	 */
	public function params( $params )
	{
		$this->paramValues = $params;
		
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
	 * Connects a query to this query
	 *
	 * @param {string} $idTree
	 * @param {string} $type 'child'|'merge'
	 *
	 * @return {Query}
	 */
	private function add($idTree, $type)
	{
		$child = new Query($this->db, $idTree);

		$this->children[] = $child;
		
		// If there is no id, it is assumed that this is a merge query, so we are ready
		if (!$this->id)
			return $this;
		
		// Load parent query
		$this->load();
		
		// If parent is compiled & type=child we are ready (child specs are set by compiler)
		if ($this->compiled && $type == 'child')
			return $this;
		
		// Load child query
		$child->load();
		
		if ($this->compiled != $child->compiled)
			throw new \Exception("Query::add - parent & child queries should both be compiled or not compiled");
		
		// Find the matching key between parent & child
		$matchKey = $this->match( $child );

		if (!$matchKey)
			throw new \Exception("Query::add - cannot add query; there is no unique matching key for '" . $this->id . "' and '" . $child->id . "'");
		
		$parentKey 	= $this->keys->$matchKey;
		$childKey	= $child->keys->$matchKey;
		
		$parentKeyAlias = ($this->compiled) 
			? $parentKey
			: "__parentKey-" . count($this->children);

		$childKeyAlias = ($this->compiled) 
			? $childKey
			: $matchKey;

		// Add parentKey to select
		if (!$this->compiled)
			$this->select[] = $parentKey . " as '" . $parentKeyAlias . "'";
				
		// Create child definition which is compatible with the one used for compiled queries
		$childDef = new \StdClass();
		$childDef->type  		= $type;
		$childDef->child 		= $child->id;
		$childDef->parentKey 	= $parentKeyAlias;
		$childDef->childKey		= $childKeyAlias;
		$childDef->params 		= new \StdClass();
		$childDef->params->$matchKey = $parentKeyAlias;
		
		$this->output->fields->{$child->id} = $childDef;
		
		// Modify child such that it can be linked to the parent
		if (!$this->compiled)
			$child->bind( $matchKey, $childKey );
		
		return $this;
	}
	
	/**
	 * Adds a parameter binding to the query
	 *
	 * @param {string} $paramName
	 * @param {string} $fieldName
	 */
	public function bind($paramName, $fieldName)
	{
		if ($this->compiled)
			throw new \Exception("Cannot add binding to compiled queries");
			
		if (property_exists($this->params, $paramName))
			throw new \Exception("Query::bind - parameter '" . $paramName . "' already present in query '" . $this->id . "'");
		
		$this->params->$paramName = new \StdClass();
		$this->params->$paramName->doc		= "Auto generated parameter";
		$this->params->$paramName->type 	= "string";
		$this->params->$paramName->expose 	= "public";
		
		$this->select[] = $fieldName . " as '"  . $paramName . "'";
		$this->where[] 	= $fieldName . " in (:" . $paramName . ")"; 
		
		return $this;
	}
	
	/**
	 * Loads the JSON and/or SQL files which correspond to this query
	 *
	 */
	public function load()
	{
		if (!$this->id)
			return $this;
	
		// Already loaded
		if ($this->querySql)
			return $this;
			
		// Create the SQL query
		$this->querySql = new QuerySQL( $this->db, $this->id );
		
		$json = null;
			
		try
		{
			// Try the compiled query first
			$json = $this->querySql->getInterface();
			$this->compiled = true;
		}
		catch (\Exception $e)
		{
			// If no compiled query is present, load the uncompiled json
			$json = $this->db->queries->json( $this->id );
			$this->compiled = false;
		}
		
		$this->import( $json );
		
		return $this;
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
			
		// If there is no ID and the query has children the output of this query is a merge of it children
		if (!$this->id && count($this->children) > 0)
			return $this->merge();
			
		$this->load();

		if (!$this->compiled)
		{
			$this->querySql->setSql( $this->compile() );
			$this->querySql->setInterface( $this->params, $this->output );
		}
		
		// If the query has output, do select, otherwise just execute it
		return ($this->output)
			? $this->querySql->select( $this->paramValues )
			: $this->querySql->execute( $this->paramValues );
	}
	
	/**
	 * Generic select function
	 *
	 * @param {assoc} $paramValues
	 * @param {string} $key
	 */
	public function select($paramValues = null, $key = null)
	{
		$this->load();

		if (!is_null($key))
			$this->key( $key );
		
		$data = $this->execute($paramValues);
		
		$this->bindChildren( $data );
		
		// Handle output settings 'key' and 'group'
		if ($this->output->columns == 'all' && $this->output->rows == 'all')
		{
			if ($this->output->key && $this->output->group)
				return Arrays::groupBy($data, $this->output->key, true);
			
			if ($this->output->key)
				return Arrays::toAssoc($data, $this->output->key);
		}

		return $data;
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
		$this->select 	= Arrays::toArray( property_exists($query, 'select') 	? $query->select 	: null );
		$this->from 	= property_exists( $query, 'from') ? $query->from : null;
		$this->where 	= Arrays::toArray( property_exists($query, 'where') 	? $query->where 	: null );
		$this->groupBy 	= Arrays::toArray( property_exists($query, 'groupBy') 	? $query->groupBy 	: null );
		$this->orderBy 	= Arrays::toArray( property_exists($query, 'orderBy')	? $query->orderBy 	: null );
		$this->having 	= Arrays::toArray( property_exists($query, 'having') 	? $query->having 	: null );
		
		if (property_exists($query, 'keys'))	$this->keys 	= $query->keys;
		if (property_exists($query, 'params'))	$this->params 	= $query->params;
		
		if (property_exists($query, 'output'))
		{
			if ($query->output)
			{
				$fields = array('key', 'group', 'rows', 'columns', 'nested', 'fields');
				foreach ($fields as $field)
					if (property_exists($query->output, $field) && $query->output->$field)
						$this->output->$field = $query->output->$field;
			}
			else
				// This means, the query is an insert, update or delete
				$query->output = false;
		}
			
		return $this;
	}
	
	/**
	 * Compiles the query to SQL
	 *
	 */
	private function compile()
	{
		if (!$this->select)
			throw new \Exception("compile: 'select' is missing");
			
		$sql = "select\n" . implode(",\n", $this->select) . "\n";
		
		if ($this->from)
			$sql .= "from\n" . $this->from . "\n";
			
		if (count($this->where) > 0)
		{
			$where = array();
			foreach ($this->where as $w)
				$where[] = "(" . $w . ")";
				
			$sql .= "where\n" . implode(" and\n", $where);
		}
		
		if (count($this->groupBy) > 0)
			$sql .= "group by\n" . implode(",\n", $this->groupBy) . "\n";
		
		if (count($this->orderBy) > 0)
			$sql .= "order by\n" . implode(",\n", $this->orderBy) . "\n";
			
		if ($this->orderType)
			$sql .= $this->orderType;
		
		return $sql;
	}
	
	/**
	 * Splits the string by the separator, respecting possible nested parenthesis structures
	 *
	 * @param {string} $string
	 * @param {string} $separator Must be a single char!
	 */
	private function split($string, $separator)
	{
		$string = trim( $string );
		$stack 	= 0;
		$part 	= '';
		$list 	= array();
		
		if ($string == '')
			return $list;
		
		for ($i=0; $i<strlen($string); $i++)
		{
			$char = substr( $string, $i, 1 );
			
			switch ($char)
			{
				case '(': $stack++; break;
				case ')': $stack--; break;
				case $separator: 
					if ($stack == 0)
					{
						$list[] = $part;
						$part 	= '';
						$char 	= '';
					}
					break;
			}

			$part .= $char;
		}
		
		// Add last element
		$list[] = $part;
		
		return $list;
	}

	/**
	 * Parses an id list structure and sets the queryID + its children
	 *
	 * @param {string} $idList ID of the query, or a list of ID's like "a,b,c", or a nested structure of ID's like "a(b(c),d)" or a combination like "a,b(c)"
	 */
	private function parseList($idList = null, $alwaysCreateChildren = false)
	{
		$list = $this->split($idList, ',');
		
		// If there is only one element, parse it as a double-dotted expression a:b:c
		if (!$alwaysCreateChildren && count($list) == 1)
			return $this->parseDotted( $list[0] );
	
		// Otherwise create a child query for each element
		foreach ($list as $idTree)
			$this->add( $idTree, 'child' );
	}
	
	/**
	 * Parses a structure like a:b:c
	 *
	 */
	private function parseDotted($idDotted)
	{
		$list = $this->split($idDotted, ':');
		
		if (count($list) > 2)
			throw new \Exception("Sorry, using more than one ':' is not yet supported");

		if (count($list) == 1)
			return $this->parseTree( $list[0] );
		
		$child = $list[0];
		
		list( $childID, $dummy ) = $this->parseID( $child );			
					
		$parent = $childID . "." . $list[1];
		
		$this->parseTree( $parent );
			
		$this->add( $child, 'merge' );
	}
	
	/**
	 * Parses a ID tree structure and sets the ID of this query and creates child queries
	 *
	 * @param {string} $idTree
	 */
	private function parseTree($idTree)
	{
		list( $this->id, $children ) = $this->parseID( $idTree );

		if (!$this->id)
			throw new \Exception("Query: Cannot parse idTree " . $idTree);
		
		if (is_null($children))
		{
			// If no children are specified, load all children by default
			$this->loadChildren = true;
			return;
		}
		
		$this->parseList( $children, true );
	}
	
	/**
	 * Gets the ID part and children-part out of a tree structure, so "a(b(c),d)" will return "a" & "b(c),d"
	 *
	 * @param {string} $idTree
	 */
	private function parseID($idTree)
	{
		$idTree = trim($idTree);
		
		// If tree has only one node this is just the ID of the query
		if (preg_match('/^[\w\-\.]+$/', $idTree))
			return array($idTree, null);
			
		$match = null;
		
		if (!preg_match('/^([\w\-\.]+)\s*\((.*)\)$/', $idTree, $match))
			return array( null, null );
			
		return array( $match[1], trim( $match[2] ) );
	}
	
	/**
	 * Checks if the given queries match based on common keys.
	 * If one query is passed, then it is compared with this query
	 *
	 * @param {array|Query} $queries
	 */
	private function match(&$queries)
	{
		$matching = null;
		
		$list = (is_array($queries))
			? $queries
			: array( $this, $queries );
		
		foreach ($list as $query)
		{
			$query->load();
			
			$keys = array_keys( get_object_vars( $query->keys ) );
			
			$matching = ($matching)
				? array_intersect( $matching, $keys )
				: $keys;
		}
				
		if (count($matching) != 1)
			return null;
			
		return $matching[ 0 ];
	}
	
	/**
	 * Merges the output of the child queries
	 *
	 */
	private function merge()
	{
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
			// Add the key to the select fields
			if (!$query->compiled)
				$query->select[] = $query->keys->$key . " as '" . $key . "'";
				
			$rows = $query->key( $key )->params( $this->paramValues )->select();
				
			Arrays::mergeAssocs( $result, $rows, $orderBy, $this->orderType );
		}

		// Convert 'back' to indexed array in case the main query has no output key
		if (!$this->output->key)
			return Arrays::toIndexed($result);
		
		return $result;
	}
	
	/**
	 * Binds the child queries to the query output
	 *
	 * @param {array} $rows The rows/row as returned by QuerySQL
	 */
	private function bindChildren(&$rows)
	{
		// In this case child binding does not apply
		if ($this->output->columns == 'first')
			return;
		
		if ($this->output->rows == 'first')
			$rows = array( $rows );
			
		if (count($rows) == 0)
			return;
	
		$columnsToRemove = array();
		
		// Get the child definitions
		$children = array();
		foreach ($this->output->fields as $name => $type)
			if (property_exists($type, 'child'))
			{
				 $childDef = $type;
				 $childDef->fieldName = $name;
				 $children[] = $childDef;
			}				
		
		// Merge the child queries with this query
		foreach ($children as $child)
		{
			$r = $this->bindChild($rows, $child);
			
			if (!$r)
				$columnsToRemove[] = $child->fieldName;
		}

		// Clean up helper columns
		$registeredOutputFields = is_array($this->output->fields)
									? $this->output->fields
									: array_keys( get_object_vars($this->output->fields) );

		foreach (array_keys($rows[0]) as $field)
			if (in_array($field, $columnsToRemove) || ($field != $this->output->key && preg_match("/^\_\_/", $field) && !in_array( $field, $registeredOutputFields )))
				for ($i=0;$i<count($rows);$i++)
					unset( $rows[$i][$field] ); 
					
		if ($this->output->rows == 'first')
			$rows = $rows[ 0 ];
	}

	/**
	 * Creates the child query based on the given child definition, executes it and ties 
	 * the result to the output of the parent query
	 *
	 * @param {array} $parentRows Query output of the parent query
	 * @param {object} $childDef Definition of the child query. This object has the following fields:
	 *					{string} $child ID of the child query
	 *					{string} $childKey Fieldname which is present in the child query, which matched the:
	 *					{string} $parentKey Fieldname which is present in the parent query
	 */
	private function bindChild(&$parentRows, $childDef)
	{
		$child = null;
	
		// Check if the child is in the list of children which are set by the idTree
		foreach ($this->children as $c)
			if ($c->id == $childDef->fieldName)
			{
				$child = $c;
				
				// Update the ID, since the ID tree-structure might contain aliases of queries instead of the queryID's
				$child->id = $childDef->child;
			}
		
		// If not and not all children should be loaded we are ready
		if (!$child && !$this->loadChildren)
			return false;
		
		// Create the child if it was not in the child-array
		if (!$child)
			$child = new Query( $this->db, $childDef->child );
		
		$params 	= $this->paramValues; // Take parent parameters as default params for child
		$childKey 	= $childDef->childKey;
		$parentKey 	= $childDef->parentKey;
		
		// Check types
		if (!is_array($parentRows))
			throw new \Exception('bindChild: illegal function call - parentRows should be an array of associative arrays');

		if (count($parentRows)>0 && !is_array($parentRows[0]))
			throw new \Exception('bindChild: illegal function call - parentRows should be an array of associative arrays');
		
		if (count($parentRows)>0 && !array_key_exists($parentKey, $parentRows[0]))
			throw new \Exception('bindChild: illegal function call - parentRows should consist of associative arrays containing the field '.$parentKey);
		
		// Select the childQuery parameter values from the parent query output
		foreach ($childDef->params as $name => $parentColumn)
		{
			$values = array();
			
			foreach ($parentRows as $row)
				if (!in_array( $row[ $parentColumn ], $values))
					$values[] = $row[ $parentColumn ];
				
			$params[ $name ] = $values;
		}

		// Execute child query and group results
		$childRows = $child->group()->select( $params, $childKey );

		// Combine child rows with parent rows
		switch ($childDef->type)
		{
			case 'merge':
				for ($i=0;$i<count($parentRows);$i++)
				{
					$keyValue = $parentRows[$i][$parentKey];
					
					if (array_key_exists($keyValue, $childRows))
						foreach ($childRows[ $keyValue ][0] as $k => $v)
							$parentRows[$i][ $k ] = $v;
				}
				break;
				
			case 'child':
				for ($i=0;$i<count($parentRows);$i++)
				{
					$keyValue = $parentRows[$i][$parentKey];
					 
					$parentRows[$i][ $childDef->fieldName ] = (array_key_exists($keyValue, $childRows)) 
																? $childRows[ $keyValue ]
																: array();
				}
				break;
		}
		
		return true;
	}
}

