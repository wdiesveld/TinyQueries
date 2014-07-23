<?php
/**
 * TinyQueries - Framework for merging and nesting relational data
 *
 * @author      Wouter Diesveld <wouter@tinyqueries.com>
 * @copyright   2012 - 2014 Diesveld Query Technology
 * @link        http://www.tinyqueries.com
 * @version     1.1
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

require_once("QueryDB.class.php");

/**
 * QueryApi
 *
 * This is a simple json api based on the modules HttpTools & UserFeedback.
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class QueryApi
{
	protected $server;
	protected $apiCallID;
	protected $params;
	protected $debugMode;
	protected $dbConfigFile;
	protected $addProfilingInfo;
	protected $doTransaction;
	protected $request;
	
	public $db;
	public $profiler;
	
	/**
	 * Constructor
	 *
	 * @param {string} $dbConfigFile (optional) Path to DB settings file
	 * @param {boolean} $debugMode (optional) Sets debug mode
	 * @param {boolean} $addProfilingInfo (optional) Adds profiling info to api response
	 */
	public function __construct($dbConfigFile = null, $debugMode = false, $addProfilingInfo = false)
	{
		$this->server 	 		= \HttpTools::getServerVar('SERVER_NAME');
		$this->debugMode 		= $debugMode;
		$this->dbConfigFile 	= $dbConfigFile;
		$this->addProfilingInfo = $addProfilingInfo;
		$this->doTransaction	= true;
		$this->request			= array();

		// Overrule profiling setting if param _profiling is send
		if (array_key_exists('_profiling', $_REQUEST))
			$this->addProfilingInfo	= \HttpTools::getRequestVar('_profiling', '/^\d+$/'); 
		
		// Create Profiler object
		if ($this->addProfilingInfo)
		{
			require_once("Profiler.class.php");
			$this->profiler	= new Profiler();
		}
	}
	
	/**
	 * Initializes the api (connects to db)
	 *
	 */
	public function init()
	{
		if ($this->db)
			return;
		
		$this->db = new QueryDB( $this->dbConfigFile );
		
		// Pass the profiler to the DB, so that each query can be profiled separately (not yet implemented)
		$this->db->profiler = $this->profiler;
		
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
	 * Processes the request and sends the JSON response to the stdout
	 *
	 * @param {string} $contentType (optional)
	 * @param {function} $postProcess (optional) Callback function which postprocesses the json output
	 */
	public function sendResponse($contentType = "application/json; charset=utf-8", $postProcess = null)
	{
		// optional parameters for redirect (non ajax only)
		$urlSuccess	= \HttpTools::getRequestVar('url-success');
		$urlError 	= \HttpTools::getRequestVar('url-error');
		
		// Set content type
		header( 'Content-type: ' . $contentType);

		$response = array();
		
		try
		{
 			// vang alle eventuele warnings/errors op
			ob_start();
			
			$this->apiCallID = \HttpTools::getRequestVar('apicall_id',	'/^[\d\w\-]+$/'); // is sent back to caller; can be used to discriminate between responses
			
			$this->init();
			
			$dbConnectedModus = ($this->db && $this->db->connected());
			
			if ($dbConnectedModus && $this->doTransaction)
				$this->db->doQuery('START TRANSACTION');
			
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
					$this->db->doQuery('COMMIT');
					
				$this->db->disconnect();
			}
			
			if ($this->addProfilingInfo)
				$response['profiling'] = $this->profiler->results();
				
			if ($this->addProfilingInfo && $dbConnectedModus)
				$response['profiling']['database'] = $this->db->getTotalQueryTime(); 
			
		}
		catch (\Exception $e)
		{
			// reset output buffer
			ob_clean();
			
			if ($this->doTransaction)
				$this->rollback();

			$errorMessage = $e->getMessage();
		
			$showToUser = (get_class( $e ) == 'UserFeedback' || $this->debugMode == true) 
								? true 
								: false;
								
			$httpCode	= (get_class( $e ) == 'UserFeedback')
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
		
		if ($urlSuccess && !array_key_exists('error', $response))
		{
			header('Location: ' . \HttpTools::addParamsToURL($urlSuccess, $response));
			exit;
		}
		
		if ($urlError && array_key_exists('error', $response))
		{
			header('Location: ' . \HttpTools::addParamsToURL($urlError, $response));
			exit;
		}

		$json = $this->jsonEncode( $response );
		
		echo ($postProcess)
				? $postProcess( $json )
				: $json;
	}
	
	/**
	 * Overload this function if you have more things to clean up when an error occors during the request-processing
	 */
	protected function rollback()
	{
		if (!$this->db)
			return;
			
		if (!$this->db->getHandle())
			return;
			
		$this->db->doQuery('ROLLBACK');
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
	 * Processes the api request, e.g. executes the query/queries and returns the output
	 */
	protected function processRequest()
	{
		$querySpec	= \HttpTools::getRequestVar('query',		'/^[\w\.\:\-\,\(\)]+$/'); 
		$idField	= \HttpTools::getRequestVar('id_field',		'/^[\w\.\-]+$/');
		$orderBy	= \HttpTools::getRequestVar('order_by',		'/^[\w\.\-]+$/');
		$orderType	= \HttpTools::getRequestVar('order_type',	'/^\w+$/');
		$maxResults	= \HttpTools::getRequestVar('max_results',	'/^\d+$/');
		$params  	= array();
		$response 	= null; 

		if (!$querySpec) 
			throw new \Exception('query-param is empty'); 
			
		$query = $this->db->query($querySpec);
		
		$this->request['query']		= $querySpec;	
		$this->request['queryID'] 	= $query->id;
	
		if (!$this->checkRequestPermissions())
			throw new \UserFeedback( 'You have no permission to do this request' );
		
		// read the query-parameters
		foreach (array_keys($_GET) as $getkey)
			if (preg_match("/^param\_/", $getkey))
			{
				$paramname = substr($getkey, 6);
				
				// Prevent global parameters to be overwritten by api users
				if (!$this->db->globalQueryParamExists($paramname))
					$params[ $paramname ] = \HttpTools::getRequestVar($getkey); 
			}
			
		if ($this->addProfilingInfo)	
			$this->profiler->begin('query');
		
		$query->params($params)
				->key($idField)
				->order($orderBy, $orderType)
				->max($maxResults);
			
		$response = ($this->addProfilingInfo)
			? array
				(
					"query"			=> $querySpec,
					"params"		=> $params,
					"apicall_id"	=> $this->apiCallID,
					"rows"			=> $query->select()
				)
			: $query->select();

		if ($this->addProfilingInfo)	
			$this->profiler->end();
		
		$this->postProcessResponse( $response );
		
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
			"params"		=> $this->params,
			"apicall_id"	=> $this->apiCallID,
			"error"			=> $errorMessage
		);
		
		$this->setHttpResponseCode($httpCode);
		
		return $response;
	}
	
	/**
	 * JSON encoder function (Also does the UTF8 encoding)
	 *
	 * @param {object} $object
	 */
	public function jsonEncode($object)
	{
		if (is_object($object))
			$object = Arrays::objectToArray($object);
		
		if (is_array($object))
			array_walk_recursive($object, array($this, 'toUTF8'));
		else
			$object = $this->toUTF8($object);
		
		return json_encode( $object );
	}
	
	/**
	 * Converts a string to UTF8, if it is not yet in UTF8
	 *
	 * @param {mixed} $item If item is not a string, it is untouched
	 */
	public function toUTF8(&$item) 
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
		if ($code !== NULL) 
		{
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

			$protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');

			header($protocol . ' ' . $code . ' ' . $text);

			$GLOBALS['http_response_code'] = $code;

		} 
		else 
		{
			$code = (isset($GLOBALS['http_response_code']) ? $GLOBALS['http_response_code'] : 200);
		}

		return $code;
	}
	
}
