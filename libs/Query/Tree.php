<?php
namespace TinyQueries;

require_once('Query.php');

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
     * @param DB $db Handle to database
     * @param string $id ID of parent query - $id should refer to an atomic query
     * @param string $terms Query terms corresponding to the child queries of the tree
     */
    public function __construct(&$db, $id, $terms = array())
    {
        parent::__construct($db);

        // Create the root query
        $this->base = $this->db->query($id);

        // Ensure root query fields are copied to this query
        $this->update();

        // Check for child aliases
        $aliases = array();

        foreach ($this->output->fields as $field => $spec) {
            if (property_exists($spec, 'child') && $spec->child != $field) {
                $aliases[ $field ] = $spec->child;
            }
        }

        $terms = Term::convertAliases($terms, $aliases);

        // Add root or id as filter to each child
        $linkID = $this->prefix();

        if (!$linkID) {
            throw new \Exception("Query '$id' has no prefix");
        }

        for ($i=0;$i<count($terms);$i++) {
            $terms[$i] = "(" . $terms[$i] . "):" . $linkID;
        }

        // Create a child query for each term
        $this->linkList($terms, false);
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
     * @param string $paramName
     * @param string $fieldName
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
     * @param assoc $paramValues
     */
    public function execute($paramValues = null)
    {
        parent::execute($paramValues);

        $data = $this->base->select($this->paramValues);

        $this->bindChildren($data);

        return $data;
    }

    /**
     * Connects a query to this query
     *
     * @param string $term
     *
     * @return Query
     */
    protected function link($term)
    {
        $child = parent::link($term);

        // If parent is compiled we are ready (child specs are set by compiler)
        if (get_class($this->base) == "TinyQueries\\QuerySQL") {
            return $child;
        }

        // Find the matching key between parent & child
        $queries = array( $this->base, $child );
        $matchKey = $this->match($queries);

        if (!$matchKey) {
            throw new \Exception("Tree::link - cannot link query; there is no unique matching key for '" . $this->base->name() . "' and '" . $child->name() . "'");
        }

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
        $child->bind($matchKey, $childKey);

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

        // Copy fields from parent (base)
        $fields = array('root', 'params', 'keys', 'defaultParam', 'operation');
        foreach ($fields as $field) {
            $this->$field = is_object($this->base->$field)
                ? clone $this->base->$field
                : $this->base->$field;
        }

        // Output fields need special care - not all fields should be copied
        $outputToCopy = array('rows', 'columns', 'fields');
        foreach ($outputToCopy as $field) {
            $this->output->$field = is_object($this->base->output->$field)
                ? clone $this->base->output->$field
                : $this->base->output->$field;
        }
    }

    /**
     * Binds the child queries to the query output
     *
     * @param array $rows The rows/row as returned by QuerySQL
     */
    private function bindChildren(&$rows)
    {
        // In this case child binding does not apply
        if ($this->output->columns == 'first') {
            return;
        }

        if (!$rows || count($rows) == 0) {
            return;
        }

        if ($this->output->rows == 'first') {
            $rows = array( $rows );
        }

        foreach ($this->children as $child) {
            $this->bindChild($rows, $child);
        }

        if ($this->output->rows == 'first') {
            $rows = $rows[ 0 ];
        }
    }

    /**
     * Executes the child query and ties the result to the output of the parent query
     *
     * @param array $parentRows Query output of the parent query
     * @param object $child
     */
    private function bindChild(&$parentRows, &$child)
    {
        $generalErrorMessage = "Cannot nest queries " . $this->name() . " and " . $child->name() . " - ";

        // This error should never occur, since the parser constructs the two childs automatically
        if (!$child->children || count($child->children) != 2) {
            throw new \Exception($generalErrorMessage . "child does not have 2 children");
        }

        $paramID = $child->defaultParam;

        // Fall back in case there is no default param (should not occur anymore)
        if (!$paramID) {
            // Get parameters of second (last) child of $child.
            // Suppose you have "a(b)". This corresponds to parent = "a" and child = "b:a"
            // "b:a" has two childs: "b" and "b.a"
            // We should get the param of the link-query "b.a" which is the second child of $child
            $paramIDs = array_keys(get_object_vars($child->children[1]->params));

            // There should be exactly 1 parameter
            if (count($paramIDs) != 1) {
                throw new \Exception($generalErrorMessage . "link-query " . $child->children[1]->name() . " should have exactly one parameter");
            }

            $paramID = $paramIDs[0];
        }

        // Get the parent key which should be matched with the childs parameter
        $keyIDs = array_keys(get_object_vars($this->keys));

        if (count($keyIDs) != 1) {
            throw new \Exception($generalErrorMessage . "parent should have exactly one key");
        }

        $parentKey 	= $this->keys->{$keyIDs[0]};

        if (!is_array($parentRows)) {
            throw new \Exception($generalErrorMessage . "parentRows should be an array of associative arrays");
        }

        if (count($parentRows)>0 && !is_array($parentRows[0])) {
            throw new \Exception($generalErrorMessage . "parentRows should be an array of associative arrays");
        }

        if (count($parentRows)>0 && !array_key_exists($parentKey, $parentRows[0])) {
            throw new \Exception($generalErrorMessage . "parentRows should consist of associative arrays containing the field '".$parentKey."'");
        }

        // Take root parameters as default params for child
        $params	= $this->paramValues;

        // Select the child param values from the parent query output
        $values = array();

        foreach ($parentRows as $row) {
            if (!in_array($row[ $parentKey ], $values)) {
                $values[] = $row[ $parentKey ];
            }
        }

        $params[ $paramID ] = $values;

        // Execute child query and group results; cleanUp can also be done at this point
        $childRows = $child->group()->select($params, $paramID, true);

        $childFieldName = $child->prefix();

        // Combine child rows with parent rows
        for ($i=0;$i<count($parentRows);$i++) {
            $keyValue = $parentRows[$i][$parentKey];

            $parentRows[$i][ $childFieldName ] = (array_key_exists($keyValue, $childRows))
                ? $childRows[ $keyValue ]
                : array();
        }
    }
}
