<?php
namespace TinyQueries;

require_once('Config.php');
require_once('Term.php');
require_once('QuerySet.php');
require_once('Profiler.php');
require_once('Compiler.php');

/**
 * DB
 *
 * PDO based DB layer which can be used to call predefined SQL queries
 *
 * @author Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class DB
{
    public $dbh;	// PDO database handle
    public $nested; // Default setting whether or not query output should be nested - more info see Query::nested(.)
    public $queries;
    public $profiler;
    public $globals;
    public $driver;
    public $host;
    public $port;
    public $charset;
    public $dbname;
    public $user;

    private $pw;
    private $initQuery;
    private $globalQueryParams;
    private $primaryKey;

    /**
     * Constructor
     *
     * If no parameters are specified, database-parameters like username/passwd are read from the default configfile config.xml
     * The connection should be explicitly set up by calling the connect-method after the DB-object is constructed.
     * If you specify a $pdoHandle, this method should not be called.
     *
     * @param PDO $pdoHandle (optional) Use this if you already have a PDO database connection.
     * @param string $configFile (optional) Use this to specify your custom XML-configfile
     * @param Profiler|boolean $profiler (optional) If 'true' then a Profiler object is created and run is called; if 'false' the object is also created but not initialized
     * @param boolean $neverAutoCompile (optional) This can be used to overrule the setting in the config file
     */
    public function __construct($pdoHandle = null, $configFile = null, $profiler = null, $neverAutoCompile = false)
    {
        // Initialize profiler object
        if (is_object($profiler)) {
            $this->profiler = &$profiler;
        } else {
            $this->profiler = new Profiler(($profiler) ? true : false);
        }

        $config = new Config($configFile);

        // Import settings
        $this->driver		= $config->database->driver;
        $this->host			= ($config->database->host) ? $config->database->host : 'localhost';
        $this->port			= $config->database->port;
        $this->dbname		= $config->database->name;
        $this->user			= $config->database->user;
        $this->pw 			= $config->database->password;
        $this->charset 		= $config->database->charset;
        $this->initQuery	= $config->database->initQuery;
        $this->nested		= $config->postprocessor->nest_fields;

        // Call the compiler if autocompile is set
        if (!$neverAutoCompile && $config->compiler->autocompile) {
            $compiler = new Compiler($configFile);
            $compiler->compile(false, true);
        }

        $this->queries 		= new QuerySet($config->compiler->output);
        $this->globals 		= array();
        $this->primaryKey 	= 'id';

        if ($pdoHandle) {
            $this->dbh = $pdoHandle;
        }
    }

    /**
     * Get/set method for global query parameters. If value is not specified, the value of the global is returned
     *
     * @param string $name
     * @param mixed $value
     */
    public function param($name, $value = -99999999)
    {
        if ($value == -99999999) {
            if (!array_key_exists($name, $this->globals)) {
                throw new \Exception("DB::param - global parameter '".$name."' does not exist");
            }

            return $this->globals[ $name ];
        }

        $this->globals[ $name ] = $value;
    }

    /**
     * Sets up the database connection
     *
     */
    public function connect()
    {
        $this->disconnect();

        if (!$this->driver) {
            throw new \Exception("No database driver specified in config");
        }

        if (!$this->dbname) {
            throw new \Exception("No database name specified in config");
        }

        if (!$this->user) {
            throw new \Exception("No database user specified in config");
        }

        // construct PDO object
        $dsn = $this->driver . ':dbname=' . $this->dbname . ';host=' . $this->host;

        if ($this->driver != 'pgsql' && $this->charset) {
            $dsn .= ';charset=' . $this->charset;
        }

        if ($this->port) {
            $dsn .= ';port=' . $this->port;
        }

        $this->dbh = new \PDO($dsn, $this->user, $this->pw);

        // throw exception for each error
        $this->dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Needed for Postgres to be able to execute multiple SQL statements at once
        $this->dbh->setAttribute(\PDO::ATTR_EMULATE_PREPARES, 1);

        // execute the initial query
        if ($this->initQuery) {
            $this->execute($this->initQuery);
        }

        if ($this->driver == 'pgsql' && $this->charset) {
            $this->execute("SET NAMES '" . $this->charset . "'");
        }
    }

    /**
     * Checks if there is a connection
     *
     * @return boolean
     */
    public function connected()
    {
        return (($this->dbh) ? true : false);
    }

    /**
     * Disconnects from the DB
     *
     */
    public function disconnect()
    {
        // destroy db-object
        $this->dbh = null;
    }

    /**
     * @return PDO The PDO DB handle
     */
    public function pdo()
    {
        return $this->dbh;
    }

    /**
     * Creates and returns a new Query object
     *
     * @param string $term A query term like "a" or "a:b+c(d|e)"
     * @return Query
     */
    public function query($term)
    {
        return Term::parse($this, $term);
    }

    /**
     * Creates a query based on $term, executes it and returns the query output
     *
     * @param string $term
     * @param mixed $paramValues
     * @return array|string
     */
    public function get($term, $paramValues = null)
    {
        return $this->query($term)->select($paramValues);
    }

    /**
     * Creates a query based on $term, executes it and returns the first row of the query output
     *
     * @param string $term
     * @param mixed $paramValues
     * @return array|string
     */
    public function get1($term, $paramValues = null)
    {
        return $this->query($term)->select1($paramValues);
    }

    /**
     * @param string $identifier Can be a table name or field name
     * @return string
     */
    public function quoteIdentifier($identifier)
    {
        $match = null;
        $quoteChar = null;

        switch ($this->driver) {
            case 'pgsql':
                $quoteChar = '"';
                break;
            case 'mysql':
            default:
                $quoteChar = '`';
                break;
        }

        return (preg_match('/^(.+)\.(.+)$/', $identifier, $match))
            ? $match[1] . $quoteChar . $match[2] . $quoteChar
            : $quoteChar . $identifier . $quoteChar;
    }

    /**
     * Creates a basic select query for the given table and IDfields
     *
     * @param string $table
     * @param int|array $IDfields If an integer is supplied, it is assumed to be the primary key.
     * If it is an array, it is assumed to be an assoc array of fields which should all be matched
     * @param string|array $orderByFields
     * @return string
     */
    private function createSelect($table, $IDfields, $orderByFields = null)
    {
        // Convert to primary key selection
        if (!is_array($IDfields)) {
            $IDfields = array( $this->primaryKey => $IDfields );
        }

        $sql =
            "SELECT * FROM " .
            $this->quoteIdentifier($table) .
            " WHERE " .
            $this->fieldList($IDfields, " AND ", true);

        if ($orderByFields) {
            if (is_string($orderByFields)) {
                $orderByFields = array($orderByFields);
            }

            $sql .=
                " ORDER BY " .
                implode(", ", array_map(
                    array($this, 'quoteIdentifier'),
                    $orderByFields
                ));
        }

        return $sql;
    }

    /**
     * Selects a single record from the given table
     *
     * @param string $table
     * @param int|array $IDfields If an integer is supplied, it is assumed to be the primary key.
     * If it is an array, it is assumed to be an assoc array of fields which should all be matched
     * @return array
     */
    public function getRecord($table, $IDfields)
    {
        return $this->selectAssoc($this->createSelect($table, $IDfields));
    }

    /**
     * Selects records from the given table
     *
     * @param string $table
     * @param int|array $IDfields If an integer is supplied, it is assumed to be the primary key.
     * If it is an array, it is assumed to be an assoc array of fields which should all be matched
     * @param array $orderByFields
     * @return array
     */
    public function getRecordSet($table, $IDfields, $orderByFields = null)
    {
        return $this->selectAllAssoc($this->createSelect($table, $IDfields, $orderByFields));
    }

    /**
     * Selects a single record from the given table
     *
     * @param string $field Fieldname which is used for selection
     * @param string $table
     * @param int|string $value Fieldvalue
     * @return array
     */
    public function getRecordBy($field, $table, $value)
    {
        return $this->getRecord($table, array( $field => $value ));
    }

    /**
     * Selects records from the given table
     *
     * @param string $field Fieldname which is used for selection
     * @param string $table
     * @param int|string $value Fieldvalue
     * @param array $orderByFields
     * @return array
     */
    public function getRecordSetBy($field, $table, $value, $orderByFields = null)
    {
        return $this->getRecordSet($table, array( $field => $value ), $orderByFields);
    }

    /**
     * Inserts a record in the given table
     *
     * @param string $table
     * @param assoc $record
     * @param boolean $updateOnDuplicateKey If the insert fails due to a duplicate key error, then try to do an update (MySQL only)
     * @return int Id of the new record
     */
    public function insert($table, $record, $updateOnDuplicateKey = false)
    {
        if (!is_array($record) || count($record)==0) {
            throw new \Exception("DB::insert - record is empty");
        }

        $keys 	= array_keys($record);
        $values	= array_values($record);

        for ($i=0;$i<count($keys);$i++) {
            $keys[$i] = $this->quoteIdentifier($keys[$i]);
            $values[$i] = $this->toSQL($values[$i], true);
        }

        $keysSQL 	= implode(",", $keys);
        $valuesSQL 	= implode(",", $values);

        $query = "INSERT INTO " . $this->quoteIdentifier($table) . " ($keysSQL) VALUES ($valuesSQL)";

        if ($updateOnDuplicateKey) {
            $query .= " on duplicate key update " . $this->fieldList($record, ",");
        }

        $this->execute($query);

        $id = $this->dbh->lastInsertId();

        // If an update is done and the update is not changing the record, lastInsertId will return "0"
        if ($id == 0 && $updateOnDuplicateKey) {
            // Try to find the record based on $record
            $recordForID = array();
            foreach ($record as $key => $value) {
                // skip large fields like text & blobs
                if (strlen($value) <= 255) {
                    $recordForID[ $key ] = $value;
                }
            }

            $recordInDB = $this->getRecord($table, $recordForID);

            $id = ($recordInDB && array_key_exists($this->primaryKey, $recordInDB))
                    ? $recordInDB[ $this->primaryKey ]
                    : null;
        }

        return $id;
    }

    /**
     * Saves (either inserts or updates) a record in the given table (MySQL only)
     * NOTE: for this function to work correctly, the field(s) which correspond to a unique DB-key should be present in $record
     *
     * @param string $table
     * @param assoc $record
     * @return int
     */
    public function save($table, $record)
    {
        return $this->insert($table, $record, true);
    }

    /**
     * Updates a record in the given table
     *
     * @param string $table
     * @param int|array $IDfields If an integer is supplied, it is assumed to be the primary key.
     * If it is an array, it is assumed to be an assoc array of fields which should all be matched
     * @param assoc $record
     */
    public function update($table, $IDfields, $record)
    {
        if (!is_array($record) || count($record)==0) {
            throw new \Exception("DB::update - record is empty");
        }

        // Convert to primary key selection
        if (!is_array($IDfields)) {
            $IDfields = array( $this->primaryKey => $IDfields );
        }

        $query =
            "UPDATE " . $this->quoteIdentifier($table) .
            " SET " . $this->fieldList($record, ",") .
            " WHERE " . $this->fieldList($IDfields, " and ", true);

        $this->execute($query);
    }

    /**
     * Deletes a record from the given table
     *
     * @param string $table
     * @param int|array $IDfields If an integer is supplied, it is assumed to be the primary key.
     * If it is an array, it is assumed to be an assoc array of fields which should all be matched
     */
    public function delete($table, $IDfields)
    {
        // Convert to primary key selection
        if (!is_array($IDfields)) {
            $IDfields = array( $this->primaryKey => $IDfields );
        }

        $query =
            "DELETE FROM " .
            $this->quoteIdentifier($table) .
            " WHERE " .
            $this->fieldList($IDfields, " AND ", true);

        $this->execute($query);
    }

    /**
     * Executes the given query
     *
     * Note that when using $checkForMultipleStatements, this will only work for non-select queries
     * because the statement cursor is moved forward and cannot be rewinded
     *
     * @param string $query SQL query
     * @param assoc $params Query parameters
     * @param bool $checkForMultipleStatements Checks if there is an error in multi statement queries
     */
    public function execute($query, $params = array(), $checkForMultipleStatements = false)
    {
        if (!$this->dbh) {
            throw new \Exception("DB::execute called but there is no connection to the DB - call connect first");
        }

        $this->profiler->begin('db::execute');

        $sth = $this->dbh->prepare($query);

        // Bind the parameters
        foreach ($params as $name => $props) {
            // Do casting (otherwise the types might still not be set correctly)
            if (is_null($props['value'])) {
                $props['type'] = \PDO::PARAM_NULL;
            } else {
                switch ($props['type']) {
                    case \PDO::PARAM_INT: $props['value'] = (int) $props['value']; break;
                    case \PDO::PARAM_STR: $props['value'] = (string) $props['value']; break;
                }
            }

            $sth->bindValue(":" . $name, $props['value'], $props['type']);
        }

        $r = $sth->execute();

        $this->profiler->end();

        if ($checkForMultipleStatements) {
            // Just by moving through the rowsets, possible exceptions are thrown per set
            do {
            } while ($sth->nextRowset());
        }

        if (!$r) {
            $error = $sth->errorInfo();
            if ($error && is_array($error) && count($error)>=3) {
                throw new \Exception($error[1] . " - " . $error[2]);
            }
            throw new \Exception('unknown error during execution of query');
        }

        return $sth;
    }

    /**
     * Escapes a string such that it can be used in a query
     *
     * @param string $string
     * @param boolean $addquotes (optional)
     * @param boolean $useNULLforEmptyValue (optional)
     * @return string
     */
    public function toSQL($string, $addquotes = false, $useNULLforEmptyValue = false)
    {
        if (!$this->dbh) {
            throw new \Exception("toSQL called before creation of dbh-object");
        }

        if (is_array($string)) {
            throw new \Exception("toSQL: Array passed while expecting a string or a number");
        }

        if (is_object($string)) {
            throw new \Exception("toSQL: Object passed while expecting a string or a number");
        }

        if (is_null($string)) {
            return "NULL";
        }

        if ($string === '' && $useNULLforEmptyValue) {
            return "NULL";
        }

        $sql = '';

        if (!isset($string)) {
            $string = "";
        }

        // Not all drivers implement PDO::quote, so check if it is working
        if (preg_match('/test/', $this->dbh->quote('test'))) {
            $sql = $this->dbh->quote($string);
        // Do default quote-encoding
        } else {
            $sql = preg_replace('/\'/', "\\\'", $string);
            $sql = preg_replace('/\"/', "\\\"", $sql);
            $sql = "'" . $sql . "'";
        }

        // remove quotes added by quote(.)
        if (!$addquotes) {
            $sql = substr($sql, 1, strlen($sql)-2);
        }

        return $sql;
    }

    /**
     * Same as toSQL, except that integers & tuples like (1,2,3) are not quoted
     *
     * @param string $string
     * @return string
     */
    public function encode($string)
    {
        if (is_string($string) && (preg_match("/^\d+$/", $string) || preg_match("/^\([\d\,]+\)$/", $string))) {
            return $string;
        }

        return $this->toSQL($string, true);
    }

    /**
     * Executes query and returns numeric array of numeric arrays
     *
     * @param string $query SQL-query
     * @param assoc $params Query parameters
     * @return array
     */
    public function selectAll($query, $params = array())
    {
        return $this->execute($query, $params)->fetchAll(\PDO::FETCH_NUM);
    }

    /**
     * Executes query and returns numeric array of associative arrays
     *
     * @param string $query SQL-query
     * @param assoc $params Query parameters
     * @return array
     */
    public function selectAllAssoc($query, $params = array())
    {
        return $this->execute($query, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Executes query and returns first record as numeric array
     *
     * @param string $query SQL-query
     * @param assoc $params Query parameters
     * @return array
     */
    public function selectRow($query, $params = array())
    {
        return $this->execute($query, $params)->fetch(\PDO::FETCH_NUM);
    }

    /**
     * Executes query and returns first record as associative array
     *
     * @param string $query SQL-query
     * @param assoc $params Query parameters
     * @return array
     */
    public function selectAssoc($query, $params = array())
    {
        return $this->execute($query, $params)->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Executes query and returns first field of first record
     *
     * @param string $query SQL-query
     * @param assoc $params Query parameters
     * @return string|int|null
     */
    public function selectFirst($query, $params = array())
    {
        $sth = $this->execute($query, $params);
        $row = $sth->fetch(\PDO::FETCH_NUM);
        return is_array($row)
            ? $row[0]
            : null;
    }

    /**
     * Executes query and returns numeric array containing first field of each row
     *
     * @param string $query SQL-query
     * @param assoc $params Query parameters
     * @return array
     */
    public function selectAllFirst($query, $params = array())
    {
        $sth = $this->execute($query, $params);
        $rows = $sth->fetchAll(\PDO::FETCH_NUM);
        $firsts = array();
        foreach ($rows as $row) {
            $firsts[] = $row[0];
        }
        return $firsts;
    }

    /**
     * Create a concatenation of fieldname = "value" strings
     *
     * @param array $fields key/value pairs
     * @param string $glue
     * @param boolean $isOnNull If true, it uses 'is' for NULL values
     * @return string
     */
    private function fieldList($fields, $glue, $isOnNull = false)
    {
        $list = array();

        foreach ($fields as $name => $value) {
            $equalsSign = ($isOnNull && is_null($value))
                ? " is "
                : " = ";

            $list[] = $this->quoteIdentifier($name) . $equalsSign . $this->toSQL($value, true);
        }

        return implode($glue, $list);
    }
}
