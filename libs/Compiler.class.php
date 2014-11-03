<?php
namespace TinyQueries;

require_once("Config.class.php");
require_once("QuerySet.class.php");

/**
 * Compiler
 *
 * Interface for the online TinyQueries compiler
 * CURL needs to be enabled
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 *
 */
class Compiler
{
	private $apiKey;
	private $folderInput;
	private $folderOutput;
	private $server;
	private $version;
	private $logfile;
	private $querySet;
	private $verbose;
	private $curlOutput;
	
	/**
	 * Constructor
	 *
	 * @param {string} $configFile Optionally you can provide a config file
	 */
	public function __construct($configFile = null)
	{
		$config = new Config( $configFile );
		
		// Import settings
		$this->apiKey		= $config->compiler->api_key;
		$this->folderInput 	= $config->compiler->input;
		$this->folderOutput	= $config->compiler->output;
		$this->server		= $config->compiler->server;
		$this->version		= $config->compiler->version;
		$this->logfile		= $config->compiler->logfile;
		$this->querySet 	= new QuerySet( $this->folderOutput );
		$this->verbose		= true;
	}
	
	/**
	 * Checks if the TinyQuery code has changed; if so calls the online compiler
	 *
	 * @param {boolean} $force (optional) Set this to true to call the compiler anyway
	 */
	public function compile($force = false)
	{
		if (!$force && !$this->codeChanged())
			return;
		
		try
		{
			$this->callCompiler();
		}
		catch (Exception $e)
		{
			$this->log( $e->getMessage() );
			if ($this->curlOutput)
				$this->log( $this->curlOutput );
				
			throw $e;
		}
	}
	
	/**
	 * Checks whether there are changes made in either the model or the queries file
	 *
	 */
	public function codeChanged()
	{
		$sqlPath	= $this->querySet->path() . QuerySet::PATH_SQL;
		$qplChanged = 0;
		$sqlChanged = 0;
		
		// Get max time of all project files
		foreach ($this->inputFiles() as $file)
		{
			$mtime = filemtime($this->folderInput . "/" . $file);
			if ($mtime > $qplChanged)
				$qplChanged = $mtime;
		}

		// Get max time of all sql files
		foreach (scandir($sqlPath) as $file)
		if (preg_match('/\.sql$/', $file))
		{
			$mtime = filemtime($sqlPath . "/" . $file);
			if ($mtime > $sqlChanged)
				$sqlChanged = $mtime;
		}

		return ($qplChanged > $sqlChanged);
	}
	
	/**
	 * Reads the filenames of all project files
	 *
	 */
	private function inputFiles()
	{
		$inputFiles = array();

		foreach (scandir($this->folderInput) as $file)
			if (preg_match('/\.json$/', $file))
				$inputFiles[] = $file;
				
		return $inputFiles;
	}
	
