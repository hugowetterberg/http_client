<?php
// $Id$

class RestClientOAuth implements RestClientAuthentication {
  private $consumer;
  private $token;
  private $sign;
  private $signImpl;
  private $hash_body;
  private $header_auth;

  public function __construct($consumer, $token=NULL, $sign_impl=NULL, $hash_body=TRUE, $header_auth=FALSE) {
    $this->consumer = $consumer;
    $this->token = $token;
    $this->signImpl = $sign_impl;
    $this->sign = is_object($sign_impl);
    $this->hash_body = $hash_body;
    $this->header_auth = $header_auth;
  }

  /**
   * Enables and disables request signing.
   * N.B. Signing will always be disabled if no signing implementation was
   * passed to the constructor.
   *
   * @param bool $sign
   *  Set to FALSE to disable signing, TRUE to enable.
   * @return void
   */
  public function signRequests($sign) {
    $this->sign = $sign;
  }

  /**
   * Used by the RestClient to authenticate requests.
   *
   * @param RestClientRequest $request 
   * @return void
   */
  public function authenticate($request) {
    // Create a OAuth request object.
    $req = OAuthRequest::from_consumer_and_token($this->consumer, $this->token,
      $request->method, $request->url, $request->parameters);

    if ($this->hash_body) {
      // Add a body hash if applicable.
      $content_type = $request->getHeader('Content-type', TRUE);
      if (in_array($request->method, array('POST', 'PUT')) && $content_type !== 'application/x-www-form-urlencoded') {
        $data = $request->data;
        $data || $data = '';
        $req->set_parameter('oauth_body_hash', base64_encode(sha1($data, TRUE)));
      }
    }

    // Sign the request if we can and should.
    if ($this->sign && $this->signImpl) {
      $req->sign_request($this->signImpl, $this->consumer, $this->token);
    }

    // Make sure that we use the normalized url for the request
    $request->url = $req->get_normalized_http_url();

    if ($this->header_auth) {
      $auth_header = split(':', $req->to_header(), 2);
      $request->setHeader($auth_header[0], trim($auth_header[1]));
    }
    else {
      // Transfer all parameters to the request objects
      foreach ($req->get_parameters() as $key => $val) {
        $request->parameters[$key] = $val;
      }
    }
  }
}