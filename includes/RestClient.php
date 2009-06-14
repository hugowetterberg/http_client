<?php

class RestClient {
  private $authentication;
  public $rawResponse;
  public $lastResponse;

  public function __construct($authentication=NULL) {
    $this->authentication = $authentication;
  }

  private function get($resource, $parameters) {
    $url = $resource . '.php';
    $ch = $this->curl($url, $parameters, 'GET');
    return $this->execute($ch);
  }

  public function post($resource, $data, $parameters=array()) {
    $url = $resource . '.php';
    $ch = $this->curl($url, $parameters, 'POST', $data);
    return $this->execute($ch);
  }

  public function put($resource, $data, $parameters=array()) {
    $url = $resource . '.php';
    $ch = $this->curl($url, $parameters, 'PUT', $data);
    return $this->execute($ch);
  }

  public function delete($resource, $parameters=array()) {
    $url = $resource . '.php';
    $ch = $this->curl($url, $parameters, 'DELETE');
    return $this->execute($ch);
  }

  public function curl($url, $parameters, $method, $data=NULL) {
    $serialized = $data===NULL ? NULL : serialize($data);
    return $this->curlRaw($url, $parameters, $method, $serialized);
  }

  public function postFile($url, $file, $mime, $parameters=array()) {
    $contents = file_get_contents($file);
    $ch = $this->curlRaw($url . '.php', $parameters, 'POST', $contents, $mime, array(
      'Content-disposition: inline; filename="' . addslashes(basename($file)) . '"',
    ));
    return $this->execute($ch);
  }

  public function curlRaw($url, $parameters, $method, $data=NULL, $content_type='application/vnd.php.serialized', $extra_headers=array()) {
    $ch = curl_init();

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
    if ($req->hasData() !== NULL) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $req->getData());
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($req->getHeaders(), $extra_headers));

    return $ch;
  }

  private function execute($ch) {
    $this->rawResponse = curl_exec($ch);
    $res = $this->interpretResponse($this->rawResponse);
    $this->lastResponse = $res;
    curl_close($ch);

    if ($res->responseCode==200) {
      if (($data = @unserialize($res->body))!==FALSE || $res->body == serialize(FALSE)) {
        return $data;
      }
      else {
        throw new Exception('Unserialization of response body failed.', 1);
      }
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