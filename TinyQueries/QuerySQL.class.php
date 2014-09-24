<?php
/**
 * TinyQueries - Framework for merging and nesting relational data
 *
 * @author      Wouter Diesveld <wouter@tinyqueries.com>
 * @copyright   2012 - 2014 Diesveld Query Technology
 * @link        http://www.tinyqueries.com
 * @version     1.5a
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

require_once('Query.class.php');
require_once('Arrays.class.php');

/**
 * QuerySQL
 *
 * This class represents one SQL query
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class QuerySQL extends Query
{
	public $id;
	
	private $nested;
	private $sql;
	protected $_interface;

	/**
	 * Constructor
	 *
	 * @param {QueryDB} $db Handle to database
	 * @param {string} $id (optional) ID of the query
	 */
	public function __construct($db, $id = null)
	{
		parent::__construct($db);
		
		$this->id = $id;
		
		// Take default setting from db
		$this->nested = $this->db->nested;
		
		$this->load();
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
		if ($this->_interface)
			return $this;
		
		$this->import( $this->getInterface() );
		
		return $this;
	}
	
	/**
	 * Returns the name of the query
	 *
	 */
	public function name()
	{
		return $this->id;
	}
	
	/**
	 * Sets the nested flag to indicate that the output of the query should be nested.
	 * This means that for example sql output fields named 'user.name' and 'user.email' will be converted to 
	 * a nested structure 'user' having fields 'name' and 'email' 
	 *
	 * @param {boolean} $nested
	 */
	public function nested( $nested = true )
	{
		$this->nested = $nested;
		
		return $this;
	}
	
	/**
	 * Executes the query
	 *
	 * @param {assoc} $queryParams
	 */
	public function execute($paramValues = null)
	{
		parent::execute($paramValues);
		
		$this->getInterface();

		try
		{
			// If the query has no output just execute it
			if (!$this->output)
			{
				list($sql, $pdoParams) = $this->getSql( $this->paramValues );
				return $this->db->execute( $sql, $pdoParams );
			}
			
			$rows = (string) $this->_interface->output->rows;
			$cols = (string) $this->_interface->output->columns;
			
			// Determine which select function should be used
			if ($rows == "first" && $cols == "first")	return $this->selectFirst( $this->paramValues );
			if ($rows == "first" && $cols == "all")		return $this->selectAssoc( $this->paramValues );
			if ($rows == "all" 	 && $cols == "first")	return $this->selectAllFirst( $this->paramValues );
			if ($rows == "all" 	 && $cols == "all")		return $this->selectAllAssoc( $this->paramValues );
			
			// Default:
			return $this->selectAllAssoc( $this->paramValues );
		}
		catch (\Exception $e)
		{
			throw new \Exception("SQL error for query " . $this->id . ": " . $e->getMessage());
		}
	}
	
	/**
	 *
	 *
	 * @param {assoc} $queryParams
	 */
	public function selectAllAssoc($queryParams = null)
	{
		list($sql, $pdoParams) = $this->getSql( $queryParams );
		
		$rows = $this->db->selectAllAssoc( $sql, $pdoParams );
		
		$this->postProcess( $rows );
			
		return $rows;
	}

	/**
	 *
	 *
	 * @param {assoc} $queryParams
	 */
	public function selectAssoc($queryParams = null)
	{
		list($sql, $pdoParams) = $this->getSql( $queryParams );
		
		$rows = array( $this->db->selectAssoc( $sql, $pdoParams ) );
		
		$this->postProcess( $rows );
		
		return $rows[0];
	}
	
	/**
	 *
	 *
	 * @param {assoc} $queryParams
	 */
	public function selectAllFirst($queryParams = null)
	{
		list($sql, $pdoParams ) = $this->getSql( $queryParams );
		
		$rows = $this->db->selectAllFirst( $sql, $pdoParams );
		
		$this->postProcess( $rows );
		
		return $rows;
	}
	
	/**
	 *
	 *
	 * @param {assoc} $queryParams
	 */
	public function selectFirst($queryParams = null)
	{
		list($sql, $pdoParams ) = $this->getSql( $queryParams );
		
		$rows = array( array( $this->db->selectFirst( $sql, $pdoParams ) ) );
		
		$this->postProcess( $rows );
		
		return $rows[0][0];
	}
	
	/**
	 * Does all post processing for the output of a query
	 *
	 * @param {array} $rows The rows as returned by the database
	 */
	private function postProcess(&$rows)
	{
		// If array is empty we are ready
		if (count($rows)==0)
			return;
			
		// If array consists of numerical arrays, we are ready (child queries only make sense for associative arrays)
		if (!Arrays::isAssoc($rows[0]))
			return;
		
		// Apply nesting
		$this->nestDottedFields($rows);
		
		// Apply typing
		$this->applyTyping($rows);
					
		// Do custom callback
		if ($callback = $this->db->queries->callback($this->id))
			$callback( $rows );
	}
	
	/**
	 * Sets the type of the output fields, according to the type specification in the json file
	 *
	 * @param {array} $rows
	 */
	private function applyTyping(&$rows)
	{
		foreach ($this->_interface->output->fields as $name => $type)
			if ($type != 'string')
				for ($i=0;$i<count($rows);$i++)
					$this->setType($rows[$i][$name], $type);
	}
	
	/**
	 * Do type casting for the given field
	 *
	 * @param {string} $field
	 * @param {string} $type
	 */
	private function setType(&$field, $type)
	{
		if (is_null($field))
			return;
			
		// Type can either be a string or an object
		$typeStr = (is_object($type) && property_exists($type, 'type'))
			? $type->type
			: $type;
		
		switch ($typeStr)
		{
			// Basic type casting
			case 'int': 	$field = (int) $field; break;
			case 'float': 	$field = (float) $field; break;
			case 'number': 	$field = (float) $field; break;
			case 'string':	$field = (string) $field; break;
			
			// Recursive call for object properties
			case 'object':
				foreach ($type->fields as $name => $subtype)
					$this->setType($field[$name], $subtype);
				break;
				
			// Child queries are handled by Query::tieParentChild
			case 'child':
				break;
			
			// JSON should only be decoded
			case 'json': 	
				$field = json_decode( $field ); 
				self::fixGroupConcatArray( $field );
				break;
				
			// Unknown type, do nothing
			default:
				break;
		}
	}
	
	/**
	 * Converts dot-notation fields to a nested structure.
	 * 
	 * @param {array} $rows
	 */
	private function nestDottedFields(&$rows)
	{
		// If nesting is not set, we are ready
		if (!$this->nested)
			return;
		
		// If there are no rows we are ready		
		if (count($rows) == 0)
			return;
			
		$keys 		= array_keys( $rows[0] ); 
		$mapping	= array();
		
		// Split dotted fieldnames
		foreach ($keys as $key)
		{
			$map = explode('.', $key);
			$mapping[ $key ] = (count($map)>1)
									? $map
									: null;
		}
		
		// Apply nesting for each row
		for ($i=0; $i<count($rows); $i++)
			foreach ($keys as $key)
			if ($mapping[$key])
			{
				$this->nestField( $rows[$i], $mapping[$key], $rows[$i][$key] );
				unset( $rows[$i][$key] );
			}
		
		// Check for null objects, which are caused by 'empty' left joins
		$nestedFields = array();
		foreach ($rows[0] as $key => $value)
			if (Arrays::isAssoc($value) && count($value)>0)
				$nestedFields[] = $key;
		
		for ($i=0; $i<count($rows); $i++)
			foreach ($nestedFields as $field)
				Arrays::reduceNulls( $rows[$i][$field] );
	}
	
	/**
	 * Helper function for nestDottedFields
	 *
	 * @param {assoc} $row
	 * @param {array} $fieldComponents
	 * @param {string} $value
	 */
	private function nestField(&$row, $fieldComponents, $value)
	{
		if (!$fieldComponents || count($fieldComponents)==0)
			return;
			
		$head = array_shift( $fieldComponents );
		
		// If last field component
		if (count($fieldComponents) == 0)
		{
			$row[ $head ] = $value;
			return;
		}
		
		// Initialize array if not yet present
		if (!array_key_exists( $head, $row ))
			$row[ $head ] = array();
			
		// Recursive call
		$this->nestField($row[ $head ], $fieldComponents, $value);
	}
	
	/**
	 * Loads the interface if not yet loaded
	 *
	 */
	public function getInterface()
	{
		if (!$this->id)
			throw new \Exception('getInterface: Query ID not known');

		if ($this->_interface)
			return $this->_interface;

		$this->_interface = $this->db->queries->getInterface( $this->id );
		
		return $this->_interface;
	}
	
	/**
	 * Sets the interface for this query
	 *
	 */
	public function setInterface($params, $output)
	{
		if (!$this->_interface)
			$this->_interface = new \StdClass();
			
		$this->_interface->params = $params;
		$this->_interface->output = $output;
	}
	
	/**
	 * Sets the SQL code for this query
	 */
	public function setSql($sql)
	{
		$this->sql = $sql;
	}
	
	/**
	 * Reads query-file and fills in the IN-parameters - 
	 * other params will be converted to PDO params which can be passed to the select methods (which is faster)
	 *
	 * @param {array} $params query parameters
	 */
	public function getSql($params = array())
	{
	// TODO: parameter gedeelte in andere functie
	// sql($sql) get/set van maken
	
		$pdoParams = array();
		
		if (is_null($params))
			$params = array();
		
		if (!$this->id)
			throw new \Exception('sql: Query ID not known');

		// Read interface if it not yet known
		if (!$this->_interface)
			$this->getInterface();
		
		// Read compiled SQL if there is no SQL yet
		if (!$this->sql)
			$this->sql = $this->db->queries->sql( $this->id );
			
		$sqlParsed = $this->sql;	
		
		// Set defaults (only if not present in given param list)
		foreach ($this->_interface->params as $p => $props)
			if (!array_key_exists($p, $params))
				if (property_exists($props, 'default'))
					$params[ $p ] = $props->{'default'};
			
		// Add global parameters (only if not present in given param list)
		foreach ($this->db->globals as $p => $val) 
			if (!array_key_exists($p, $params) || is_null($params[$p]))
				$params[ $p ] = $val;
		
		// Special handling for paging parameters
		if (property_exists($this->_interface, 'paging'))
		{
			$page = (array_key_exists('page', $params)) ? $params['page'] : 0;
			unset($params['page']); // unset page param because it is not present in the SQL itself
			$params['__limitStart'] = $page * (int) $this->_interface->paging;
		}

		// Set the parameters
		foreach ($params as $name => $value)
		{ 
			// Convert array to CSV which is suitable for IN
			if (is_array($value))
			{
				$values = array();
				foreach ($value as $v)
					$values[] = $this->db->toSQL( $v, true, true );

				$valueSQL = (count($values)==0)
							? "NULL"
							: implode(",", $values);
				
				// Replace the ":param" string with the value
				$sqlParsed = preg_replace("/\:" . $name . "(\W)/", $valueSQL . "$1", $sqlParsed . " ");
			}
			// Param is a registered parameter
			elseif (property_exists($this->_interface->params, $name))
			{
				switch ($this->_interface->params->$name->type)
				{
					case "int": $pdoType = \PDO::PARAM_INT; break;
					default:	$pdoType = \PDO::PARAM_STR; break;
				}
			
				$pdoParams[ $name ] = array
				(
					'value'	=> $value,
					'type'	=> $pdoType
				);
			}
			// Param is not registered (DEPRECATED - but still needed for global params)
			else
			{
				$valueSQL = $this->db->toSQL( $value, true, true );
				
				// Replace the ":param" string with the value
				$sqlParsed = preg_replace("/\:" . $name . "(\W)/", $valueSQL . "$1", $sqlParsed . " ");
			}
		}

		return array($sqlParsed, $pdoParams);	
	}
	
	/**
	 * Workaround for groupconcat 'bug': when the groupconcat is based on a left join, the resulting array can 
	 * contain 1 (empty) element while you would expect is has 0 elements.
	 * This function checks for this special case, and ensures that $array is empty
	 */
	public static function fixGroupConcatArray(&$array)
	{
		if (!is_array($array))
			return;
			
		if (count($array) != 1)
			return;
			
		Arrays::reduceNulls( $array[0] );
		
		if (is_null($array[0]))
			$array = array();
	}
}
