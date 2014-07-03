# TinyQueries

TinyQueries is a framework to create any data structure out of standard relational databases. 
It uses a simple and powerful syntax to merge and nest SQL queries. 

## Example

Suppose you want a nested data structure consisting of a list of users and for each user you want a list of the messages written by the user.
For this you write two queries **users** and **messages** and define the key-fields. To nest the data you only have to write:

	users(messages)

## System Requirements

* Currently TinyQueries is only available in PHP. You need at least PHP 5.3.
* PHP's PDO database layer should be installed.
* A SQL database.