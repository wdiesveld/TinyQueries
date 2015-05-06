[![TinyQueries](http://tinyqueries.com/css/images/tiny-queries-logo-large.png)](http://www.tinyqueries.com/)

TinyQueries can be regarded as an alternative for Object Relation Mapping. It's based on two components:
* A query compiler - it's like a Less for SQL, so you create SQL queries by compiling them.
* A simple and powerful syntax to combine the output of SQL queries

## Example

Suppose you want a nested data structure consisting of a list of users and for each user you want a list of the messages written by the user.
For this you write two queries **users** and **messages**. To nest the data you just need to write:

	users(messages)
	
## System Requirements

* Currently TinyQueries is only available for PHP. You need at least PHP 5.3
* PHP's PDO database layer should be installed
* PHP's cURL lib should be enabled
* A SQL database
* If you want to use the IDE you need a webserver, for example Apache

## Get Started

TinyQueries is currently in beta. You can get an API-key for the online compiler on request.
Go to http://www.tinyqueries.com/signup

	