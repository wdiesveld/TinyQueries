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
			return $http.get('api/?method=compile');
		},
	
		getProject:	function()
		{
			return $http.get('api/?method=getProject');
		},
		
		getQuery: function(queryID)
		{
			return $http.get('api/?method=getInterface&query=' + queryID);
		},
		
		runQuery: function(term, params)
		{
			var apiParams = 
			{
				query: term,
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
								? 'param_' + id + '[]'
								: 'param_' + id;
				
				// Convert CSV to arrays
				apiParams[ name ] = (isArray)
					? ( (p.value) ? p.value.toString().split(',') : '')
					: ( (p.value || p.value===0) ? p.value : '');
			}
			
			return $http.get('api/', { params: apiParams }); 
		}
		
	};
}]);

 
/**
 * Main controller which is attached to the body element
 */
admin.controller('main', ['$scope', '$api', '$cookies', function($scope, $api, $cookies)
{
	// Set scope vars
	$scope.nav 					= 'queries';
	$scope.queries 				= {};
	$scope.globals 				= {};
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
			$scope.project	= data.id;
			$scope.globals 	= data.globals;
			$scope.queries 	= data.queries;
				
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
	$scope.query 		= {};
	$scope.tab			= 'run';
	$scope.queryTerm	= null;
	$scope.output		= '';

	$scope.$watch('compileStatusCode', function(value)
	{
		if (value != 0)
			return;
			
		$scope.refresh();
	});
	
	$scope.run = function()
	{
		$scope.status = "Query is running...";
		
//		$scope.saveParams();
		
		$api.runQuery( $scope.queryTerm, $scope.query.params ).success( function(data)
		{
			$scope.error 	= null;
			$scope.output 	= data.rows;
			$scope.nRows 	= (data.rows && data.rows.length) ? data.rows.length + ' rows' : '';
			
			if (data.profiling)
				for (var pv in data.profiling)
					$scope.profiling[ pv ] = pv + ': ' + new String( data.profiling[pv] ).substr(0,6) + ' sec';
					
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
			$scope.query 	= reformatQueryDef( data );
			$scope.query.id = queryID;
			
			if ($scope.query && !$scope.queryTerm)
				$scope.queryTerm = $scope.query.id;
		}).error( function(data)
		{
			$scope.error = data.error;
		});
	};
	
	$scope.refresh();
}]);

