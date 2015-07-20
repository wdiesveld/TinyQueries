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
	if (count($argv) <= 1)
		throw new Exception("Connector should be called with query term as first parameter");
	
	$term = $argv[1];
	$params = null;
	
	if (count($argv) >= 3)
	{
		$params = @json_decode( $argv[2], true );
		
		if (!$params)
			throw new Exception("Cannot decode parameters - parameters should be encoded as JSON");
	}
	
	$db = new TinyQueries\QueryDB();
	
	$db->connect();
	
	echo json_encode( $db->query($term)->run($params) );
}
catch (Exception $e)
{
	echo json_encode( array( "error" => $e->getMessage() ) );
	exit(1);
}

exit(0);
