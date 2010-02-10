<?php

/**
 * A rest client.
 */
class RestClient {

  protected $authentication = NULL;
  protected $request_alter = NULL;
  protected $formatter = NULL;
  protected $lastError = FALSE;

  public $rawResponse;
  public $lastResponse;

  /**
   * Allows specification of additional custom curl options.
   */
  public $curlOpts = array();


  /**
   * Creates a Rest client
   *
   * @param string $authentication
   * @param string $request_alter
   * @author Hugo Wetterberg
   */
  public function __construct($authentication = NULL, $formatter = NULL, $request_alter = NULL) {
    $this->authentication = $authentication;
    $this->formatter = $formatter;

    if (!$formatter || in_array('RestClientFormatter', class_implements($formatter))) {
      $this->formatter = $formatter;
    }
    else {
      throw new Exception(t('The formatter parameter must either be a object implementing RestClientFormatter, or evaluate to FALSE.'));
    }

    if (!$this->request_alter || is_callable(array($request_alter, 'alterRequest'))) {
      $this->request_alter = $request_alter;
    }
    else {
      throw new Exception(t('The request_alter parameter must either be a object with a public alterRequest method, or evaluate to FALSE.'));
    }
  }

  /**
   * Inject authentication class
   *
   * @param RestClientAuthentication $auth
   *   The class to use for authentication.
   */
  public function setAuthentication(RestClientAuthentication $auth) {
    $this->authentication = $auth;
  }

  /**
   * Inject formatter class
   *
   * @param RestClientFormatter $formatter
   *   The class to use for formatting.
   */
  public function setFormatter(RestClientFormatter $formatter) {
    $this->formatter = $formatter;
  }

  /**
   * Executes a GET request.
   */
  public function get($url, $parameters = array()) {
    return $this->execute(new RestClientRequest($url, array(
      'method' =>  'GET',
      'parameters' => $parameters,
    )));
  }

  /**
   * Executes a POST request.
   */
  public function post($url, $data = NULL, $parameters = array()) {
    return $this->execute(new RestClientRequest($url, array(
      'method' =>  'POST',
      'parameters' => $parameters,
      'data' => $data
    )));
  }

  /**
   * Executes a PUT request.
   */
  public function put($url, $data = NULL, $parameters = array()) {
    return $this->execute(new RestClientRequest($url, array(
      'method' =>  'PUT',
      'parameters' => $parameters,
      'data' => $data
    )));
  }

  /**
   * Executes a DELETE request.
   */
  public function delete($url, $parameters = array()) {
    return $this->execute(new RestClientRequest($url, array(
      'method' =>  'DELETE',
      'parameters' => $parameters,
    )));
  }

  /**
   * Gets a curl handle for the given request.
   */
  public function curl(RestClientRequest $request) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HEADER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request->method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, $request->url());
    curl_setopt($ch, CURLOPT_USERAGENT, 'Drupal (+http://drupal.org/)');

    if (isset($request->data)) {
      if ($this->formatter) {
        $request->headers[] = 'Content-type: ' . $this->formatter->mimeType();
        $data = $this->formatter->serialize($request->data);
      }
      else {
        $data = (string)$request->data;
      }
      curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
      $request->headers[] = 'Content-length: '. strlen($data);
    }

    curl_setopt_array($ch, $request->curlOpts + $this->curlOpts);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $request->headers);
    return $ch;
  }

  /**
   * Executes the given request.
   */
  public function execute(RestClientRequest $request) {
    // Allow the request to be altered
    if ($this->request_alter) {
      $this->request_alter->alterRequest($request);
    }

    // Allow the authentication implementation to do it's magic
    if ($this->authentication) {
      $this->authentication->authenticate($request);
    }

    $ch = $this->curl($request);
    $this->rawResponse = curl_exec($ch);
    $response = $this->interpretResponse($this->rawResponse);
    $this->lastResponse = $response;
    $this->lastError = curl_error($ch);
    curl_close($ch);

    $result = NULL;

    if ($response->responseCode == 200) {
      if ($this->formatter) {
        try {
          $result = $this->formatter->unserialize($response->body);
        }
        catch (Exception $e) {
          throw new RestClientException('Failed to unserialize response', 0, $response, $e);
        }
      }
      else {
        $result = $response->body;
      }
    }
    // Deal with curl errors.
    else if (empty($this->rawResponse) && !empty($this->lastError)) {
      throw new RestClientException('Curl Error: ' . $this->lastError);
    }
    // Output any errors set by remote drupal sites.
    elseif (!empty($response->drupalErrors)) {
      throw new RestClientException(check_plain(implode("\n", $response->drupalErrors)), $response->responseCode, $response);
    }
    // Treat all remaining non-200 responses as errors
    else {
      throw new RestClientException(check_plain($response->responseMessage), $response->responseCode, $response);
    }

    return $result;
  }

  /**
   * Interprets the response.
   */
  protected function interpretResponse($response) {
    $split = preg_split('/\r\n\r\n/', $response, 2);
    if (!isset($split[1])) {
      throw new RestClientException('Error interpreting response', 0, (object)array(
        'rawResponse' => $response,
      ));
    }
    list($headers, $body) = $split;

    $obj = (object)array(
      'headers' => $headers,
      'body' => $body,
    );

    // Drupal sends errors are via X-Drupal-Assertion-* headers,
    // generated by _drupal_log_error(). Read them to ease debugging.
    if (preg_match_all('/X-Drupal-Assertion-[0-9]+: (.*)\n/', $headers, $matches)) {
      foreach ($matches[1] as $key => $match) {
        $obj->drupalErrors[] = print_r(unserialize(urldecode($match)), 1);
      }
    }
    $matches = array();
    if (preg_match('/HTTP\/1.\d (\d{3}) (.*)/', $headers, $matches)) {
      $obj->responseCode = trim($matches[1]);
      $obj->responseMessage = trim($matches[2]);

      // Handle HTTP/1.1 100 Continue
      if ($obj->responseCode == 100) {
        return $this->interpretResponse($body);
      }
    }
    return $obj;
  }

  /**
   * Stolen from OAuth_common
   */
  public static function urlencode_rfc3986($input) {
    if (is_array($input)) {
      return array_map(array('RestClient', 'urlencode_rfc3986'), $input);
    } else if (is_scalar($input)) {
      return str_replace(
        '+',
        ' ',
        str_replace('%7E', '~', rawurlencode($input))
      );
    } else {
      return '';
    }
  }
}

