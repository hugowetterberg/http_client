<?php

/**
 * Class for handling xml-responses.
 * Returns a SimpleXML object
 *
 * @author Simon Ljungberg <simon.ljungberg@goodold.se>
 */

class HttpClientXMLFormatter implements HttpClientFormatter {
  /**
   * Serializes arbitrary data to the implemented format.
   * Directly stolen from http_server by Hugo Wetterberg
   *
   * @param mixed $data
   *  The data that should be serialized.
   * @return string
   *  The serialized data as a string.
   */
  public function serialize($data) {
    $doc = new DOMDocument('1.0', 'utf-8');
    $root = $doc->createElement('result');
    $doc->appendChild($root);

    $this->xml_recurse($doc, $root, $data);

    return $doc->saveXML();
  }

  /**
   * Unserializes data in the implemented format.
   *
   * @param string $data
   *  The data that should be unserialized.
   * @return mixed
   *  The unserialized data.
   */
  public function unserialize($data) {
    $xml = simplexml_load_string($data);

    if ($xml instanceof SimpleXMLElement) {
      // Only return data if we got well formed xml
      return $xml;
    }
    else {
      // Data was messed up
      throw new InvalidArgumentException('XML response was malformed.');
    }
  }

  public function mimeType() {
    return 'application/xml';
  }

  /**
   * Directly stolen from http_server by Hugo Wetterberg
   */
  protected function xml_recurse(&$doc, &$parent, $data) {
    if (is_object($data)) {
      $data = get_object_vars($data);
    }

    if (is_array($data)) {
      $assoc = FALSE || empty($data);
      foreach ($data as $key => $value) {
        if (is_numeric($key)) {
          $key = 'item';
        }
        else {
          $assoc = TRUE;
          $key = preg_replace('/[^a-z0-9_]/', '_', $key);
          $key = preg_replace('/^([0-9]+)/', '_$1', $key);
        }
        $element = $doc->createElement($key);
        $parent->appendChild($element);
        $this->xml_recurse($doc, $element, $value);
      }

      if (!$assoc) {
        $parent->setAttribute('is_array', 'true');
      }
    }
    else if ($data !== NULL) {
      $parent->appendChild($doc->createTextNode($data));
    }
  }
}
