<?php

namespace Drupal\workerman\Controller;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Utility\Error;
use Drupal\user\Entity\User;
use Drupal\user\UserAuthInterface;
use Drupal\user\UserFloodControlInterface;
use Drupal\user\UserStorageInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Serializer;

/**
 * Provides controllers for login, login status and logout via HTTP requests.
 */
class WorkermanController extends ControllerBase implements ContainerInjectionInterface {
  /**
   * The user flood control service.
   *
   * @var \Drupal\user\UserFloodControl
   */
  protected $userFloodControl;

  /**
   * The user storage.
   *
   * @var \Drupal\user\UserStorageInterface
   */
  protected $userStorage;

  /**
   * The CSRF token generator.
   *
   * @var \Drupal\Core\Access\CsrfTokenGenerator
   */
  protected $csrfToken;

  /**
   * The user authentication.
   *
   * @var \Drupal\user\UserAuthInterface
   */
  protected $userAuth;

  /**
   * The route provider.
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  protected $routeProvider;

  /**
   * The serializer.
   *
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * The available serialization formats.
   *
   * @var array
   */
  protected $serializerFormats = [];

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new UserAuthenticationController object.
   *
   * @param \Drupal\user\UserFloodControlInterface $user_flood_control
   *   The user flood control service.
   * @param \Drupal\user\UserStorageInterface $user_storage
   *   The user storage.
   * @param \Drupal\Core\Access\CsrfTokenGenerator $csrf_token
   *   The CSRF token generator.
   * @param \Drupal\user\UserAuthInterface $user_auth
   *   The user authentication.
   * @param \Drupal\Core\Routing\RouteProviderInterface $route_provider
   *   The route provider.
   * @param \Symfony\Component\Serializer\Serializer $serializer
   *   The serializer.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   */
  public function __construct($user_flood_control, UserStorageInterface $user_storage, CsrfTokenGenerator $csrf_token, UserAuthInterface $user_auth, RouteProviderInterface $route_provider, Serializer $serializer, array $serializer_formats, LoggerInterface $logger) {
    if (!$user_flood_control instanceof UserFloodControlInterface) {
      @trigger_error('Passing the flood service to ' . __METHOD__ . ' is deprecated in drupal:9.1.0 and is replaced by user.flood_control in drupal:10.0.0. See https://www.drupal.org/node/3067148', E_USER_DEPRECATED);
      $user_flood_control = \Drupal::service('user.flood_control');
    }
    $this->userFloodControl = $user_flood_control;
    $this->userStorage = $user_storage;
    $this->csrfToken = $csrf_token;
    $this->userAuth = $user_auth;
    $this->serializer = $serializer;
    $this->serializerFormats = $serializer_formats;
    $this->routeProvider = $route_provider;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    if ($container->hasParameter('serializer.formats') && $container->has('serializer')) {
      $serializer = $container->get('serializer');
      $formats = $container->getParameter('serializer.formats');
    }
    else {
      $formats = ['json'];
      $encoders = [new JsonEncoder()];
      $serializer = new Serializer([], $encoders);
    }

    return new static(
      $container->get('user.flood_control'),
      $container->get('entity_type.manager')->getStorage('user'),
      $container->get('csrf_token'),
      $container->get('user.auth'),
      $container->get('router.route_provider'),
      $serializer,
      $formats,
      $container->get('logger.factory')->get('user')
    );
  }

