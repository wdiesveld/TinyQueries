<?php
/**
 * TinyQueries - Framework for merging and nesting relational data
 *
 * @author      Wouter Diesveld <wouter@tinyqueries.com>
 * @copyright   2012 - 2014 Diesveld Query Technology
 * @link        http://www.tinyqueries.com
 * @version     1.5.1
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

/**
 * UserFeedback
 *
 * @author 	Wouter Diesveld <wouter@tinyqueries.com>
 * @package TinyQueries
 */
class UserFeedback extends \Exception
{
	/**
	 * Constructor
	 *
	 * @param {string} $message
	 * @param {int} $httpCode
	 */
	public function __construct($message = null, $httpCode = 400)
	{
		parent::__construct($message, $httpCode);
	}
};

