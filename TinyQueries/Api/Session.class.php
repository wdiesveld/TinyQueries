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

require_once('HttpTools.class.php');

/**
 * Session
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class Session
{
	private $db;
	public $tableUsers;
	public $fieldnameUsername;
	public $fieldnamePassword;
	public $fieldnameUserID;
	public $fieldnameToken;

	/**
	 * Constructor
	 *
	 * @param {\PDO} $pdoHandle
	 */
	public function __construct($pdoHandle)
	{
		$this->db 					= $pdoHandle;
		$this->tableUsers 			= 'users';
		$this->fieldnameUsername	= 'username';
		$this->fieldnamePassword	= 'password';
		$this->fieldnameUserID		= 'id';
		$this->fieldnameToken		= 'token';
		
		$this->init();
	}
	
	public function init()
	{
		if(!isset($_SESSION))
			session_start();
		
		// check for token
		if ($token = HttpTools::getRequestVar( $this->fieldnameToken ))
		{
			if (!$this->loginByToken( $token ))
				$this->logout();
		}
	}
	
	/**
	 * Returns the user ID which is in the session
	 *
	 * @param {boolean} $checkIfExistsInDB Option to check if user exists in the DB. If not, the session is reset
	 */
	public function getUserID($checkIfExistsInDB = false)
	{
		$userID = HttpTools::getSessionVar('userID');
		
		if (!$checkIfExistsInDB)
			return $userID;
			
		$query = 
			"select count(*) from ". 
				"`" . $this->tableUsers . "`" . 
			" where " . 
				"`" . $this->fieldnameUserID . "`" . "=" . $this->db->quote($userID);
					
		$r = $this->db->query( $query )->fetch( \PDO::FETCH_NUM );
			
		if ($r[0])
			return $userID;
			
		$this->reset();
		
		return null;
	}
		
	public function getUserByCredentials($username, $password)
	{
		$passwordMD5 = ($password)
							? md5( $password )
							: '';

		$query = "select * from " . 
				"`" . $this->tableUsers . "`" . 
				" where " . 
				"`" . $this->fieldnameUsername . "`" . "=" . $this->db->quote($username) . " and " .
				"`" . $this->fieldnamePassword . "`" . "=" . $this->db->quote($passwordMD5);

		return $this->db->query( $query )->fetch( \PDO::FETCH_ASSOC );
	}
	
	public function getUserByToken($token)
	{
		$query = "select * from " . 
				"`" . $this->tableUsers . "`" . 
				" where " . 
				"`" . $this->fieldnameToken . "`" . "=" . $this->db->quote($token);
		
		return $this->db->query( $query )->fetch( \PDO::FETCH_ASSOC );
	}
	
	public function login($username, $password)
	{
		if ($user = $this->getUserByCredentials($username, $password))
		{
			$_SESSION['userID'] = $user[ $this->fieldnameUserID ];
			return $user;
		}
		
		return 0;
	}
	
	public function loginByToken($token)
	{
		if ($user = $this->getUserByToken($token))
		{
			$_SESSION['userID'] = $user[ $this->fieldnameUserID ];
			return $user;
		}
		
		return 0;
	}
	
	public function reset()
	{
		$_SESSION = array();
	}
	
	public function logout()
	{
		$this->reset();
		
		if (ini_get("session.use_cookies")) 
		{
			$params = session_get_cookie_params();
			setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"],$params["secure"], $params["httponly"]);
		}
		
		session_destroy();
	}
}