  /**
   * Get user session.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  public function message(Request $request) {
    $response_data = [];
    $format = $this->getRequestFormat($request);
    $content = $request->getContent();
    if (!$content) {
      $response_data['error'] = 'Empty request.';
      $encoded_response_data = $this->serializer->encode($response_data, $format);
      return new Response($encoded_response_data);
    }
    $request_data = $this->serializer->decode($content, $format);
    $config = \Drupal::config('workerman.settings');

    if (!isset($request_data['serviceKey']) || !($config->get('service_key') == $request_data['serviceKey'])) {
      $response_data['error'] = 'Invalid service key.';
    }

    if (!isset($request_data['messageJson'])) {
      $response_data['error'] = 'No message.';
    }
    if (!empty($response_data['error'])) {
      $encoded_response_data = $this->serializer->encode($response_data, $format);
      return new Response($encoded_response_data);
    }
    $message = $this->serializer->decode($request_data['messageJson']);
    $response = array();
    switch ($request_data['messageType']) {
      case 'authenticate':
        $response = $this->authCheck($message);
        break;

      case 'userOffline':
        if (empty($message['uid'])) {
          $response['error'] = 'Missing uid for userOffline message.';
        }
        else if (!preg_match('/^\d+$/', $message['uid'])) {
          $response['error'] = 'Invalid (!/^\d+$/) uid for userOffline message.';
        }
        else {
          $this->setUserOffline($message['uid']);
          $response['message'] = "User {$message['uid']} set offline.";
        }
        break;

      default:
        $handlers = array();
        foreach (\Drupal::moduleHandler()->getImplementations('workerman_message_callback') as $module) {
          $function = $module . '_workerman_message_callback';
          if (is_array($function($message['messageType']))) {
            $handlers += $function($message['messageType']);
          }
        }
        foreach ($handlers as $callback) {
          $callback($message, $response);
        }
    }
    \Drupal::moduleHandler()->alter('workerman_message_response', $response, $message);
    $response_data = $response ? $response : array('error' => 'Not implemented');

    $encoded_response_data = $this->serializer->encode($response_data, $format);
    return new Response($encoded_response_data);
  }


  /**
   * Checks the given key to see if it matches a valid session.
   */
  function authCheck($message) {
    $uid = $this->authCheckCallback($message['authToken']);
    $auth_user = $uid > 0 ? User::load($uid) : new AnonymousUserSession();
    $auth_user->authToken = $message['authToken'];
    $auth_user->validAuthToken = $uid !== FALSE;
    $auth_user->clientId = $message['clientId'];

    if ($auth_user->validAuthToken) {
      // Get the list of channels I have access to.
      $auth_user->channels = array();
      foreach (\Drupal::moduleHandler()->getImplementations('wokerman_user_channels') as $module) {
        $function = $module . '_workerman_user_channels';
        foreach ($function($auth_user) as $channel) {
          $auth_user->channels[] = $channel;
        }
      }

      // Get the list of users who can see presence notifications about me.
      $auth_user->presenceUids = array_unique(\Drupal::moduleHandler()->invokeAll('workerman_user_presence_list', $auth_user));

      $config = \Drupal::config('workerman.settings');
      $auth_user->serviceKey = $config->get('service_key');
      \Drupal::moduleHandler()->alter('workerman_auth_user', $auth_user);
      if ($auth_user->id()) {
        $this->setUserOnline($auth_user->id());
      }
      $auth_user->contentTokens = isset($message['contentTokens']) ? $message['contentTokens'] : array();
    }
    return $auth_user;
  }

  /**
   * Default Node.js auth check callback implementation.
   */
  function authCheckCallback($auth_token) {
    $sql = "SELECT uid FROM {sessions} WHERE MD5(sid) = :auth_key OR MD5(CONCAT(uid, sid)) = :auth_key";
    return \Drupal::database()->query($sql, array(':auth_key' => $auth_token))->fetchField();
  }

  /**
   * Default nodejs_auth_get_token() implementation.
   */
  function getAuthToken($account) {
    return md5(session_id());
  }

  /**
   * Set the user as online.
   *
   * @param $uid
   */
  function setUserOnline($uid) {
    try {
      $request = \Drupal::requestStack()->getCurrentRequest();
      $sid = \Drupal::service('session')->getId();
      $fields = [
        'uid' => $request->getSession()->get('uid', 0),
        'hostname' => $request->getClientIP(),
        'session' => "",
        'timestamp' => \Drupal::time()->getRequestTime(),
      ];
      \Drupal::database()->merge('sessions')
        ->keys(['sid' => Crypt::hashBase64($sid)])
        ->fields($fields)
        ->execute();
      return TRUE;
    }
    catch (\Exception $exception) {
      require_once DRUPAL_ROOT . '/core/includes/errors.inc';
      // If we are displaying errors, then do so with no possibility of a
      // further uncaught exception being thrown.
      if (error_displayable()) {
        print '<h1>Uncaught exception thrown in session handler.</h1>';
        print '<p>' . Error::renderExceptionSafe($exception) . '</p><hr />';
      }
      return FALSE;
    }
  }

  /**
   * Set the user as online.
   *
   * @param $uid
   */
  function setUserOffline($uid) {
    try {
      \Drupal::database()->delete('sessions')->condition('uid', $uid)->execute();
    }
    catch (Exception $e) { }
  }

  /**
   * Gets the format of the current request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return string
   *   The format of the request.
   */
  protected function getRequestFormat(Request $request) {
    $format = $request->getRequestFormat();
    if (!in_array($format, $this->serializerFormats)) {
      throw new BadRequestHttpException("Unrecognized format: $format.");
    }
    return $format;
  }
}
