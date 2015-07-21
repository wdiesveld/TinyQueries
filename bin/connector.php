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

require_once( dirname(__FILE__) . '/../libs/QueryDB.class.php' ); 

try
{
	// Show usage message
	if (count($argv) <= 1)
	{
		echo "Usage: php connector.php [queryTerm] [queryParameters]\n";
		exit(0);
	}
	
	// Get query term
	$term 	= $argv[1];
	$params = null;
	
	// Get query parameters
	if (count($argv) >= 3)
	{
		$params = @json_decode( $argv[2], true );
		
		if (!$params)
			throw new Exception("Cannot decode parameters - parameters should be encoded as JSON");
	}
	
	// Create database object
	$db = new TinyQueries\QueryDB();
	
	$db->connect();
	
	// Run query and return result as JSON
	echo json_encode( $db->query($term)->run($params) );
}
catch (Exception $e)
{
	echo json_encode( array( "error" => $e->getMessage() ) );
	exit(1);
}

exit(0);