	/**
	 * Calls the online TinyQueries compiler and updates the local SQL-cache
	 *
	 */
	private function callCompiler()
	{
		// Update log-file
		$this->log('Compiler being called..');

		// Init CURL
		$this->curlOutput = null;
		$ch = curl_init();

		if (!$ch) 
			throw new \Exception( 'Cannot initialize curl' );
		
		// Set post message 
		$postBody = 
			"api_key=" . urlencode( $this->apiKey ) 	. "&" .
			"version=" . urlencode( $this->version )	. "&" ;
		
		// Read project files and add them to the postBody
		foreach ($this->inputFiles() as $file)
		{
			$content = @file_get_contents( $this->folderInput . "/" . $file );
		
			if (!$content) 	
				throw new \Exception('Cannot read ' . $file);
				
			$codeID = preg_replace("/\.json$/", "", $file);
				
			$postBody .= "code[$codeID]=" . urlencode( $content ) . "&";
		}
			
		// Catch curl output
		$curlOutputFile = "qpl-call.txt";
		
		$handle = fopen($curlOutputFile, "w+");

		curl_setopt($ch, CURLOPT_VERBOSE, true);
		curl_setopt($ch, CURLOPT_STDERR, $handle);	
		curl_setopt($ch, CURLOPT_HEADER, true); 		// Return the headers
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);	// Return the actual reponse as string
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
		curl_setopt($ch, CURLOPT_URL, $this->server);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); // nodig omdat er anders een ssl-error is; waarschijnlijk moet er een intermediate certificaat aan curl worden gevoed.
		curl_setopt($ch, CURLOPT_HTTPHEADER,array("Expect:")); // To disable status 100 response 
		
		// Execute the API call
		$raw_data = curl_exec($ch); 

		curl_close($ch);
		
		// Read temp file for curl output
		fclose($handle);
		$this->curlOutput = file_get_contents($curlOutputFile);
		@unlink($curlOutputFile);
		
		$status = null;
		
		if ($raw_data === false) 
			throw new \Exception('Did not receive a response from the online TinyQueryCompiler; no internet? - SQL-files are NOT updated');
		
		// Split the headers from the actual response
		$response = explode("\r\n\r\n", $raw_data, 2);
			
		// Find the HTTP status code
		$matches = array();
		if (preg_match('/^HTTP.* ([0-9]+) /', $response[0], $matches)) 
			$status = intval($matches[1]);

		if ($status != 200)
		{
			$error = @simplexml_load_string( $response[1] ); 
			$errorMessage = ($error)
								? $error->message
								: 'Received status '.$status." - ". $response[1];
								
			throw new \Exception( $errorMessage );
		}
		
		// Unfortunately, the xml-code needs to be parsed twice in order to handle the CDATA-blocks
		$queryIDs 	= @simplexml_load_string( $response[1] ); 
		$queryCode	= @simplexml_load_string( $response[1] , 'SimpleXMLElement', LIBXML_NOCDATA ); 
		
		if ($queryIDs===false || $queryCode===false) 
		{
			$errorMsg = 'Error parsing xml coming from the TinyQueryCompiler - please visit www.tinyqueries.com for support.';
			
			if ($this->verbose) 
				$errorMsg .= '\n\nResponse:\n\n' . $response[1];
			
			throw new \Exception( $errorMsg );
		}

		// Update sql & interface-files
		for ($i=0;$i<count($queryIDs->query);$i++)
		{
			$queryID = $queryIDs->query[$i]->attributes()->id;
			
			$this->writeSQLfile( $queryID, $queryCode->query[$i]->sql );
			$this->writeInterface( $queryID, $queryCode->query[$i]->{'interface'} );
		}
		
		if ($queryCode->{'interface'})
			$this->writeInterface( '_project', (string) $queryCode->{'interface'} );
		
		// Update log-file
		$this->log('SQL-files updated successfully');
	}
	
	/**
	 * Writes a message to the logfile (if present)
	 *
	 * @param {string} $message
	 */
	private function log($message)
	{
		if (!$this->logfile) return;
		
		$message = "[" . date('Y-m-d H:i:s') . "] " . $message . "\n";
		
		@file_put_contents( $this->logfile, $message, FILE_APPEND);
	}

	/**
	 * Writes the interface file
	 *
	 * @param {string} $fileID 
	 * @param {string} $interface
	 */
	private function writeInterface($fileID, $interface)
	{
		$filename = $this->querySet->path() . QuerySet::PATH_INTERFACE . "/" . $fileID . ".json";

		$this->writeFile( $filename, $interface );
	}
	
	/**
	 * Creates a .sql file containing the query. The name of the file will be [$queryID].sql
	 *
	 * @param {string} $fileID 
	 * @param {string} $sqlCode
	 */
	private function writeSQLfile($fileID, $sqlCode)
	{
		$filename = $this->querySet->path() . QuerySet::PATH_SQL . "/" . $fileID . ".sql";
			
		$this->writeFile( $filename, $sqlCode );
	}

	/**
	 * Writes $content to $filename
	 *
	 * @param {string} $filename
	 * @param {string} $content
	 */
	private function writeFile($filename, $content)
	{
		$r = @file_put_contents($filename, (string) $content);
			
		if (!$r) 
			throw new \Exception('Error writing ' . $filename . ' -  are the permissions set correctly?' );
	}
}
