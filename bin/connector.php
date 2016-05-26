<?php

/**
 * Connector script - can be used as middleware to run TinyQueries from a non-php language
 *
 * From your non-php script you do a system call like
 *
 * 		php <this-script> [query-term] [query-parameters]
 *
 * The query term should be of the form "a" or "a(b)" etc.
 * The query parameters should be encoded as JSON
 *
 * The output of this script is the resulting data set in JSON
 * 
 */

register_shutdown_function( 'shutdown' );

error_reporting(0);

require_once( dirname(__FILE__) . '/../libs/QueryDB.class.php' ); 
require_once( dirname(__FILE__) . '/../libs/Api/Api.class.php' ); 

try
{
	// Show usage message
	if (count($argv) <= 1)
	{
		echo "Usage: php connector.php [queryTerm] [queryParameters] [globalParameters]\n";
		exit(0);
	}
	
	// Catch all output which is send to stdout
	ob_start();

	// Get query term
	$term 		= $argv[1];
	$params 	= null;
	$globals 	= null;
	
	// Get query parameters
	if (count($argv) >= 3)
	{
		$params = @json_decode( $argv[2], true );
		
		if (is_null($params))
			throw new Exception("Cannot decode parameters - parameters should be encoded as JSON");
	}
	
	// Get global query parameters
	if (count($argv) >= 4)
	{
		$globals = @json_decode( $argv[3], true );
		
		if (is_null($globals))
			throw new Exception("Cannot decode global parameters - global parameters should be encoded as JSON");
	}
	
	// Create database object
	$db = new TinyQueries\QueryDB();
	
	$db->connect();
	
	if ($globals)
		foreach ($globals as $name => $value)
			$db->param($name, $value);
	
	// Run query and return result as JSON
	$json = TinyQueries\Api::jsonEncode( $db->query($term)->run($params) );
	
	$textOutput = ob_get_contents();

	if ($textOutput)
		throw new \Exception($textOutput);
		
	// reset output buffer
	ob_clean();
	
	echo $json;
}
catch (Exception $e)
{
	// reset output buffer
	ob_clean();

	echo json_encode( array( "error" => $e->getMessage() ) );
	exit(1);
}

exit(0);

/**
 * This is needed to catch fatal errors
 *
 */
function shutdown()
{
	$error = error_get_last();

	if ($error !== NULL)
		echo json_encode( array( "error" => "PHP fatal error:  " . $error["message"] ) );
}

