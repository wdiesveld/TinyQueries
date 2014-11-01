<?php

require_once('../libs/Compiler.class.php');

/**
 * Compile script for command line compiling of TinyQueries 
 *
 */
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
