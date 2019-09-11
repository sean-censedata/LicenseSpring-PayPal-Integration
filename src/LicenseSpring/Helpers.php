<?php

namespace LicenseSpring;

class Helpers {

  private $api_key, $secret_key;

  private static $backoff_steps = 10, $backoff_wait_time = 100; # in miliseconds

  private $api_host;

  function __construct($api_key, $secret_key, $api_host) {
      $this->api_key = $api_key;
      $this->secret_key = $secret_key;
      $this->api_host = $api_host;
  }


  private function sign($datestamp) {
    $data = "licenseSpring\ndate: $datestamp";
    $hashed = hash_hmac('sha256', $data, $this->secret_key, $raw_output = true);
    return base64_encode($hashed);
  }

  private function getHeaders() {
    $date_header = date("D, j M Y H:i:s") . " GMT";
    $signing_key = $this->sign($date_header);

    $auth = array(
        'algorithm="hmac-sha256"',
        'headers="date"',
        strtr('signature="@key"', ["@key" => $signing_key]),
        strtr('apiKey="@key"', ["@key" => $this->api_key]),
    );
    return array(
        'Date: ' . $date_header, 
        'Authorization: ' . implode(",", $auth),
        'Content-Type: application/json',
    );
  }

  /*
  used by makeAndHandleRequest().
  */
  private static function generateResponsePrivate($curl_obj, $success, $msg = null) {
      curl_close($curl_obj);
      return (object) array(
          "success" => $success,
          "message" => $msg,
      );
  }

  private function makeAndHandleRequest($request_type, $api, $data) {
    if ($request_type == "POST") {
        $data = json_encode($data);
        $ch = curl_init($this->api_host . $api);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
      } else {
          $ch = curl_init($this->api_host . $api . "?" . http_build_query($data));
      }
      curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getHeaders()); 
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

      $res = curl_exec($ch);
      if ( ! ($res)) {
          return self::generateResponsePrivate($ch, $success = false, $msg = curl_error($ch));
      }
      $info = curl_getinfo($ch);
      if ($info['http_code'] != 201 && $info['http_code'] != 200) {
          return self::generateResponsePrivate($ch, $success = false, $msg = $res);
      }
      return self::generateResponsePrivate($ch, $success = true, $msg = $res);
  }

  private function exponentialBackoff($request_type, $api, $data, $counter) {
      $response = $this->makeAndHandleRequest($request_type, $api, $data);
      if ($response->success) {
          return $response;
      }
      if ($counter + 1 < self::$backoff_steps) {
          usleep($counter * self::$backoff_wait_time * 1000);
          return $this->exponentialBackoff($request_type, $api, $data, $counter + 1);
      }
      return $response;
  }


  /*
    wrapper for starting exponentialBackoff.
  */
  public function makeRequest($request_type, $api, $data) {
    return $this->exponentialBackoff($request_type, $api, $data, $counter = 1);
  }

  public static function tryDecodeJSON($payload) {
    $json = json_decode($payload);
    if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception("Invalid JSON format.");
    }
    return $json;
  }
  
  /*
  generate response for frontend (licensespring.js) with success and message.
  */
  public static function generateResponse($success, $message) {
      return json_encode(array(
          "success" => $success,
          "message" => $message,
      ), JSON_PRETTY_PRINT);
  }

}