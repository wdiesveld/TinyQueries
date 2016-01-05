<?php
namespace TinyQueries;

/**
 * QuerySet
 *
 * Maintains a set of queries
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class QuerySet
{
	const PATH_INTERFACE 	= '/interface';
	const PATH_SOURCE		= '/tiny';
	const PATH_SQL 			= '/sql';
	
	private $project;
	private $pathQueries;
	private $labelQuerySet;
	private $callbacks;
	
	/**
	 * Constructor
	 *
	 * @param {string} $pathQueries
	 */
	public function __construct($pathQueries)
	{
		$this->path($pathQueries);
		
		$this->callbacks 	= array();
		$this->project		= null;
	}

	/**
	 * Gets/sets the label for the query set
	 *
	 * @param {string} $label
	 */
	public function label($label = -1)
	{
		if ($label != -1)
		{
			if ($label && !preg_match("/^[\w\-]+$/", $label))
				throw new \Exception("setLabel: No valid label value");
			
			$this->labelQuerySet = $label;
		}
		
		return $this->labelQuerySet;
	}	
	
	/**
	 * Gets/sets a callback function for a query
	 *
	 * @param {string} $queryID
	 * @param {function} $callback
	 */
	public function callback($queryID, $callback = null)
	{
		if (is_null($callback))
			return (array_key_exists($queryID, $this->callbacks))
				? $this->callbacks[ $queryID ]
				: null;
				
		$this->callbacks[ $queryID ] = $callback;
	}
	
	/**
	 * Gets/sets the path to the queries
	 *
	 * @param {string} $path
	 */
	public function path($path = null)
	{
		if ($path)
			$this->pathQueries = $path;	
	
		return 
			$this->pathQueries .
			(($this->labelQuerySet) ? "-" . $this->labelQuerySet : "");
	}
	
	/**
	 * Loads the content of a file
	 *
	 * @param {string} $filename
	 */
	public static function load($filename, $parseAsJSON = false)
	{
		if (!file_exists($filename)) 	
			throw new \Exception('Cannot find ' . $filename); 
		
		$content = @file_get_contents( $filename );
		
		if (!$content)
			throw new \Exception('File ' . $filename . ' is empty');
			
		if (!$parseAsJSON)
			return $content;
			
		// Replace EOL's and tabs by a space character (these chars are forbidden to be used within json strings)
		$content = preg_replace("/[\n\r\t]/", " ", $content);
			
		$json = @json_decode( $content );
		
		if (!$json)
			throw new \Exception("Error parsing JSON of " . $filename);
		
		return $json;
	}
	
	/**
	 * Gets all meta data related to the given query
	 *
	 * @param {string} $queryID
	 */
	public function getInterface($queryID)
	{
		$filename = $this->path() . self::PATH_INTERFACE . "/" . $queryID . ".json";

		try
		{
			return $this->load( $filename, true );
		}
		catch (\Exception $e)
		{
			// Throw more human readable message
			throw new \Exception("Cannot load query '" . $queryID . "' - maybe the name of the query is misspelled, the project might not be compiled yet or the file permissions of the queries folder are not set correctly");
		}
	}
	
	/**
	 * Gets the JSON file for the given query
	 *
	 * @param {string} $queryID
	 */
	public function json($queryID)
	{
		$filename = $this->path() . "/" . $queryID . ".json";
		
		return $this->load( $filename, true );
	}
	
	/**
	 * Returns the SQL-code which is associated with the given queryID
	 *
	 * @param {string} $queryID ID of the query
	 */
	public function sql($queryID)
	{
		$filename = $this->path() . self::PATH_SQL . "/" . $queryID . ".sql";

		return $this->load( $filename, false );
	}
	
	/**
	 * Returns the project info contained in _project.json
	 */
	public function project()
	{
		if ($this->project)
			return $this->project;
			
		$filename = $this->path() . self::PATH_INTERFACE . "/" . "_project.json";
		
		$this->project = $this->load( $filename, true );
		
		return $this->project;
	}
};

