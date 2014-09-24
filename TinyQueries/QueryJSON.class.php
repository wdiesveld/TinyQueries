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

require_once('QuerySQL.class.php');

/**
 * QueryJSON
 *
 * This class represents a query defined by JSON
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class QueryJSON extends QuerySQL
{
	private $select;
	private $from;
	private $where;
	private $groupBy;
	private $having;

	/**
	 * Constructor
	 *
	 * @param {QueryDB} $db Handle to database
	 * @param {string} $id (optional) ID of the query
	 */
	public function __construct($db, $id = null)
	{
		$this->select	= array();
		$this->where	= array();
		$this->groupBy	= array();
		$this->having	= array();

		parent::__construct($db, $id);
	}
	
	/**
	 * Adds a field to the select array
	 *
	 * @param {string} $field
	 * @param {string} $alias
	 */
	public function addSelect($field, $alias = null)
	{
		if ($alias)
			$field .= " as '" . $alias . "'";
			
		$this->select[] = $field;
	}
	
	/**
	 * Adds a parameter binding to the query
	 *
	 * @param {string} $paramName
	 * @param {string} $fieldName If null, then $this->keys->$paramName is used as fieldname
	 */
	public function bind($paramName, $fieldName = null)
	{
		parent::bind($paramName, $fieldName);
		
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
	 * Imports (a part of) a query definition
	 *
	 * @param {object} $query
	 */
	public function import($query)
	{
		parent::import($query);
		
		$this->select 	= Arrays::toArray( property_exists($query, 'select') 	? $query->select 	: null );
		$this->from 	= property_exists( $query, 'from') ? $query->from : null;
		$this->where 	= Arrays::toArray( property_exists($query, 'where') 	? $query->where 	: null );
		$this->groupBy 	= Arrays::toArray( property_exists($query, 'groupBy') 	? $query->groupBy 	: null );
		$this->orderBy 	= Arrays::toArray( property_exists($query, 'orderBy')	? $query->orderBy 	: null );
		$this->having 	= Arrays::toArray( property_exists($query, 'having') 	? $query->having 	: null );
		$this->root		= property_exists( $query, 'root' ) ? $query->root : null;
		
		// Set root equal to from, if from does not contain a join (e.g. it is a plain table name)
		if (!$this->root && preg_match( "/^[\w\.\-\_]+$/", $this->from))
			$this->root = $this->from;
	}
			
	/**
	 * Loads the JSON and/or SQL files which correspond to this query
	 *
	 */
	public function load()
	{
		if (!$this->id)
			return $this;
	
		$this->import( $this->db->queries->json( $this->id ) );
		
		return $this;
	}

	/**
	 * Compiles this query to SQL
	 *
	 * @param {array} $params query parameters
	 */
	public function getSql($params = array())
	{
		$this->setSql( $this->compile() );
		
		return parent::getSql( $params );
	}
	
	// dummy tijdelijk totdat _interface is opgeruimd
	public function getInterface()
	{
		$this->setInterface($this->params, $this->output);
		
		return $this->_interface;
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
				
			$sql .= "where\n" . implode(" and\n", $where) . "\n";
		}
		
		if (count($this->groupBy) > 0)
			$sql .= "group by\n" . implode(",\n", $this->groupBy) . "\n";
		
		if (count($this->orderBy) > 0)
			$sql .= "order by\n" . implode(",\n", $this->orderBy) . "\n";
			
		if ($this->orderType)
			$sql .= $this->orderType;
		
		return $sql;
	}
}
