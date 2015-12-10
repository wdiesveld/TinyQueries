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
	const SQL_FILES			= 'sql';
	const INTERFACE_FILES 	= 'interface';
	const SOURCE_FILES 		= 'source';
	
	public $querySet;
	
	private $apiKey;
	private $folderInput;
	private $folderOutput;
	private $server;
	private $version;
	private $logfile;
	private $verbose;
	private $curlOutput;
	private $filesWritten;
	
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
		$this->filesWritten	= array();
	}
	
	/**
	 * Checks if the TinyQuery code has changed; if so calls the online compiler
	 *
	 * @param {boolean} $force (optional) Set this to true to call the compiler anyway
	 * @param {boolean} $doCleanUp (optional) If set to true, it will delete local sql files which are not in the compiler output
	 */
	public function compile($force = false, $doCleanUp = false)
	{
		if (!$force && !$this->compileNeeded())
			return;
		
		try
		{
			$this->callCompiler($doCleanUp);
		}
		catch (\Exception $e)
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
	public function compileNeeded()
	{
		// If there is no input folder specified we cannot know
		if (!$this->folderInput)
			return null;
		
		$project	= null;
		$qplChanged = 0;
		$sqlChanged = 0;
		
		try
		{
			$project = $this->querySet->project();
		}
		catch (\Exception $e)
		{
			// If there is no compiled project file, a compile is needed
			return true;
		}
		
		// If versions differ a compile is needed
		if ($project->compiledWith && $this->version && $project->compiledWith != $this->version)
			return true;
		
		list($dummy, $sourceFiles, $sourceIDs) = $this->getFolder( self::SOURCE_FILES );
		list($sqlPath, $sqlFiles)  = $this->getFolder( self::SQL_FILES );
		
		// Get max time of all source files
		foreach ($sourceFiles as $file)
		{
			$mtime = filemtime($file);
			if ($mtime > $qplChanged)
				$qplChanged = $mtime;
		}

		// Get max time of all sql files
		foreach ($sqlFiles as $file)
		{
			$mtime = filemtime($file);
			if ($mtime > $sqlChanged)
				$sqlChanged = $mtime;
		}

		if ($qplChanged > $sqlChanged)
			return true;
			
		// Check for source files which are deleted
		foreach ($project->queries as $queryID => $dummy)
			if (!in_array($queryID, $sourceIDs))
			{
				$sqlFile = $sqlPath . "/" . $queryID . ".sql";
				if (file_exists($sqlFile))
				{
					$mtime = filemtime($sqlFile);
					if ($mtime < $sqlChanged)
						return true; 
				}
			}
			
		return false;	
	}
	
	/**
	 * Returns the path + files + fileID's
	 *
	 */
	private function getFolder( $fileType )
	{
		$extension = null;
		
		switch ($fileType)
		{
			case self::SQL_FILES:
				$path = $this->querySet->path() . QuerySet::PATH_SQL;
				$extenstion = "sql"; 
				break;
				
			case self::INTERFACE_FILES:
				$path = $this->querySet->path() . QuerySet::PATH_INTERFACE;
				$extenstion = "json"; 
				break;
				
			case self::SOURCE_FILES:
				$path = $this->folderInput;
				$extenstion = "json"; 
				break;
				
			default: 
				throw new \Exception("getFolder: Unknown filetype");
		}
		
		$files 	= array();
		$ids	= array();
		$match	= null;
		
		foreach (scandir($path) as $file)
			if (preg_match('/^(.+)\.'.$extenstion.'$/', $file, $match))
			{
				$files[] = $path . "/" . $file;
				$ids[] 	 = $match[1];
			}
				
		return array($path, $files, $ids);
	}
	
	/**
	 * Calls the online TinyQueries compiler and updates the local SQL-cache
	 *
	 */
	private function callCompiler($doCleanUp)
	{
		// Reset array
		$this->filesWritten = array();
		
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
		list($dummy, $sourceFiles, $sourceIDs) = $this->getFolder( self::SOURCE_FILES );
		
		for ($i=0; $i<count($sourceFiles); $i++)
		{
			$content = @file_get_contents( $sourceFiles[ $i ] );
		
			if (!$content) 	
				throw new \Exception('Cannot read ' . $file);
				
			$sourceID = $sourceIDs[ $i ];
				
			$postBody .= "code[$sourceID]=" . urlencode( $content ) . "&";
		}
			
		// Catch curl output
		$curlOutputFile = "qpl-call.txt";
		
		$handle = @fopen($curlOutputFile, "w+");

		if ($handle)
			curl_setopt($ch, CURLOPT_STDERR, $handle);	

		curl_setopt($ch, CURLOPT_VERBOSE, true);
		curl_setopt($ch, CURLOPT_HEADER, true); 		// Return the headers
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);	// Return the actual reponse as string
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
		curl_setopt($ch, CURLOPT_URL, $this->server . '/api/compile/' );
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); // nodig omdat er anders een ssl-error is; waarschijnlijk moet er een intermediate certificaat aan curl worden gevoed.
		curl_setopt($ch, CURLOPT_HTTPHEADER,array("Expect:")); // To disable status 100 response 
		
		// Execute the API call
		$raw_data = curl_exec($ch); 

		curl_close($ch);
		
		// Read temp file for curl output
		if ($handle)
		{
			fclose($handle);
			$this->curlOutput = file_get_contents($curlOutputFile);
			@unlink($curlOutputFile);
		}
		
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
			
			$this->writeInterface( $queryID, $queryCode->query[$i]->{'interface'} );
			
			if (property_exists($queryCode->query[$i], 'sql'))
				$this->writeSQLfile( $queryID, $queryCode->query[$i]->sql );
		}
		
		// Write _project file
		if ($queryCode->{'interface'})
			$this->writeInterface( '_project', (string) $queryCode->{'interface'} );
			
		// Clean up files which were not in the compiler output
		if ($doCleanUp)
			foreach (array(self::SQL_FILES, self::INTERFACE_FILES) as $filetype)
			{
				list($path, $files) = $this->getFolder( $filetype );
				foreach ($files as $file)
					if (!in_array($file, $this->filesWritten))
					{
						$r = @unlink($file);
						if ($r)
							$this->log( 'Deleted ' . $file );
					}
			}
		
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
			
		$this->filesWritten[] = $filename;
	}
}
