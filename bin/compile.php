<?php

/**
 * Compile script for command line compiling of TinyQueries 
 *
 */

require_once( dirname(__FILE__) . '/../libs/Compiler.php' );

try
{
	$configFile = (count($argv) > 1)
		? $argv[1]
		: null;
	
	$compiler = new TinyQueries\Compiler( $configFile );
	
	$compiler->compile(true);
}
catch (Exception $e)
{
	echo $e->getMessage() . "\n";
	exit(1);
}

exit(0);
