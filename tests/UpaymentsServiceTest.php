<?php

use PHPUnit\Framework\TestCase;
use Osama\Upayments\Services\UpaymentsService;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;

class UpaymentsServiceTest extends TestCase
{
    protected $clientMock;
    protected $loggerMock;
    protected $upaymentService;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the Guzzle Client
        $this->clientMock = $this->createMock(Client::class);

        // Mock the LoggerInterface
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        // Create the UpaymentsService instance with mocked dependencies
        $this->upaymentService = new UpaymentsService($this->loggerMock,'e66a94d579cf75fba327ff716ad68c53aae11528','https://sandboxapi.upayments.com/api/v1');
        $this->upaymentService->client = $this->clientMock;
    }

    public function testCreatePaymentSuccess()
    {
        $response = new Response(200, [], json_encode(['status' => true, 'data' => ['payment_link' => 'https://example.com']]));
        $this->clientMock->method('request')
            ->willReturn($response);

        $this->upaymentService->addProduct('Test Product', 'Description', 100.0, 1)
            ->setOrder([
                'id' => 'ORD123',
                'reference' => 'REF123',
                'description' => 'Order Description',
                'currency' => 'USD',
                'amount' => 100.0,
            ])
            ->setCustomer([
                'uniqueId' => 'CUST123',
                'name' => 'John Doe',
                'email' => 'john.doe@example.com',
                'mobile' => '+1234567890',
            ])
            ->setPaymentGateway('knet')
            ->setReturnUrl('https://example.com/return')
            ->setCancelUrl('https://example.com/cancel')
            ->setNotificationUrl('https://example.com/notify');

        $result = $this->upaymentService->createPayment();

        $this->assertTrue($result['status']);
        $this->assertEquals('https://example.com', $result['data']['payment_link']);
    }

    public function testGetPaymentStatusSuccess()
    {
        $response = new Response(200, [], json_encode(['status' => true, 'data' => ['status' => 'Completed']]));
        $this->clientMock->method('request')->willReturn($response);

        $result = $this->upaymentService->getPaymentStatus('ORD123', 'trackId');

        $this->assertTrue($result['status']);
        $this->assertEquals('Completed', $result['data']['status']);
    }

    public function testCreateRefundSuccess()
    {
        $response = new Response(200, [], json_encode(['status' => true, 'data' => ['refund_id' => 'REF123']]));
        $this->clientMock->method('request')->willReturn($response);

        // Set up the refund with optional parameters
        $result = $this->upaymentService
            ->createRefund('ORD123', 50.0, [
                'customerFirstName' => 'John',
                'customerEmail' => 'john.doe@example.com',
                'reference' => 'REF12345'
            ]);

        $this->assertTrue($result['status']);
        $this->assertEquals('REF123', $result['data']['refund_id']);
    }


    public function testGetRefundStatusSuccess()
    {
        $response = new Response(200, [], json_encode(['status' => true, 'data' => ['is_refunded' => true]]));
        $this->clientMock->method('request')->willReturn($response);

        $result = $this->upaymentService->getRefundStatus('ORD123');

        $this->assertTrue($result['status']);
        $this->assertTrue($result['data']['is_refunded']);
    }


    public function testCheckSingleRefundStatusSuccess()
    {
        $response = new Response(200, [], json_encode(['status' => true, 'data' => ['is_refunded' => true]]));
        $this->clientMock->method('request')->willReturn($response);

        $result = $this->upaymentService->checkSingleRefundStatus('REF123');

        $this->assertTrue($result['status']);
        $this->assertTrue($result['data']['is_refunded']);
    }

    public function testDeleteRefundSuccess()
    {
        $response = new Response(200, [], json_encode(['status' => true, 'message' => 'Refund deleted successfully']));
        $this->clientMock->method('request')->willReturn($response);

        $result = $this->upaymentService->deleteRefund('ORD123', 'REF123');

        $this->assertTrue($result['status']);
        $this->assertEquals('Refund deleted successfully', $result['message']);
    }


    public function testCreateMultiVendorRefundSuccess()
    {
        $response = new Response(200, [], json_encode(['status' => true, 'data' => ['refund_id' => 'MULTI_REF123']]));
        $this->clientMock->method('request')->willReturn($response);

        // Add multiple refund vendors
        $this->upaymentService->addRefundVendor([
            'refundRequestId' => 'REF123',
            'ibanNumber' => 'KW91KFHO0000000000051010173254',
            'totalPaid' => '100.0',
            'refundedAmount' => 1.0,
            'remainingLimit' => 100.0,
            'amountToRefund' => 10.0,
            'merchantType' => 'vendor'
        ])->addRefundVendor([
            'refundRequestId' => 'REF124',
            'ibanNumber' => 'KW31NBOK0000000000002010177457',
            'totalPaid' => '200.0',
            'refundedAmount' => 1.0, // Required field must be set
            'remainingLimit' => 200.0,
            'amountToRefund' => 20.0,
            'merchantType' => 'vendor'
        ]);

        // Call createMultiVendorRefund
        $result = $this->upaymentService->createMultiVendorRefund('ORD123', [
            'reference' => 'REF12345',
            'notifyUrl' => 'https://example.com/notify'
        ]);

        $this->assertTrue($result['status']);
        $this->assertEquals('MULTI_REF123', $result['data']['refund_id']);
    }

    public function testDeleteMultiVendorRefundSuccess()
    {
        $response = new Response(200, [], json_encode(['status' => true, 'message' => 'Multi-vendor refund deleted successfully']));
        $this->clientMock->method('request')->willReturn($response);

        $result = $this->upaymentService->deleteMultiVendorRefund('INV123', 'ORD123', 'REF123', 'ARN123');

        $this->assertTrue($result['status']);
        $this->assertEquals('Multi-vendor refund deleted successfully', $result['message']);
    }


    public function testCreateCustomerUniqueTokenSuccess()
    {
        $response = new Response(200, [], json_encode(['status' => true, 'data' => ['customerUniqueToken' => 'CUST12345']]));
        $this->clientMock->method('request')->willReturn($response);

        $result = $this->upaymentService->createCustomerUniqueToken('CUST12345');

        $this->assertTrue($result['status']);
        $this->assertEquals('CUST12345', $result['data']['customerUniqueToken']);
    }


    public function testAddCardSuccess()
    {
        $response = new Response(200, [], json_encode(['status' => true, 'data' => ['link' => 'https://example.com/my-cards']]));
        $this->clientMock->method('request')->willReturn($response);

        $result = $this->upaymentService->addCard('https://example.com/return', 'CUST12345');

        $this->assertTrue($result['status']);
        $this->assertEquals('https://example.com/my-cards', $result['data']['link']);
    }

    public function testRetrieveCustomerCardsSuccess()
    {
        $response = new Response(200, [], json_encode(['status' => true, 'data' => ['customerCards' => [['number' => '512345xxxxxx0008']]]]));
        $this->clientMock->method('request')->willReturn($response);

        $result = $this->upaymentService->retrieveCustomerCards('CUST12345');

        $this->assertTrue($result['status']);
        $this->assertEquals('512345xxxxxx0008', $result['data']['customerCards'][0]['number']);
    }
}