<?php

namespace Drupal\workerman\Form;

use Workerman\Worker;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Implements the SMTP admin settings form.
 */
class WorkermanConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'workerman_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->configFactory->getEditable('workerman.settings');
    $form['server'] = array(
      '#type' => 'fieldset',
      '#title' => t('Workerman server'),
    );

    $form['server']['scheme'] = array(
      '#type' => 'radios',
      '#title' => t('Protocol used by Workerman server'),
      '#default_value' => $config->get('scheme'),
      '#options' => array('http' => t('http'), 'tcp' => t('tcp'), 'websocket' => t('websocket')),
      '#description' => t('The protocol used to communicate with the Workerman server.'),
    );
    $form['server']['host'] = array(
      '#type' => 'textfield',
      '#title' => t('Workerman server host'),
      '#default_value' => $config->get('host'),
      '#size' => 40,
      '#required' => TRUE,
      '#description' => t('The hostname of the Workerman server.'),
    );

    $form['server']['port'] = array(
      '#type' => 'textfield',
      '#title' => t('Workerman server port'),
      '#default_value' => $config->get('port'),
      '#size' => 10,
      '#required' => TRUE,
      '#description' => t('The number of port the Workerman server process.'),
    );

    $form['server']['process'] = array(
      '#type' => 'textfield',
      '#title' => t('The number of Workerman server process'),
      '#default_value' => $config->get('process'),
      '#size' => 10,
      '#required' => TRUE,
      '#description' => t('The number of the Workerman server process.'),
    );
    $form['server']['service_key'] = array(
      '#type' => 'textfield',
      '#title' => t('Service key'),
      '#default_value' => $config->get('service_key'),
      '#size' => 40,
      '#description' => t('An arbitrary string used as a secret between the Workerman server and the Drupal site. Be sure to enter the same service key in the Workerman app configuration file.'),
    );
    $form['server']['enable_ssl'] = array(
      '#type' => 'checkbox',
      '#title' => t('Workerman server ssl'),
      '#default_value' => $config->get('enable_ssl'),
      '#size' => 10,
      '#description' => t('The port of the Workerman server.'),
    );

    $form['server']['ssl_settings'] = array(
      '#type' => 'fieldset',
      '#title' => t('SSL Settings'),
      '#states' => array(
        'visible' => array(
          ':input[name="enable_ssl"]' => array('checked' => TRUE),
        ),
      )
    );

    $form['server']['ssl_settings']['ssl_cert'] = array(
      '#type' => 'managed_file',
      '#title' => t('Path to ssl certificate'),
      '#default_value' => $config->get('ssl_cert'),
      '#upload_validators' => array(
        'file_validate_extensions' => array('crt pem txt'),
      ),
      '#upload_location' => 'public://workerman/',
      '#states' => array(
        'visible' => array(
          ':input[name="enable_ssl"]' => array('checked' => TRUE),
        ),
      ),
    );
    $form['server']['ssl_settings']['ssl_cert_path'] = array(
      '#type' => 'textfield',
      '#title' => t('Path to ssl certificate path'),
      '#default_value' => $config->get('ssl_cert_path'),
      '#states' => array(
        'visible' => array(
          ':input[name="enable_ssl"]' => array('checked' => TRUE),
        ),
      ),
    );
    $form['server']['ssl_settings']['ssl_key'] = array(
      '#type' => 'managed_file',
      '#title' => t('Path to ssl key'),
      '#default_value' => $config->get('ssl_key'),
      '#upload_validators' => array(
        'file_validate_extensions' => array('crt pem txt'),
      ),
      '#upload_location' => 'public://workerman/',
      '#states' => array(
        'visible' => array(
          ':input[name="enable_ssl"]' => array('checked' => TRUE),
        )
      )
    );
    $form['server']['ssl_settings']['ssl_key_path'] = array(
      '#type' => 'textfield',
      '#title' => t('Path to ssl certificate key path'),
      '#default_value' => $config->get('ssl_key_path'),
      '#states' => array(
        'visible' => array(
          ':input[name="enable_ssl"]' => array('checked' => TRUE),
        ),
      ),
    );

    $form['backend'] = array(
      '#type' => 'fieldset',
      '#title' => 'Backend',
      '#tree' => TRUE
    );
    $form['backend']['scheme'] = array(
      '#type' => 'radios',
      '#title' => t('Protocol'),
      '#default_value' => $config->get('scheme'),
      '#options' => array('http' => t('http'), 'https' => t('https')),
      '#description' => t('The protocol of the Drupal site.'),
    );
    $form['backend']['host'] = array(
      '#type' => 'textfield',
      '#title' => 'Host',
      '#required' => TRUE,
      '#description' => 'Host name of the Drupal site.',
      '#default_value' => $config->get('backend.host'),
    );
    $form['backend']['port'] = array(
      '#type' => 'textfield',
      '#title' => 'Port',
      '#required' => TRUE,
      '#description' => 'TCP port of the server running the Drupal site. Usually 80.',
      '#default_value' => $config->get('backend.port'),
    );
    $form['backend']['message_path'] = array(
      '#type' => 'textfield',
      '#title' => 'Auth Path',
      '#description' => 'http path on which the Drupal Workerman module listens for authentication check
                       requests. Must end with /.',
      '#default_value' => $config->get('backend.message_path'),
    );

    return parent::buildForm($form, $form_state);
  }

  /**
   * Check if config variable is overridden by the settings.php.
   *
   * @param string $name
   *   SMTP settings key.
   *
   * @return bool
   *   Boolean.
   */
  protected function isOverridden($name) {
    $original = $this->configFactory->getEditable('workerman.settings')->get($name);
    $current = $this->configFactory->get('workerman.settings')->get($name);
    return $original != $current;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $settings = $form_state->getValues();

    if ($settings['enable_ssl']) {
      if (!$settings['ssl_cert'] && !$settings['ssl_cert_path']) {
        $form_state->setErrorByName('ssl_cert', t('SSL certificate not found.'));
      }
      if (!$settings['ssl_key'] && !$settings['ssl_key_path']) {
        $form_state->setErrorByName('ssl_key', t('SSL certificate key not found.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->cleanValues();
    $values = $form_state->getValues();
    $config = $values;
    if ($config['enable_ssl']) {
      if ($config['ssl_cert'] && $file = \Drupal::entityTypeManager()->getStorage('file')->load($config['ssl_cert'])) {
        $file->status = FILE_STATUS_PERMANENT;
        $file->save();
        $file_usage = \Drupal::service('file.usage');
        $file_usage->add($file, 'workerman', 'workerman_cert', \Drupal::currentUser()->uid);
        $config['ssl_cert'] = file_create_url($file->createFileUrl(TRUE));
      }
      if ($config['ssl_key'] && $file = \Drupal::entityTypeManager()->getStorage('file')->load($config['ssl_key'])) {
        $file->status = FILE_STATUS_PERMANENT;
        $file->save();
        $file_usage = \Drupal::service('file.usage');
        $file_usage->add($file, 'workerman', 'workerman_key', \Drupal::currentUser()->uid);
        $config['ssl_key'] = file_create_url($file->createFileUrl(TRUE));
      }
      if ($config['ssl_cert_path'] && file_exists($config['ssl_cert_path'])) {
        $config['ssl_cert'] = $config['ssl_cert_path'];
        $replacements["@ssl_cert"] = $config['ssl_cert_path'];
      }
      if ($config['ssl_key_path'] && file_exists($config['ssl_key_path'])) {
        $config['ssl_key'] = $config['ssl_key_path'];
        $replacements["@ssl_key"] = $config['ssl_key_path'];
      }
    }

    $this->config('workerman.settings')
      ->set('scheme', $values['scheme'])
      ->set('host', $values['host'])
      ->set('port', $values['port'])
      ->set('service_key', $values['service_key'])
      ->set('enable_ssl', $values['enable_ssl'])
      ->set('process', $values['process'])
      ->set('ssl_cert', $values['ssl_cert'])
      ->set('ssl_cert_path', $values['ssl_cert_path'])
      ->set('ssl_key_path', $values['ssl_key_path'])
      ->set('ssl_key', $values['ssl_key'])
      ->set('backend', $values['backend'])
      ->save();

    $this->messenger()->addMessage($this->t('The configuration options have been saved.'));
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'workerman.settings',
    ];
  }
}
