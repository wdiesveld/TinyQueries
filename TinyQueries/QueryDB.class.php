<?php
/**
 * TinyQueries - Framework for merging and nesting relational data
 *
 * @author      Wouter Diesveld <wouter@tinyqueries.com>
 * @copyright   2012 - 2014 Diesveld Query Technology
 * @link        http://www.tinyqueries.com
 * @version     1.0.1
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
require_once('QuerySet.class.php');

/**
 * QueryDB
 *
 * PDO based DB layer which can be used to call predefined SQL queries
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class QueryDB
{
	public $dbh;	// PDO database handle
	public $nested; // Default setting whether or not query output should be nested - more info see Query::nested(.)
	public $queries;
	public $profiler;
	
	private $lastQueryExecTime;
	private $totalQueryExecTime;
	private $host;
	private $dbname;
	private $user;
	private $pw;
	private $globalQueryParams;
	private $primaryKey;
	
	protected $configFile;
	
	/**
	 * Constructor
	 *
	 * If no parameters are specified, database-parameters like username/passwd are read from the default configfile "./QueryDB.config.xml"
	 * The connection should be explicitly set up by calling the connect-method after the DB-object is constructed.
	 * If you specify a $pdoHandle, this method should not be called.
	 *
	 * @param {PDO} $pdoHandle (optional) Use this if you already have a PDO database connection.
	 * @param {string} $configFileDB (optional) Use this to specify your custom XML-configfile containing DB-info like username/password
	 */
	public function __construct( $pdoHandle = null, $configFileDB = null )
	{
		$this->globalQueryParams 	= array();
		$this->nested 				= false;
		
		$this->configFile = ($configFileDB)
								? $configFileDB
								: dirname(__FILE__) . "/QueryDB.xml";
		
		// Default primary key name
		$this->primaryKey = 'id'; 
		
		$this->readConfig();	
		$this->resetTotalQueryTime();
		
		if ($pdoHandle)
			$this->dbh = $pdoHandle;
	}
	
	/**
	 * This method can be used to set a global params for query input parameters
	 *
	 * @param {string} $name Name of the parameter
	 * @param {mixed} $value Can be any type which is accepted as query parameter (int, string, array)
	 */
	public function setGlobalQueryParam($name, $value)
	{
		$this->globalQueryParams[ $name ] = $value;
	}
	
	/**
	 * Checks if a global query parameter is set
	 *
	 * @param {string} $name Name of the parameter
	 */
	public function globalQueryParamExists($name)
	{
		return array_key_exists( $name, $this->globalQueryParams);
	}
	
	/**
	 * @return All query parameters
	 */
	public function getGlobalQueryParams()
	{
		return $this->globalQueryParams;
	}
	
	/**
	 * @param {string} $name
	 */
	public function getGlobalQueryParam($name)
	{
		if (!$this->globalQueryParamExists($name))
			throw new \Exception("getGlobalQueryParam: param '".$name."' does not exist");
			
		return $this->globalQueryParams[ $name ];
	}
	
	/**
	 * Reads the XML config-file
	 *
	 */
	private function readConfig()
	{
		$config = @simplexml_load_file( $this->configFile );
		
		if (!$config) 		throw new \Exception("Cannot read db configfile " . $this->configFile);
		if (!$config->db)	throw new \Exception("No db-tag found in configfile " . $this->configFile);
		
		$dbConfig = null;
		
		// Find the db-entry that matches the servername
		$servername = $this->getServerName();
		
		foreach ($config->db as $db)
			if (is_null($db['server']) || $db['server'] == $servername)
				$dbConfig = $db;

		if (!$dbConfig)	throw new \Exception("No DB-config found for server " . $servername);

		// Read DB credentials
		$this->host		= (string) $dbConfig->host;
		$this->dbname	= (string) $dbConfig->dbname;
		$this->user		= (string) $dbConfig->username;
		$this->pw 		= (string) $dbConfig->password;
		
		// Read query output settings
		if ($output = $config->query_output)
		{
			if ($output->nested)
			{
				if ($output->nested['value'] == 'true')		$this->nested = true;
				if ($output->nested['value'] == 'false') 	$this->nested = false;
			}
		}

		// Read path for SQL queries
		if ($config->queries)
			$this->queries = new QuerySet( (string) $config->queries );
	}
	
	/**
	 * Reads the configfile and sets up the database connection
	 *
	 */
	public function connect()
	{
		$this->disconnect();
		
		// construct PDO object
		$dsn = "mysql:dbname=" . $this->dbname . ";host=" . $this->host;
		$this->dbh = new \PDO($dsn, $this->user, $this->pw);
		
		// throw exception for each error
		$this->dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
	}

	/**
	 * Checks if there is a connection
	 *
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
	 * @return {PDO} The PDO DB handle
	 */
	public function getHandle()
	{
		return $this->dbh;
	}
	
	/**
	 * @param {string} $queryID
	 */
	public function query($queryID)
	{
		return new Query( $this, $queryID );
	}
	
	/**
	 * Escapes a string such that it can be used in a query
	 *
	 * @param {string} $string
	 * @param {boolean} $addquotes (optional)
	 * @param {boolean} $useNULLforEmptyValue (optional)
	 */
	public function toSQL($string, $addquotes = false, $useNULLforEmptyValue = false)
	{
		if (!$this->dbh) 
			throw new \Exception("toSQL called before creation of dbh-object");
		
		if ((($string === '') || is_null($string)) && $useNULLforEmptyValue)
			return "NULL";

		$sql = '';

		if (!isset($string)) $string = "";

		$sql = $this->dbh->quote( $string );

		if (!$addquotes)
		{
			// remove quotes added by quote(.)
			$sql = substr($sql, 1, strlen($sql)-2);
		}

		return $sql;
	}
	
	/**
	 *
	 * @param {string} $query SQL-query
	 */
	public function selectAll($query, $params = array())
	{
		return $this->doQuery( $query, $params )->fetchAll( \PDO::FETCH_NUM );
	}

	public function selectAllAssoc($query, $params = array())
	{
		return $this->doQuery( $query, $params )->fetchAll( \PDO::FETCH_ASSOC );
	}

	public function selectRow($query, $params = array())
	{
		return $this->doQuery( $query, $params )->fetch( \PDO::FETCH_NUM );
	}
	
	public function selectAssoc($query, $params = array())
	{
		return $this->doQuery( $query, $params )->fetch(\PDO::FETCH_ASSOC);
	}
	
	// eerste kolom van eerste rij
	public function selectFirst($query, $params = array()) 
	{
		$sth = $this->doQuery( $query, $params );
		$row = $sth->fetch(\PDO::FETCH_NUM);
		return $row[0];
	}
	
	// van alle rijden eerste kolom
	public function selectAllFirst($query, $params = array()) 
	{
		$sth = $this->doQuery( $query, $params );
		$rows = $sth->fetchAll(\PDO::FETCH_NUM);
		$firsts = array();
		foreach ($rows as $row)
			$firsts[] = $row[0];
		return $firsts;
	}
	
	/**
	 * Selects a record from the given table
	 *
	 * @param {string} $field Fieldname which is used for selection
	 * @param {string} $table
	 * @param {int|string} $value Fieldvalue
	 */
	public function getRecordBy($field, $table, $value)
	{
		return $this->getRecord($table, array( $field => $value ));
	}

	/**
	 * Selects a record from the given table
	 *
	 * @param {string} $table
	 * @param {int|array} $IDfields If an integer is supplied, it is assumed to be the primary key. 
	 *                            If it is an array, it is assumed to be an assoc array of fields which should all be matched
	 */
	public function getRecord($table, $IDfields)
	{
		// Convert to primary key selection
		if (!is_array($IDfields))
			$IDfields = array( $this->primaryKey => $IDfields );
			
		$query = "select * from `" . $this->toSQL($table) . "` where " . $this->fieldList( $IDfields, " and ", true );
		
		return $this->selectAssoc( $query );
	}
	
	/**
	 * Create a concatenation of `fieldname` = "value" strings
	 *
	 * @param {assoc} $fields
	 * @param {string} $glue
	 * @param {boolean} $isOnNull If true, it uses 'is' for NULL values
	 */
	private function fieldList($fields, $glue, $isOnNull = false)
	{
		$list = array();
		
		foreach ($fields as $name => $value)
		{
			$equalsSign = ($isOnNull && is_null($value)) 
							? " is " 
							: " = ";
							
			$list[] = "`" . $this->toSQL($name) . "`" . $equalsSign . $this->toSQL($value, true, true);
		}
	
		return implode( $glue, $list );
	}
	
	/**
	 * Inserts a record in the given table
	 *
	 * @param {string} $table
	 * @param {assoc} $record
	 * @param {boolean} $updateOnDuplicateKey If the insert fails due to a duplicate key error, then try to do an update (MySQL only)
	 */
	public function insert($table, $record, $updateOnDuplicateKey = false)
	{
		$keys 	= array_keys($record);
		$values	= array_values($record);
		
		for ($i=0;$i<count($keys);$i++)
		{
			$keys[$i] 	= "`" . $this->toSQL($keys[$i]) . "`";
			$values[$i] = $this->toSQL($values[$i], true, true);
		}
		
		$keysSQL 	= implode(",", $keys);
		$valuesSQL 	= implode(",", $values);
		
		$query = "insert into `" . $this->toSQL($table) . "` ($keysSQL) values ($valuesSQL)";
		
		if ($updateOnDuplicateKey)
			$query .= " on duplicate key update " . $this->fieldList( $record, "," );
		
		$this->doQuery($query);
		
		$id = $this->dbh->lastInsertId();

		// If an update is done and the update is not changing the record, lastInsertId will return "0"
		if ($id == 0 && $updateOnDuplicateKey)
		{
			// Try to find the record based on $record
			$recordForID = array();
			foreach ($record as $key => $value)
				// skip large fields like text & blobs
				if (strlen($value) <= 255) 
					$recordForID[ $key ] = $value;
			
			$recordInDB = $this->getRecord($table, $recordForID);
			
			$id = ($recordInDB && array_key_exists( $this->primaryKey, $recordInDB ))
					? $recordInDB[ $this->primaryKey ]
					: null;
		}
		
		return $id;
	}
	
	/**
	 * Saves (either inserts or updates) a record in the given table (MySQL only)
	 * NOTE: for this function to work correctly, the field(s) which correspond to a unique DB-key should be present in $record
	 *
	 * @param {string} $table
	 * @param {assoc} $record
	 */
	public function save($table, $record)
	{
		return $this->insert($table, $record, true);
	}
	
	/**
	 * Updates a record in the given table
	 *
	 * @param {string} $table
	 * @param {int|array} $IDfields If an integer is supplied, it is assumed to be the primary key. 
	 *                            If it is an array, it is assumed to be an assoc array of fields which should all be matched
	 * @param {assoc} $record
	 */
	public function update($table, $IDfields, $record)
	{
		// Convert to primary key selection
		if (!is_array($IDfields))
			$IDfields = array( $this->primaryKey => $IDfields );

		$query = 	"update `" . $this->toSQL($table) . "`" .
					" set " . $this->fieldList( $record, "," ) . 
					" where " . $this->fieldList( $IDfields, " and ", true );
		
		$this->doQuery($query);
	}
	
	/**
	 * Deletes a record from the given table
	 *
	 * @param {string} $table
	 * @param {int|array} $IDfields If an integer is supplied, it is assumed to be the primary key. 
	 *                            If it is an array, it is assumed to be an assoc array of fields which should all be matched
	 */
	public function delete($table, $IDfields)
	{
		// Convert to primary key selection
		if (!is_array($IDfields))
			$IDfields = array( $this->primaryKey => $IDfields );
			
		$query = "delete from `" . $this->toSQL($table) . "` where " . $this->fieldList( $IDfields, " and ", true );
		
		$this->doQuery($query);
	}
	
	public function doQuery($query, $params = array())
	{
		$before = microtime(true);
		
		$sth = $this->dbh->prepare($query);

		// Bind the parameters
		foreach ($params as $name => $props)
			$sth->bindValue( ":" . $name, $props['value'], $props['type'] );
		
		$r = $sth->execute();

		$after = microtime(true);
		
		$this->lastQueryExecTime 	= $after - $before;
		$this->totalQueryExecTime  += $this->lastQueryExecTime;
		
		if (!$r) 
		{
			$error = $sth->errorInfo();
			if ($error && is_array($error) && count($error)>=3)
				throw new \Exception($error[2]);
			throw new \Exception('unknown error during execution of query');
		}
		
		return $sth;
	}

	public function getTotalQueryTime()
	{
		return $this->totalQueryExecTime;
	}

	public function getLastQueryTime()
	{
		return $this->lastQueryExecTime;
	}
	
	public function resetTotalQueryTime()
	{
		$this->lastQueryExecTime  = 0;
		$this->totalQueryExecTime = 0;
	}	
	
	/**
	 * Returns the name of the server
	 */
	protected static function getServerName()
	{
		$servername = ($_SERVER && array_key_exists('SERVER_NAME', $_SERVER) && $_SERVER['SERVER_NAME']) 
								? $_SERVER['SERVER_NAME']
								: "localhost";
								
		// add 'www.' if it is left out
		if (preg_match('/^\w+\.\w+$/', $servername))
			$servername = 'www.' . $servername;
			
		return $servername;
	}	
} 

?>