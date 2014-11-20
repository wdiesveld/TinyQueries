<?php
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
		
		// Add root or id as filter to each child
		$linkID = ($this->base->root)
			? $this->base->root
			: $id;
			
		for ($i=0;$i<count($terms);$i++)
			$terms[$i] = "(" . $terms[$i] . "):" . $linkID; 
		
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
			
		foreach ($this->children as $child)
			$this->bindChild($rows, $child);
		
		if ($this->output->rows == 'first')
			$rows = $rows[ 0 ];
	}

	/**
	 * Executes the child query and ties the result to the output of the parent query
	 *
	 * @param {array} $parentRows Query output of the parent query
	 * @param {object} $child
	 */
	private function bindChild(&$parentRows, &$child)
	{
		$commonKey = $this->match($child);
		
		if (!$commonKey)
			throw new \Exception("Cannot nest queries " . $this->name() . " and " . $child->name() . " - no common key found");
		
		$childKey 	= $child->keys->$commonKey;
		$parentKey 	= $this->keys->$commonKey;
		
		if (!is_array($parentRows))
			throw new \Exception('bindChild: illegal function call - parentRows should be an array of associative arrays');

		if (count($parentRows)>0 && !is_array($parentRows[0]))
			throw new \Exception('bindChild: illegal function call - parentRows should be an array of associative arrays');
		
		if (count($parentRows)>0 && !array_key_exists($parentKey, $parentRows[0]))
			throw new \Exception('bindChild: illegal function call - parentRows should consist of associative arrays containing the field '.$parentKey);
			
		if (!property_exists($child->params, $commonKey))
			throw new \Exception('bindChild: child ' . $child->name() . ' does not have a parameter corresponding to the key ' . $commonKey);
		
		// Take root parameters as default params for child
		$params	= $this->paramValues; 
		
		// Select the child param values from the parent query output
		$values = array();
			
		foreach ($parentRows as $row)
			if (!in_array( $row[ $parentKey ], $values))
				$values[] = $row[ $parentKey ];
				
		$params[ $commonKey ] = $values;

		// Execute child query and group results; cleanUp can also be done at this point
		$childRows = $child->group()->select( $params, $childKey, true );
		
		$childFieldName = $child->name();
		
		// Combine child rows with parent rows
		for ($i=0;$i<count($parentRows);$i++)
		{
			$keyValue = $parentRows[$i][$parentKey];
					 
			$parentRows[$i][ $childFieldName ] = (array_key_exists($keyValue, $childRows)) 
				? $childRows[ $keyValue ]
				: array();
		}
		
	}
}
