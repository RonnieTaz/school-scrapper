<?php

namespace Megamindame\SchoolScraper\Exceptions;

use Exception;
use Throwable;

class InvalidSchoolTypeException extends Exception
{
    public function __construct($code = 0, Throwable $previous = null)
    {
        parent::__construct('Invalid School Type passed into class.', $code, $previous);
    }
}
