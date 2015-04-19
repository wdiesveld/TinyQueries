/**
 * TinyQueries Admin Tool
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
 
var admin = angular.module('TinyQueriesAdmin', ['ngCookies', 'ngRoute', 'ui.ace']);

/**
 * Specification of the URL routes
 *
 */
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
 *
 */
admin.factory('$api', ['$http', function($http)
{
	/**
	 * Returns the interface for the api object
	 */
	return {
	
		testApi: function()
		{
			return $http.get('api/?_method=testApi');
		},
	
		compile: function()
		{
			return $http.get('api/?_method=compile');
		},
		
		deleteQuery: function(queryID)
		{
			return $http.get('api/?_method=deleteQuery&query=' + queryID);
		},
		
		renameQuery: function(queryIDold, queryIDnew, source)
		{
			// NOTE: The source is always saved when renaming, otherwise not saved edits might be lost
			return $http(
			{
				method: 'POST',
				url: 'api/', 
				data: $.param(
				{ 
					_method: 'renameQuery', 
					query_old: queryIDold, 
					query_new: queryIDnew,
					source: source 
				}),
				headers: 
				{
					'Content-Type': 'application/x-www-form-urlencoded'
				}
			});
		},
	
		getProject:	function()
		{
			return $http.get('api/?_method=getProject');
		},
		
		getQuery: function(queryID)
		{
			return $http.get('api/?_method=getInterface&query=' + queryID);
		},
		
		getSource: function(sourceID)
		{
			return $http.get('api/?_method=getSource&sourceID=' + sourceID);
		},
		
		saveSource: function(sourceID, source)
		{
			return $http(
			{
				method: 'POST',
				url: 'api/', 
				data: $.param(
				{ 
					_method: 'saveSource', 
					sourceID: sourceID, 
					source: source 
				}),
				headers: 
				{
					'Content-Type': 'application/x-www-form-urlencoded'
				}
			});
		},
		
		runQuery: function(call, params)
		{
			var apiParams = 
			{
				_profiling: 1
			};

			for (p in call)
				apiParams[ p ] = removeWhitespace( call[p] );
			
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
 *
 */
admin.controller('main', ['$scope', '$api', '$cookies', '$routeParams', function($scope, $api, $cookies, $routeParams)
{
	// Set scope vars
	$scope.view					= 'queries';
	$scope.nav 					= 'queries';
	$scope.tab					= 'run';
	$scope.project				= null;
	$scope.globals				= {};
	$scope.error				= null;
	$scope.compileStatusCode 	= -1;
	$scope.compileStatus		= '';
	$scope.editmode				= false;
	$scope.mode					= 'view';
	$scope.showMessageBox		= false;
	$scope.newQueryIndex		= 0;
	$scope.sourceIDselected		= null;
	
	// Basic check if api is working
	$api.testApi().success( function(data)
	{
		if (!data.message)
			$scope.error = "API for admintool is not working; there might be a PHP error: " + data;
		
	}).error( function(data)
	{
		$scope.error = "API for admintool is not working; check webserver settings for admin/api folder";
	});
	
	$scope.$watch('compileStatusCode', function(value)
	{
		if (value != 0)
			return;
		
		$scope.refresh();
	});
	
	$scope.$on('$routeChangeSuccess', function() 
	{
		$scope.sourceIDselected	= $routeParams.queryID;
	});	
	
	/**
	 * Sets tab of query controller
	 * (needs to be in main controller to remember the tab when another query is selected)
	 *
	 */
	$scope.setTab = function(tab)
	{
		$scope.tab = tab;
	};
	
	/**
	 * Update function for this controller; loads the project info
	 *
	 */
	$scope.refresh = function() 
	{
		$api.getProject().success( function(data)
		{
			$scope.project = data;
			$scope.editmode = (data.mode == 'edit');
			$scope.mode = data.mode;
			
			// update query path
			setRestPaths( $scope.project );
			
			$scope.globals = setValues( reformatParams(data.globals), $cookies );
				
		}).error( function(data)
		{
			$scope.error = data.error;
		});
	};
	
	/**
	 * Alias to be used in child controllers
	 *
	 */
	$scope.refreshMain = function()
	{
		$scope.refresh();
	};
	
	/**
	 * Handler for new query button
	 *
	 */
	$scope.newQuery = function()
	{
		var queryID;
		
		do
		{
			$scope.newQueryIndex ++;
			queryID = 'new-query-' + $scope.newQueryIndex;
		} 
		while ($scope.project.queries[ queryID ]);

		// Just go to the URL; the query controller will initialize the new query
		window.location.replace( '#/queries/' + queryID );
	};
	
	/**
	 * Handler for compile button
	 *
	 */
	$scope.compile = function()
	{
		$scope.showMessageBox = true;
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
	};
	
	$scope.refresh();
}]);

/**
 * Controller for ace editor
 * The content of the editor is binded to $scope.query.source
 *
 */
admin.controller('AceCtrl', [ '$scope', function($scope)
{
	// Set the options for the ace editor
	$scope.aceOption = 
	{
		onLoad: function (_ace) 
		{
			_ace.setTheme("ace/theme/chrome");
			_ace.session.setMode("ace/mode/javascript");	
			_ace.session.setOption("useWorker", false); // disable syntax checking
			
			// Set key for save
			_ace.commands.addCommand(
			{
				name: 'Save',
				bindKey: {win: 'Ctrl-S',  mac: 'Command-S'},
				exec: function(editor) 
				{
					$scope.save();
				},
				readOnly: false
			}); 
		}
	};
}]);

/**
 * Controller for query 
 *
 */
admin.controller('query', ['$scope', '$api', '$cookies', '$routeParams', function($scope, $api, $cookies, $routeParams)
{
	$scope.query 		= null;
	$scope.errorRun		= null;
	$scope.params		= {};
	$scope.queryTerm	= null;
	$scope.output		= '';
	$scope.profiling	= {};
	$scope.renameMode	= false;
	$scope.sourceInitializing = false;
	
	$scope.$watch('compileStatusCode', function(value)
	{
		if (value != 0)
			return;
			
		$scope.refresh();
	});
	
	$scope.$watch('project', function(value)
	{
		if (!value)
			return;

		$scope.refresh();
	});
	
	// Needed to keep track of saveNeeded
	$scope.$watch('query.source', function(value)
	{
		if (!$scope.query)
			return;
		
		// Skip this watch when controller is still initializing the source
		if ($scope.sourceInitializing)	
		{
			// Once there is a value, initializing has finished
			if (value)
				$scope.sourceInitializing = false;
				
			return;
		}
			
		$scope.query.saveNeeded = true;
	});
	
	/**
	 * Handler for rename query
	 *
	 */
	$scope.rename = function()
	{
		var oldName = $routeParams.queryID;
		
		// If name has not changed do nothing
		if (oldName == $scope.query.id)
		{
			$scope.renameMode = false;
			return;
		}
		
		$api.renameQuery( oldName, $scope.query.id, $scope.query.source ).success( function(data)
		{
			// Update URL and query list
			$scope.renameMode = false;
			window.location.replace( '#/queries/' + $scope.query.id );
			$scope.refreshMain();
			$scope.error = null;
			
		}).error( function(data)
		{
			$scope.error = data.error;
		});
	};
	
	/**
	 * Handler for cancelling rename query
	 *
	 */
	$scope.renameCancel = function()
	{
		$scope.renameMode = false;
		$scope.error = null;
		
		// Reset to the old queryID
		$scope.query.id = $routeParams.queryID;
	};
	
	/**
	 * Handler for delete query
	 *
	 */
	$scope.delete = function()
	{
		$api.deleteQuery( $scope.query.id ).success( function(data)
		{
			$scope.error = null;
			
			// Go to home (wait before modal has been removed)
			setTimeout( function()
			{
				window.location.replace( '#/' );
				
				$scope.$apply( function()
				{
					// Query list is changed so update main controller
					$scope.refreshMain();
				});
				
			}, 500);
		}).error( function(data)
		{
			$scope.error = data.error;
		});
	};
	
	/**
	 * Handler for save query
	 *
	 */
	$scope.save = function()
	{
		$api.saveSource( $scope.query.id, $scope.query.source ).success( function(data)
		{
			$scope.error = null;
			$scope.query.saveNeeded = false;
			$scope.project.compiler.compileNeeded = true;
		}).error( function(data)
		{
			$scope.error = data.error;
		});
	};

	/**
	 * Helper function
	 *
	 */
	$scope.numberOfParams = function()
	{
		var n=0;
		for (var p in $scope.params)
			n++;
			
		return n;
	};
	
	/**
	 * Save parameter values into cookies
	 *
	 */
	$scope.saveParams = function()
	{
		// Copy params to cookies
		for (var p in $scope.params)
			$cookies[p] = $scope.params[p].value;
		for (var p in $scope.globals)
			$cookies[p] = $scope.globals[p].value;
		
	};
	
	/**
	 * Handler for update params button
	 *
	 */
	$scope.updateParams = function()
	{
		if (!$scope.queryTerm)
		{
			$scope.params = {};
			return;
		}
		
		$api.getTermParams( $scope.queryTerm ).success( function(data)
		{
			$scope.errorRun = null;
			$scope.params = setValues( data.params, $cookies );
		}).error(function(data)
		{
			$scope.errorRun = data.error;
		});
	}
	
	/**
	 * Handler for run button
	 *
	 */
	$scope.run = function()
	{
		$scope.status = "Query is running...";
		
		$scope.saveParams();
		
		var params = {}; 
		// Merge params & globals
		for (var name in $scope.params)
			// Leaf out defaultParam for REST, since this param is already sent through the URL
			if ($scope.view != 'rest' && name != $scope.defaultParam)
				params[ name ] = $scope.params[ name ];
		for (var name in $scope.globals)
			params[ name ] = $scope.globals[ name ];
			
		var call = ($scope.view == 'rest')
			? { _path: $scope.query.path }
			: { query: $scope.queryTerm };
		
		$api.runQuery( call, params ).success( function(data)
		{
			$scope.errorRun	= null;
			$scope.output 	= data.result;
			$scope.nRows 	= (data.result && data.result.length) ? data.result.length + ' rows' : '';
			
			if (data.profiling)
				for (var pv in data.profiling)
					$scope.profiling[ pv ] = pv + ': ' + new String( data.profiling[pv] ).substr(0,5) + ' sec';
					
		}).error( function(data)
		{
			$scope.errorRun	= data.error;
			$scope.output 	= data;
			$scope.nRows 	= null;
			$scope.profiling = {};
		}).finally( function()
		{
			$scope.status 	= null;
		});
	};
	
	/**
	 * Generic refresh function for this controller
	 *
	 */
	$scope.refresh = function()
	{
		// As long as the project info is not loaded, don't do refresh
		if (!$scope.project)
			return;
			
		// Get the queryID from the URL
		var queryID = $routeParams.queryID;
		
		// If the query is not present in the list, create a new one
		if (!$scope.project.queries[ queryID ])
			$scope.project.queries[ queryID ] = 
			{
				id:				queryID,
				status:			'new',
				expose: 		'public',
				type: 			null,
				defaultParam: 	null,
				operation: 		null,
				runnable:		false,
				source:			"/**\n *\n *\n */\n{\n\tselect: [],\n\tfrom: \"\"\n}",
				saveNeeded:		true
			};
			
		// This var must be set before the next assignment, because
		// query.source might change by that assignment
		// which causes the $watch the apply
		$scope.sourceInitializing = true;
			
		// Assign query as reference 
		$scope.query = $scope.project.queries[ queryID ];
		
		// Change tab if query is not runnable
		if (($scope.tab == 'run' || $scope.tab == 'doc') && !$scope.query.runnable)
			$scope.setTab('edit');
		
		// Load source file for existing queries
		if ($scope.editmode && $scope.tab == 'edit' && queryID && !$scope.query.source)
		{
			$api.getSource( $scope.query.id ).success( function(data)
			{
				$scope.query.source = data;
			}).error( function(data)
			{
				$scope.error = data.error;
			});
		}

		// Load compiled query interface-file
		if ($scope.query.runnable)
			$api.getQuery( queryID ).success( function(data)
			{
				var query = reformatQueryDef( data );
				
				// Override query props
				for (var prop in query)
					$scope.query[ prop ] = query[ prop ];
				
				$scope.errorRun	= null;
				
				$scope.query.id = queryID;
				$scope.query.path = getPath( $scope.query, queryID );
				$scope.query.method = getMethod( $scope.query );
				
				if (query && !$scope.queryTerm)
					$scope.queryTerm = $scope.query.id;
					
				// Set the parameters
				$scope.params = setValues( $scope.query.params, $cookies );
					
			}).error( function(data)
			{
				$scope.errorRun = data.error;
			});
	};
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
 * Reformats the output fields for easier parsing in the template
 */
function reformatOutputFields(fields, level, parent)
{
	for (var f in fields)
	{
		// Convert to object if not yet object
		if (!$.isPlainObject( fields[f] ))
		{
			// substitute 'json' for 'array' 
			if (fields[f] == 'json')
				fields[f] = 'array';
				
			fields[f] = 
			{
				type: fields[f]
			};
		}
		
		fields[ f ].label 	= f;
		fields[ f ].level 	= level;
		fields[ f ].parent 	= parent;
		fields[ f ].showsub	= false;
		
		if (!$.isArray( fields[ f ].type ))
			fields[ f ].type = [ fields[ f ].type ];
		
		// Add subfields
		if (fields[ f ].fields)
			reformatOutputFields( fields[ f ].fields, level + 1, f );
	}
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
	
	query.params = reformatParams( query.params );

	if (query.output)
		reformatOutputFields( query.output.fields, 0, null );
	
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
	
/**
 * Derives the REST path & method for each query
 */
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

/**
 * Gets the HTTP method corresponding to the query oparation
 */
function getMethod(query)
{
	switch (query.operation)
	{
		case 'create': 	return 'POST';
		case 'read': 	return 'GET';
		case 'update': 	return 'PUT';
		case 'delete': 	return 'DELETE';
	}
	
	return null;
}

/**
 * Transforms a queryID into a REST path
 */
function getPath(query, id)
{
	var parts = id.split(".");
	var defaultParamSet = false;
	
	switch (query.type)
	{
		case 'nest':	
			path = "/" + parts[1] + "/:" + query.defaultParam + "/" + parts[0];	
			defaultParamSet = true;
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
		
	if (query.defaultParam && !defaultParamSet)
		path += "/:" + query.defaultParam;
	
	return path;
}