/**
 * Exception that's used to pass information about the response when
 * a operation fails.
 */
class RestClientException extends Exception {
  protected $response;

  public function __construct($message, $code = 0, $response = NULL, $exception = NULL) {
    parent::__construct($message, $code, $exception);
    $this->response = $response;
  }

  /**
   * Gets the response object, if any.
   */
  public function getResponse() {
    return clone $this->response;
  }
}

/**
 * A base formatter to format php and json.
 */
class RestClientBaseFormatter implements RestClientFormatter {

  const FORMAT_PHP = 'php';
  const FORMAT_JSON = 'json';

  protected $mimeTypes = array(
    self::FORMAT_PHP => 'application/vnd.php.serialized',
    self::FORMAT_JSON => 'application/json',
  );

  protected $format;

  public function __construct($format = self::FORMAT_PHP) {
    $this->format = $format;
  }

  /**
   * Serializes arbitrary data.
   *
   * @param mixed $data
   *  The data that should be serialized.
   * @return string
   *  The serialized data as a string.
   */
  public function serialize($data) {
    switch($this->format) {
      case self::FORMAT_PHP:
        return serialize($data);
        break;
      case self::FORMAT_JSON:
        return json_encode($data);
        break;
    }
  }

  /**
   * Unserializes data.
   *
   * @param string $data
   *  The data that should be unserialized.
   * @return mixed
   *  The unserialized data.
   */
  public function unserialize($data) {
    switch($this->format) {
      case self::FORMAT_PHP:
        if (($response = @unserialize($data))!==FALSE || $data === serialize(FALSE)) {
          return $response;
        }
        else {
          throw new Exception(t('Unserialization of response body failed.'), 1);
        }
        break;
      case self::FORMAT_JSON:
        return json_decode($data);
        break;
    }
  }

  /**
   * Returns the mime type to use.
   */
  public function mimeType() {
    return $this->mimeTypes[$this->format];
  }
}

/**
 * Interface implemented by formatter implementations for the rest client
 */
interface RestClientFormatter {
  /**
   * Serializes arbitrary data to the implemented format.
   *
   * @param mixed $data
   *  The data that should be serialized.
   * @return string
   *  The serialized data as a string.
   */
  public function serialize($data);

  /**
   * Unserializes data in the implemented format.
   *
   * @param string $data
   *  The data that should be unserialized.
   * @return mixed
   *  The unserialized data.
   */
  public function unserialize($data);

  /**
   * Return the mime type to use.
   */
  public function mimeType();
}

/**
 * Interface that should be implemented by classes that provides a
 * authentication method for the rest client.
 */
interface RestClientAuthentication {
  /**
   * Used by the RestClient to authenticate requests.
   *
   * @param RestClientRequest $request
   * @return void
   */
  public function authenticate($request);
}

/**
 * This is a convenience class that allows the manipulation of a http request
 * before it's handed over to curl.
 */
class RestClientRequest {

  const METHOD_GET = 'GET';
  const METHOD_POST = 'POST';
  const METHOD_PUT = 'PUT';
  const METHOD_DELETE = 'DELETE';

  public $method = self::METHOD_GET;
  public $url = '';
  public $parameters = array();
  public $headers = array();
  public $data = NULL;

  /**
   * Allows specification of additional custom curl options.
   */
  public $curlOpts = array();

  /**
   * Construct a new client request.
   *
   * @param $url
   *   The url to send the request to.
   * @param $values
   *   An array of values for the object properties to set for the request.
   */
  public function __construct($url, $values = array()) {
    $this->url = $url;
    foreach (get_object_vars($this) as $key => $value) {
      if (isset($values[$key])) {
        $this->$key = $values[$key];
      }
    }
  }

  /**
   * Returns the url taken the parameters into account.
   */
  public function url() {
    if (empty($this->parameters)) {
      return $this->url;
    }
    $total = array();
    foreach ($this->parameters as $k => $v) {
      if (is_array($v)) {
        foreach ($v as $va) {
          $total[] = RestClient::urlencode_rfc3986($k) . "[]=" . RestClient::urlencode_rfc3986($va);
        }
      } else {
        $total[] = RestClient::urlencode_rfc3986($k) . "=" . RestClient::urlencode_rfc3986($v);
      }
    }
    $out = implode("&", $total);
    return $this->url . '?' . $out;
  }
}