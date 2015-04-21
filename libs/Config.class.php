<?php
namespace TinyQueries;

/**
 * Config
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class Config
{
	const DEFAULT_CONFIGFILE 	= '../config/config.xml';
	const DEFAULT_COMPILER 		= 'https://compiler1.tinyqueries.com';
	const VERSION_LIBS			= '{version}';

	public $compiler;
	public $database;
	public $postprocessor;
	
	private $configFile;
	
	/**
	 * Constructor
	 *
	 * @param {string} $configFile Optionally you can provide a config file
	 */
	public function __construct($configFile = null)
	{
		$this->configFile = ($configFile)
			? $configFile
			: dirname(__FILE__) . "/" . self::DEFAULT_CONFIGFILE;
			
		$this->load();
	}
	
	/**
	 * Returns the absolute path 
	 *
	 * @param {string} $path
	 */
	public static function pathAbs($path)
	{
		// Check if $path is a relative or absolute path
		$pathAbs = ($path && preg_match('/^\./', $path))
			? realpath( dirname(__FILE__) . "/" . $path )
			: realpath( $path );
			
		if (!$pathAbs)
			throw new \Exception("Cannot find path '" . $path . "'");
			
		return $pathAbs;
	}
	
	/**
	 * Loads the config file
	 *
	 */
	private function load()
	{
		// Load XML file
		$config = @simplexml_load_file( $this->configFile );
		
		// Check required fields
		if (!$config)						throw new \Exception("Cannot read configfile " . $this->configFile);
		if (!$config->database)				throw new \Exception("Tag 'database' not found in " . $this->configFile);
		if (!$config->database['name'])		throw new \Exception("Field 'name' not found in database tag of " . $this->configFile);
		if (!$config->database['user'])		throw new \Exception("Field 'user' not found in database tag of " . $this->configFile);
		if (!$config->database['password'])	throw new \Exception("Field 'password' not found in database tag of " . $this->configFile);
		if (!$config->compiler)				throw new \Exception("Tag 'compiler' not found in " . $this->configFile);
		if (!$config->compiler['output'])	throw new \Exception("Field 'output' not found in compiler tag of " . $this->configFile);
		
		// Import database fields
		$this->database = new \StdClass();
		$this->database->host		= ($config->database['host']) ? (string) $config->database['host'] : 'localhost';
		$this->database->name		= (string) $config->database['name'];
		$this->database->user		= (string) $config->database['user'];
		$this->database->password	= (string) $config->database['password'];
		
		// Import compiler fields
		$this->compiler = new \StdClass();
		$this->compiler->api_key	= (string) $config->compiler['api_key'];
		$this->compiler->input 		= ($config->compiler['input']) ? self::pathAbs( $config->compiler['input'] ) : null;
		$this->compiler->output		= self::pathAbs( (string) $config->compiler['output'] );
		$this->compiler->server		= ($config->compiler['server']) 	? (string) $config->compiler['server'] : self::DEFAULT_COMPILER;
		$this->compiler->version	= ($config->compiler['version']) 	? (string) $config->compiler['version'] : null;
		$this->compiler->logfile	= null;
		
		// Add "v" to version if missing
		if ($this->compiler->version && !preg_match("/^v/", $this->compiler->version))
			$this->compiler->version = "v" . $this->compiler->version;
		
		// Logfile needs special treatment 
		if ((string) $config->compiler['logfile']) 
		{
			$path = pathinfo( (string) $config->compiler['logfile'] );
			
			if (!$path || !array_key_exists('dirname', $path))
				throw new \Exception("Configfile " . $this->configFile . ": Path of logfile does not exist");
			
			$dir = realpath( $path['dirname'] );
			
			if (!$dir)
				throw new \Exception("Configfile " . $this->configFile . ": Path of logfile does not exist");
			
			$filename = (array_key_exists('filename', $path))
							? $path['filename'] . "." . $path['extension']
							: 'compiler.log';

			$this->compiler->logfile = $dir . "/" . $filename;
		}

		// Import postprocessor fields
		$this->postprocessor = new \StdClass();
		$this->postprocessor->nest_fields = 
			($config->postprocessor && 
			 $config->postprocessor['nest_fields'] && 
			 strtolower( $config->postprocessor['nest_fields'] ) == 'false')
			? false
			: true;
	}
};
