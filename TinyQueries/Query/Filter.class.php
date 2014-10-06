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
 * Filter
 *
 * This class represents a sequence of filter queries
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class QueryFilter extends Query
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

		$this->linkList( $terms, true );
		
		// Get the link key
		list($key) = array_keys( get_object_vars( $this->keys ) );

		// Add key-binding for all children except the last
		for ($i=0; $i<count($this->children)-1; $i++)
			$this->children[$i]->bind($key);
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

