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
	$scope.tab					= 'run';
	$scope.project				= null;
	$scope.globals				= {};
	$scope.error				= null;
	$scope.compileStatusCode 	= -1;
	$scope.compileStatus		= '';
	$scope.editmode				= false;
	
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
	
	// Initialize queries var
	$scope.refresh = function() 
	{
		$api.getProject().success( function(data)
		{
			$scope.project = data;
			$scope.editmode = (data.mode == 'edit');
			
			// update query path
			setRestPaths( $scope.project );
			
			$scope.globals = setValues( reformatParams(data.globals), $cookies );
				
		}).error( function(data)
		{
			$scope.error = data.error;
		});
	};
	
	$scope.newQuery = function()
	{
		var queryID = 'new-query-1';

		window.location.replace( '#/queries/' + queryID );
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
	};
	
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
	$scope.queryTerm	= null;
	$scope.output		= '';
	$scope.profiling	= {};
	$scope.editor		= null;
	$scope.saveNeeded	= false;
	$scope.renameMode	= false;
	
	$scope.initEditor = function()
	{
		if ($scope.editor)
			return;
			
		$scope.editor =	ace.edit("query-editor");
		$scope.editor.setTheme("ace/theme/chrome");
		$scope.editor.session.setMode("ace/mode/javascript");	
		$scope.editor.session.setOption("useWorker", false); // disable syntax checking
		
		$scope.editor.session.on('change', function(e) 
		{
			$scope.saveNeeded = true;
			
			// Calling apply is needed because this is an event handler of an external module
			try
			{
				$scope.$apply(); 
			}
			catch (ex)
			{
			}
		});
		
		// Set key for save
		$scope.editor.commands.addCommand(
		{
			name: 'Save',
			bindKey: {win: 'Ctrl-S',  mac: 'Command-S'},
			exec: function(editor) 
			{
				$scope.save();
				$scope.$apply(); 
			},
			readOnly: false
		}); 

		// Set template for new queries
		if ($scope.query.status == 'new')
		{
			$scope.loadIntoEditor("/**\n *\n *\n */\n{\n\tselect: []\n\tfrom: \"\"\n}");
			$scope.saveNeeded = true;
			return;
		}
		
		// Load source file for existing queries
		$api.getSource( $scope.query.id ).success( function(data)
		{
			$scope.error = null;
			$scope.loadIntoEditor(data);
			$scope.saveNeeded = false;
		}).error( function(data)
		{
			$scope.error = data.error;
		});
	};
	
	$scope.loadIntoEditor = function( text )
	{
		$scope.editor.setValue(text, 0);
		$scope.editor.clearSelection();
		$scope.editor.gotoLine(1);
		$scope.editor.session.setScrollTop(1);
	};
	
	$scope.rename = function()
	{
		$scope.renameMode = false;
	};
	
	$scope.delete = function()
	{
		$api.deleteQuery( $scope.query.id ).success( function(data)
		{
			$scope.refreshMain();
			// Go to home
			// window.location.replace( '#/' );
		}).error( function(data)
		{
			$scope.error = data.error;
		});
	};
	
	$scope.save = function()
	{
		$api.saveSource( $scope.query.id, $scope.editor.getValue() ).success( function(data)
		{
			$scope.saveNeeded = false;
		}).error( function(data)
		{
			$scope.error = data.error;
		});
	};

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
		
		// Copy status from queries var to check if query is new
		if ($scope.project)
		{
			$scope.query.status = ($scope.project.queries[ queryID ])
				? 'exist'
				: 'new';
		}
		
		if ($scope.editmode && $scope.tab == 'edit')
			$scope.initEditor();
			
		if ($scope.query.status == 'new')
		{
			$scope.project.queries[ queryID ] = 
			{
				status:			'new',
				expose: 		'public',
				type: 			null,
				defaultParam: 	null,
				operation: 		null
			};
		
			return;
		}
			
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

