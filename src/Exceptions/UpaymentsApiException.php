<?php

namespace Osama\Upayments\Exceptions;

use Exception;

class UpaymentsApiException extends Exception
{
    protected array $apiResponse;

    /**
     * UpaymentApiException constructor.
     *
     * @param string $message
     * @param int $code
     * @param array $apiResponse
     * @param Exception|null $previous
     */
    public function __construct($message = "API Error", $code = 500, array $apiResponse = [], Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->apiResponse = $apiResponse;
    }

    /**
     * Get the original API response.
     *
     * @return array
     */
    public function getApiResponse(): array
    {
        return $this->apiResponse;
    }
}