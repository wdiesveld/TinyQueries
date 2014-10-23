<?php
/**
 * TinyQueries - Framework for merging and nesting relational data
 *
 * @author      Wouter Diesveld <wouter@tinyqueries.com>
 * @copyright   2012 - 2014 Diesveld Query Technology
 * @link        http://www.tinyqueries.com
 * @version     1.6.1
 * @package     TinyQueries
 *
 * License
 *
 * This software is licensed under Apache License 2.0
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
 * LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
 * OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
 * WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */
namespace TinyQueries;

require_once('Query/Attach.class.php');
require_once('Query/Filter.class.php');
require_once('Query/JSON.class.php');
require_once('Query/Merge.class.php');
require_once('Query/SQL.class.php');
require_once('Query/Tree.class.php');

/**
 * Term
 *
 * This class represents a query term
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class Term
{
	/**
	 * Parses a query term and returns an object of type Query (or extended class)
	 *
	 * Technical notes:
	 * First tries to parse a structure like "( ... )" or "prefix.( ... )"
	 * ( the second form is needed for parsing terms like a:(b|c) - a is passed as prefix to (b|c), like a.(b|c) )
	 *
	 * @param {QueryDB} $db
	 * @param {string} $term
	 */
	public static function parse($db, $term)
	{
		if (!$term)
			return;
		
		// Replace return chars by space
		$term = str_replace("\t", " ", $term);
		$term = str_replace("\r", " ", $term);
		$term = str_replace("\n", " ", $term);
			
		list( $id, $children ) = self::parseID( $term );
		
		// Extract the prefix from the id (if present)
		$prefix = ($id && substr($id, -1) == '.')
			? substr($id, 0, -1)
			: null;
		
		// Determine the term to be passed to the merge parser
		$termMerge = ($children && (!$id || $prefix))
			? $children
			: $term;
			
		return self::parseMerge( $db, $termMerge, $prefix );
	}
	
	/**
	 * Checks if the 'root' element of each terms should be replaced by an alias
	 * For example "a(b,c)" and alias "a" => "d" will result in "d(b,c)"
	 *
	 * @param {array} $terms
	 * @param {assoc} $aliases
	 */
	public static function convertAliases($terms, $aliases)
	{
		if (count($aliases) == 0)
			return $terms;
	
		$terms1 = array();
		
		foreach ($terms as $term)
		{
			list( $id, $children ) = self::parseID( $term );
			
			$terms1[] = ($id && array_key_exists($id, $aliases))
				? $aliases[ $id ] . (($children) ? "(" . $children . ")" : "")
				: $term;
		}
		
		return $terms1;
	}
	
	/**
	 * Parses a merge term, like a|b|c
	 *
	 * @param {string} $term 
	 */
	private static function parseMerge($db, $term = null, $prefix = null)
	{
		$list = self::split($term, '|');

		if ($prefix)
			foreach ($list as $i => $v)
				$list[$i] = $prefix . "." . $list[$i];
		
		// If there is only one element, parse it as an attach
		if (count($list) == 1)
			return self::parseAttach( $db, $list[0] );

		return new QueryMerge($db, $list);
	}
	
	/**
	 * Parses a attach term, like a+b+c
	 *
	 * @param {string} $term
	 */
	private static function parseAttach($db, $term)
	{
		$list = self::split($term, '+');
		
		// If there is only 1 element, parse it as a chain
		if (count($list) == 1)
			return self::parseChain( $db, $list[0] );

		return new QueryAttach($db, $list);
	}
	
	/**
	 * Parses a filter term, like a:b:c
	 *
	 * @param {string} $term
	 */
	private static function parseChain($db, $term)
	{
		$list = self::split($term, ':');
		
		// If there is only 1 element, parse it as an tree
		if (count($list) == 1)
			return self::parseTree( $db, $list[0] );

		return new QueryFilter($db, $list);
	}
	
	/**
	 * Parses a ID tree structure and sets the ID of this query and creates child queries
	 *
	 * @param {string} $term
	 */
	private static function parseTree($db, $term)
	{
		list( $id, $children ) = self::parseID( $term );

		if (!$id && !$children)
			throw new \Exception("Term::parseTree - Cannot parse term " . $term);
			
		if (!$id)
			throw new \Exception("Term::parseTree - No id found " . $term);
		
		// If there are no children, we are at the 'leaves', e.g. the atomic queries (either JSON or SQL)
		if (!$children)
		{
			// Try to load the SQL variant first
			try
			{
				return new QuerySQL($db, $id);
			}
			catch (\Exception $e)
			{
			}

			// If that fails load it as JSON
			return new QueryJSON($db, $id);
		}
			
		$list = self::split($children, ',');
		
		return new QueryTree( $db, $id, $list );
	}
	
	/**
	 * Gets the ID part and children-part out of a tree structure, so "a(b(c),d)" will return "a" & "b(c),d"
	 *
	 * @param {string} $idTree
	 */
	private static function parseID($idTree)
	{
		$idTree = trim($idTree);
		
		// If tree has only one node this is just the ID of the query
		if (preg_match('/^[\w\-\.]+$/', $idTree))
			return array($idTree, null);
			
		$match = null;
		
		if (!preg_match('/^([\w\-\.]*)\s*\((.*)\)$/', $idTree, $match))
			return array( null, null );
			
		$id = ($match[1])
			? $match[1]
			: null;
			
		return array( $id, trim( $match[2] ) );
	}
	
	/**
	 * Splits the string by the separator, respecting possible nested parenthesis structures
	 *
	 * @param {string} $string
	 * @param {string} $separator Must be a single char!
	 */
	private static function split($string, $separator)
	{
		$string = trim( $string );
		$stack 	= 0;
		$part 	= '';
		$list 	= array();
		
		if ($string == '')
			return $list;
		
		for ($i=0; $i<strlen($string); $i++)
		{
			$char = substr( $string, $i, 1 );
			
			switch ($char)
			{
				case '(': $stack++; break;
				case ')': $stack--; break;
				case $separator: 
					if ($stack == 0)
					{
						$list[] = $part;
						$part 	= '';
						$char 	= '';
					}
					break;
			}

			$part .= $char;
		}
		
		// Add last element
		$list[] = $part;
		
		return $list;
	}
}
