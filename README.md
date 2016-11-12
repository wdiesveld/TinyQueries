[![TinyQueries](http://tinyqueries.com/css/images/tiny-queries-logo-large.png)](http://www.tinyqueries.com/)

TinyQueries can be regarded as an alternative for Object Relation Mapping. It's based on two components:
* A query compiler - it's like a Less for SQL, so you create SQL queries by compiling them.
* A simple and powerful syntax to combine the output of SQL queries

## System Requirements

* Currently TinyQueries is only available for PHP. You need at least PHP 5.3
* PHP's PDO database layer should be installed
* PHP's cURL lib should be enabled
* A SQL database
* If you want to use the IDE you need a webserver, for example Apache

## Installation

NOTE: This package is especially usefull if you want to integrate TinyQueries with an existing PHP application. However if
you want to start from scratch it's recommended to use [TinyQueries API version] instead which requires less configuration.

1. If you use Composer then update or create your composer.json file as follows. Alternatively, instead of using Composer you could just download the ZIP-file and put the content in the folder where you put your libs.

	```json
	{
		"require": {
			"tiny-queries/tiny-queries": "*" 	
		}
	}
	```

1. Do the composer command, either ```composer install``` or ```php composer.phar install```

1. Create a folder ```queries``` in your project folder. Within this folder create three subfolders ```sql```, ```interface``` and ```tiny```. So you have:

	* ```queries``` the main query folder
	* ```queries/interface``` this folder will be used to store compiled json files
	* ```queries/sql``` this folder will be used to store compiled SQL files
	* ```queries/tiny``` this folder will be used to store your TinyQueries source files
	
	For security reasons ensure that this folder is not accessible through http (so in case you use Apache add a .htaccess file)

1. Use the file ```config/config.template.xml``` to set the database credentials and save it as ```config/config.xml```.

1. In ```config.xml``` you should specify the path to the folder ```queries/tiny``` in ```<compiler input="[path-to-queries-tiny-folder]" />```

1. In ```config.xml``` you should specify the path to the folder ```queries``` in ```<compiler output="[path-to-queries-folder]" />```

1. Create a file ```_model.json``` inside the folder ```queries/tiny``` which has the following content:

	```
	/**
	 * Model for my project
	 *
	 */
	{
	}
	```

1. Create a file ```_project.json``` inside the folder ```queries/tiny``` which has the following content:

	```
	/**
	 * Projectfile
	 *
	 */
	{
		id: "myproject"
	}
	```

Your project is now ready to be compiled. You can choose to compile using the online IDE or compile from the commandline.
Please check http://docs.tinyqueries.com for more info.

[TinyQueries API version]:https://github.com/wdiesveld/tiny-queries-php-api
