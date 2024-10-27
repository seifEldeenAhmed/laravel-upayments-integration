<?php

namespace Osama\Upayments\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Illuminate\Support\Facades\Log;
use Osama\Upayments\Exceptions\UpaymentsException;
use Osama\Upayments\Exceptions\UpaymentsApiException;
use Osama\Upayments\Exceptions\UpaymentsValidationException;

class UpaymentsService
{
    public Client $client;
    protected bool $isWhiteLabeled = false;
    protected string $apiKey;
    protected string $baseUrl;
    protected array $parameters = [];

    protected const ENDPOINTS = [
        'createPayment'              => '/api/v1/charge',
        'getPaymentStatus'           => '/api/v1/get-payment-status',
        'createRefund'               => '/api/v1/create-refund',
        'getRefundStatus'            => '/api/v1/check-refund',
        'checkSingleRefundStatus'    => '/api/v1/check-refund-status',
        'deleteRefund'               => '/api/v1/delete-refund',
        'createMultiVendorRefund'    => '/api/v1/create-multivendor-refund',
        'deleteMultiVendorRefund'    => '/api/v1/delete-multivendor-refund',
        'createCustomerToken'        => '/api/v1/create-customer-unique-token',
        'addCard'                    => '/api/v1/add-card',
        'retrieveCustomerCards'      => '/api/v1/retrieve-customer-cards',
        'checkPaymentButtonStatus'   =>'/api/v1/check-payment-button-status'
    ];

    public function __construct($apiKey = null, $baseUrl = null, $logChannel = null, $loggingEnabled = null)
    {
        $this->apiKey         = $apiKey ?? config('upayments.api_key');
        $this->baseUrl        = $baseUrl ?? config('upayments.api_base_url');
        $this->logChannel     = $logChannel ?? config('upayments.logging_channel', 'default');
        $this->loggingEnabled = $loggingEnabled ?? config('upayments.logging_enabled', true);

        $handlerStack = HandlerStack::create();

        // Add retry middleware
        $handlerStack->push($this->retryMiddleware());

        // Conditionally add logging middleware
        if ($this->loggingEnabled) {
            $handlerStack->push($this->loggingMiddleware());
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

    private function loggingMiddleware()
    {
        return Middleware::tap(
            function (RequestInterface $request, $options) {
                Log::channel($this->logChannel)->info('Request', [
                    'method'  => $request->getMethod(),
                    'uri'     => (string) $request->getUri(),
                    'headers' => $request->getHeaders(),
                    'body'    => (string) $request->getBody(),
                ]);
            },
            function ($responseOrException) {
                if ($responseOrException instanceof ResponseInterface) {
                    Log::channel($this->logChannel)->info('Response', [
                        'status'  => $responseOrException->getStatusCode(),
                        'headers' => $responseOrException->getHeaders(),
                        'body'    => (string) $responseOrException->getBody(),
                    ]);
                } elseif ($responseOrException instanceof RequestException) {
                    Log::channel($this->logChannel)->error('Request Exception', [
                        'message' => $responseOrException->getMessage(),
                        'code'    => $responseOrException->getCode(),
                        'request' => (string) $responseOrException->getRequest()->getBody(),
                    ]);
                }
            }
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

    public function markAsWhiteLabeled(): self
    {
        $this->isWhiteLabeled = true;

        return $this;
    }


    public function markAsNonWhiteLabeled(): self
    {
        $this->isWhiteLabeled = false;

        return $this;
    }

    public function setOrder(array $orderData): self
    {
        $requiredFields = ['id', 'description', 'currency', 'amount'];
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

        if (!in_array($source,['knet', 'cc', 'samsung-pay', 'apple-pay', 'google-pay' , 'create-invoice'])) {
            throw new UpaymentsValidationException("The payment gateway source is not valid, please add one of knet, cc, samsung-pay, apple-pay, google-pay and create-invoice.");
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

    public function setExtraMerchantData(array $data): self
    {
        $requiredFields = ['amount', 'knetCharge', 'knetChargeType', 'ccCharge','ccChargeType','ibanNumber'];
        $this->validateRequiredFields($data, $requiredFields);

        $this->parameters['extraMerchantData'][] = $data;
        return $this;
    }

    public function setCustomerExtraData(string $data): self
    {

        $this->parameters['customerExtraData'] = $data;
        return $this;
    }

    public function addMerchantData(array $merchantData): self
    {
        $this->validateRequiredFields($this->parameters, [ 'order' , 'paymentGateway', 'returnUrl', 'cancelUrl', 'notificationUrl']);

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

    public function setNotificationType(string $type): self
    {
        if(!in_array($type,['email','sms','link','all'])){
            throw new UpaymentsValidationException("The field notification type is must be one of 'email','sms','link','all' ");
        }

        $this->parameters['notificationType'] = $type;
        return $this;
    }

    public function createPayment(): array
    {
        // Validate that required parameters are set
        $requiredFields = [ 'order' ,  'returnUrl', 'cancelUrl', 'notificationUrl'];
        if($this->isWhiteLabeled)
            $requiredFields[] = 'paymentGateway';

        if (isset($this->parameters['paymentGateway']) && $this->parameters['paymentGateway']['src'] === 'create-invoice'){
            array_push($requiredFields, 'customer');
            array_push($requiredFields, 'notificationType');
        }
        $this->validateRequiredFields($this->parameters, $requiredFields);

        $endpoint = self::ENDPOINTS['createPayment'];
        return $this->sendRequest('POST', $endpoint, $this->parameters);
    }

    public function getPaymentStatus(string $id, string $type = 'trackId'): array
    {
        $endpoint = $type === 'trackId'
            ? self::ENDPOINTS['getPaymentStatus'] . "/$id"
            : self::ENDPOINTS['getPaymentStatus'] . "?invoice_id=$id";

        return $this->sendRequest('GET', $endpoint);
    }

    public function checkPaymentButtonStatus(): array
    {
        $endpoint = self::ENDPOINTS['checkPaymentButtonStatus'] ;

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