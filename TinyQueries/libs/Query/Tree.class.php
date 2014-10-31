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
 * Tree
 *
 * This class represents a tree of queries
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class QueryTree extends Query
{
	private $base; // Base query (actually the 'root' of the tree)
	
	/**
	 * Constructor
	 *
	 * @param {QueryDB} $db Handle to database
	 * @param {string} $id ID of parent query - $id should refer to an atomic query
	 * @param {string} $terms Query terms corresponding to the child queries of the tree
	 */
	public function __construct($db, $id, $terms = array())
	{
		parent::__construct($db);
		
		// Create the root query
		$this->base = $this->db->query($id);
		
		// Ensure root query fields are copied to this query
		$this->update();
		
		// Check for child aliases
		$aliases = array();
		
		foreach ($this->output->fields as $field => $spec )
			if (property_exists($spec, 'child') && $spec->child != $field)
				$aliases[ $field ] = $spec->child;
		
		$terms = Term::convertAliases( $terms, $aliases );
		
		// Create a child query for each term
		$this->linkList( $terms, false );
	}
	
	/**
	 * Returns the name of the query
	 *
	 */
	public function name()
	{
		return $this->base->name();
	}
	
	/**
	 * Adds a parameter binding to the query
	 *
	 * @param {string} $paramName
	 * @param {string} $fieldName 
	 */
	public function bind($paramName, $fieldName = null)
	{
		// Only bind to root
		$this->base->bind($paramName, $fieldName);
				
		return $this;
	}	
	
	/**
	 * Executes the query
	 *
	 * @param {assoc} $paramValues
	 */
	public function execute($paramValues = null)
	{
		parent::execute($paramValues);

		$data = $this->base->execute( $this->paramValues );
		
		$this->bindChildren($data);
		
		return $data;
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
		$child = parent::link($term);

		// If parent is compiled we are ready (child specs are set by compiler)
		if (get_class($this->base) == "TinyQueries\\QuerySQL")
			return $child;
		
		// Find the matching key between parent & child
		$queries = array( $this->base, $child );
		$matchKey = $this->match( $queries );

		if (!$matchKey)
			throw new \Exception("Tree::link - cannot link query; there is no unique matching key for '" . $this->base->name() . "' and '" . $child->name() . "'");

		$parentKey 		= $this->keys->$matchKey;
		$childKey		= $child->keys->$matchKey;
		$parentKeyAlias = "__parentKey-" . count($this->children);
		$childKeyAlias 	= $matchKey;

		// Add parentKey to select
		$this->base->addSelect($parentKey, $parentKeyAlias);
				
		// Create child definition which is compatible with the one used for compiled queries
		$childDef = new \StdClass();
		$childDef->type  		= 'child';
		$childDef->child 		= $child->name();
		$childDef->parentKey 	= $parentKeyAlias;
		$childDef->childKey		= $childKeyAlias;
		$childDef->params 		= new \StdClass();
		$childDef->params->$matchKey = $parentKeyAlias;
		
		$this->output->fields->{$child->name()} = $childDef;
		
		// Modify child such that it can be linked to the parent
		$child->bind( $matchKey, $childKey );
		
		return $child;
	}

	/**
	 * Updates meta info for this query 
	 * (Now only keys & parameters are updated; should be extended with other fields like output-fields etc)
	 */
	protected function update()
	{
		// Update base query
		$this->base->update();
		
		// Copy fields from parent
		$fields = array('root', 'params', 'output', 'keys');
		foreach ($fields as $field)
			$this->$field = is_object($this->base->$field)
				? clone $this->base->$field
				: $this->base->$field;
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
		
		if (!$rows || count($rows) == 0)
			return;
	
		if ($this->output->rows == 'first')
			$rows = array( $rows );
			
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
			$this->bindChild($rows, $child);
			
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

		// Check if the child is in the list of children which are set by the query term
		foreach ($this->children as $c)
			if ($c->name() == $childDef->child)
			{
				$child = $c;
				break;
			}
				
		// If no child is found we are ready
		if (!$child)
			return;
			
		$params 	= $this->paramValues; // Take root parameters as default params for child
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

		// Execute child query and group results; cleanUp can also be done at this point
		$childRows = $child->group()->select( $params, $childKey, true );
		
		// Combine child rows with parent rows
		for ($i=0;$i<count($parentRows);$i++)
		{
			$keyValue = $parentRows[$i][$parentKey];
					 
			$parentRows[$i][ $childDef->fieldName ] = (array_key_exists($keyValue, $childRows)) 
				? $childRows[ $keyValue ]
				: array();
		}
	}
}
