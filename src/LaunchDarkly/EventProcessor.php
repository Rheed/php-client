<?php
namespace LaunchDarkly;

/**
 * @internal
 */
class EventProcessor {

  private $_apiKey;
  private $_queue;
  private $_capacity;
  private $_timeout;
  private $_socket_failed;
  private $_host;
  private $_port;
  private $_ssl;

  public function __construct($apiKey, $options = []) {
    $this->_apiKey = $apiKey;
    if (!isset($options['base_uri'])) {
        $this->_host = 'app.launchdarkly.com';
        $this->_port = 443;
        $this->_ssl = true;
    } 
    else {
        $url = parse_url($options['base_uri']);
        $this->_host = $url['host'];
        $this->_ssl = $url['scheme'] === 'https';
        if (isset($url['port'])) {
          $this->_port = $url['port'];
        } 
        else {
          $this->_port = $this->_ssl ? 443 : 80;
        }
    }

    $this->_capacity = $options['capacity'];
    $this->_timeout = $options['timeout'];

    $this->_queue = array(); 
  }

  public function __destruct() {
    $this->flush();
  }

  public function sendEvent($event) {
    return $this->enqueue($event);
  }

  public function enqueue($event) {
    if (count($this->_queue) > $this->_capacity) {
      return false;
    }

    array_push($this->_queue, $event);

    return true;
  }

  protected function flush() {
    if (empty($this->_queue)) {
      return;
    }

    $socket = $this->createSocket();
    
    if (!$socket) {
      error_log("LaunchDarkly unable to open socket");
      return;
    }
    $payload = json_encode($this->_queue);

    $body = $this->createBody($payload);

    return $this->makeRequest($socket, $body);
  }

  private function createSocket() {
    if ($this->_socket_failed) {
      return false;
    }

    $protocol = $this->_ssl ? "ssl" : "tcp";
  
    try {

      $socket = @pfsockopen($protocol . "://" . $this->_host, $this->_port, $errno, $errstr, $this->_timeout);

      if ($errno != 0) {
        error_log("LaunchDarkly error opening socket $errno");
        $this->_socket_failed = true;
        return false;
      }

      return $socket;
    } catch (Exception $e) {
      error_log("LaunchDarkly caught $e");
      $this->socket_failed = true;
      return false;
    }
  }

  private function createBody($content) {
    $req = "";
    $req.= "POST /api/events/bulk HTTP/1.1\r\n";
    $req.= "Host: " . $this->_host . "\r\n";
    $req.= "Content-Type: application/json\r\n";
    $req.= "Authorization: api_key " . $this->_apiKey . "\r\n";
    $req.= "User-Agent: PHPClient/" . LDClient::VERSION . "\r\n";
    $req.= "Accept: application/json\r\n";
    $req.= "Content-length: " . strlen($content) . "\r\n";
    $req.= "\r\n";
    $req.= $content;
    return $req;
  }

  private function makeRequest($socket, $req, $retry = true) {
    $bytes_written = 0;
    $bytes_total = strlen($req);
    $closed = false;

    while (!$closed && $bytes_written < $bytes_total) {
      try {
        $written = @fwrite($socket, substr($req, $bytes_written));
      } catch (Exception $e) {
        error_log("LaunchDarkly caught $e");
        $closed = true;
      }
      if (!isset($written) || !$written) {
        $closed = true;
      } else {
        $bytes_written += $written;
      }
    }

    if ($closed) {
      fclose($socket);
      if ($retry) {
        error_log("LaunchDarkly retrying send");
        $socket = $this->createSocket();
        if ($socket) {
          return $this->makeRequest($socket, $req, false);
        } else {
          error_log("LaunchDarkly unable to open socket");
          return false;
        }
      }
      return false;
    }

    return true;
  }


}