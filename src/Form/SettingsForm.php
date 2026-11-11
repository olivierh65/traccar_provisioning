<?php

namespace Drupal\traccar_provisioning\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\traccar_provisioning\Exception\TraccarApiException;
use Drupal\traccar_provisioning\Service\TraccarApiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Back-office configuration form for Traccar Provisioning.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The Traccar API client.
   */
  protected TraccarApiClient $apiClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = parent::create($container);
    $instance->apiClient = $container->get('traccar_provisioning.api_client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['traccar_provisioning.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'traccar_provisioning_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('traccar_provisioning.settings');
    $has_password = (string) $config->get('password') !== '';
    $has_token = (string) $config->get('api_token') !== '';

    $form['security_notice'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      '#value' => $this->t("Le token et le mot de passe sont enregistrés dans la configuration Drupal. Restreignez l'accès à cette page et n'exportez pas ces valeurs dans un dépôt public. Un token API est recommandé."),
    ];

    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Connexion au serveur Traccar'),
      '#open' => TRUE,
    ];

    $form['connection']['api_url'] = [
      '#type' => 'url',
      '#title' => $this->t('URL de base de l’API Traccar'),
      '#description' => $this->t('Exemple : https://traccar.example.com:8082. Le module ajoute automatiquement le suffixe /api s’il est absent.'),
      '#default_value' => (string) $config->get('api_url'),
      '#required' => TRUE,
    ];

    $form['connection']['client_url'] = [
      '#type' => 'url',
      '#title' => $this->t('URL de suivi pour Traccar Client'),
      '#description' => $this->t('URL complète utilisée par l’application Traccar Client, avec le port de suivi. Exemple : https://traccar.example.com:5055.'),
      '#default_value' => (string) $config->get('client_url'),
      '#required' => TRUE,
    ];

    $form['connection']['auth_method'] = [
      '#type' => 'select',
      '#title' => $this->t('Méthode d’authentification'),
      '#options' => [
        'token' => $this->t('Token API (recommandé)'),
        'basic' => $this->t('Email / mot de passe'),
      ],
      '#default_value' => (string) $config->get('auth_method') ?: 'token',
      '#required' => TRUE,
    ];

    $form['connection']['api_token'] = [
      '#type' => 'password',
      '#title' => $this->t('Token API Traccar'),
      '#description' => $has_token
        ? $this->t('Un token est déjà enregistré. Laissez vide pour le conserver.')
        : $this->t('Token généré dans Traccar (Paramètres du compte ou API). Utilisez de préférence un compte administrateur pour lister tous les groupes et créer les appareils.'),
      '#default_value' => '',
      // Drupal's password element defaults to #maxlength 128, which silently
      // truncates long Traccar tokens in the browser.
      '#maxlength' => 512,
      '#attributes' => ['autocomplete' => 'off'],
      '#states' => [
        'visible' => [
          ':input[name="auth_method"]' => ['value' => 'token'],
        ],
      ],
    ];

    $form['connection']['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email ou identifiant Traccar'),
      '#description' => $this->t('Utilisez de préférence un compte administrateur pour lister tous les groupes et créer les appareils.'),
      '#default_value' => (string) $config->get('username'),
      '#attributes' => ['autocomplete' => 'off'],
      '#states' => [
        'visible' => [
          ':input[name="auth_method"]' => ['value' => 'basic'],
        ],
      ],
    ];

    $form['connection']['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Mot de passe Traccar'),
      '#description' => $has_password
        ? $this->t('Un mot de passe est déjà enregistré. Laissez vide pour le conserver.')
        : $this->t('Mot de passe du compte Traccar utilisé pour créer les appareils.'),
      '#default_value' => '',
      '#attributes' => ['autocomplete' => 'off'],
      '#states' => [
        'visible' => [
          ':input[name="auth_method"]' => ['value' => 'basic'],
        ],
      ],
    ];

    $form['connection']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Délai d’attente HTTP (secondes)'),
      '#default_value' => (int) $config->get('timeout'),
      '#min' => 1,
      '#max' => 120,
      '#required' => TRUE,
    ];

    $form['connection']['verify_ssl'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Vérifier les certificats SSL'),
      '#default_value' => (bool) $config->get('verify_ssl'),
      '#description' => $this->t('À désactiver uniquement pour un serveur de test avec un certificat auto-signé.'),
    ];

    $form['client'] = [
      '#type' => 'details',
      '#title' => $this->t('Paramètres par défaut du client Traccar'),
      '#open' => TRUE,
      '#description' => $this->t('Ces valeurs sont intégrées dans l’URL et le QR code. Elles peuvent être adaptées par l’application Traccar Client.'),
    ];

    $form['client']['accuracy'] = [
      '#type' => 'select',
      '#title' => $this->t('Précision de localisation'),
      '#options' => [
        'highest' => $this->t('Maximale (GPS permanent)'),
        'high' => $this->t('Haute'),
        'medium' => $this->t('Moyenne'),
        'low' => $this->t('Basse'),
      ],
      '#default_value' => (string) $config->get('accuracy') ?: 'high',
      '#required' => TRUE,
    ];

    $form['client']['distance'] = [
      '#type' => 'number',
      '#title' => $this->t('Distance minimale (mètres)'),
      '#default_value' => (int) $config->get('distance'),
      '#min' => 0,
      '#max' => 100000,
      '#required' => TRUE,
    ];

    $form['client']['interval'] = [
      '#type' => 'number',
      '#title' => $this->t('Intervalle (secondes)'),
      '#default_value' => (int) $config->get('interval'),
      '#min' => 0,
      '#max' => 86400,
      '#required' => TRUE,
    ];

    $form['client']['angle'] = [
      '#type' => 'number',
      '#title' => $this->t('Angle (degrés)'),
      '#default_value' => (int) $config->get('angle'),
      '#min' => 0,
      '#max' => 360,
      '#required' => TRUE,
    ];

    $form['client']['heartbeat'] = [
      '#type' => 'number',
      '#title' => $this->t('Heartbeat stationnaire (secondes)'),
      '#default_value' => (int) $config->get('heartbeat'),
      '#min' => 0,
      '#max' => 86400,
      '#required' => TRUE,
    ];

    $form['client']['buffer'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Activer le buffer hors ligne'),
      '#default_value' => (bool) $config->get('buffer'),
    ];

    $form['client']['wakelock'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Activer le wake lock (Android)'),
      '#default_value' => (bool) $config->get('wakelock'),
    ];

    $form['client']['stop_detection'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Activer la détection des arrêts'),
      '#default_value' => (bool) $config->get('stop_detection'),
    ];

    $form['client']['prefer_platform_providers'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Préférer les fournisseurs de localisation du système (Android)'),
      '#default_value' => (bool) $config->get('prefer_platform_providers'),
      '#description' => $this->t('Force l’utilisation du fournisseur de localisation Android au lieu de Google Play Services.'),
    ];

    $form['provisioning'] = [
      '#type' => 'details',
      '#title' => $this->t('Création des appareils'),
      '#open' => TRUE,
    ];

    $form['provisioning']['group_required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Imposer la sélection d’un groupe'),
      '#default_value' => (bool) $config->get('group_required'),
      '#description' => $this->t('Si activé, l’utilisateur devra choisir un groupe existant sur le serveur Traccar.'),
    ];

    $form['provisioning']['identifier_pattern'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Format d’identifiant autorisé'),
      '#default_value' => (string) $config->get('identifier_pattern'),
      '#description' => $this->t('Expression régulière PCRE optionnelle, par exemple <code>^[0-9]{6,12}$</code> pour n’accepter que des numéros. Laissez vide pour accepter tous les identifiants valides pour Traccar.'),
    ];

    $form['provisioning']['allowed_categories'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Catégories autorisées'),
      '#default_value' => (string) $config->get('allowed_categories'),
      '#rows' => 5,
      '#description' => $this->t('Une catégorie par ligne, ou plusieurs séparées par des virgules. Si la liste est vide, toute catégorie est acceptée. Traccar ne fournit pas de référentiel de catégories : cette liste permet de vérifier les catégories saisies dans les lots.'),
    ];

    $form['provisioning']['manifestation_delete_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Supprimer les appareils d’une manifestation'),
      '#url' => Url::fromRoute('traccar_provisioning.manifestation_delete'),
    ];

    $form['qr'] = [
      '#type' => 'details',
      '#title' => $this->t('QR code'),
      '#open' => TRUE,
    ];

    $form['qr']['qr_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Taille du QR code (pixels)'),
      '#default_value' => (int) $config->get('qr_size'),
      '#min' => 100,
      '#max' => 1000,
      '#required' => TRUE,
    ];

    $form['qr']['qr_error_correction'] = [
      '#type' => 'select',
      '#title' => $this->t('Niveau de correction d’erreur'),
      '#options' => [
        'L' => $this->t('L — 7 %'),
        'M' => $this->t('M — 15 % (recommandé)'),
        'Q' => $this->t('Q — 25 %'),
        'H' => $this->t('H — 30 %'),
      ],
      '#default_value' => (string) $config->get('qr_error_correction') ?: 'M',
      '#required' => TRUE,
    ];

    $form['qr']['qr_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Clé API de l’endpoint QR'),
      '#default_value' => (string) $config->get('qr_api_key'),
      '#maxlength' => 255,
      '#description' => $this->t('Clé secrète à ajouter dans le paramètre <code>key</code> des URL de génération de QR codes. Utilisez une chaîne aléatoire d’au moins 16 caractères. Laissez vide pour désactiver l’endpoint. Exemple : <code>@url?id=IDENTIFIANT&amp;key=VOTRE_CLE&amp;format=png</code>', [
        '@url' => Url::fromRoute('traccar_provisioning.qr', [], ['absolute' => TRUE])->toString(),
      ]),
      '#attributes' => ['autocomplete' => 'off'],
    ];

    $form['qr']['qr_batch_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Générer des QR codes en lot'),
      '#url' => Url::fromRoute('traccar_provisioning.qr_batch'),
    ];

    $form = parent::buildForm($form, $form_state);

    $form['actions']['test_connection'] = [
      '#type' => 'submit',
      '#value' => $this->t('Tester la connexion'),
      '#name' => 'test_connection',
      '#button_type' => 'secondary',
      '#submit' => ['::submitTestConnection'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $config = $this->config('traccar_provisioning.settings');

    foreach (['api_url' => 'URL de l’API', 'client_url' => 'URL du client'] as $key => $label) {
      $url = trim((string) $values[$key]);
      $parts = parse_url($url);
      if (
        $url === '' ||
        !is_array($parts) ||
        empty($parts['scheme']) ||
        empty($parts['host']) ||
        !in_array(strtolower($parts['scheme']), ['http', 'https'], TRUE)
      ) {
        $form_state->setErrorByName($key, $this->t('@label invalide. Utilisez une URL complète en http:// ou https://.', ['@label' => $label]));
      }
    }

    if (!in_array($values['auth_method'], ['token', 'basic'], TRUE)) {
      $form_state->setErrorByName('auth_method', $this->t('Méthode d’authentification invalide.'));
    }

    if ($values['auth_method'] === 'token') {
      if (trim((string) $values['api_token']) === '' && (string) $config->get('api_token') === '') {
        $form_state->setErrorByName('api_token', $this->t('Un token API Traccar est requis.'));
      }
    }
    else {
      if (trim((string) $values['username']) === '') {
        $form_state->setErrorByName('username', $this->t('L’email ou l’identifiant Traccar est requis.'));
      }
      if ((string) $values['password'] === '' && (string) $config->get('password') === '') {
        $form_state->setErrorByName('password', $this->t('Le mot de passe Traccar est requis.'));
      }
    }

    if ((int) $values['timeout'] < 1 || (int) $values['timeout'] > 120) {
      $form_state->setErrorByName('timeout', $this->t('Le délai doit être compris entre 1 et 120 secondes.'));
    }

    if (!in_array((string) $values['accuracy'], ['highest', 'high', 'medium', 'low'], TRUE)) {
      $form_state->setErrorByName('accuracy', $this->t('Niveau de précision invalide.'));
    }

    foreach (['distance', 'interval', 'angle', 'heartbeat'] as $key) {
      if ((int) $values[$key] < 0) {
        $form_state->setErrorByName($key, $this->t('Cette valeur ne peut pas être négative.'));
      }
    }

    if ((int) $values['qr_size'] < 100 || (int) $values['qr_size'] > 1000) {
      $form_state->setErrorByName('qr_size', $this->t('La taille du QR code doit être comprise entre 100 et 1000 pixels.'));
    }

    if (!in_array((string) $values['qr_error_correction'], ['L', 'M', 'Q', 'H'], TRUE)) {
      $form_state->setErrorByName('qr_error_correction', $this->t('Niveau de correction d’erreur invalide.'));
    }

    $qr_api_key = trim((string) $values['qr_api_key']);
    if ($qr_api_key !== '' && mb_strlen($qr_api_key) < 16) {
      $form_state->setErrorByName('qr_api_key', $this->t('La clé API QR doit contenir au moins 16 caractères.'));
    }

    $pattern = trim((string) $values['identifier_pattern']);
    if ($pattern !== '' && @preg_match($pattern, '') === FALSE) {
      $form_state->setErrorByName('identifier_pattern', $this->t('L’expression régulière est invalide.'));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * Submit handler for the test connection button.
   */
  public function submitTestConnection(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $overrides = [
      'api_url' => rtrim(trim((string) $values['api_url']), '/'),
      'auth_method' => (string) $values['auth_method'],
      'username' => trim((string) $values['username']),
      'timeout' => (int) $values['timeout'],
      'verify_ssl' => (bool) $values['verify_ssl'],
    ];

    // Keep stored secrets when the password fields are left empty.
    if (trim((string) $values['api_token']) !== '') {
      $overrides['api_token'] = trim((string) $values['api_token']);
    }
    if ((string) $values['password'] !== '') {
      $overrides['password'] = (string) $values['password'];
    }

    try {
      $groups = $this->apiClient->testConnection($overrides);
      $this->messenger()->addStatus($this->t('Connexion réussie. @count groupe(s) trouvé(s) sur le serveur Traccar. Enregistrez la configuration pour conserver ces paramètres.', [
        '@count' => count($groups),
      ]));
    }
    catch (TraccarApiException $exception) {
      $this->messenger()->addError($this->t('Échec du test de connexion : @message', [
        '@message' => $exception->getMessage(),
      ]));
    }

    $form_state->setRebuild(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->saveSettings($form_state);
    $this->messenger()->addStatus($this->t('La configuration Traccar Provisioning a été enregistrée.'));
  }

  /**
   * Persists submitted values to module configuration.
   */
  protected function saveSettings(FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $config = $this->configFactory()->getEditable('traccar_provisioning.settings');
    $auth_method = in_array($values['auth_method'], ['token', 'basic'], TRUE) ? $values['auth_method'] : 'token';

    $config->set('api_url', rtrim(trim((string) $values['api_url']), '/'))
      ->set('client_url', rtrim(trim((string) $values['client_url']), '/'))
      ->set('auth_method', $auth_method)
      ->set('username', trim((string) $values['username']))
      ->set('timeout', (int) $values['timeout'])
      ->set('verify_ssl', (bool) $values['verify_ssl'])
      ->set('accuracy', (string) $values['accuracy'])
      ->set('distance', (int) $values['distance'])
      ->set('interval', (int) $values['interval'])
      ->set('angle', (int) $values['angle'])
      ->set('heartbeat', (int) $values['heartbeat'])
      ->set('buffer', (bool) $values['buffer'])
      ->set('wakelock', (bool) $values['wakelock'])
      ->set('stop_detection', (bool) $values['stop_detection'])
      ->set('prefer_platform_providers', (bool) $values['prefer_platform_providers'])
      ->set('group_required', (bool) $values['group_required'])
      ->set('identifier_pattern', trim((string) $values['identifier_pattern']))
      ->set('allowed_categories', trim((string) $values['allowed_categories']))
      ->set('qr_size', (int) $values['qr_size'])
      ->set('qr_error_correction', (string) $values['qr_error_correction'])
      ->set('qr_api_key', trim((string) $values['qr_api_key']));

    // Only overwrite the secret used by the selected authentication method,
    // and only when the user entered a new value.
    if ($auth_method === 'basic' && (string) $values['password'] !== '') {
      $config->set('password', (string) $values['password']);
    }
    if ($auth_method === 'token' && trim((string) $values['api_token']) !== '') {
      $config->set('api_token', trim((string) $values['api_token']));
    }

    $config->save();
  }

}
