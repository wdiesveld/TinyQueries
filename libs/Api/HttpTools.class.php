<?php
namespace TinyQueries;

/**
 * HttpTools
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
 class HttpTools
 {
	/**
	 * Gets a param from an array of params, trims it and checks if it matches the regexp provided
	 *
	 * Note 1: This is a helper-function and should not be used directly; use getURLparam, getPostVar etc instead
	 * Note 2: If the param itself is an array, all elements of the array are checked
	 *
	 * @param paramname
	 * @param requestArray
	 * @param regexp (optional)
	 * @param defaultvalue (optional) Value which is returned if there is no match with the regexp
	 */
	public static function getParam($paramname, $requestArray, $regexp=null, $defaultvalue=null)
	{
		$value = $defaultvalue;

		if (array_key_exists($paramname, $requestArray))
			$value = $requestArray[$paramname];
			
		self::sanitize( $value, $regexp, $defaultvalue );	

		return $value;
	}
	
    private static function is_assoc($arr) 
	{
        return (is_array($arr) && count(array_filter(array_keys($arr),'is_string')) == count($arr));
    }	

	/**
	 * Converts encoding to latin, trims the value, and checks it against the regular expression
	 *
	 * @param {mixed} $value Can be an array or a string - if it is an array, sanitize is called recursively for each array element
	 * @param {string} $regexp
	 * @param {string} $defaultvalue
	 */
	private static function sanitize(&$value, $regexp, $defaultvalue)
	{
		if (self::is_assoc($value))
		{
			foreach (array_keys($value) as $key)
			{
				self::sanitize( $value[$key], $regexp, $defaultvalue );
			}
		}
		elseif (is_array($value))
		{
			for ($i=0;$i<count($value);$i++)
			{
				self::sanitize( $value[$i], $regexp, $defaultvalue );
			}
		}
		elseif (is_string($value))
		{
			$value = trim( $value );
			
			if ($value == 'null')
			{
				// exception for javascript serialization of null-values
				$value = null;
			}
			elseif ($regexp && !preg_match($regexp, $value))
			{
				$value = $defaultvalue;
			}
		}
	}
	
	/**
	 * Gets the posted (or put) json blob
	 *
	 */
	public static function getJsonBody()
	{
		// Get content of body of HTTP message
		$body = file_get_contents('php://input');
		
		// Replace EOL's and tabs by a space character (these chars are forbidden to be used within json strings)
		$body = preg_replace("/[\n\r\t]/", " ", $body);		
		
		if ($json = @json_decode($body, true))
			return $json;
			
		return null;
	}

	public static function getURLparam($paramname, $regexp=null, $defaultvalue=null)
	{
		return self::getParam($paramname, $_GET, $regexp, $defaultvalue);
	}

	public static function getPostVar($paramname, $regexp=null, $defaultvalue=null)
	{
		return self::getParam($paramname, $_POST, $regexp, $defaultvalue);
	}

	public static function getRequestVar($paramname, $regexp=null, $defaultvalue=null)
	{
		return self::getParam($paramname, $_REQUEST, $regexp, $defaultvalue);
	}

	public static function getSessionVar($paramname, $regexp=null, $defaultvalue=null)
	{
		return self::getParam($paramname, $_SESSION, $regexp, $defaultvalue);
	}

	public static function getCookie($paramname, $regexp=null, $defaultvalue=null)
	{
		return self::getParam($paramname, $_COOKIE, $regexp, $defaultvalue);
	}
	
	public static function getServerVar($paramname, $regexp=null, $defaultvalue=null)
	{
		return self::getParam($paramname, $_SERVER, $regexp, $defaultvalue);
	}
	
	public static function getServerName()
	{
		$servername = ($_SERVER && array_key_exists('SERVER_NAME', $_SERVER) && $_SERVER['SERVER_NAME']) 
								? $_SERVER['SERVER_NAME']
								: "localhost";
								
		// add 'www.' if it is left out
		if (preg_match('/^\w+\.\w+$/', $servername))
			$servername = 'www.' . $servername;
			
		return $servername;
	}
	
	/**
	 * Converts the input string to latin encoding if it not already in latin
	 *
	 * @param string
	 */
	public static function toLatin($string)
	{
		if (mb_detect_encoding($string, 'UTF-8', true))
			return utf8_decode($string);
			
		return $string;
	}
	
	public static function urlEncode($parameters)
	{
		$paramValues = array();
		
		foreach ($parameters as $key=>$value)
		{
			if (is_string($value))
				$paramValues[] = urlencode($key) . '=' . urlencode($value);
		}
		
		return implode('&', $paramValues);
	}
	
	public static function addParamsToURL($url, $parameters)
	{
		$joinChar = (strpos($url, '?') === FALSE)
						? '?'
						: '&';
						
		return $url . $joinChar . self::urlEncode($parameters);
	}
	
	/**
	 * Sets the HTTP status code
	 *
	 * @param {int} $code
	 */
	public static function setHttpResponseCode($code = NULL) 
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
 
