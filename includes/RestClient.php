<?php

class RestClient {
  private $authentication = NULL;
  private $request_alter = NULL;
  private $formatter = NULL;
  private $lastError = FALSE;
  public $rawResponse;
  public $lastResponse;

  /**
   * Creates a Rest client
   *
   * @param string $authentication
   * @param string $request_alter
   * @param string $unserialize
   * @author Hugo Wetterberg
   */
  public function __construct($authentication=NULL, $formatter=NULL, $request_alter=NULL) {
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
   * @param RestClientAuthentication $auth
   *   The class to use for authentication. Must implement RestClientAuthentication
   *
   * @return void
   */
  public function setAuthentication(RestClientAuthentication $auth) {
    $this->authentication = $auth;
  }

  /**
   * Inject formatter class
   * @param RestClientFormatter $formatter
   *   The class to use for formatting. Must implement RestClientFormatter
   *
   * @return void
   */
  public function setFormatter(RestClientFormatter $formatter) {
    $this->formatter = $formatter;
  }

  public function get($url, $parameters) {
    $ch = $this->curl($url, $parameters, 'GET');
    return $this->execute($ch);
  }

  public function post($url, $data, $parameters=array()) {
    $ch = $this->curl($url, $parameters, 'POST', $data);
    return $this->execute($ch);
  }

  public function put($url, $data, $parameters=array()) {
    $ch = $this->curl($url, $parameters, 'PUT', $data);
    return $this->execute($ch);
  }

  public function delete($url, $parameters=array()) {
    $ch = $this->curl($url, $parameters, 'DELETE');
    return $this->execute($ch);
  }

  public function postFile($url, $file, $mime, $parameters=array()) {
    $contents = file_get_contents($file);
    $ch = $this->curlRaw($url, $parameters, 'POST', $contents, $mime, array(
      'Content-disposition: inline; filename="' . addslashes(basename($file)) . '"',
    ));
    return $this->execute($ch);
  }

  public function curl($url, $parameters, $method, $data=NULL, $content_type='application/vnd.php.serialized', $extra_headers=array()) {
    $ch = curl_init();

    if ($this->formatter && $data) {
      $data = $this->formatter->serialize($data);
    }

    $req = new RestClientRequest(array(
      'method' => $method,
      'url' => $url,
      'parameters' => $parameters,
      'data' => $data,
    ));
    if ($data) {
      $req->setHeader('Content-type', $content_type);
      $req->setHeader('Content-length', strlen($data));
    }

    // Allow the request to be altered
    if ($this->request_alter) {
      $this->request_alter->alterRequest($req);
    }

    // Allow the authentication implementation to do it's magic
    if ($this->authentication) {
      $this->authentication->authenticate($req);
    }

    curl_setopt($ch, CURLOPT_HEADER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $req->getMethod());
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, $req->toUrl());
    curl_setopt($ch, CURLINFO_HEADER_OUT, TRUE);
    if ($req->hasData()) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $req->getData());
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($req->getHeaders(), $extra_headers));
    return $ch;
  }

  public function execute($ch, $unserialize = TRUE) {
    $this->rawResponse = curl_exec($ch);
    $res = $this->interpretResponse($this->rawResponse);
    $this->lastResponse = $res;
    $this->lastError = curl_error($ch);
    curl_close($ch);

    if ($res->responseCode==200) {
      if ($this->formatter) {
        return $this->formatter->unserialize($res->body);
      }
      return $res->body;
    }
    else {
      // Add better error reporting
      if (empty($this->lastError)) {
        throw new Exception('Curl Error: ' . $this->lastError . ' when accessing ' . curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) . ' with headers ' . curl_getinfo($ch, CURLINFO_HEADER_OUT));
      }
      throw new Exception($res->responseMessage, $res->responseCode);
    }
  }

  private function interpretResponse($res) {
    list($headers, $body) = preg_split('/\r\n\r\n/', $res, 2);

    $obj = (object)array(
      'headers' => $headers,
      'body' => $body,
    );

    $matches = array();
    if (preg_match('/HTTP\/1.\d (\d{3}) (.*)/', $headers, $matches)) {
      $obj->responseCode = trim($matches[1]);
      $obj->responseMessage = trim($matches[2]);

      // Handle HTTP/1.1 100 Continue
      if ($obj->responseCode==100) {
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

  /**
   * Check for curl error
   * Returns FALSE if no error occured
   */
  public function getCurlError() {
    if (empty($this->lastError)) {
      $this->lastError = FALSE;
    }
    return $this->lastError;
  }

}

class RestClientBaseFormatter implements RestClientFormatter {
  const FORMAT_PHP = 'php';
  const FORMAT_JSON = 'json';

  private $format;

  public function __construct($format=self::FORMAT_PHP) {
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
        if (($res = @unserialize($data))!==FALSE || $data === serialize(FALSE)) {
          return $res;
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