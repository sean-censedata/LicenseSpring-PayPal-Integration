# LicenseSpring-PayPal-Integration
LicenseSpring-PayPal integration with PHP backend.

## Installation
```bash
composer require licensespring/paypal
```

## Note
This package is used for PayPal integration with LicenseSpring using LicenseSpring JS library. You can see the example here: https://github.com/sean-censedata/LicenseSpring-PayPal-Integration-example

## Usage
### Get license keys from LicenseSpring platform
Create this PHP script and name it for instance `php/api/licensespring/acquire-licenses.php`. You will need to use that path from your frontend when using LicenseSpring JS library.

```php
<?php
// your script will return JSON string
header("Content-type:application/json");

// require autoload.php.
// set path differently if your PHP script doesn't reside three levels deep.
// for instance, if your PHP script is in /api/ folder relative to the document root then use: require_once '../vendor/autoload.php';
require_once "../../../vendor/autoload.php";

// initialize new LSWebhook object and give it your UUID and your Shared key (provided by LicenseSpring platform)
$webhook = new LicenseSpring\Webhook("insert-your-UUID-here", "insert-your-shared-key-here");

// capture any POST data (should be from your frontend part)
$frontend_payload = file_get_contents("php://input");

// this obtains license keys from LicenseSpring platform and returns response to frontend.
echo $webhook->acquireLicenses($frontend_payload);
```

### Create order on LicenseSpring platform
Create this PHP script and name it for instance `php/api/licensespring/create-order.php`. You will need to use that path from your frontend when using LicenseSpring JS library.

```php
<?php
// your script will return JSON string
header("Content-type:application/json");

// require autoload.php.
// set path differently if your PHP script doesn't reside three levels deep.
// for instance, if your PHP script is in /api/ folder relative to the document root then use: require_once '../vendor/autoload.php';
require_once "../../../vendor/autoload.php";

// initialize new LSWebhook object and give it your UUID and your Shared key (provided by LicenseSpring platform)
$webhook = new LicenseSpring\Webhook("insert-your-UUID-here", "insert-your-shared-key-here");

// capture any POST data (should be from your frontend part)
$frontend_payload = file_get_contents("php://input");

// this creates order in LicenseSpring platform and returns response to frontend.
echo $webhook->createOrder($frontend_payload);
```
