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
	
		compile: function()
		{
			return $http.get('api/?_method=compile');
		},
	
		getProject:	function()
		{
			return $http.get('api/?_method=getProject');
		},
		
		getQuery: function(queryID)
		{
			return $http.get('api/?_method=getInterface&query=' + queryID);
		},
		
		runQuery: function(term, params)
		{
			var apiParams = 
			{
				query: removeWhitespace(term),
				_profiling: 1
			};
			
			for (var id in params)
			{
				var p = params[ id ];
				
				var isArray = $.isArray( p.type )
							? ($.inArray('array', p.type) != -1)
							: (p.type == 'array');
				
				// Add [] for array parameters
				var name = (isArray)
					? id + '[]'
					: id;
				
				// Convert CSV to arrays
				apiParams[ name ] = (isArray)
					? ( (p.value) ? p.value.toString().split(',') : '')
					: ( (p.value || p.value===0) ? p.value : '');
			}
			
			return $http.get('api/', { params: apiParams }); 
		},
		
		getTermParams: function(term)
		{
			return $http.get('api/?_method=getTermParams&query=' + removeWhitespace(term));
		}
		
	};
}]);

 
/**
 * Main controller which is attached to the body element
 */
admin.controller('main', ['$scope', '$api', '$cookies', function($scope, $api, $cookies)
{
	// Set scope vars
	$scope.view					= 'queries';
	$scope.nav 					= 'queries';
	$scope.project				= {};
	$scope.globals				= {};
	$scope.error				= null;
	$scope.compileStatusCode 	= -1;
	$scope.compileStatus		= '';

	$scope.$watch('compileStatusCode', function(value)
	{
		if (value != 0)
			return;
		
		$scope.refresh();
	});
	
	// Initialize queries var
	$scope.refresh = function() 
	{
		$api.getProject().success( function(data)
		{
			$scope.project = data;
			
			// update query path
			setRestPaths( $scope.project );
			
			$scope.globals = setValues( reformatParams(data.globals), $cookies );
				
		}).error( function(data)
		{
			$scope.error = data.error;
		});
	};
	
	$scope.compile = function()
	{
		$scope.compileStatusCode = 1;
		$scope.compileStatus = 'Compiler is being called...';
		$api.compile().success(function(data)
		{
			$scope.compileStatusCode = 0;
			$scope.compileStatus = data.message;
			$scope.refresh();
		}).error(function(data)
		{
			$scope.compileStatusCode = 2;
			$scope.compileStatus = data.error;
		});
	}
	
	$scope.refresh();
}]);


// not in use yet
admin.controller('message', ['$scope', function($scope)
{
	$scope.content = '';
}]);


/**
 * Controller for query info
 */
