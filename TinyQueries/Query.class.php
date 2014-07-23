<?php
/**
 * TinyQueries - Framework for merging and nesting relational data
 *
 * @author      Wouter Diesveld <wouter@tinyqueries.com>
 * @copyright   2012 - 2014 Diesveld Query Technology
 * @link        http://www.tinyqueries.com
 * @version     1.1
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
	// Query types
	const PLAIN	= 'plain';
	const TREE	= 'tree';
	const MERGE	= 'merge';
	const CHAIN	= 'chain';
	
	public $id;
	public $compiled;
	public $children;
	public $params;
	
	private $db;
	private $loadChildren;
	private $type;
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
	 * @param {string} $idSpec (optional) 
	 *		ID of the query, or a list of ID's like "a,b,c" (a merge), 
	 *		or a nested structure of ID's like "a(b(c),d)" 
	 *		or a chained structure like "a:b:c"
	 *		or a combination like "a,b(c):d"
	 *		If you supply this parameter, the meta data of the query and all subqueries is loaded from the JSON files
	 */
	public function __construct($db, $idSpec = null)
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
		$this->type			= self::PLAIN;

		$this->output->key 		= null;
		$this->output->group	= false;
		$this->output->rows 	= "all";
		$this->output->columns 	= "all";
		$this->output->nested 	= true;
		$this->output->fields 	= new \StdClass();
		
		// Parse $idSpec
		$this->parseList($idSpec);
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
	 * Adds a parameter binding to the query
	 *
	 * @param {string} $paramName
	 * @param {string} $fieldName If null, then $this->keys->$paramName is used as fieldname
	 */
	public function bind($paramName, $fieldName = null)
	{
		// Compiled queries cannot be modified, so quit
		if ($this->compiled)
			return $this;
		
		// For chain & merge do recursive call on children
		if (in_array($this->type, array(self::MERGE, self::CHAIN)))
		{
			foreach ($this->children as $child)
				$child->bind($paramName, $fieldName);
				
			return $this;
		}
			
		if (property_exists($this->params, $paramName))
			throw new \Exception("Query::bind - parameter '" . $paramName . "' already present in query '" . $this->id . "'");
			
		if (is_null($fieldName) && !property_exists($this->keys, $paramName))
			throw new \Exception("Query::bind - key '" . $paramName . "' not found in query '" . $this->id . "'");
		
		if (is_null($fieldName))
			$fieldName = $this->keys->$paramName;
		
		$param = new \StdClass();
		$param->doc		= "Auto generated parameter";
		$param->type 	= array("string", "array");
		$param->expose 	= "public";
		
		$this->params->$paramName = $param;
		
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

		// Do merge 
		if ($this->type == self::MERGE)
			return $this->merge();

		// Do chaining 
		if ($this->type == self::CHAIN)
			return $this->chain();

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
	 * @param {string} $key (optional) Key field which can be used to group the output
	 */
	public function select($paramValues = null, $key = null)
	{
		// If no key is supplied take default from JSON spec
		if (is_null($key))
			$key = $this->output->key;
		
		$data = $this->execute($paramValues);
		
		$this->bindChildren( $data, $key );
		
		// Handle $key and output->group
		if ($this->output->columns == 'all' && $this->output->rows == 'all')
		{
			if ($key && $this->output->group)
				return Arrays::groupBy($data, $key, true);
			
			if ($key)
				return Arrays::toAssoc($data, $key);
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
	 * Basic 'explain' function which shows the query-tree
	 *
	 */
	public function explain($depth = 0)
	{
		$expl = '';
	
		for ($i=0;$i<$depth;$i++)
			$expl .= "  ";
			
		$expl .= "- ";
		
		if ($this->id)
			$expl .= $this->id . " ";
			
		$expl .= "[" . $this->type . "]\n";
		
		foreach ($this->children as $child)
			$expl .= $child->explain($depth+1);
			
		return $expl;
	}
	
	/**
	 * Updates meta info for this query 
	 * (Now only keys & parameters are updated; should be extended with other fields like output-fields etc)
	 */
	private function update()
	{
		// Only applies for queries with children
		if (!$this->children || count($this->children)==0)
			return;
		
		// Call update recursively
		foreach ($this->children as $child)
			$child->update();
			
		// Code below only applies to merge & chain queries
		if (!in_array($this->type, array(self::MERGE, self::CHAIN)))
			return;
			
		// Determine the merge/chain key
		$matchingKey = $this->match( $this->children );
			
		// Copy key from first child
		$this->keys->$matchingKey = ($matchingKey)
			? $this->children[ 0 ]->keys->$matchingKey
			: new \StdClass();
		
		// Copy parameters from children
		$this->params = new \StdClass();
		foreach ($this->children as $child)
			foreach ($child->params as $name => $spec)
				$this->params->$name = $spec;
	}
	
	/**
	 * Connects a query to this query
	 *
	 * @param {string} $idSpec
	 *
	 * @return {Query}
	 */
	private function link($idSpec)
	{
		$child = new Query($this->db, $idSpec);

		$this->children[] = $child;

		// Only tree queries need further processing, so in this case we are ready
		if ($this->type != self::TREE)
			return $this;
		
		// If parent is compiled we are ready (child specs are set by compiler)
		if ($this->compiled)
			return $this;
		
		if ($this->compiled != $child->compiled)
			throw new \Exception("Query::link - parent & child queries should both be compiled or not compiled");
		
		// Find the matching key between parent & child
		$matchKey = $this->match( $child );

		if (!$matchKey)
			throw new \Exception("Query::link - cannot link query; there is no unique matching key for '" . $this->id . "' and '" . $child->id . "'");

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
		$childDef->type  		= 'child';
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
	private function parseList($idList = null, $prefix = null, $alwaysCreateChildren = false)
	{
		$list = $this->split($idList, ',');

		if ($prefix)
			foreach ($list as $i => $v)
				$list[$i] = $prefix . "." . $list[$i];
		
		// If there is only one element, parse it as a double-dotted expression a:b:c
		if (!$alwaysCreateChildren && count($list) == 1)
			return $this->parseChain( $list[0] );

		// Otherwise create a child query for each element
		foreach ($list as $element)
			$this->link( $element );
			
		$this->update();
	}
	
	/**
	 * Parses a chained ID-structure like a:b:c
	 *
	 * @param {string} $idChain
	 */
	private function parseChain($idChain, $prefix = null)
	{
		if ($prefix)
			$idChain = $prefix . "." . $idChain;
			
		$list = $this->split($idChain, ':');
		
		// If there is only 1 element, parse it as a tree
		if (count($list) == 1)
			return $this->parseTree( $list[0] );
		
		// Set type
		$this->type = self::CHAIN;	
		
		// Take ID of first element as the root
		list( $root, $dummy ) = $this->parseID( $list[0] );

		// Add root to all elements after the first
		for ($i=1; $i<count($list); $i++)
			$list[ $i ] = $root . "." . $list[ $i ];
		
		// Create a child query for each element
		foreach ($list as $element)
			$this->link( $element );
			
		$this->update();
		
		// Code below is only for non compiled queries
		if ($this->compiled)
			return;

		// Get the chain key
		list($key) = array_keys( get_object_vars( $this->keys ) );

		// Add key-binding for all children except the last
		for ($i=0; $i<count($this->children)-1; $i++)
			$this->children[$i]->bind($key);
	}
	
	/**
	 * Parses a ID tree structure and sets the ID of this query and creates child queries
	 *
	 * @param {string} $idTree
	 */
	private function parseTree($idTree, $prefix = null)
	{
		list( $id, $children ) = $this->parseID( $idTree );

		if (!$id && !$children)
			throw new \Exception("Query: Cannot parse idTree " . $idTree);
			
		// Add prefix to id
		if ($prefix && $id)
			$id = $prefix . "." . $id;
			
		// Check if id contains only a prefix, like "a." or "a.b."
		if ($id && substr($id, -1) == '.')
		{
			$prefix = substr($id, 0, -1);
			$id = null;
		}
		
		// Set query ID		
		$this->id = $id;
		
		// Load the query meta info
		$this->load();
		
		// If no children are specified, load all children by default
		if (is_null($children))
		{
			$this->loadChildren = true;
			return;
		}
		
		// Set type
		$this->type = ($this->id)
			? self::TREE
			: self::MERGE;

		// Only pass the prefix recursively if type==merge
		$prefixChildren = ($this->type == self::MERGE) 
			? $prefix
			: null;
		
		$this->parseList( $children, $prefixChildren, true );
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
		
		if (!preg_match('/^([\w\-\.]*)\s*\((.*)\)$/', $idTree, $match))
			return array( null, null );
			
		$id = ($match[1])
			? $match[1]
			: null;
			
		return array( $id, trim( $match[2] ) );
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
	 * Chains the output of the child queries
	 *
	 */
	private function chain()
	{
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
		$rows 		= $this->children[ $n-1 ]->select( $params );
		$keyBase 	= $this->children[ $n-1 ]->keys->$key;

		// Attach all other queries
		for ($i=$n-2; $i>=0; $i--)
		{
			// If the number of rows is 0 we can stop
			if (count($rows) == 0)
				return $rows;
			
			// Collect the key field values and set them as parameter value for the next query
			$params[ $key ] = array();
			foreach ($rows as $row)
				$params[ $key ][] = $row[ $keyBase ];
		
			$query 		= $this->children[ $i ];
			$keyField 	= $query->keys->$key;
			$rows1 		= $query->select($params, $keyField);
			
			$j=0;
			
			// Do an intersection of $rows & $rows1
			while ($j<count($rows))
			{
				$keyValue = $rows[$j][$keyBase];
					
				if (array_key_exists($keyValue, $rows1))
				{
					// Attach the fields of $rows1 to $rows
					foreach ($rows1[ $keyValue ] as $name => $value)
						$rows[$j][$name] = $value;
					$j++;
				}
				else
				{
					// Remove elements which are not in the latest query result (rows1)
					array_splice( $rows, $j, 1 );
				}
			}
		}
		
		return $rows;
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
				$query->select[] = $query->keys->$key;

				// TODO: geen key( . ) gebruiken , want dan verdander je de query settings
			$rows = $query->key( $query->keys->$key )->params( $this->paramValues )->select();
				
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
	 * @param {string} $key Key which is passed to select( . )
	 */
	private function bindChildren(&$rows, $key)
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

		$registeredOutputFields = is_array($this->output->fields)
			? $this->output->fields
			: array_keys( get_object_vars($this->output->fields) );

		// Check which columns can be removed	
		foreach (array_keys($rows[0]) as $field)
			if ($field != $key && $field != $this->output->key && preg_match("/^\_\_/", $field) && !in_array( $field, $registeredOutputFields ))
				$columnsToRemove[] = $field;
				
		// Clean up columns
		foreach (array_keys($rows[0]) as $field)
			if (in_array($field, $columnsToRemove))
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
		for ($i=0;$i<count($parentRows);$i++)
		{
			$keyValue = $parentRows[$i][$parentKey];
					 
			$parentRows[$i][ $childDef->fieldName ] = (array_key_exists($keyValue, $childRows)) 
				? $childRows[ $keyValue ]
				: array();
		}
		
		return true;
	}
}

