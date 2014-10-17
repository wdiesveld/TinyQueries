<?php
/**
 * TinyQueries - Framework for merging and nesting relational data
 *
 * @author      Wouter Diesveld <wouter@tinyqueries.com>
 * @copyright   2012 - 2014 Diesveld Query Technology
 * @link        http://www.tinyqueries.com
 * @version     1.6a
 * @package     TinyQueries
 *
 * License
 *
 * This software is licensed under Apache License 2.0
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
 * LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
 * OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
 * WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */
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
	const PATH_SQL 			= '/sql';
	const PATH_INTERFACE 	= '/interface';
	
	private $queries;
	private $model;
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
		$this->queries		= null;
		$this->model		= null;
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
		{
			// Check if $path is a relative or absolute path
			$pathAbs = (preg_match('/^\./', $path))
							? realpath( dirname(__FILE__) . "/" . $path )
							: realpath( $path );
				
			if (!$pathAbs)
				throw new \Exception("QuerySet::path: cannot find path '" . $path . "'");
			
			$this->pathQueries = $pathAbs;	
		}
	
		return 
			$this->pathQueries .
			(($this->labelQuerySet) ? "-" . $this->labelQuerySet : "");
	}
	
	/**
	 * Gets all meta data related to the given query
	 *
	 * @param {string} $queryID
	 */
	public function getInterface($queryID)
	{
		$filename = $this->path() . self::PATH_INTERFACE . "/" . $queryID . ".json";

		return $this->load( $filename, true );
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
	 * Returns the list of queries
	 */
	public function queries()
	{
		if ($this->queries)
			return $this->queries;
			
		$filename = $this->path() . self::PATH_INTERFACE . "/" . "__index.json";
		
		$this->queries = $this->load( $filename, true );
		
		return $this->queries;
	}
	
	/**
	 * Returns the model
	 */
	public function model()
	{
		if ($this->model)
			return $this->model;
			
		$filename = $this->path() . "/" . "__model.json";
		
		try
		{
			$this->model = $this->load( $filename, true );
		}
		catch (\Exception $e)
		{
			// The model is optional, so don't throw an error if it cannot be loaded
			$this->model = new \StdClass();
		}
		
		return $this->model;
	}
	
	/**
	 * Loads a file
	 *
	 * @param {string} $filename
	 */
	private function load($filename, $parseAsJSON = false)
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
};

