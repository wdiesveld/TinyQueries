<?php
/**
 * TinyQueries - Framework for merging and nesting relational data
 *
 * @author      Wouter Diesveld <wouter@tinyqueries.com>
 * @copyright   2012 - 2014 Diesveld Query Technology
 * @link        http://www.tinyqueries.com
 * @version     2.0a
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
	 * @param {QueryDB} $db Handle to database
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
			// Add the key to the select fields
			if (get_class($query) == "TinyQueries\\QueryJSON")
				$query->addSelect($query->keys->$key);

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
	}
}

