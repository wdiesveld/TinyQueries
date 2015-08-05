<?php
namespace TinyQueries;

require_once( 'HttpTools.class.php' );
require_once( 'UserFeedback.class.php' );
require_once( dirname(__FILE__) . '/../QueryDB.class.php' ); 

/**
 * Api
 *
 * This is a simple JSON API which can be used on top of QueryDB
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class Api extends HttpTools
{
	protected $server;
	protected $apiCallID;
	protected $query;
	protected $debugMode;
	protected $configFile;
	protected $addProfilingInfo;
	protected $doTransaction;
	protected $request;
	protected $outputFormat;
	protected $reservedParams;
	
	public $db;
	public $profiler;
	
	/**
	 * Constructor
	 *
	 * @param {string} $configFile (optional) Path to DB settings file
	 * @param {boolean} $debugMode (optional) Sets debug mode
	 * @param {boolean} $addProfilingInfo (optional) Adds profiling info to api response
	 */
	public function __construct($configFile = null, $debugMode = false, $addProfilingInfo = false)
	{
		$this->server 	 		= self::getServerVar('SERVER_NAME');
		$this->debugMode 		= $debugMode;
		$this->configFile 		= $configFile;
		$this->addProfilingInfo = $addProfilingInfo;
		$this->doTransaction	= true;
		$this->request			= array();
		$this->contentType		= null;
		$this->reservedParams 	= array('query', 'param'); // + all params starting with _ are also ignored as query parameter

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
		
		$this->db = new QueryDB( null, $this->configFile, $this->profiler );
		
		$this->db->connect();
	}
	
	/**
	 * Returns the DB object
	 *
	 */
	public function getDB()
	{
		return $this->db;
	}
	
	/**
	 * Processes the request and sends the response to the stdout
	 *
	 * @param {string} $contentType (optional)
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
			
			$this->apiCallID = self::getRequestVar('apicall_id', '/^[\d\w\-]+$/'); // is sent back to caller; can be used to discriminate between responses
			
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
				$response = array
				(
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

		// Do contentType specific encoding and output to stdout
		switch ($this->contentType)
		{
			case 'text/csv': 
				header('Content-Disposition: attachment; filename="' . $this->createFilename( $this->request['query'] ) . '.csv"');
				$this->csvEncode( $response );
				break; 
				
			case 'application/json':
			default:
				echo $this->jsonEncode( $response );
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
			
		$this->db->pdo()->rollBack();
	}
	
	/**
	 * Overload this function if you want some post processing of the response before it is sent to the client
	 *
	 * @param {mixed} $response
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
	 * Converts a URI-path to a term + paramvalue + [output should be single row] true/false
	 *
	 * @param {$string} $path The resource path
	 * @param {string} $method The HTTP method
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
		$body = file_get_contents('php://input');
		
		// Replace EOL's and tabs by a space character (these chars are forbidden to be used within json strings)
		$body = preg_replace("/[\n\r\t]/", " ", $body);		
		if ($json = json_decode($body, true))
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
		$method = self::getServerVar('REQUEST_METHOD', '/^\w+$/');
		
		$singleRow = false;
		
		if (!$term && !$path) 
			throw new \Exception('query-param is empty'); 
			
		if (!$term && $path)
			list($term, $param, $singleRow) = $this->pathToTerm($path, $method);
			
		// Convert space to + (since in URL's + is converted to space, while + is the attach operator and should be preserved)
		$term = str_replace(" ", "+", $term);
		
		$params = $this->getQueryParams();

		$this->request['method'] 	= $method;
		$this->request['query']		= $term;	
		
		return array( $term, $params, $param, $singleRow );
	}
	
	/**
	 * Processes the api request, e.g. executes the query/queries and returns the output
	 */
	protected function processRequest()
	{
		if (!$this->db)
			throw new \Exception('Database is not initialized');

		if (!$this->db->connected())
			throw new \Exception('There is no database connection');
			
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
				"apicall_id"	=> $this->apiCallID,
				"result"		=> $response
			);
		
		return $response;
	}
	
	/**
	 * Creates an error response in JSON
	 *
	 */
	protected function createErrorResponse($errorMessage, $showToUser, $httpCode = 400, $altoMessage = "Cannot process request" )
	{
		$errorMessage = ($showToUser) 
							? $errorMessage 
							: $altoMessage;
	
		$response = array
		(
			"query"			=> (array_key_exists('queryID', $this->request)) ? $this->request['queryID'] : null,
			"apicall_id"	=> $this->apiCallID,
			"error"			=> $errorMessage
		);
		
		$this->setHttpResponseCode($httpCode);
		
		return $response;
	}

	/**
	 * CSV encoder function; outputs to stdout
	 *
	 * @param {assoc|array} $response
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
	 * @param {object} $object
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
	 * @param {mixed} $item If item is not a string, it is untouched
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
	
	/**
	 * Sets the HTTP status code
	 *
	 * @param {int} $code
	 */
	private function setHttpResponseCode($code = NULL) 
	{
		if (is_null($code))
			return (isset($GLOBALS['http_response_code'])) 
				? $GLOBALS['http_response_code'] 
				: 200;

		switch ($code) 
		{
			case 100: $text = 'Continue'; break;
			case 101: $text = 'Switching Protocols'; break;
			case 200: $text = 'OK'; break;
			case 201: $text = 'Created'; break;
			case 202: $text = 'Accepted'; break;
			case 203: $text = 'Non-Authoritative Information'; break;
			case 204: $text = 'No Content'; break;
			case 205: $text = 'Reset Content'; break;
			case 206: $text = 'Partial Content'; break;
			case 300: $text = 'Multiple Choices'; break;
			case 301: $text = 'Moved Permanently'; break;
			case 302: $text = 'Moved Temporarily'; break;
			case 303: $text = 'See Other'; break;
			case 304: $text = 'Not Modified'; break;
			case 305: $text = 'Use Proxy'; break;
			case 400: $text = 'Bad Request'; break;
			case 401: $text = 'Unauthorized'; break;
			case 402: $text = 'Payment Required'; break;
			case 403: $text = 'Forbidden'; break;
			case 404: $text = 'Not Found'; break;
			case 405: $text = 'Method Not Allowed'; break;
			case 406: $text = 'Not Acceptable'; break;
			case 407: $text = 'Proxy Authentication Required'; break;
			case 408: $text = 'Request Time-out'; break;
			case 409: $text = 'Conflict'; break;
			case 410: $text = 'Gone'; break;
			case 411: $text = 'Length Required'; break;
			case 412: $text = 'Precondition Failed'; break;
			case 413: $text = 'Request Entity Too Large'; break;
			case 414: $text = 'Request-URI Too Large'; break;
			case 415: $text = 'Unsupported Media Type'; break;
			case 500: $text = 'Internal Server Error'; break;
			case 501: $text = 'Not Implemented'; break;
			case 502: $text = 'Bad Gateway'; break;
			case 503: $text = 'Service Unavailable'; break;
			case 504: $text = 'Gateway Time-out'; break;
			case 505: $text = 'HTTP Version not supported'; break;
			default:
				exit('Unknown http status code "' . htmlentities($code) . '"');
			break;
		}

		$protocol = (isset($_SERVER['SERVER_PROTOCOL'])) 
			? $_SERVER['SERVER_PROTOCOL'] 
			: 'HTTP/1.0';

		header($protocol . ' ' . $code . ' ' . $text);

		$GLOBALS['http_response_code'] = $code;

		return $code;
	}
	
}
