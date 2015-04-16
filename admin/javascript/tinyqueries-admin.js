/**
 * TinyQueries Admin Tool
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
 
var admin = angular.module('TinyQueriesAdmin', ['ngCookies', 'ngRoute', 'ui.ace']);

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
		
		deleteQuery: function(queryID)
		{
			return $http.get('api/?_method=deleteQuery&query=' + queryID);
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
 */
admin.controller('main', ['$scope', '$api', '$cookies', function($scope, $api, $cookies)
{
	// Set scope vars
	$scope.view					= 'queries';
	$scope.nav 					= 'queries';
	$scope.tab					= 'edit';
	$scope.project				= null;
	$scope.globals				= {};
	$scope.error				= null;
	$scope.compileStatusCode 	= -1;
	$scope.compileStatus		= '';
	$scope.editmode				= false;
	$scope.mode					= 'view';
	$scope.showMessageBox		= false;
	$scope.newQueryIndex		= 0;
	
	$scope.$watch('compileStatusCode', function(value)
	{
		if (value != 0)
			return;
		
		$scope.refresh();
	});
	
	// tab needs to be set at the main controller to remember the tab if another query is selected
	$scope.setTab = function(tab)
	{
		$scope.tab = tab;
	};
	
	$scope.refreshMain = function()
	{
		$scope.refresh();
	};
	
	$scope.showError = function(error)
	{
		$scope.showMessageBox = true;
		$scope.error = error;
	};
	
	// Load project var
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
			$scope.showError( data.error );
		});
	};
	
	$scope.newQuery = function()
	{
		var queryID;
		
		do
		{
			$scope.newQueryIndex ++;
			queryID = 'new-query-' + $scope.newQueryIndex;
		} 
		while ($scope.project.queries[ queryID ]);

		window.location.replace( '#/queries/' + queryID );
	};
	
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


// not in use yet
admin.controller('message', ['$scope', function($scope)
{
	$scope.content = '';
}]);


/**
 * Controller for editor
 *
 */
admin.controller('AceCtrl', [ '$scope', function($scope)
{
	// The ui-ace option
	$scope.aceOption = 
	{
		onLoad: function (_ace) 
		{
			_ace.setTheme("ace/theme/chrome");
			_ace.session.setMode("ace/mode/javascript");	
			_ace.session.setOption("useWorker", false); // disable syntax checking
			
			// Set event handler for code changes
			_ace.session.on('change', function(e) 
			{
				if (!$scope.query)
					return;
				
				// If no source code is being loaded, it is assumed the change event was triggered by an edit
				if (!$scope.query.loading)
				{
					$scope.query.saveNeeded = true;
					return;
				}
				
				// Somehow, the source is loaded in 4 parts
				if ($scope.query.loading<4)
					$scope.query.loading++;
				else
					$scope.query.loading = 0;
			});
			
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
	
	$scope.initSource = function()
	{
		// Return if the source is already in memory
		if ($scope.query.source)
		{
			$scope.query.loading = 1;
			return;
		}
		
		// Return if there is no ID
		if (!$scope.query.id)
			return;

		// Load source file for existing queries
		$api.getSource( $scope.query.id ).success( function(data)
		{
			$scope.error = null;
			$scope.query.loading = 1;
			$scope.query.source = data;
		}).error( function(data)
		{
			$scope.showError( data.error );
		});
	};
	
	$scope.rename = function()
	{
		// TODO..
		$scope.renameMode = false;
	};
	
	$scope.delete = function()
	{
		$api.deleteQuery( $scope.query.id ).success( function(data)
		{
			// Query list is changed so update main controller
			$scope.refreshMain();
			
			// Go to home (wait before modal has been removed)
			setTimeout( function()
			{
				window.location.replace( '#/' );
			}, 1000);
		}).error( function(data)
		{
			$scope.showError( data.error );
		});
	};
	
	$scope.save = function()
	{
		$api.saveSource( $scope.query.id, $scope.query.source ).success( function(data)
		{
			// Query file might be changed so update main controller (e.g. for compile needed)
			$scope.refreshMain();
			
			$scope.query.saveNeeded = false;
		}).error( function(data)
		{
			$scope.showError( data.error );
		});
	};

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
		for (var p in $scope.globals)
			$cookies[p] = $scope.globals[p].value;
		
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
			$scope.errorRun = null;
			$scope.params = setValues( data.params, $cookies );
		}).error(function(data)
		{
			$scope.errorRun = data.error;
		});
	}
	
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
				status:			'new',
				expose: 		'public',
				type: 			null,
				defaultParam: 	null,
				operation: 		null,
				runnable:		false,
				source:			"/**\n *\n *\n */\n{\n\tselect: [],\n\tfrom: \"\"\n}",
				saveNeeded:		true
			};
		
		// Assign query as reference 
		if (!$scope.query)
			$scope.query = $scope.project.queries[ queryID ];
		else
		{
			// If query already exists only overwrite properties (otherwise source is reloaded which causes the cursor to be at the top)
			for (var prop in $scope.project.queries[ queryID ])
				$scope.query[ prop ] = $scope.project.queries[ queryID ][ prop ];
		}

		$scope.query.id = queryID;
		
		// Change tab if query is not runnable
		if (($scope.tab == 'run' || $scope.tab == 'doc') && !$scope.query.runnable)
			$scope.setTab('edit');
		
		if ($scope.editmode && $scope.tab == 'edit')
			$scope.initSource();
		
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
				$scope.error = data.error;
			});
	};
	
	// $scope.refresh();
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

