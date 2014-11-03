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
	 * Loads the config file
	 *
	 */
	private function load()
	{
		// Load XML file
		$config = @simplexml_load_file( $this->configFile );
		
		// Check required fields
		if (!$config)						throw new \Exception("Cannot read configfile " . $this->configFile);
		if (!$config->database)				throw new \Exception("Element 'database' not found in " . $this->configFile);
		if (!$config->database['name'])		throw new \Exception("Field 'name' not found in " . $this->configFile);
		if (!$config->database['user'])		throw new \Exception("Field 'user' not found in " . $this->configFile);
		if (!$config->database['password'])	throw new \Exception("Field 'password' not found in " . $this->configFile);
		if (!$config->compiler)				throw new \Exception("Element 'compiler' not found in " . $this->configFile);
		if (!$config->compiler['api_key'])	throw new \Exception("Field 'api_key' not found in " . $this->configFile);
		if (!$config->compiler['input'])	throw new \Exception("Field 'input' not found in " . $this->configFile);
		if (!$config->compiler['output'])	throw new \Exception("Field 'output' not found in " . $this->configFile);
		
		// Import database fields
		$this->database = new \StdClass();
		$this->database->host		= ($config['host']) ? (string) $config['host'] : 'localhost';
		$this->database->name		= (string) $config['name'];
		$this->database->user		= (string) $config['user'];
		$this->database->password	= (string) $config['password'];
		
		// Import compiler fields
		$this->compiler = new \StdClass();
		$this->compiler->api_key	= (string) $config['api_key'];
		$this->compiler->input 		= (string) $config['input'];
		$this->compiler->output		= (string) $config['output'];
		$this->compiler->server		= ($config['server']) 	? (string) $config['server'] : self::DEFAULT_COMPILER;
		$this->compiler->version	= ($config['version']) 	? (string) $config['version'] : null;
		$this->compiler->logfile	= null;
		
		// Logfile needs special treatment 
		if ($config->compiler['logfile']) 
		{
			$path 	= pathinfo( (string) $config->compiler['logfile'] );
			$dir 	= realpath( $path['dirname'] );
			
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
