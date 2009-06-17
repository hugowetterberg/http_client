<?php
// $Id$

class RestClientOAuth implements RestClientAuthentication {
  private $consumer;
  private $token;
  private $sign;
  private $signImpl;
  private $hash_body;

  public function __construct($consumer, $token=NULL, $sign_impl=NULL, $hash_body=TRUE) {
    $this->consumer = $consumer;
    $this->token = $token;
    $this->signImpl = $sign_impl;
    $this->sign = is_object($sign_impl);
    $this->hash_body = $hash_body;
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
    if ($this->hash_body) {
      // Add a body hash if applicable
      $content_type = $request->getHeader('Content-type', TRUE);
      if ($content_type !== 'application/x-www-form-urlencoded') {
        $data = $request->getData();
        $data || $data = '';
        $request->setParameter('oauth_body_hash', base64_encode(sha1($data, TRUE)));
      }
    }

    // Create a OAuth request object, and sign it if we got a sign
    $req = OAuthRequest::from_consumer_and_token($this->consumer, $this->token,
      $request->getMethod(), $request->getUrl(), $request->getParameters());
    if ($this->sign && $this->signImpl) {
      $req->sign_request($this->signImpl, $this->consumer, $this->token);
    }

    // Make sure that we use the normalized url for the request
    $request->setUrl($req->get_normalized_http_url());

    // Transfer all parameters to the request objects
    foreach ($req->get_parameters() as $key => $val) {
      $request->setParameter($key, $val);
    }
  }
}