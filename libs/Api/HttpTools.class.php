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
}
 
