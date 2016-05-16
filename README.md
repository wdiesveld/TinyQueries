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

1. If you use Composer then update or create your composer.json file as follows

	```
	{
		"require": {
			"tiny-queries/tiny-queries": "*" 	
		}
	}
	```

1. Do the composer command, either ```composer install``` or ```php composer.phar install```

1. Alternatively, instead of using Composer you could just download the ZIP-file and put the content in the folder where you put your libs.



	