admin.controller('query', ['$scope', '$api', '$cookies', '$routeParams', function($scope, $api, $cookies, $routeParams)
{
	$scope.query 		= {};
	$scope.error		= null;
	$scope.params		= {};
	$scope.tab			= 'run';
	$scope.queryTerm	= null;
	$scope.output		= '';
	$scope.profiling	= {};

	$scope.$watch('compileStatusCode', function(value)
	{
		if (value != 0)
			return;
			
		$scope.refresh();
	});
	
	$scope.numberOfParams = function()
	{
		var n=0;
		for (var p in $scope.params)
			n++;
			
		return n;
	};
	
	$scope.saveParams = function()
	{
		// Copy params to cookies
		for (var p in $scope.params)
			$cookies[p] = $scope.params[p].value;
		
	};
	
	$scope.updateParams = function()
	{
		if (!$scope.queryTerm)
		{
			$scope.params = {};
			return;
		}
		
		$api.getTermParams( $scope.queryTerm ).success( function(data)
		{
			$scope.error = null;
			$scope.params = setValues( data.params, $cookies );
		}).error(function(data)
		{
			$scope.error = data.error;
		});
	}
	
	$scope.run = function()
	{
		$scope.status = "Query is running...";
		
		$scope.saveParams();
		
		var params = {}; 
		
		// Merge params & globals
		for (var name in $scope.params)
			params[ name ] = $scope.params[ name ];
		for (var name in $scope.globals)
			params[ name ] = $scope.globals[ name ];
		
		$api.runQuery( $scope.queryTerm, params ).success( function(data)
		{
			$scope.error 	= null;
			$scope.output 	= data.result;
			$scope.nRows 	= (data.result && data.result.length) ? data.result.length + ' rows' : '';
			
			if (data.profiling)
				for (var pv in data.profiling)
					$scope.profiling[ pv ] = pv + ': ' + new String( data.profiling[pv] ).substr(0,5) + ' sec';
					
		}).error( function(data)
		{
			$scope.error 	= data.error;
			$scope.output 	= data;
			$scope.nRows 	= null;
			$scope.profiling = {};
		}).finally( function()
		{
			$scope.status 	= null;
		});
	};
	
	$scope.refresh = function()
	{
		var queryID = $routeParams.queryID;

		$scope.query.id = queryID;
		
		$api.getQuery( queryID ).success( function(data)
		{
			$scope.error 	= null;
			$scope.query 	= reformatQueryDef( data );
			$scope.query.id = queryID;
			$scope.query.path = getPath( $scope.query, queryID );
			$scope.query.method = getMethod( $scope.query );
			
			if ($scope.query && !$scope.queryTerm)
				$scope.queryTerm = $scope.query.id;
				
			// Set the parameters
			$scope.params = setValues( $scope.query.params, $cookies );
				
		}).error( function(data)
		{
			$scope.error = data.error;
		});
	};
	
	$scope.refresh();
}]);


/**
 * Does some changes to the params structure for the view template
 *
 */
function reformatParams( params )
{
	for (var p in params)
	{
		params[ p ].default_str =
			(params[ p ]['default'] === null)
			 ? 'null'
			 : params[ p ]['default'];
			
			
		if (!$.isArray( params[ p ].type ))
			params[ p ].type = [ params[ p ].type ];
			
		if (params[ p ].expose != 'public')
			delete params[ p ];
	}
	
	return params;
}

/**
 * Does some changes to the query json structure for the view template
 *
 */
function reformatQueryDef( query )
{
	if (query.doc)
		query.doc = query.doc.split("\n");
		
	if (!query.params)
		query.params = {};
	
	if (!query.output)
		query.output = {};
	
	query.params = reformatParams( query.params );

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
 * Set the values of the params (either by using the cookie or by default which is set for the param)
 *
 */
function setValues( params, cookies )
{
	var params1 = {};
	
	for (var p in params)
		if (params[p].expose == 'public')
		{
			params1[ p ] = params[p];
			params1[ p ].value = (cookies[p]) 
				? cookies[p] 
				: ( 
					(params[p]['default'] === null) 
						? 'null' 
						: params[p]['default'] 
					);
		}
		
	return params1;
}

/**
 * Removes all whitespace from the string
 */
function removeWhitespace(string)
{
	var s = new String(string);
	return s.replace(/\s+/g, '');
}
	
function setRestPaths(project)
{
	project.paths = {};
	
	for (var id in project.queries)
	if (project.queries[id].expose == 'public')
	{
		var path = getPath( project.queries[id], id );
		
		project.queries[id].method = getMethod( project.queries[id] ); 
		project.paths[ path ] = {
			queryID: id
		};
	}
}

function getMethod(query)
{
	switch (query.operation)
	{
		case 'read': 	return 'GET';
		case 'write': 	return 'UPDATE';
		case 'create': 	return 'POST';
		case 'delete': 	return 'DELETE';
	}
	
	return null;
}

function getPath(query, id)
{
	var parts = id.split(".");
		
	switch (query.type)
	{
		case 'nest':	
			path = "/" + parts[1] + "/:" + query.defaultParam + "/" + parts[0];	
			break;
		case 'filter':	
			path = "/" + parts[0] + "/" + parts[1];		
			break;
		case 'attach':	
			path = "/" + parts[0] + "+" + parts[1];		
			break;
		default:
			path = "/" + id;
			break;
	}
		
	if (query.defaultParam && query.type != 'nest')
		path += "/:" + query.defaultParam;
	
	return path;
}

