<?php

class RestClient {
  private $authentication = NULL;
  private $url_alter = NULL;
  private $formatter = NULL;
  public $rawResponse;
  public $lastResponse;

  /**
   * Creates a Rest client
   *
   * @param string $authentication
   * @param string $url_alter
   * @param string $unserialize
   * @author Hugo Wetterberg
   */
  public function __construct($authentication=NULL, $formatter=NULL, $url_alter=NULL) {
    $this->authentication = $authentication;
    $this->formatter = $formatter;

    if (!$formatter || in_array('RestClientFormatter', class_implements($formatter))) {
      $this->formatter = $formatter;
    }
    else {
      throw new Exception(t('The formatter parameter must either be a object implementing RestClientFormatter, or evaluate to FALSE.'));
    }

    if (!$this->url_alter || is_callable(array($url_alter, 'alterUrl'))) {
      $this->url_alter = $url_alter;
    }
    else {
      throw new Exception(t('The url_alter parameter must either be a object with a public alterUrl method, or evaluate to FALSE.'));
    }
  }

  private function get($url, $parameters) {
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

    if ($formatter && $data) {
      $data = $formatter->serialize($data);
    }

    if ($this->url_alter) {
      $url = $this->url_alter->alterUrl($url);
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

    // Allow the authentication implementation to do it's magic
    if ($this->authentication) {
      $this->authentication->authenticate($req);
    }

    curl_setopt($ch, CURLOPT_HEADER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $req->getMethod());
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, $req->toUrl());
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
    curl_close($ch);

    if ($res->responseCode==200) {
      if ($formatter) {
        return $formatter->unserialize($res->body);
      }
      return $res->body;
    }
    else {
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