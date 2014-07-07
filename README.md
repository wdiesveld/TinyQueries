# TinyQueries

TinyQueries is a framework to create any data structure out of standard relational databases. 
It uses a simple and powerful syntax to merge and nest SQL queries. 

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
* Use the file **QueryDB.template.xml** to set the database credentials and save it as **QueryDB.xml** in the same folder as the PHP libs.
* Create a folder **queries** and specify its path in **QueryDB.xml**.
* Include **QueryDB.class.php** in your project.
* Create the database object by:

	$db = new TinyQueries\QueryDB();

## Hello World

In the queries-folder create a json file named **helloWorld.json** with the following content:

	{
		"select": "'Hello World'"
	}

Then the query can be called as follows:

	$output = $db->query( "helloWorld" )->select();
	
	var_dump( $output );
	



	