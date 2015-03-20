<?php

/**
 * API for the admin tool
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */

// Path to libs
$pathLibs = dirname(__FILE__) . "/../../libs";

// Include libs  
require_once( $pathLibs . '/Api/Api.class.php' );
require_once( $pathLibs . '/Compiler.class.php' );

// Implements the API
class AdminApi extends TinyQueries\Api
{
	private $compiler;
	private $dbError;
	
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
		$this->compiler = new TinyQueries\Compiler();
	}
	
	/**
	 *  
	 */
	protected function processRequest()
	{
		$method	= self::getRequestVar('_method', '/^[\w\.]+$/');
		
		// If no method is send, just do the default request handler for queries
		if (!$method)
			return parent::processRequest();
			
		// Method mapper
		switch ($method)
		{
			case 'compile': 		return $this->compile();
			case 'getInterface':	return $this->getInterface();
			case 'getProject':		return $this->getProject();
			case 'getTermParams': 	return $this->getTermParams();
		}
		
		throw new Exception('Unknown method');
	}
	
	/**
	 * Compiles the tinyqueries source code 
	 *
	 */
	public function compile()
	{
		$this->compiler->compile( true );
		
		return array
		(
			'message' => 'Compiled at ' . date('Y-m-d H:i:s')
		);
	}
	
	/**
	 * Returns the project info
	 *
	 */
	public function getProject()
	{
		$config 	= new TinyQueries\Config();
		$project 	= null;

		try
		{
			$project = $this->compiler->querySet->project();
		}
		catch (Exception $e)
		{
			$project = new StdClass();
			$project->loadError = $e->getMessage();
		}
		
		// Add compiler info to project
		$project->compiler = $config->compiler;
		
		$project->compiler->compileNeeded 	= $this->compiler->compileNeeded();
		$project->version_libs 				= TinyQueries\Config::VERSION_LIBS;
		$project->dbError 					= $this->dbError;
		$project->dbStatus					= ($this->db && $this->db->connected()) ? 'Connected' : 'Not connected';
		$project->mode						= ($config->compiler->api_key) ? 'edit' : 'view';
		
		return $project;
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

// Handle request
$api = new AdminApi();

$api->sendResponse();

