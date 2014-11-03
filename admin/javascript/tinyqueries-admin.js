/**
 * TinyQueries Admin Tool
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
 
var admin = angular.module('TinyQueriesAdmin', ['ngCookies', 'ngRoute']);

admin.config(['$routeProvider',
	function($routeProvider) 
	{
		$routeProvider
		.when('/', 
		{
			templateUrl: 'templates/home.html'
		})
		.when('/queries/:queryID', 
		{
			templateUrl: 'templates/query.html'
		})
		.otherwise(
		{
			redirectTo: ''
		});
	}
]);

/**
 * Service for the api
 */
admin.factory('$api', ['$http', function($http)
{
	/**
	 * Returns the interface for the api object
	 */
	return {
		getProject:	getProject,
		getQuery:	getQuery
	};
	
	function getProject()
	{
		return $http.get('api/?method=getProject');
	}
	
	function getQuery(queryID)
	{
		return $http.get('api/?method=getInterface&query=' + queryID);
	}
}]);

 
/**
 * Main controller which is attached to the body element
 */
admin.controller('main', ['$scope', '$api', '$cookies', function($scope, $api, $cookies)
{
	// Set scope vars
	$scope.nav 		= 'queries';
	$scope.queries 	= {};
	$scope.globals 	= {};
	$scope.error	= null;
	$scope.config	= null;

	// Initialize queries var
	$scope.refresh = function() 
	{
		$api.getProject().success( function(data)
		{
			$scope.project	= data.id;
			$scope.globals 	= data.globals;
			$scope.queries 	= data.queries;
				
		}).error( function(data)
		{
			$scope.error = data.error;
		});
	};
	
	$scope.refresh();
}]);


// not in use yet
admin.controller('message', ['$scope', function($scope)
{
	$scope.content = '';
}]);


/**
 * Does some changes to the query json structure for the view template
 *
 */
function reformatQueryDef( query )
{
	if (query.doc)
		query.doc = query.doc.split("\n");
	
	for (var p in query.params)
	{
		query.params[ p ].default_str =
			(query.params[ p ]['default'] === null)
			 ? 'null'
			 : query.params[ p ]['default'];
			
			
		if (!$.isArray( query.params[ p ].type ))
			query.params[ p ].type = [ query.params[ p ].type ];
			
		if (query.params[ p ].expose != 'public')
			delete query.params[ p ];
			
	}
	
	for (var f in query.output.fields)
	{
		if (!$.isPlainObject( query.output.fields[f] ))
		{
			// substitute 'json' for 'array' 
			if (query.output.fields[f] == 'json')
				query.output.fields[f] = 'array';
				
			query.output.fields[f] = 
			{
				type: query.output.fields[f]
			};
		}
		
		if (!$.isArray( query.output.fields[ f ].type ))
			query.output.fields[ f ].type = [ query.output.fields[ f ].type ];
	}
	
	return query;
}

/**
 * Controller for query info
 */
admin.controller('query', ['$scope', '$api', '$cookies', '$routeParams', function($scope, $api, $cookies, $routeParams)
{
	$scope.query = {};

	$scope.refresh = function()
	{
		var queryID = $routeParams.queryID;

		$scope.query.id = queryID;
		
		$api.getQuery( queryID ).success( function(data)
		{
			$scope.query 	= reformatQueryDef( data );
			$scope.query.id = queryID;
				
		}).error( function(data)
		{
			$scope.error = data.error;
		});
	};
	
	$scope.refresh();
}]);

