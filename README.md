# Laravel Upayment Integration

A Laravel package for integrating the Upayment payment gateway. It provides a convenient way to interact with the Upayment API, allowing you to create payments, refunds, manage cards, and more.

## Installation

1. **Require the package using Composer:**

   Run the following command in your terminal:

    ```bash
    composer require osa-eg/laravel-upayments -W
    ```

   The `-W` flag allows Composer to upgrade dependencies if needed. This can resolve version conflicts, such as when the project requires a different version of `psr/log`.

**If there are still conflicts with `psr/log`:**

   If you encounter an error stating that the `psr/log` version is incompatible, you can manually update `psr/log` to a compatible version before installing the package:

    ```bash
    composer require psr/log:^3.0
    composer require osa-eg/laravel-upayments
    ```

2. **Publish the configuration file:**

    ```bash
    php artisan vendor:publish --provider="Osama\Upayments\Providers\UpaymentServiceProvider" --tag="config"
    ```

3. **Configure your environment:**

   Update your `.env` file with the following:

    ```dotenv
    UPAYMENT_API_KEY=your_upayment_api_key
    UPAYMENT_API_URL=https://sandboxapi.upayments.com/api/v1
    ```

## Configuration

After publishing, the configuration file `config/upayments.php` will be created. You can modify it according to your needs.

## Usage

### Basic Usage

1. **Add a product and create a payment:**

    ```php
    use Upayment;

    $response = Upayment::addProduct('Test Product', 'Description', 100.0, 1)
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
        ->setNotificationUrl('https://example.com/notify')
        ->createPayment();
    
    if ($response['status']) {
        echo "Payment link: " . $response['data']['payment_link'];
    } else {
        echo "Error: " . $response['error']['message'];
    }
    ```

2. **Retrieve payment status:**

    ```php
    $response = Upayment::getPaymentStatus('ORD123', 'trackId');
    ```

3. **Create a refund:**

    ```php
    $response = Upayment::createRefund('ORD123', 50.0, [
        'customerFirstName' => 'John',
        'customerEmail' => 'john.doe@example.com',
        'reference' => 'REF12345',
        'notifyUrl' => 'https://example.com/refund-notify'
    ]);
    ```

4. **Multi-vendor refund:**

    ```php
    $response = Upayment::addRefundVendor([
            'refundRequestId' => 'REF123',
            'ibanNumber' => 'KW91KFHO0000000000051010173254',
            'totalPaid' => '100.0',
            'refundedAmount' => 0.0,
            'remainingLimit' => 100.0,
            'amountToRefund' => 10.0,
            'merchantType' => 'vendor'
        ])
        ->addRefundVendor([
            'refundRequestId' => 'REF124',
            'ibanNumber' => 'KW31NBOK0000000000002010177457',
            'totalPaid' => '200.0',
            'refundedAmount' => 0.0,
            'remainingLimit' => 200.0,
            'amountToRefund' => 20.0,
            'merchantType' => 'vendor'
        ])
        ->createMultiVendorRefund('ORD123', [
            'reference' => 'REF12345',
            'notifyUrl' => 'https://example.com/multi-refund-notify'
        ]);
    ```

## Available Methods

### Payment Methods

- `addProduct($name, $description, $price, $quantity)`
- `setOrder($orderData)`
- `setCustomer($customerData)`
- `setPaymentGateway($source)`
- `setReturnUrl($url)`
- `setCancelUrl($url)`
- `setNotificationUrl($url)`
- `createPayment()`
- `getPaymentStatus($id, $type = 'invoiceId')`

### Refund Methods

- `createRefund($orderId, $totalPrice, array $optionalParams = [])`
- `getRefundStatus($orderId)`
- `checkSingleRefundStatus($orderId)`
- `deleteRefund($orderId, $refundOrderId)`

### Multi-Vendor Methods

- `addRefundVendor(array $vendorData)`
- `createMultiVendorRefund($orderId, array $optionalParams = [])`
- `deleteMultiVendorRefund($generatedInvoiceId, $orderId, $refundOrderId, $refundArn)`

### Card Management Methods

- `createCustomerUniqueToken($customerUniqueToken)`
- `addCard($returnUrl, $customerUniqueToken)`
- `retrieveCustomerCards($customerUniqueToken)`

## Middleware Support

The package includes retry and logging middleware to handle request retries and log requests for debugging purposes.

## Unit Tests

To run the tests, use the following command:

```bash
vendor/bin/phpunit
```
## Contributing

Feel free to submit issues or pull requests for improvements and bug fixes.

## License

The Laravel Upayment Integration package is open-sourced software licensed under the MIT license.

```
This `README.md` provides comprehensive documentation on how to use the package, including installation, configuration, usage examples, and available methods. It serves as a guide for integrating and utilizing the Laravel Upayment Integration package effectively.
```