<?php
namespace TinyQueries;

// Include libs  
require_once( dirname(__FILE__) . '/Api.class.php' );
require_once( dirname(__FILE__) . '/../Compiler.class.php' );

/**
 * API for the admin tool
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class AdminApi extends Api
{
	const REG_EXP_SOURCE_ID = "/^[\w\.\-]+$/";
	
	protected $compiler;
	protected $dbError;
	
	/**
	 * Constructor 
	 */
	public function __construct()
	{
		// Set debug mode = true
		parent::__construct(null, true);
	}
	
	/**
	 * Initializer
	 */
	public function init()
	{
		try
		{
			parent::init();
		}
		catch (\Exception $e)
		{
			// If initializing fails, there is no DB connection
			// A DB connection is not required for the admin tool (except that some functions are not available)
			// So no exception must be thrown, only the message must be saved
			$this->dbError = $e->getMessage();
		}
		
		// Initialize compiler
		$this->compiler = new Compiler();
	}
	
	/**
	 *  
	 */
	protected function processRequest()
	{
		$method		= self::getRequestVar('_method', '/^[\w\.]+$/');
		$globals	= self::getRequestVar('_globals');
		
		// Set global query params
		if ($globals)
		{
			$globals = json_decode( $globals );
			foreach ($globals as $name => $value)
				$this->db->param($name, $value);
		}
		
		// If no method is send, just do the default request handler for queries
		if (!$method)
			return parent::processRequest();
			
		// Method mapper
		switch ($method)
		{
			case 'compile': 		return $this->compile();
			case 'deleteQuery':		return $this->deleteQuery();
			case 'getInterface':	return $this->getInterface();
			case 'getProject':		return $this->getProject();
			case 'getSource':		return $this->getSource();
			case 'getSQL':			return $this->getSQL();
			case 'getTermParams': 	return $this->getTermParams();
			case 'renameQuery':		return $this->renameQuery();
			case 'saveSource':		return $this->saveSource();
			case 'testApi':			return array( "message" => "Api is working" );
		}
		
		throw new \Exception('Unknown method');
	}
	
	/**
	 * Compiles the tinyqueries source code 
	 *
	 */
	public function compile()
	{
		// Call compiler with settings Force compile & do clean up
		$this->compiler->compile( true, true );
		
		return array
		(
			'message' => 'Compiled at ' . date('Y-m-d H:i:s')
		);
	}
	
	/**
	 * Checks if file exists, if so deletes it
	 *
	 */
	private function deleteFile($path)
	{
		if (!file_exists($path))
			return;
			
		$r = @unlink( $path );
		
		if (!$r)
			throw new \Exception("Could not delete $file");
	}
	
	/**
	 * Deletes the source, sql and interface file of a query
	 *
	 */
	public function deleteQuery()
	{
		$queryID = self::getRequestVar('query', self::REG_EXP_SOURCE_ID);
		
		if (!$queryID)
			throw new \Exception("No queryID");
		
		$this->deleteFile( $this->getSourceFilename('query') );
		
		return array
		(
			'message' => 'Query is removed'
		);
	}
	
	/**
	 * Checks the given ID and gives feedback if it is not ok
	 *
	 */
	private static function checkUserDefinedSourceID($requestVar)
	{
		$sourceID = self::getRequestVar($requestVar);
		
		if (is_null($sourceID) || ($sourceID === ''))
			throw new \Exception("You have to give a name to the query");

		if (!preg_match(self::REG_EXP_SOURCE_ID, $sourceID))
			throw new \Exception("Name of the query can only contain the characters: a-z A-Z _ 0-9 . -");
			
		return $sourceID;
	}
	
	/**
	 * Renames the source file and deletes the sql and interface file of a query
	 *
	 */
	public function renameQuery()
	{
		// First save the source
		$this->saveSource('query_old');
		
		$queryIDold = self::getRequestVar('query_old', self::REG_EXP_SOURCE_ID);
		$queryIDnew = self::checkUserDefinedSourceID('query_new');
		
		if (!$queryIDold)
			throw new \Exception("param query_old is missing");
		
		$filenameSourceOld = $this->getSourceFilename('query_old');
		$filenameSourceNew = $this->getSourceFilename('query_new');
		
		// Don't throw error in this case, because the query which is being renamed might not be saved yet
		if (!file_exists($filenameSourceOld))
			return array
			(
				'message' => 'Query is not present on file system'
			);
		
		if (file_exists($filenameSourceNew))
			throw new \Exception("Cannot rename: queryID already exists");
			
		$r = @rename($filenameSourceOld, $filenameSourceNew);
		
		if (!$r)
			throw new \Exception("Error during renaming");
		
		return array
		(
			'message' => 'Query is renamed'
		);
	}
	
	/**
	 * Returns the project info
	 *
	 */
	public function getProject()
	{
		$config 	= new Config();
		$project 	= null;

		try
		{
			$project = $this->compiler->querySet->project();
		}
		catch (\Exception $e)
		{
			$project = new \StdClass();
			$project->loadError = $e->getMessage();
		}
		
		// Add compiler info to project
		$project->compiler = $config->compiler;
		
		$project->compiler->compileNeeded 	= $this->compiler->compileNeeded();
		$project->version_libs 				= Config::VERSION_LIBS;
		$project->dbError 					= $this->dbError;
		$project->dbStatus					= ($this->db && $this->db->connected()) ? 'Connected' : 'Not connected';
		$project->mode						= ($config->compiler->api_key) ? 'edit' : 'view';

		if (!property_exists($project, 'queries'))
			$project->queries = new \StdClass();
		
		// Set runnable = true for all compiled queries & add id
		foreach ($project->queries as $queryID => $def)
		{
			$project->queries->$queryID->id			= $queryID;
			$project->queries->$queryID->runnable 	= true;
		}
		
		// We are ready in case there is nothing to edit
		if ($project->mode != 'edit' || !$project->compiler->input)
			return $project;
		
		// Load query list from the input folder in order to get all other files which have no equivalent in the sql folder
		// (these are _model, hidden queries, not compiled queries)
		$match = null;
		$sourceIDs = array();
			
		// Scan input folder for source files
		foreach (scandir($project->compiler->input) as $file)
			if (preg_match("/^(.*)\.json$/", $file, $match))
				$sourceIDs[] = $match[1];

		// Compiled items which are not in the source file list should be removed
		// (usually these are deleted or renamed source files)
		foreach (array_keys(get_object_vars($project->queries)) as $queryID)
			if (!in_array($queryID, $sourceIDs))
				unset($project->queries->$queryID);
		
		// Source files which are not in the compiled list should be added
		foreach ($sourceIDs as $sourceID)		
			if (!property_exists( $project->queries, $sourceID ))
			{
				$queryDef = new \StdClass();
				$queryDef->id			= $sourceID;
				$queryDef->expose 		= 'hide';
				$queryDef->type			= null;
				$queryDef->defaultParam = null;
				$queryDef->operation	= null;
				$queryDef->runnable		= false;
						
				$project->queries->$sourceID = $queryDef;
			}
		
		return $project;
	}
	
	/**
	 * Returns the name of the source file which is posted
	 *
	 */
	private function getSourceFilename( $requestVar )
	{
		$sourceID = self::getRequestVar($requestVar, self::REG_EXP_SOURCE_ID);
		
		if (!$sourceID)
			throw new \Exception("sourceID not known");
			
		$config	= new Config();
			
		if (!$config->compiler->input)
			throw new \Exception("No input folder specified");
		
		return $config->compiler->input . "/" . $sourceID . ".json";
	}
	
	/**
	 * Returns the source of a query (if available)
	 *
	 */
	public function getSource()
	{
		$filename = $this->getSourceFilename('sourceID');
		
		// NOTE: regular api output is overruled - just the file itself is sent
		header( 'Content-type:  text/plain' );
		echo QuerySet::load( $filename );
		exit;
	}

	/**
	 * Saves the source of a query
	 *
	 */
	public function saveSource( $sourceIDvar = 'sourceID' )
	{
		self::checkUserDefinedSourceID($sourceIDvar);
		
		$filename 	= $this->getSourceFilename($sourceIDvar);
		$source 	= self::getRequestVar('source');
		
		$r = @file_put_contents($filename, $source);
			
		if (!$r) 
			throw new \Exception('Error writing ' . $filename . ' -  are the permissions set correctly?' );			
			
		return array
		(
			'message' => 'Source is saved'
		);
	}
	
	/**
	 * Returns the interface for a query
	 *
	 */
	public function getInterface()
	{
		list($queryID, $dummy) = self::requestedQuery();
		
		$interface = $this->compiler->querySet->getInterface($queryID);
		
		// Add parameters for aliases
		if (property_exists($interface, 'term'))
		{
			$response = $this->getTermParams();
		
			$interface->params = $response['params'];
		}
		
		return $interface;
	}
	
	public function getSQL()
	{
		list($queryID, $dummy) = self::requestedQuery();
		
		$sql = $this->compiler->querySet->sql($queryID);
		
		if (!$sql)
			throw new \Exception("Could not read SQL file");
	
		// NOTE: regular api output is overruled - just the file itself is sent
		header( 'Content-type:  text/plain' );
		echo $sql;
		exit;
	}
	
	/**
	 * Returns the parameters of the query-term passed by URL param 'query'
	 *
	 */
	private function getTermParams()
	{
		list($term, $dummy) = self::requestedQuery();
		
		$query = $this->db->query($term);
		
		return array
		(
			'params' => $query->params
		);
	}
};
