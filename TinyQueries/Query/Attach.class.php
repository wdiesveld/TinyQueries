<?php
/**
 * TinyQueries - Framework for merging and nesting relational data
 *
 * @author      Wouter Diesveld <wouter@tinyqueries.com>
 * @copyright   2012 - 2014 Diesveld Query Technology
 * @link        http://www.tinyqueries.com
 * @version     1.5.1
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

/**
 * Attach
 *
 * This class represents a sequence of attached queries
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class QueryAttach extends Query
{
	/**
	 * Constructor
	 *
	 * @param {QueryDB} $db Handle to database
	 * @param {string} $terms (optional) 
	 */
	public function __construct($db, $terms = array())
	{
		parent::__construct($db);
		
		$this->linkList($terms, true);
		
		// Get the link key
		list($key) = array_keys( get_object_vars( $this->keys ) );

		// Add key-binding for all children except the first
		for ($i=1; $i<count($this->children); $i++)
			$this->children[$i]->bind($key);
	}

	/**
	 * 'Left joins' the child queries
	 *
	 * @param {assoc} $paramValues
	 */
	public function execute($paramValues = null)
	{
		parent::execute($paramValues);
		
		// Determine the attach key
		$key = ($this->output->key)
			? $this->output->key
			: $this->match( $this->children );
			
		if (!$key)
			throw new \Exception("Cannot attach queries - no common key found");
			
		$n = count($this->children);
		
		if ($n==0)
			return array();
		
		// Take first query as base
		$params		= $this->paramValues;
		$rows 		= $this->children[ 0 ]->select( $params );
		$keyBase 	= $this->children[ 0 ]->keys->$key;

		// If the number of rows is 0 we can stop
		if (count($rows) == 0)
			return $rows;
			
		// Collect the key field values and set them as parameter value for the other queries
		$params[ $key ] = array();
		foreach ($rows as $row)
			$params[ $key ][] = $row[ $keyBase ];

		// Attach all other queries
		for ($i=1; $i<$n; $i++)
		{
			$query 		= $this->children[ $i ];
			$keyField 	= $query->keys->$key;
			$rows1 		= $query->select($params, $keyField);
			
			for ($j=0;$j<count($rows);$j++)
			{
				$keyValue = $rows[$j][$keyBase];
					
				// Attach the fields of $rows1 to $rows
				if (array_key_exists($keyValue, $rows1))
					foreach ($rows1[ $keyValue ] as $name => $value)
						$rows[$j][$name] = $value;
			}
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
		
		// Determine the common key of the children
		$this->keys = new \StdClass();

		// Copy key from first child
		if ($matchingKey = $this->match( $this->children ))
			$this->keys->$matchingKey = $this->children[ 0 ]->keys->$matchingKey;
		
		// Copy other fields from first child
		$this->root = $this->children[ 0 ]->root;
	}
}

