<?php
namespace TinyQueries;

require_once('Query.php');
require_once(dirname(__FILE__) . '/../Arrays.php');

/**
 * SQL
 *
 * This class represents one SQL query
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class QuerySQL extends Query
{
    public $id;

    private $sql;
    protected $_interface;

    /**
     * Constructor
     *
     * @param DB $db Handle to database
     * @param string $id (optional) ID of the query
     */
    public function __construct(&$db, $id = null)
    {
        parent::__construct($db);

        $this->id = $id;

        $this->load();
    }

    /**
     * Loads the JSON and/or SQL files which correspond to this query
     *
     */
    public function load()
    {
        if (!$this->id) {
            return $this;
        }

        // Already loaded
        if ($this->_interface) {
            return $this;
        }

        $this->import($this->getInterface());

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
     * Executes the query
     *
     * @param assoc $queryParams
     */
    public function execute($paramValues = null)
    {
        parent::execute($paramValues);

        $this->getInterface();

        // Check if there is a split defined for a parameter
        $paramSplit = null;
        foreach ($this->params as $paramID => $def) {
            if (property_exists($this->params->$paramID, 'split') && is_array($this->paramValues[ $paramID])) {
                $buffersize = $this->params->$paramID->split;
                $paramSplit = $paramID;
            }
        }

        if (!$paramSplit) {
            return $this->executeHelper($this->paramValues);
        }

        // Split parameter value
        $output 	= array();
        $nValues 	= count($this->paramValues[ $paramSplit ]);

        for ($k=0; $k<$nValues; $k+=$buffersize) {
            // Create parameter value set for the current buffer
            $paramsBuffer = array();
            foreach ($this->paramValues as $key => $value) {
                $paramsBuffer[$key] = ($key==$paramSplit)
                    ? array_slice($value, $k, $buffersize)
                    : $value;
            }

            $output = array_merge($output, $this->executeHelper($paramsBuffer));
        }

        return $output;
    }

    /**
     *
     *
     */
    private function executeHelper($paramValues)
    {
        try {
            // If the query has no output just execute it
            if (!$this->output) {
                list($sql, $pdoParams) = $this->getSql($paramValues);
                return $this->db->execute($sql, $pdoParams, true);
            }

            $rows = (string) $this->_interface->output->rows;
            $cols = (string) $this->_interface->output->columns;

            // Determine which select function should be used
            if ($rows == "first" && $cols == "first") {
                return $this->selectFirst($paramValues);
            }
            if ($rows == "first" && $cols == "all") {
                return $this->selectAssoc($paramValues);
            }
            if ($rows == "all" 	 && $cols == "first") {
                return $this->selectAllFirst($paramValues);
            }
            if ($rows == "all" 	 && $cols == "all") {
                return $this->selectAllAssoc($paramValues);
            }

            // Default:
            return $this->selectAllAssoc($paramValues);
        } catch (\Exception $e) {
            throw new \Exception("SQL error for query " . $this->id . ": " . $e->getMessage());
        }
    }

    /**
     *
     *
     * @param assoc $queryParams
     */
    public function selectAllAssoc($queryParams = null)
    {
        list($sql, $pdoParams) = $this->getSql($queryParams);

        $rows = $this->db->selectAllAssoc($sql, $pdoParams);

        $this->postProcess($rows);

        return $rows;
    }

    /**
     *
     *
     * @param assoc $queryParams
     */
    public function selectAssoc($queryParams = null)
    {
        list($sql, $pdoParams) = $this->getSql($queryParams);

        $rows = array( $this->db->selectAssoc($sql, $pdoParams) );

        $this->postProcess($rows);

        return $rows[0];
    }

    /**
     *
     *
     * @param assoc $queryParams
     */
    public function selectAllFirst($queryParams = null)
    {
        list($sql, $pdoParams) = $this->getSql($queryParams);

        $rows = $this->db->selectAllFirst($sql, $pdoParams);

        $this->postProcess($rows);

        return $rows;
    }

    /**
     *
     *
     * @param assoc $queryParams
     */
    public function selectFirst($queryParams = null)
    {
        list($sql, $pdoParams) = $this->getSql($queryParams);

        $rows = array( array( $this->db->selectFirst($sql, $pdoParams) ) );

        $this->postProcess($rows);

        return $rows[0][0];
    }

    /**
     * Does all post processing for the output of a query
     *
     * @param array $rows The rows as returned by the database
     */
    private function postProcess(&$rows)
    {
        // If array is empty we are ready
        if (count($rows)==0) {
            return;
        }

        // If array consists of numerical arrays, we are ready (child queries only make sense for associative arrays)
        if (!Arrays::isAssoc($rows[0])) {
            return;
        }

        $this->db->profiler->begin('query::postprocess');

        // Apply nesting
        $this->nestDottedFields($rows);

        // Apply typing
        $this->applyTyping($rows);

        // Do custom callback
        if ($callback = $this->db->queries->callback($this->id)) {
            $callback($rows);
        }

        $this->db->profiler->end();
    }

    /**
     * Sets the type of the output fields, according to the type specification in the json file
     *
     * @param array $rows
     */
    private function applyTyping(&$rows)
    {
        foreach ($this->_interface->output->fields as $name => $type) {
            if ($type != 'string') {
                for ($i=0;$i<count($rows);$i++) {
                    $this->setType($rows[$i][$name], $type);
                }
            }
        }
    }

    /**
     * Do type casting for the given field
     *
     * @param string $field
     * @param string $type
     */
    private function setType(&$field, $type)
    {
        if (is_null($field)) {
            return;
        }

        // Type can either be a string or an object
        $typeStr = (is_object($type) && property_exists($type, 'type'))
            ? $type->type
            : $type;

        switch ($typeStr) {
            // Basic type casting
            case 'int': 	$field = (int) $field; break;
            case 'float': 	$field = (float) $field; break;
            case 'number': 	$field = (float) $field; break;
            case 'string':	$field = (string) $field; break;

            // Recursive call for object properties
            case 'object':
                foreach ($type->fields as $name => $subtype) {
                    $this->setType($field[$name], $subtype);
                }
                break;

            // Child queries are handled by QueryTree
            case 'child':
                break;

            // JSON should only be decoded
            case 'json':
                $field = json_decode($field);
                self::fixGroupConcatArray($field);
                break;

            // Unknown type, do nothing
            default:
                break;
        }
    }

    /**
     * Converts dot-notation fields to a nested structure.
     *
     * @param array $rows
     */
    private function nestDottedFields(&$rows)
    {
        // If nesting is not set, we are ready
        if (!$this->output->nested) {
            return;
        }

        // If there are no rows we are ready
        if (count($rows) == 0) {
            return;
        }

        $keys 		= array_keys($rows[0]);
        $mapping	= array();

        // Split dotted fieldnames
        foreach ($keys as $key) {
            $map = explode('.', $key);
            if (count($map) > 1) {
                $mapping[ $key ] = $map;
            }
        }

        // Apply nesting for each row
        foreach ($mapping as $key => $map) {
            for ($i=0; $i<count($rows); $i++) {
                // These are some shortcuts for faster processing (nestField does the same but is slower)
                switch (count($map)) {
                    case 2: $rows[$i][$map[0]][$map[1]] = $rows[$i][$key]; break;
                    case 3: $rows[$i][$map[0]][$map[1]][$map[2]] = $rows[$i][$key]; break;
                    case 4: $rows[$i][$map[0]][$map[1]][$map[2]][$map[3]] = $rows[$i][$key]; break;
                    case 5: $this->nestField($rows[$i], $map, $rows[$i][$key]); break;
                }

                unset($rows[$i][$key]);
            }
        }

        // Check for null objects, which are caused by 'empty' left joins
        $nestedFields = array();
        foreach ($rows[0] as $key => $value) {
            if (Arrays::isAssoc($value) && count($value)>0) {
                $nestedFields[] = $key;
            }
        }

        foreach ($nestedFields as $field) {
            for ($i=0; $i<count($rows); $i++) {
                Arrays::reduceNulls($rows[$i][$field]);
            }
        }
    }

    /**
     * Helper function for nestDottedFields
     *
     * @param assoc $row
     * @param array $fieldComponents
     * @param string $value
     */
    private function nestField(&$row, $fieldComponents, $value)
    {
        $head = array_shift($fieldComponents);

        // If last field component
        if (count($fieldComponents) == 0) {
            $row[ $head ] = $value;
            return;
        }

        // Recursive call
        $this->nestField($row[ $head ], $fieldComponents, $value);
    }

    /**
     * Loads the interface if not yet loaded
     *
     */
    public function getInterface()
    {
        if (!$this->id) {
            throw new \Exception('getInterface: Query ID not known');
        }

        if ($this->_interface) {
            return $this->_interface;
        }

        $this->_interface = $this->db->queries->getInterface($this->id);

        return $this->_interface;
    }

    /**
     * Sets the interface for this query
     *
     */
    public function setInterface($params, $output)
    {
        if (!$this->_interface) {
            $this->_interface = new \StdClass();
        }

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
     * @param array $params query parameters
     */
    public function getSql($params = array())
    {
        // TODO: parameter gedeelte in andere functie
        // sql($sql) get/set van maken

        $pdoParams = array();

        if (is_null($params)) {
            $params = array();
        }

        if (!$this->id) {
            throw new \Exception('sql: Query ID not known');
        }

        // Read interface if it not yet known
        if (!$this->_interface) {
            $this->getInterface();
        }

        // Read compiled SQL if there is no SQL yet
        if (!$this->sql) {
            $this->sql = $this->db->queries->sql($this->id);
        }

        $sqlParsed = $this->sql;

        // Set defaults (only if not present in given param list)
        foreach ($this->_interface->params as $p => $props) {
            if (!array_key_exists($p, $params)) {
                if (property_exists($props, 'default')) {
                    $params[ $p ] = $props->{'default'};
                }
            }
        }

        // Add global parameters (only if not present in given param list)
        foreach ($this->db->globals as $p => $val) {
            if (!array_key_exists($p, $params) || is_null($params[$p])) {
                $params[ $p ] = $val;
            }
        }

        // Special handling for paging parameters
        if (property_exists($this->_interface, 'paging')) {
            $page = (array_key_exists('page', $params)) ? $params['page'] : 0;
            unset($params['page']); // unset page param because it is not present in the SQL itself
            $params['__limitStart'] = $page * (int) $this->_interface->paging;
        }

        // Set the parameters
        foreach ($params as $name => $value) {
            // Convert array to CSV which is suitable for IN
            if (is_array($value)) {
                $this->setArrayParam($sqlParsed, $name, $value);
            }
            // Param is a registered parameter
            elseif (property_exists($this->_interface->params, $name)) {
                switch ($this->_interface->params->$name->type) {
                    case "int": $pdoType = \PDO::PARAM_INT; break;
                    default:	$pdoType = \PDO::PARAM_STR; break;
                }

                $pdoParams[ $name ] = array(
                    'value'	=> $value,
                    'type'	=> $pdoType
                );
            }
            // Param is not registered (DEPRECATED - but still needed for global params)
            else {
                $valueSQL = $this->db->toSQL($value, true);
                $this->setParam($sqlParsed, $name, $valueSQL);
            }
        }

        return array($sqlParsed, $pdoParams);
    }

    /**
     * Helper function to convert parameters which are arrays into a format suitable to be used in the query
     *
     * @param string $sql
     * @param string $name Parameter name
     * @param array $value Parameter value
     */
    private function setArrayParam(&$sql, $name, $value)
    {
        $values = array();

        // In case $value is an array of arrays, create tuples like (1,2,3)
        if (count($value)>0 && is_array($value[0])) {
            return $this->setTupleParam($sql, $name, $value);
        }

        foreach ($value as $v) {
            $values[] = $this->db->encode($v);
        }

        $this->setParam($sql, $name, $values);
    }

    /**
     * Helper function to convert parameters which are arrays of tuples into a format suitable to be used in the query
     *
     * @param string $sql
     * @param string $name Parameter name
     * @param array $value Parameter value
     */
    private function setTupleParam(&$sql, $name, $value)
    {
        $tuples = array();
        $values = array();

        // Init array $values
        foreach ($value[0] as $i => $v) {
            $values[$i] = array();
        }

        // Create the tuples, but also collect the separate values
        foreach ($value as $v) {
            $tuple = array();

            foreach ($v as $i => $w) {
                $encval = $this->db->encode($w);

                $values[$i][] 	= $encval;
                $tuple[] 		= $encval;
            }

            $tuples[] = "(" . implode(",", $tuple) . ")";
        }

        // Set parameters $name[0], $name[1] etc.
        for ($i=0; $i<count($values); $i++) {
            $this->setParam($sql, $name . "\[" . $i . "\]", $values[$i]);
        }

        $this->setParam($sql, $name, $tuples);
    }

    /**
     * Replace the ":param" string with the value
     *
     * @param string $sql
     * @param string $name Parameter name
     * @param mixed $value SQL encoded parameter value or array of SQL encoded parameter values
     */
    private function setParam(&$sql, $name, $value)
    {
        if (is_array($value)) {
            $value = (count($value)==0)
                ? "NULL"
                : implode(",", $value);
        }

        $sql = preg_replace("/\:" . $name . "(\W)/", $value . "$1", $sql . " ");
    }

    /**
     * Workaround for groupconcat 'bug': when the groupconcat is based on a left join, the resulting array can
     * contain 1 (empty) element while you would expect is has 0 elements.
     * This function checks for this special case, and ensures that $array is empty
     */
    public static function fixGroupConcatArray(&$array)
    {
        if (!is_array($array)) {
            return;
        }

        if (count($array) != 1) {
            return;
        }

        Arrays::reduceNulls($array[0]);

        if (is_null($array[0])) {
            $array = array();
        }
    }
}
