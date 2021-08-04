<?php

namespace Drupal\workerman\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Implements the admin settings form.
 */
class WorkermanStatusForm extends FormBase {
  protected $modulePath;

  /**
   * Constructs a \Drupal\system\ConfigFormBase object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->setConfigFactory($config_factory);
    $this->modulePath = \Drupal::moduleHandler()->getModule( 'workerman')->getPath();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'workerman_status';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = \Drupal::config('workerman.settings');
    $pid = $this->getPid();
    if ($pid) {
      $form['message'] = array(
        '#type' => 'item',
        '#markup' => t('Workerman server(%pid) is running.', array(
          '%pid' => trim($pid)
        ))
      );
      $form['stop'] = array(
        '#type' => 'submit',
        '#value' => t('Stop server'),
        '#submit' => array('::stopSubmit')
      );
    }
    else {
      $form['message'] = array(
        '#type' => 'item',
        '#markup' => t('Workerman server is not run.')
      );
      $form['start'] = array(
        '#type' => 'submit',
        '#value' => t('Start server'),
        '#submit' => array('::startSubmit'),
        /*'#ajax' => array(
          'callback' => 'workerman_status_config_ajax_callback',
          'wrapper' => 'workerman-status-config-wrapper',
          'method' => 'replace',
          'effect' => 'fade',
        )*/
      );
    }
    $form['#prefix'] = "<div id=\"workerman-status-config-wrapper\">";
    $form['#suffix'] = "</div>";

    return $form;
  }

  /**
   * Form submit callback function
   */
  function stopSubmit($form, &$form_state) {
    $pid = $this->getPid();
    if ($this->isWindows()) {
      @exec("TASKKILL /T /F /PID {$pid}");
    }
    else {
      shell_exec("php -q $this->modulePath/workerman.server.php stop");
    }
    $this->deletePidFile();
  }

  /**
   * Form submit callback function
   */
  function ajaxCallback($form, &$form_state) {
    $form_state['rebuild'] = FALSE;
    return $form;
  }

  /**
   * Form submit callback function
   */
  function startSubmit(&$form, FormStateInterface $form_state) {
    $pid = $this->getPid();
    if (!$pid) {
      $cmd = "nohup php -q $this->modulePath/workerman.server.php start -d";
      if ($this->isWindows()) {
        $cmd = "nohup php -q $this->modulePath/workerman.server.php & echo $!";
      }
      exec($cmd);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->cleanValues();
    $values = $form_state->getValues();

    $this->messenger()->addMessage($this->t('The configuration options have been saved.'));
  }


  /**
   * Get Server PID
   */
  function getPid() {
    $this->modulePath = \Drupal::moduleHandler()->getModule( 'workerman')->getPath();
    @$pid = file_get_contents("$this->modulePath/workerman.pid");
    if(!$pid){
      @$pid = file_get_contents(DRUPAL_ROOT . '/workerman.pid');
    }
    if(!$pid){
      @$pid = file_get_contents('/tmp/workerman.pid');
    }
    if($pid){
      if($this->checkProcess($pid)){
        // Process is running.
        return $pid;
      }
    }
    return NULL;
  }

  /**
   * Store PID
   */
  function storePid($pid) {
    if(!empty($pid) && is_numeric($pid)){
      // Write the PID to the file
      if(!@file_put_contents(__DIR__ . "/workerman.pid", $pid)){
        if(!@file_put_contents(DRUPAL_ROOT . "/workerman.pid", $pid)){
          if(!@file_put_contents("/tmp/workerman.pid", $pid)){
            \Drupal::logger('workerman')->notice('Unable to record the PID of the WebSockets server');
          }
        }
      }
    }
  }

  /**
   * Store PID
   */
  function deletePidFile() {
    if(!@unlink(__DIR__ . "/workerman.pid")){
      if(!@unlink(DRUPAL_ROOT . "/workerman.pid")){
        if(!@unlink("/tmp/workerman.pid")){
          \Drupal::logger('workerman')->notice('Unable to delete the PID FIle of the server');
        }
      }
    }
  }


  /**
   * Get OS
   */
  function isWindows() {
    return (PHP_OS == 'Windows' || PHP_OS == 'WINNT') ? TRUE: FALSE;
  }

  /**
   * Check if process exists on Linux type OS
   *
   * http://www.blrf.net/howto/25_PHP__How_to_check_if_PID_exists_on_Linux_.html
   *
   * @param int $pid Process ID
   * @param string $name Process name, null for no process name matching
   * @return bool
   */
  function checkProcess($pid, $name = null){
    if ($this->isWindows()) {
      $output = shell_exec(sprintf('TASKLIST /NH /FI "PID eq %s" /FO "csv"', $pid));
      if (preg_match("/{$pid}/", $output)) {
        return TRUE;
      }
      return FALSE;
    }
    else {
      // form the filename to search for
      $file = '/proc/' . (int)$pid . '/cmdline';
      $fp = false;
      if (file_exists($file))
        $fp = @fopen($file, 'r');
      // if file does not exist or cannot be opened, return false
      if (!$fp)
        return false;
      $buf = fgets($fp);
      // if we failed to read from file, return false
      if ($buf === false) {
        return false;
      }
      if ($name !== null) {
        // this code will also check if name matches
        $cmd = basename($buf);
        if (preg_match('/' . $name . '.*/', $cmd)) {
          fclose($fp);
          return true;
        } else {
          // process was found, but name did not match
          fclose($fp);
          return false;
        }
      } else {
        // process found, name is null, return true
        fclose($fp);
        return true;
      }
    }
  }

}
