<?php

/**
 * A delegate for the HttpClient that uses curl to fetch data.
 */
class HttpClientCurlDelegate extends HttpClientDelegate {
  /**
   * Executes a request for the HttpClient.
   *
   * @param HttpClient $client
   *  The client we're acting as a delegate for.
   * @param HttpClientRequest $request
   *  The request to execute.
   * @return object
   *  The interpreted response.
   */
  function execute(HttpClient $client, HttpClientRequest $request) {
    $curlopts = array();
    if (isset($client->options['curlopts'])) {
      $curlopts = $curlopts + $client->options['curlopts'];
    }
    if (isset($request->options['curlopts'])) {
      $curlopts = $request->options['curlopts'] + $curlopts;
    }

    $ch = $this->curl($request, $curlopts);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
      throw new HttpClientException('Curl Error: ' . $error);
    }

    return $this->interpretResponse($client, $response);
  }

  /**
   * Gets a curl handle for the given request.
   */
  public function curl(HttpClientRequest $request, $curlopts) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_USERAGENT, 'Drupal (+http://drupal.org/)');

    curl_setopt_array($ch, $curlopts);

    curl_setopt($ch, CURLOPT_HEADER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request->method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, $request->url());
    curl_setopt($ch, CURLOPT_POSTFIELDS, $request->data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $request->getHeaders());
    return $ch;
  }
}
