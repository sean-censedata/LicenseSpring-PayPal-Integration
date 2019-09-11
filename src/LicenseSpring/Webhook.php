<?php

namespace LicenseSpring;
require_once('Helpers.php');

class Webhook {

    private $api_key, $secret_key;

    private static $order_successful_msg = "License keys successfuly activated.";
    private static $order_error_msg = "There was a problem activating your license keys. Please contact LicenseSpring.";

    private static $api_host_prod = "https://api.licensespring.com";
    private static $api_host_dev = "https://api-dev.licensespring.com";
    private static $license_endpoint = "/api/v3/webhook/license";
    private static $order_endpoint = "/api/v3/webhook/order";

    private $helpers;

    function __construct($api_key, $secret_key, $isDebug = false) {
        $this->api_key = $api_key;
        $this->secret_key = $secret_key;
        $this->helpers = new Helpers($api_key, $secret_key, self::getApiHost($isDebug));
    }

    private static function getApiHost($isDebug) {
      return $isDebug ? self::$api_host_dev : self::$api_host_prod;
    }

    /*
    POST /order
    */
    private static function check_PayPalResponseAndLicenses($payload) {
      if (!array_key_exists("details", $payload)) {
        throw new \Exception("Provided data did not originate from PayPal.");
      }
      if (!array_key_exists("status", $payload->details) || $payload->details->status !== "COMPLETED") {
        throw new \Exception("PayPal order is not yet competed.");
      }
      if (!array_key_exists("id", $payload->details)) {
        throw new \Exception("Provided data did not originate from PayPal.");
      }
      if (!array_key_exists("licenses", $payload)) {
        throw new \Exception("Can not create LicenseSpring order without licenses.");
      }
      if (sizeof($payload->licenses) < 1) {
        throw new \Exception("Can not create LicenseSpring order without licenses.");
      }
    }

    /*
    POST /order
    */
    private static function check_ProductObject($product) {
      if (!array_key_exists("code", $product)) {
        throw new \Exception("Can not create LicenseSpring order without product code.");
      }
      if (!array_key_exists("licenses", $product)) {
        throw new \Exception("Can not create LicenseSpring order without product licenses.");
      }
    }

    /*
    POST /order.
    */
    private function generateLSOrderData($payload) {
        $paypal_order_details = $payload->details;

        // paypal order id & user defined order reference
        $paypal_order_id = $paypal_order_details->id;
        $purchase_units = array_key_exists("purchase_units", $paypal_order_details) ? $paypal_order_details->purchase_units : array();
        $purchase_unit = sizeof($purchase_units) > 0 ? $purchase_units[0] : array();
        $user_defined_order_reference = array_key_exists("reference_id", $purchase_unit) ? $purchase_unit->reference_id : bin2hex(uniqid());

        // basic order data
        $order_data = (object) array();
        $order_data->id = $user_defined_order_reference . "_paypal_" . $paypal_order_id;
        $order_data->created = array_key_exists("create_time", $paypal_order_details) ? date("Y-m-j H:i:s", strtotime($paypal_order_details->create_time)) : "";
        $order_data->append = true;

        // customer data
        if (array_key_exists("payer", $paypal_order_details)) {
            $order_data->customer = (object) array();
            $order_data->customer->email = array_key_exists("email_address", $paypal_order_details->payer) ? $paypal_order_details->payer->email_address : "";

            if (array_key_exists("name", $paypal_order_details->payer)) {
                $order_data->customer->first_name = array_key_exists("given_name", $paypal_order_details->payer->name) ? $paypal_order_details->payer->name->given_name : "";
                $order_data->customer->last_name = array_key_exists("surname", $paypal_order_details->payer->name) ? $paypal_order_details->payer->name->surname : "";
            }
        }

        // order items
        $order_data->items = array();
        foreach($payload->licenses as $item) {
          self::check_ProductObject($item);

          array_push($order_data->items, array(
              "product_code" => $item->code,
              "licenses" => array_map(function($el) {
                return array("key" => $el);
              }, $item->licenses),
          ));
        }
        return $order_data;
    }

    /*
    POST /order.
    extracts error message from LicenseSpring webhook response.
    */
    private function webhookResponseToFrontendResponse($res) {
        $res = (object) $res;
        if ($res->success == true) {
            $message = self::$order_successful_msg;
        } else {
            $res_error = json_decode($res->message);
            if ($res_error !== null && array_key_exists("errors", $res_error) && count($res_error->errors) > 0 && array_key_exists("message", $res_error->errors[0]) && array_key_exists("value", $res_error->errors[0])) {
                $message = $res_error->errors[0]->message . ": " . $res_error->errors[0]->value;
            } else {
                $message = self::$order_error_msg;
            }
        }
        return Helpers::generateResponse($res->success, $message);
    }

    /*
    POST /order.
    params: 
      $payload = { 
        licenses: [] (array of license items containing objects with properties: code (str), licenses (arr of str)), 
        details: {} (details from paypal order)
      }
    */
    public function createOrder($payload) {
      try {
        $json = Helpers::tryDecodeJSON($payload);
        self::check_PayPalResponseAndLicenses($json);

        $order_data = $this->generateLSOrderData($json);
        $webhook_response = $this->helpers->makeRequest("POST", self::$order_endpoint, $order_data);
        return $this->webhookResponseToFrontendResponse($webhook_response);
      } catch (\Exception $e) {
        return Helpers::generateResponse($success = false, $message = $e->getMessage());
      }
    }

    /*
    GET /license.
    */
    private function getKeysFromWebhook($json) {
      $licenses = array();

      foreach($json->purchase_units[0]->items as $product) {
        if (array_key_exists("quantity", $product) && array_key_exists("code", $product) && array_key_exists("name", $product)) {
            $license_request = array(
                "product" => $product->code, 
                "quantity" => $product->quantity,
            );
            $webhook_response = $this->helpers->makeRequest("GET", self::$license_endpoint, $license_request);
            if ($webhook_response->success) {
                array_push($licenses, array(
                  "name" => $product->name,
                  "code" => $product->code,
                  "licenses" => json_decode($webhook_response->message),
                ));
            } else {
                throw new \Exception("There was a problem obtaining license codes from LicenseSpring: " . $webhook_response->message);
            }
        } else {
            throw new \Exception("Product must have name, quantity and code properties.");
        }
      }
      return $licenses;
    }

    /*
    GET /license
    */
    private static function check_PayPalOrderData($obj) {
        if (!array_key_exists("purchase_units", $obj)) {
            throw new \Exception("PayPal response missing 'purchase_units' object.");
        }
        if (count($obj->purchase_units) == 0) {
            throw new \Exception("PayPal response missing 'purchase_units' data.");
        }
        if (!array_key_exists("items", $obj->purchase_units[0])) {
            throw new \Exception("PayPal response missing 'items' object.");
        }
    }

    /*
    GET /licence.
    */
    public function acquireLicenses($payload) {
      try {
        $json = Helpers::tryDecodeJSON($payload);
        self::check_PayPalOrderData($json);

        $licenses = $this->getKeysFromWebhook($json);
        return Helpers::generateResponse($success = true, $message = json_encode($licenses));
      } catch (\Exception $e) {
        return Helpers::generateResponse($success = false, $message = $e->getMessage());
      }
    }
}