[![TinyQueries](http://tinyqueries.com/css/images/tiny-queries-logo-large.png)](http://www.tinyqueries.com/)

TinyQueries is a framework to create any data structure out of standard relational databases. 
It uses a simple and powerful syntax to combine SQL queries. 

## Example

Suppose you want a nested data structure consisting of a list of users and for each user you want a list of the messages written by the user.
For this you write two queries **users** and **messages**. To nest the data you just need to write:

	users(messages)

## System Requirements

* Currently TinyQueries is only available for PHP. You need at least PHP 5.3.
* PHP's PDO database layer should be installed.
* A SQL database.

## Install

* Copy the folder **TinyQueries** into your php lib folder.
* Use the file **config/config.template.xml** to set the database credentials and save it as **config.xml**.
* Create a folder **queries** in your project folder. This folder will be used to store compiled SQL queries. In **config.xml** you should specify the path to this folder in <compiler output=".." />
* Include **QueryDB.class.php** in your project: 

	// Include lib
	require_once("[path-to-libs]/TinyQueries/libs/QueryDB.class.php");
			
	// Create database object
	$db = new TinyQueries\QueryDB();
				
	// Connect to database
	$db->connect();

## Hello World

In the queries-folder create a json file named **helloWorld.json** with the following content:

	{
		"select": "'World!' as 'hello'"
	}

Then the query can be called as follows:

	$output = $db->get( "helloWorld" );
	
$output will have the following structure (if converted to JSON):

	{
		"hello": "World!"
	}
	



	