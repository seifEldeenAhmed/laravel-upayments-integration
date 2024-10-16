<?php

namespace Osama\Upayments\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Osama\Upayments\Exceptions\UpaymentsValidationException;
use Osama\Upayments\Exceptions\UpaymentsApiException;

class UpaymentsService
{
    public Client $client;
    protected string $apiKey;
    protected string $baseUrl;
    protected array $parameters = [];

    protected const ENDPOINTS = [
        'createPayment'              => '/charge',
        'getPaymentStatus'           => '/get-payment-status',
        'createRefund'               => '/create-refund',
        'getRefundStatus'            => '/check-refund',
        'checkSingleRefundStatus'    => '/check-refund-status',
        'deleteRefund'               => '/delete-refund',
        'createMultiVendorRefund'    => '/create-multivendor-refund',
        'deleteMultiVendorRefund'    => '/delete-multivendor-refund',
        'createCustomerToken'        => '/create-customer-unique-token',
        'addCard'                    => '/add-card',
        'retrieveCustomerCards'      => '/retrieve-customer-cards',
    ];

    public function __construct(LoggerInterface $logger, $apiKey =null , $baseUrl = null)
    {
            $this->apiKey  = $apiKey??config('upayments.api_key');
            $this->baseUrl = $baseUrl??config('upayments.api_url');

        $handlerStack = HandlerStack::create();

        // Add retry middleware
        $handlerStack->push($this->retryMiddleware());

        // Add logging middleware if a logger is provided
        if ($logger) {
            $handlerStack->push($this->loggingMiddleware($logger));
        }

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'handler'  => $handlerStack,
            'headers'  => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
        ]);
    }

    protected function sendRequest(string $method, string $endpoint, array $body = []): array
    {
        try {
            $options = !empty($body) ? ['json' => $body] : [];

            /** @var ResponseInterface $response */
            $response = $this->client->request($method, $endpoint, $options);

            $responseData = json_decode($response->getBody()->getContents(), true);

            // Check for API-specific error codes
            if (isset($responseData['status']) && !$responseData['status']) {
                throw new UpaymentsApiException(
                    $responseData['message'] ?? 'Upayments API error',
                    $response->getStatusCode(),
                    $responseData
                );
            }

            return $responseData;
        } catch (RequestException $e) {
            $errorResponse = $e->hasResponse()
                ? json_decode($e->getResponse()->getBody()->getContents(), true)
                : ['message' => 'An error occurred while processing the request'];

            throw new UpaymentsException($errorResponse['message'] ?? 'Unknown error', $e->getCode(), $e);
        }
    }

    private function retryMiddleware()
    {
        return Middleware::retry(
            function (
                $retries,
                RequestInterface $request,
                ResponseInterface $response = null,
                $exception = null
            ) {
                // Retry on server errors (5xx) or network failures
                if ($retries < 3 && ($response && $response->getStatusCode() >= 500 || $exception)) {
                    return true;
                }
                return false;
            }
        );
    }

    private function loggingMiddleware(LoggerInterface $logger)
    {
        return Middleware::log(
            $logger,
            new \GuzzleHttp\MessageFormatter('{req_body} - {res_body}')
        );
    }

    private function validateRequiredFields(array $fields, array $requiredFields): void
    {
        foreach ($requiredFields as $field) {
            if (!isset($fields[$field]) || empty($fields[$field])) {
                throw new UpaymentsValidationException("The field '{$field}' is required.");
            }
        }
    }

    protected function addOptionalParams(array $optionalParams, array $fields): void
    {
        foreach ($fields as $field) {
            if (!empty($optionalParams[$field])) {
                $this->parameters[$field] = $optionalParams[$field];
            }
        }
    }

    public function addProduct(string $name, string $description, float $price, int $quantity): self
    {
        $this->parameters['products'][] = [
            'name'        => $name,
            'description' => $description,
            'price'       => $price,
            'quantity'    => $quantity,
        ];

        return $this;
    }

    public function setOrder(array $orderData): self
    {
        $requiredFields = ['id', 'reference', 'description', 'currency', 'amount'];
        $this->validateRequiredFields($orderData, $requiredFields);

        $this->parameters['order'] = $orderData;
        return $this;
    }

    public function setCustomer(array $customerData): self
    {
        $requiredFields = ['uniqueId', 'name', 'email', 'mobile'];
        $this->validateRequiredFields($customerData, $requiredFields);

        $this->parameters['customer'] = $customerData;
        return $this;
    }

    public function setPaymentGateway(string $source): self
    {
        if (empty($source)) {
            throw new UpaymentsValidationException("The payment gateway source is required.");
        }
        $this->parameters['paymentGateway']['src'] = $source;
        return $this;
    }

    public function setLanguage(string $language): self
    {
        $this->parameters['language'] = $language;
        return $this;
    }

    public function setReference(string $id): self
    {
        $this->parameters['reference'] = ['id' => $id];
        return $this;
    }

    public function setCustomerExtraData(array $data): self
    {
        $this->parameters['customerExtraData'] = $data;
        return $this;
    }

    public function addMerchantData(array $merchantData): self
    {
        $this->parameters['extraMerchantData'][] = $merchantData;
        return $this;
    }

    public function setReturnUrl(string $url): self
    {
        $this->parameters['returnUrl'] = $url;
        return $this;
    }

    public function setCancelUrl(string $url): self
    {
        $this->parameters['cancelUrl'] = $url;
        return $this;
    }

    public function setNotificationUrl(string $url): self
    {
        $this->parameters['notificationUrl'] = $url;
        return $this;
    }

    public function createPayment(): array
    {
        // Validate that required parameters are set
        $this->validateRequiredFields($this->parameters, ['products', 'order', 'customer', 'paymentGateway', 'returnUrl']);

        $endpoint = self::ENDPOINTS['createPayment'];
        return $this->sendRequest('POST', $endpoint, $this->parameters);
    }

    public function getPaymentStatus(string $id, string $type = 'invoiceId'): array
    {
        $endpoint = $type === 'trackId'
            ? self::ENDPOINTS['getPaymentStatus'] . "/$id"
            : self::ENDPOINTS['getPaymentStatus'] . "?invoice_id=$id";

        return $this->sendRequest('GET', $endpoint);
    }

    public function createRefund(string $orderId, float $totalPrice, array $optionalParams = []): array
    {
        if (empty($orderId) || $totalPrice <= 0) {
            throw new UpaymentsValidationException("The order ID and a valid total price are required for a refund.");
        }

        $this->parameters = [
            'orderId'    => $orderId,
            'totalPrice' => $totalPrice,
        ];

        $this->addOptionalParams($optionalParams, [
            'customerFirstName',
            'customerEmail',
            'customerMobileNumber',
            'reference',
            'notifyUrl',
        ]);

        $endpoint = self::ENDPOINTS['createRefund'];
        return $this->sendRequest('POST', $endpoint, $this->parameters);
    }

    public function getRefundStatus(string $orderId): array
    {
        $endpoint = self::ENDPOINTS['getRefundStatus'] . "/$orderId";
        return $this->sendRequest('GET', $endpoint);
    }

    public function checkSingleRefundStatus(string $orderId): array
    {
        $endpoint = self::ENDPOINTS['checkSingleRefundStatus'] . "/$orderId";
        return $this->sendRequest('GET', $endpoint);
    }

    public function deleteRefund(string $orderId, string $refundOrderId): array
    {
        $this->parameters = [
            'orderId'       => $orderId,
            'refundOrderId' => $refundOrderId,
        ];

        $endpoint = self::ENDPOINTS['deleteRefund'];
        return $this->sendRequest('POST', $endpoint, $this->parameters);
    }

    public function addRefundVendor(array $vendorData): self
    {
        // Validate required fields for vendor data
        $requiredFields = ['refundRequestId', 'ibanNumber', 'totalPaid', 'refundedAmount', 'remainingLimit', 'amountToRefund', 'merchantType'];
        $this->validateRequiredFields($vendorData, $requiredFields);

        $this->parameters['refundPayload'][] = $vendorData;
        return $this;
    }

    public function createMultiVendorRefund(string $orderId, array $optionalParams = []): array
    {
        if (empty($orderId)) {
            throw new UpaymentsValidationException("The order ID is required for multi-vendor refund.");
        }

        $this->parameters['orderId'] = $orderId;
        $this->addOptionalParams($optionalParams, [
            'receiptId',
            'customerFirstName',
            'customerEmail',
            'customerMobileNumber',
            'reference',
            'notifyUrl',
        ]);

        $endpoint = self::ENDPOINTS['createMultiVendorRefund'];
        return $this->sendRequest('POST', $endpoint, $this->parameters);
    }

    public function deleteMultiVendorRefund(string $generatedInvoiceId, string $orderId, string $refundOrderId, string $refundArn): array
    {
        if (empty($generatedInvoiceId) || empty($orderId) || empty($refundOrderId) || empty($refundArn)) {
            throw new UpaymentsValidationException("All parameters are required for deleting a multi-vendor refund.");
        }

        $this->parameters = [
            'generatedInvoiceId' => $generatedInvoiceId,
            'orderId'            => $orderId,
            'refundOrderId'      => $refundOrderId,
            'refundArn'          => $refundArn,
        ];

        $endpoint = self::ENDPOINTS['deleteMultiVendorRefund'];
        return $this->sendRequest('POST', $endpoint, $this->parameters);
    }

    public function createCustomerUniqueToken(string $customerUniqueToken): array
    {
        if (empty($customerUniqueToken)) {
            throw new UpaymentsValidationException("The customer unique token is required.");
        }

        $this->parameters = ['customerUniqueToken' => $customerUniqueToken];
        $endpoint = self::ENDPOINTS['createCustomerToken'];
        return $this->sendRequest('POST', $endpoint, $this->parameters);
    }

    public function addCard(string $returnUrl, string $customerUniqueToken): array
    {
        if (empty($returnUrl) || empty($customerUniqueToken)) {
            throw new UpaymentsValidationException("Both return URL and customer unique token are required.");
        }

        $this->parameters = [
            'returnUrl'          => $returnUrl,
            'customerUniqueToken' => $customerUniqueToken,
        ];

        $endpoint = self::ENDPOINTS['addCard'];
        return $this->sendRequest('POST', $endpoint, $this->parameters);
    }

    public function retrieveCustomerCards(string $customerUniqueToken): array
    {
        if (empty($customerUniqueToken)) {
            throw new UpaymentsValidationException("The customer unique token is required.");
        }

        $this->parameters = ['customerUniqueToken' => $customerUniqueToken];
        $endpoint = self::ENDPOINTS['retrieveCustomerCards'];
        return $this->sendRequest('POST', $endpoint, $this->parameters);
    }
}