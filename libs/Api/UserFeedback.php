<?php
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
     * @param string $message
     * @param int $httpCode
     */
    public function __construct($message = null, $httpCode = 400)
    {
        parent::__construct($message, $httpCode);
    }
};
