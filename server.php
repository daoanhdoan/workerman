<?php

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;
use Workerman\Worker;

/**
 * @file
 * Locates the Drupal root directory and bootstraps the kernel.
 */
function _find_autoloader($dir) {
  if (file_exists($autoloadFile = $dir . '/autoload.php') || file_exists($autoloadFile = $dir . '/vendor/autoload.php')) {
    return include_once($autoloadFile);
  }
  else if (empty($dir) || $dir === DIRECTORY_SEPARATOR) {
    return FALSE;
  }
  return _find_autoloader(dirname($dir));
}

// Immediately return if classes are discoverable (already booted).
if (class_exists('\Drupal\Core\DrupalKernel') && class_exists('\Drupal')) {
  $kernel = \Drupal::service('kernel');
} else {
  $autoloader = _find_autoloader(empty($_SERVER['PWD']) ? getcwd() : $_SERVER['PWD']);
  if (!$autoloader || !class_exists('\Drupal\Core\DrupalKernel')) {
    print "This script must be invoked inside a Drupal 8 environment. Unable to continue.\n";
    exit();
  }

  $request = Request::createFromGlobals();
  $kernel = new DrupalKernel('prod', $autoloader, FALSE);
  $site_path = $kernel::findSitePath($request);
  $kernel::bootEnvironment();
  $kernel->setSitePath($site_path);
  Settings::initialize($kernel->getAppRoot(), $kernel->getSitePath(), $autoloader);
  $kernel->boot()->preHandle($request);
  set_error_handler("errorHandler");
}

function errorHandler($severity, $message) {
  echo $message;
}

$config = \Drupal::config('workerman.settings');
$scheme = $config->get('scheme');
$host = $config->get('host');
$port = $config->get('port');
$process = $config->get('process');
$ssl = $config->get('enable_ssl');
$local_cert = $config->get('ssl_cert_path');
$local_pk = $config->get('ssl_key_path');
$url = "{$scheme}://{$host}:{$port}";
$backend_scheme = $config->get('backend.scheme');
$backend_host = $config->get('backend.host');
$backend_port = $config->get('backend.port');
$backend_message_path = $config->get('backend.message_path');
$backend_url = "{$backend_scheme}://{$backend_host}:{$backend_port}{$backend_message_path}";

$context = array();
if ($ssl && $local_cert && $local_pk) {
  $context = array(
    'ssl' => array(
      'local_cert'  => $local_cert,
      'local_pk'    => $local_pk,
      'verify_peer' => FALSE,
      'allow_self_signed' => TRUE,
      'verify_peer_name' => FALSE,
      'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
    )
  );
}

try {
  $channels = [];
  $ws_worker = new Worker($url, $context);
  $ws_worker->count = $process;
  if ($ssl) {
    $ws_worker->transport = 'ssl';
  }

  $ws_worker->config = $config;

  $ws_worker->clients = [];

  $ws_worker->onWorkerStart = function ($ws_worker) {
    $inner_worker = new Workerman\Worker("tcp://live.gloryjsc.com:60008");
    $inner_worker->onMessage = function($connection, $data) {
      echo "Internal Received Message: {$connection->getRemoteAddress()}\n";
      global $ws_worker;
      $message = (object)JSON::decode($data);
      if (empty($message->Type)) {
        return;
      }
      if (!empty($ws_worker->clients[$message->AuthToken])) {
        foreach ($ws_worker->connections as $id => $tcpConnection) {
          if (!isset($ws_worker->clients[$message->AuthToken][$tcpConnection->id])) {
            if(!empty($tcpConnection->AuthToken)){
              $message->AuthToken = $tcpConnection->AuthToken;
              $tcpConnection->send(JSON::encode((array)$message));
            }
          }
        }
      }
    };
    $inner_worker->listen();
  };

// Emitted when new connection come
  $ws_worker->onConnect = function ($connection) {
    echo "New connection: {$connection->getRemoteAddress()}\n";
  };

// Emitted when data received
  $ws_worker->onMessage = function ($connection, $data) {
    echo "Received Message: {$connection->getRemoteAddress()}\n";
    global $ws_worker, $config;
    $message = (object)JSON::decode($data);
    if (empty($message->Type)) {
      return;
    }
    if(empty($connection->AuthToken) && !empty($message->AuthToken)) {
      $connection->AuthToken = $message->AuthToken;
      $ws_worker->connections[$connection->id] = $connection;
    }
    if ($message->Type == 'ClientId' && !empty($message->AuthToken)) {
      $ws_worker->clients[$message->AuthToken][$connection->id] = $connection;
    }
    if ($message->Type != 'ClientId') {
      if (!empty($ws_worker->clients[$message->AuthToken])) {
        foreach($ws_worker->connections as $key => $tcpConnection) {
          if(!isset($ws_worker->clients[$message->AuthToken][$tcpConnection->id])) {
            $tcpConnection->send(JSON::encode((array)$message));
          }
        }
      }
      else {
        foreach ($ws_worker->clients as $token => $connections) {
          foreach ($connections as $key => $tcpConnection) {
            if ($tcpConnection->getStatus() == 'CLOSED' || $tcpConnection->getStatus() == 'CLOSING') {
              unset($ws_worker->clients[$token][$key]);
            } elseif ($tcpConnection->id != $connection->id) {
              $tcpConnection->send(JSON::encode($message));
            }
          }
        }
      }
    }
    if ($message->Type != 'ClientId' && !empty($message->ClientId)) {

    }
  };

// Emitted when data received
  //$ws_worker->onError = function ($connection, $code, $msg) {};

// Emitted when connection closed
  $ws_worker->onClose = function ($connection) {
    echo "Connection closed: {$connection->getRemoteAddress()}\n";
  };

  $ws_worker::runAll();
}
catch (Exception $exception) {
  throw $exception;
}
