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
		parent::init();
		
		// Initialize compiler
		$this->compiler = new TinyQueries\Compiler();
	}
	
	/**
	 *  
	 */
	protected function processRequest()
	{
		$method	= self::getRequestVar('method', '/^[\w\.]+$/');
		
		// If no method is send, just do the default request handler for queries
		if (!$method)
			return parent::processRequest();
			
		// Method mapper
		switch ($method)
		{
			case 'compile': 		return $this->compile();
			case 'getInterface':	return $this->getInterface();
			case 'getProject':		return $this->getProject();
			case 'getProjectInfo': 	return $this->getProjectInfo();
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
		$config = new TinyQueries\Config();
		$project = $this->compiler->querySet->project();
		
		// Add compiler info to project
		$project->compiler = $config->compiler;
		$project->compiler->compileNeeded = $this->compiler->codeChanged();
		
		return $project;
	}

	/**
	 * Returns the interface for a query
	 *
	 */
	public function getInterface()
	{
		$queryID = self::getRequestVar("query");
		
		return $this->compiler->querySet->getInterface($queryID);
	}
	
	/**
	 * Returns the parameters of the query-term passed by URL param 'query'
	 *
	 */
	private function getTermParams()
	{
		$term = self::getRequestVar("query");
		
		$query = $this->db->query($term);
		
		return array
		(
			'params' => $query->params
		);
	}
};

// All API handling is done by creating the object
$api = new AdminApi();

$api->init();
$api->sendResponse();

