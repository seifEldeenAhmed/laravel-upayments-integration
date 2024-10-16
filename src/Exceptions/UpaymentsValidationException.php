<?php

namespace Osama\Upayments\Exceptions;

use Exception;

class UpaymentsValidationException extends Exception
{
    /**
     * UpaymentsValidationException constructor.
     *
     * @param string $message
     * @param int $code
     * @param Exception|null $previous
     */
    public function __construct($message = "Validation Error", $code = 422, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}