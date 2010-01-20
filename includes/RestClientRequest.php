<?php
// $Id$

/**
 * This is a convenience class that allows the manipulation of a http request
 * before it's handed over to curl.
 */
class RestClientRequest {
  const METHOD_GET = 'GET';
  const METHOD_POST = 'POST';
  const METHOD_PUT = 'PUT';
  const METHOD_DELETE = 'DELETE';

  private $method = self::METHOD_GET;
  private $url = '';
  private $parameters = array();
  private $headers = array();
  private $data = NULL;

  public function __construct($kw=array()) {
    $kwaccept = array('method', 'url', 'parameters', 'data');
    foreach ($kwaccept as $key) {
      if (isset($kw[$key])) {
        $this->$key = $kw[$key];
      }
    }
  }

  public function setMethod($method) {
    $this->method = strtoupper($method);
  }

  public function getMethod() {
    return $this->method;
  }

  public function setUrl($url) {
    $this->url = $url;
  }

  public function getUrl() {
    return $this->url;
  }

  public function setHeader($name, $value) {
    $this->headers[$name] = array($value);
  }

  public function addHeader($name, $value) {
    $this->headers[$name][] = $value;
  }

  public function getHeader($name, $as_string=FALSE) {
    if (!empty($this->headers[$name])) {
      return $as_string ? $this->headers[$name][0] : $this->headers[$name];
    }
    return $as_string ? '' : array();
  }

  public function removeHeader($name, $value=NULL) {
    if ($value === NULL) {
      unset($this->headers[$name]);
    }
    else {
      $idx = array_search($value, $this->headers[$name]);
      if ($idx !== FALSE) {
        unset($this->headers[$name][$idx]);
      }
    }
  }

  public function headerIs($name, $value) {
    if (isset($this->headers[$name])) {
      return in_array($value, $this->headers[$name]);
    }
    return FALSE;
  }

  public function getHeaders() {
    $headers = array();
    foreach ($this->headers as $name => $values) {
      foreach ($values as $value) {
        $headers[] = $name . ': ' . $value;
      }
    }
    return $headers;
  }

  public function setParameter($name, $value) {
    $this->parameters[$name] = $value;
  }

  public function getParameter($name, $value) {
    if (isset($this->parameters[$name])) {
      return $this->parameters[$name];
    }
    return NULL;
  }

  public function removeParameter($name) {
    unset($this->parameters[$name]);
  }

  public function getParameters() {
    return $this->parameters;
  }

  public function getData() {
    return $this->data;
  }

  public function hasData() {
    return $this->data !== NULL;
  }

  public function setData($data) {
    $this->data = $data;
  }
  
  public function toUrl() {
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