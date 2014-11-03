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
			case 'getParams': 		return $this->getQueryParams();
			case 'getProject':		return $this->getProject();
			case 'getProjectInfo': 	return $this->getProjectInfo();
			case 'getInterface':	return $this->getInterface();
		}
		
		throw new Exception('Unknown method');
	}
	
	/**
	 * Compiles the tinyqueries source code 
	 *
	 */
	public function compile()
	{
		$this->compiler->compile();
		
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
		return $this->compiler->querySet->project();
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
	private function getQueryParams()
	{
		$term = self::getRequestVar("query");
		
		$query = $this->db->query($term);
		
		return array
		(
			'params' => $query->params
		);
	}
	
	/**
	 * Returns some basic project info | TODO is deze method nog nodig nu je ook project() hebt ?
	 *
	 * @param {string} $projectID
	 */
	public function getProjectInfo($projectID)
	{
		$project = $this->compiler->project($projectID);
		
		return array
		(
			'name'				=> $project->projectName,
			'queriesfolder'		=> $project->querySet->path(),
			'php'				=> $project->pathCompiledPHP,
			'modelFile'			=> $project->fileQPLmodel,
			'queriesFile'		=> $project->fileQPLqueries,
			'queryApi'			=> $project->queryApi,
			'compiler'			=> $this->compiler->qplServer,
			'compileNeeded'		=> $this->compiler->qplCodeChanged(),
			'compilerVersion'	=> $project->compilerVersion
		);
	}
};

// All API handling is done by creating the object
$api = new AdminApi();

$api->sendResponse();

