<?php
namespace TinyQueries;

require_once( 'HttpTools.php' );
require_once( 'UserFeedback.php' );
require_once( dirname(__FILE__) . '/../DB.php' ); 

/**
 * Api
 *
 * This is a simple JSON API which can be used on top of DB
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class Api extends HttpTools
{
	protected $server;
	protected $query;
	protected $debugMode;
	protected $config;
	protected $configFile;
	protected $addProfilingInfo;
	protected $doTransaction;
	protected $request;
	protected $outputFormat;
	protected $reservedParams;
	protected $params = array();
	protected $endpoints = array();
	protected $handler;
	
	public $db;
	public $profiler;
	
	/**
	 * Constructor
	 *
	 * @param string $configFile (optional) Path to DB settings file
	 * @param boolean $debugMode (optional) Sets debug mode
	 * @param boolean $addProfilingInfo (optional) Adds profiling info to api response
	 */
	public function __construct($configFile = null, $debugMode = false, $addProfilingInfo = false)
	{
		$this->server 	 		= self::getServerVar('SERVER_NAME');
		$this->debugMode 		= $debugMode;
		$this->configFile 		= $configFile;
		$this->addProfilingInfo = $addProfilingInfo;
		$this->doTransaction	= true;
		$this->contentType		= null;
		$this->reservedParams 	= array('query', 'param'); // + all params starting with _ are also ignored as query parameter
		
		// request contains the details of the request
		$this->request = array(
			'method' => self::getServerVar('REQUEST_METHOD', '/^\w+$/', 'GET')
		);

		// Overrule profiling setting if param _profiling is send
		if (array_key_exists('_profiling', $_REQUEST))
			$this->addProfilingInfo	= self::getRequestVar('_profiling', '/^\d+$/'); 

		// Create Profiler object
		$this->profiler	= new Profiler( $this->addProfilingInfo );
	}
	
	/**
	 * Initializes the api (connects to db)
	 *
	 */
	public function init()
	{
		if ($this->db)
			return;
		
		$this->db = new DB( null, $this->configFile, $this->profiler );
		
		$this->db->connect();
		
		$this->config = new Config( $this->configFile );
		
		// Check for Swagger specs
		if ($this->config->api->swagger)
			$this->importSwagger(  $this->config->api->swagger );
	}
	
	/**
	 * Processes the request and sends the response to the stdout
	 *
	 * @param string $contentType (optional)
	 */
	public function sendResponse($contentType = 'application/json')
	{
		// Get the output format
		$this->contentType = self::getRequestVar('_contentType', '/^[\w\/]+$/', $contentType);
		
		// Set content type
		header( 'Content-type: ' . $this->contentType . '; charset=utf-8' );

		$response = array();
		
		try
		{
 			// Catch all output which is send to stdout
			ob_start();
			
			$this->init();
			
			$dbConnectedModus = ($this->db && $this->db->connected());
			
			if ($dbConnectedModus && $this->doTransaction)
			{
				// Disable nested fields for CSV since CSV can only handle 'flat' data
				if (preg_match('/\/csv$/', $this->contentType))
					$this->db->nested = false;
		
				$this->db->pdo()->beginTransaction();
			}
			
			$response = $this->processRequest();

			// Ensure the output is an associative array, so that meta data like exectime can be added
			if ($this->addProfilingInfo && (!Arrays::isAssoc($response) || (Arrays::isAssoc($response) && count($response) == 0)))
				$response = array(
					'result' => $response
				);

			$textOutput = ob_get_clean();
			
			if ($textOutput)
				throw new \Exception($textOutput);
			
			if ($dbConnectedModus)
			{
				if ($this->doTransaction)
					$this->db->pdo()->commit();
					
				$this->db->disconnect();
			}
			
			if ($this->addProfilingInfo)
				$response['profiling'] = $this->profiler->results();
			
		}
		catch (\Exception $e)
		{
			// reset output buffer
			ob_clean();
			
			if ($this->doTransaction)
				$this->rollback();

			$errorMessage = $e->getMessage();
		
			$showToUser = (get_class( $e ) == "TinyQueries\\UserFeedback" || $this->debugMode == true) 
				? true 
				: false;
								
			$httpCode	= (get_class( $e ) == "TinyQueries\\UserFeedback")
				? $e->getCode()
				: 400;

			$response = $this->createErrorResponse( $errorMessage, $showToUser, $httpCode );
		}
		
		// add general info to response
		if ($this->addProfilingInfo)
		{
			$response['timestamp'] 	= time();
			$response['server'] 	= $this->server;
		}
		
		// optional parameters for redirect (non ajax only)
		$urlSuccess	= self::getRequestVar('url-success');
		$urlError 	= self::getRequestVar('url-error');
		
		// Do redirect
		if ($urlSuccess && !array_key_exists('error', $response))
		{
			header('Location: ' . self::addParamsToURL($urlSuccess, $response));
			exit;
		}
		
		// Do redirect
		if ($urlError && array_key_exists('error', $response))
		{
			header('Location: ' . self::addParamsToURL($urlError, $response));
			exit;
		}

		$this->sendResponseBody($response);
	}
	
	/**
	 * Do contentType specific encoding and output to stdout
	 *
	 */
	protected function sendResponseBody(&$response)
	{
		switch ($this->contentType)
		{
			case 'text/csv': 
				header('Content-Disposition: attachment; filename="' . $this->createFilename( $this->request['query'] ) . '.csv"');
				$this->csvEncode( $response );
				break;
				
			case 'application/json':
				echo $this->jsonEncode( $response );
				break;
				
			default:
				throw new \Exception('No handler for contentType: ' . $this->contentType);
				// Do nothing - for custom content-types you should override this method
				break;
		}
	}
	
	/**
	 * Overload this function if you have more things to clean up when an error occors during the request-processing
	 */
	protected function rollback()
	{
		if (!$this->db)
			return;
			
		if (!$this->db->pdo())
			return;
			
		if (!$this->db->pdo()->inTransaction())
			return;
			
		$this->db->pdo()->rollBack();
	}
	
	/**
	 * Overload this function if you want some post processing of the response before it is sent to the client
	 *
	 * @param mixed $response
	 */
	protected function postProcessResponse( &$response )
	{
	}
	
	/**
	 * Returns a filename based on a queryID (term)
	 */
	protected function createFilename( $queryTerm )
	{
		if (!$queryTerm)
			return 'query_output';
			
		// Replace all query term special chars with underscore
		return preg_replace('/[\+\(\)\,\s\:\|]/', '_', $queryTerm);
	}
	
	/**
	 * Sets the endpoints
	 *
	 * @example 
	 *   $api->endpoints([
	 *      'GET /my/path/{id}' => 'myQuery1',
	 *      'PUT /my/path/{id}' => 'myQuery2'
	 *   ]);
	 *
	 * @example
	 *   $api->handler( $myCustomHandler )->endpoints([
	 *      'GET /my/path/{id}' => [ 'query' => 'myQuery1' ],
	 *      'PUT /my/path/{id}' => [ 'method' => 'myCustomMethod' ]
	 *   ]);
	 *
	 * All parameters which are in the URL path or in the body will be passed to the endpoint handler.
	 * If you want to use a custom handler you should set your handler using the 'handler' method
	 *
	 * @param array $endpoints 
	 * @return Api $this
	 */
	public function endpoints( $endpoints )
	{
		if (is_null($endpoints))
			return $this->endpoints;
		
		foreach ($endpoints as $path => $handler)
		{
			if (is_array($handler)) 
			{
				if (!array_key_exists('query', $handler) && !array_key_exists('method', $handler))
					throw new \Exception("Endpoint handler for '$path' should at least have the field 'query' or 'method'");
				
				if (array_key_exists('method', $handler) && !$this->handler)
					throw new \Exception("If you use 'method' to define an endpoint, you should set your custom handler using the method 'handler'");
					
				$this->endpoints[ $path ] = $handler;
			}
			elseif (is_string($handler))
				$this->endpoints[ $path ] = array(
					'query' => $handler
				);
			else 
				throw new \Exception("Invalid format endpoint handler for '$path'");
		}
			
		return $this;
	}
	
	/**
	 * Sets a custom handler
	 * 
	 * For an example check the docs of 'endpoints'
	 *
	 * @param object $handler This should be an initialized object containing your own custom methods. 
	 * @return Api $this
	 */
	public function handler( $handler )
	{
		$this->handler = $handler;
		
		return $this;
	}
	
	/**
	 * Checks if the given endpoint matches the endpoint as coming from the webserver.
	 * Furthermore it sets the keys of the $params property corresponding to the variables in the path prefixed with :
	 *
	 * @example
	 *    if ($this->endpoint('GET /users/:userID'))
	 *			return $this->getUser();
	 *
	 * This will set $this->params['userID'] to the value in the URL if there is a match
	 *
	 * @param string $endpoint
	 */
	public function endpoint($endpoint)
	{
		// Split into two
		list($method, $pathVars) = explode(' ', $endpoint);
		
		// Check if request method matches
		if ($method != $this->request['method'])
			return false;

		// Get path which is coming from the request
		$path = self::getRequestVar('_path');
		
		// Add pre- and post slashes if not present
		if (substr($path,0,1)  != '/') $path = '/' . $path;
		if (substr($path,-1,1) != '/') $path = $path . '/';
		if (substr($pathVars,0,1)  != '/') $pathVars = '/' . $pathVars;
		if (substr($pathVars,-1,1) != '/') $pathVars = $pathVars . '/';
			
		// Get variable names from $pathVars (both format :varname and {varname})
		preg_match_all( "/\:(\w+)\//", $pathVars, $varsDotted );
		preg_match_all( "/\{(\w+)\}\//", $pathVars, $varsCurly );
		
		$vars =  ($varsDotted) ? $varsDotted[1] : array();
		$vars += ($varsCurly) ? $varsCurly[1] : array();
			
		// Create a regexp based on pathVars
		$pathRexExp = str_replace('/', '\/', $pathVars);
		$pathRexExp = str_replace('.', '\.', $pathRexExp);
		
		// Replace the :vars and {vars} with \w+ 
		foreach ($vars as $var)
		{
			$pathRexExp = str_replace(':'.$var, 	"([\\w\\-]+)", $pathRexExp);
			$pathRexExp = str_replace('{'.$var.'}', "([\\w\\-]+)", $pathRexExp);
		}

		// Check if there is a match
		if (!preg_match_all('/^' . $pathRexExp . '$/', $path, $values))
			return false;
			
		// Set the parameters
		foreach ($vars as $i => $var)
			$this->params[ $var ] = $values[$i+1][0];
			
		return true;
	}
	
	/**
	 * Converts a URI-path to a term + paramvalue + [output should be single row] true/false
	 *
	 * @param {$string} $path The resource path
	 * @param string $method The HTTP method
	 */
	private function pathToTerm($path, $method)
	{
		$match = null;
		
		// Remove first slash if there is nothing before it
		if (preg_match("/^\/(.*)$/", $path, $match))
			$path = $match[1];
			
		// Remove last slash if there is nothing after it
		if (preg_match("/^(.*)\/$/", $path, $match))
			$path = $match[1];
			
		if (!$path)
			return array(null, null, false);
		
		$words 	= explode('/', $path);
		$n 		= count($words);
		
		// Check even words for term-chars
		foreach ($words as $i => $word)
			if ($i%2==0 && !preg_match( Term::CHARS, $word ))
				throw new \Exception("Path contains invalid characters");
				
		// Determine queryID postfix		
		switch ($method)
		{
			case 'PUT':
			case 'PATCH': 	$postfix = ".update"; break;
			case 'POST': 	$postfix = ".create"; break;
			case 'DELETE': 	$postfix = ".delete"; break;
			default:		$postfix = ""; break;
		}		
		
		// path = "/a"  --> term = "a"
		if ($n==1)
			return array( $path . $postfix, null, false );
			
		// path = "../../a/:param"  --> term = "a" using parameter :param
		if ($n%2==0)
			return array($words[ $n-2 ] . $postfix, $words[ $n-1 ], true);
			
		// path = "../a/:param/b" --> term = "(b):a" using parameter :param
		return array( "(" . $words[ $n-1 ] . $postfix . "):" . $words[ $n-3 ], $words[ $n-2 ], false );	
	}
	
	/**
	 * Returns the request parameter values
	 *
	 */
	private function getQueryParams()
	{
		$params = array();
		
		// read the query-parameters
		foreach (array_keys($_REQUEST) as $paramname)
			if (!in_array($paramname, $this->reservedParams) && substr($paramname, 0, 1) != '_')
			{
				// Try/catch is needed to prevent global parameters to be overwritten by api users
				try
				{
					// If param is NOT present, an error is thrown
					$this->db->param($paramname);
				}
				catch (\Exception $e) 
				{
					$params[ $paramname ] = self::getRequestVar($paramname);
				}
			}
		
		if (count($params) > 0)
			return array( $params );
			
		// If no params are found check if the body is a json blob
		if ($json = self::getJsonBody())
		{
			// Ensure the output is an array of assoc arrays
			if (Arrays::isAssoc($json))
				return array( $json );
				
			if (is_array($json))
				return $json;
		}
				
		return array(array());
	}
	
	/**
	 * Returns the requested query term + its parameter values
	 *
	 */
	protected function requestedQuery()
	{
		$term 	= self::getRequestVar('query', Term::CHARS ); 
		$path 	= self::getRequestVar('_path');
		$param	= self::getRequestVar('param');
		
		$singleRow = false;
		
		if (!$term && !$path) 
			throw new \Exception('query-param is empty'); 
			
		if (!$term && $path)
			list($term, $param, $singleRow) = $this->pathToTerm($path, $this->request['method'] );
			
		// Convert space to + (since in URL's + is converted to space, while + is the attach operator and should be preserved)
		$term = str_replace(" ", "+", $term);
		
		$params = $this->getQueryParams();

		$this->request['query']	= $term;	
		
		return array( $term, $params, $param, $singleRow );
	}
	
	/**
	 * Imports endpoints from swagger definition file
	 *
	 */
	protected function importSwagger( $swaggerFile )
	{
		$swagger = @file_get_contents( $swaggerFile );
		
		if (!$swagger)
			throw new \Exception('Cannot read swagger file');
			
		$specs = @json_decode( $swagger, true ); 
		
		if (!$specs)
			throw new \Exception('Cannot decode swagger specs');
			
		foreach ($specs['paths'] as $path => $methods)
			foreach ($methods as $method => $def)
			{
				$handler = array();
				
				if (array_key_exists('x-tq-query', $def))
					$handler['query'] = $def['x-tq-query'];
				
				if (array_key_exists('x-tq-method', $def))
					$handler['method'] = $def['x-tq-method'];
				
				$this->endpoints[ strtoupper($method) . ' ' . $path ] = $handler;
			}
	}
	
	/**
	 * Checks if the endpoint which is called is in the list of endpoints and executes the corresponding query
	 *
	 */
	protected function processEndpoint()
	{
		// Set parameters which are posted in body
		if ($posted = self::getJsonBody())
			$this->params = $posted;	
	
		// Check which of the endpoints is called and if there is a query defined for it
		foreach ($this->endpoints as $path => $handler)
			if ($this->endpoint( $path ))
			{
				if (array_key_exists('query', $handler))
					return $this->db->query( $handler['query'] )->run( $this->params );
				
				if (array_key_exists('method', $handler))
					return $this->invokeCustomMethod( $handler['method'] );
			}
		
		// Catch the root if none was defined and return basic api info
		if ($this->endpoint('GET /'))
		{
			$endpointList = array_keys( $this->endpoints );
			sort( $endpointList );
			return array(
				'message' => 'Welcome to the API for ' . $this->config->project->label,
				'endpoints' => $endpointList
			);
		}
			
		throw new UserFeedback('No valid API-call');	
	}
	
	/**
	 * Calls the method from the custom handler
	 *
	 * @param string $methodName 
	 */
	private function invokeCustomMethod($methodName)
	{
		if (!$this->handler)
			throw new \Exception("Cannot invoke custom method '$methodName' - no handler defined");
		
		$method = new \ReflectionMethod( $this->handler, $methodName );
		$params = $method->getParameters();
		$args	= array();
		
		// Loop through method parameters and get each param value from the params in the request
		foreach ($params as $param)
		{
			$name = $param->getName();
			
			// Get parameter value from request if present
			if (array_key_exists($name, $this->params))
				$val = $this->params[ $name ];
			// Otherwise use default value if present
			elseif ($param->isOptional())
				$val = $param->getDefaultValue();
			// Otherwise throw error
			else
				throw new \Exception("Parameter '$name' is required");

			$args[] = $val;
		}

		// Call method
		return $method->invokeArgs( $this->handler, $args );		
	}
	
	/**
	 * Processes the api request, e.g. executes the query/queries and returns the output
	 *
	 */
	protected function processRequest()
	{
		if (!$this->db)
			throw new \Exception('Database is not initialized');

		if (!$this->db->connected())
			throw new \Exception('There is no database connection');
		
		// If there are endpoints defined process the endpoint
		if ($this->endpoints)
			return $this->processEndpoint();
			
		// If there are no endpoints defined check for 'query' and '_path' parameters 
		list($term, $paramsSet, $param, $singleRow) = self::requestedQuery();
		
		$multipleCalls 	= (count($paramsSet) > 1) ? true : false;
		$response 		= ($multipleCalls) ? array() : null; 
		
		$this->query = $this->db->query($term);
		
		$this->request['queryID'] = property_exists($this->query, 'id') 
			? $this->query->id 
			: null;
	
		if (!$this->checkRequestPermissions())
			throw new UserFeedback( 'You have no permission to do this request' );
			
		$this->profiler->begin('query');
		
		if ($param && !$this->query->defaultParam)
			throw new \Exception("Single parameter value passed, but query does not have a default parameter");

		foreach ($paramsSet as $params)
		{		
			// Only overwrite if $param is not null
			if ($this->query->defaultParam)
				if (!is_null($param) || !array_key_exists($this->query->defaultParam, $params))
					$params[ $this->query->defaultParam ] = $param;
					
			if ($multipleCalls)
				$response[] = $this->query->run( $params ); 
			else
				$response   = $this->query->run( $params ); 
		}

		$this->postProcessResponse( $response );
		
		$this->profiler->end();
		
		// Wrap response in array if profiling is added
		if ($this->addProfilingInfo)
			$response = array
			(
				"query"			=> $term,
				"params"		=> $params,
				"result"		=> $response
			);
		
		return $response;
	}
	
	/**
	 * Creates an error response
	 *
	 */
	protected function createErrorResponse($errorMessage, $showToUser, $httpCode = 400, $altoMessage = 'Cannot process request' )
	{
		$this->setHttpResponseCode( $httpCode );
		
		return array(
			'error'	=> ($showToUser) 
				? $errorMessage 
				: $altoMessage
		);
	}

	/**
	 * CSV encoder function; outputs to stdout
	 *
	 * @param assoc|array $response
	 */
	public function csvEncode($response)
	{
		$stdout = fopen("php://output", 'w');

		if (is_object($response))
			$response = Arrays::objectToArray($response);

		// Ignore meta info; only output query output
		if (Arrays::isAssoc($response) && count($response)>0 && array_key_exists('result', $response))
			$response = $response['result'];
		
		if (is_null($response))
			$response = array( "" );
		
		if (is_string($response))
			$response = array( $response );
			
		if (Arrays::isAssoc($response))
			$response = array(
				array_keys( $response ),
				array_values( $response )
			);
			
		// Should not occur at this point..
		if (!is_array($response))
			throw new \Exception("Cannot convert reponse to CSV");

		// Encode as UTF8
		array_walk_recursive($response, array($this, 'toUTF8'));			
			
		// If output is array of assocs
		if (count($response)>0 && Arrays::isAssoc($response[0]))
		{
			// Put array keys as first CSV line
		    fputcsv($stdout, array_keys($response[0]), ';');
			
			foreach($response as $row) 
				fputcsv($stdout, array_values($row), ';');
		}
		// Output is an array of arrays
		elseif (count($response)>0 && is_array($response[0]))
			foreach($response as $row) 
				fputcsv($stdout, $row, ';');
		// Output is 1 dim array
		else
			foreach($response as $item) 
				fputcsv($stdout, array( $item ), ';');
		
        fclose($stdout);
	}
	
	/**
	 * JSON encoder function (Also does the UTF8 encoding)
	 *
	 * @param object $object
	 */
	public static function jsonEncode($object)
	{
		if (is_object($object))
			$object = Arrays::objectToArray($object);
		
		if (is_array($object))
			array_walk_recursive($object, array('TinyQueries\Api', 'toUTF8'));
		else
			$object = self::toUTF8($object);
		
		return json_encode( $object );
	}
	
	/**
	 * Converts a string to UTF8, if it is not yet in UTF8
	 *
	 * @param mixed $item If item is not a string, it is untouched
	 */
	public static function toUTF8(&$item) 
	{ 
		if (is_string($item) && mb_detect_encoding($item, 'UTF-8', true))
			return $item;	
	
		if (is_string($item)) 
			$item = utf8_encode( $item );
			
		return $item;
	}

	/**
	 * This method can be overloaded to add your own permission checks
	 * The overloaded method can use $this->request to check the specs of the request
	 *
	 */
	protected function checkRequestPermissions()
	{
		return true;
	}
}
