<?php

namespace Drupal\traccar_provisioning\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\traccar_provisioning\Exception\ProvisioningException;
use Drupal\traccar_provisioning\Exception\TraccarApiException;
use Drupal\traccar_provisioning\Service\ProvisioningManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Front-office form to create a Traccar device and generate its QR code.
 */
class ProvisionForm extends FormBase {

  /**
   * The provisioning manager.
   */
  protected ProvisioningManager $provisioningManager;

  /**
   * The private temp store factory.
   */
  protected PrivateTempStoreFactory $privateTempStoreFactory;

  /**
   * The logger channel.
   */
  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = parent::create($container);
    $instance->provisioningManager = $container->get('traccar_provisioning.provisioning_manager');
    $instance->privateTempStoreFactory = $container->get('tempstore.private');
    $instance->logger = $container->get('logger.channel.traccar_provisioning');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'traccar_provisioning_provision_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'traccar_provisioning/provisioning';
    $form['#attributes']['class'][] = 'traccar-provisioning-form';

    if (!$this->provisioningManager->isConfigured()) {
      $form['not_configured'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['messages', 'messages--error']],
        '#value' => $this->t("Le module Traccar Provisioning n'est pas encore configuré. Contactez un administrateur."),
      ];

      if ($this->currentUser()->hasPermission('administer traccar provisioning')) {
        $form['settings_link'] = Link::fromTextAndUrl(
          $this->t('Ouvrir la configuration du module'),
          Url::fromRoute('traccar_provisioning.settings')
        )->toRenderable();
      }

      return $form;
    }

    try {
      $groups = $this->provisioningManager->getGroups();
    }
    catch (TraccarApiException $exception) {
      $form['api_error'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['messages', 'messages--error']],
        '#value' => $this->t('Impossible de récupérer les groupes depuis le serveur Traccar : @message', [
          '@message' => $exception->getMessage(),
        ]),
      ];
      $form['retry'] = Link::fromTextAndUrl(
        $this->t('Réessayer'),
        Url::fromRoute('traccar_provisioning.provision')
      )->toRenderable();
      return $form;
    }

    $config = $this->configFactory()->get('traccar_provisioning.settings');
    $group_required = (bool) $config->get('group_required');

    $calendars = [];
    $calendars_error = NULL;
    try {
      $calendars = $this->provisioningManager->getCalendars();
    }
    catch (TraccarApiException $exception) {
      $calendars_error = $exception->getMessage();
    }

    if ($groups === []) {
      $form['no_groups'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        '#value' => $group_required
          ? $this->t('Aucun groupe n’est défini sur le serveur Traccar. Créez au moins un groupe dans Traccar avant de configurer un appareil.')
          : $this->t('Aucun groupe n’est défini sur le serveur Traccar. L’appareil sera créé sans groupe.'),
      ];
      if ($group_required) {
        return $form;
      }
    }

    $form['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Renseignez l’identifiant de l’appareil et choisissez son groupe. Le module prépare la configuration, crée l’appareil sur le serveur Traccar et génère le QR code à scanner dans l’application Traccar Client.'),
    ];

    $form['identifier'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Identifiant de l’appareil'),
      '#description' => $this->t('Nom, numéro de téléphone ou tout identifiant unique. Il doit être saisi à l’identique dans l’application Traccar Client.'),
      '#required' => TRUE,
      '#maxlength' => 128,
      '#default_value' => $form_state->getValue('identifier', ''),
      '#attributes' => ['autocomplete' => 'off'],
    ];

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nom affiché dans Traccar (optionnel)'),
      '#description' => $this->t('Laissez vide pour utiliser l’identifiant comme nom.'),
      '#maxlength' => 255,
      '#default_value' => $form_state->getValue('name', ''),
    ];

    $group_options = $groups;
    if (!$group_required) {
      $group_options = [0 => $this->t('Aucun groupe')] + $group_options;
    }

    $form['group_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Groupe'),
      '#description' => $this->t('La liste est chargée directement depuis le serveur Traccar.'),
      '#options' => $group_options,
      '#required' => $group_required,
      '#default_value' => $form_state->getValue('group_id', $group_required ? '' : 0),
    ];

    if ($group_required) {
      $form['group_id']['#empty_option'] = $this->t('- Sélectionnez un groupe -');
    }

    $form['device_details'] = [
      '#type' => 'details',
      '#title' => $this->t('Informations complémentaires de l’appareil (optionnel)'),
      '#open' => TRUE,
      '#description' => $this->t('Ces informations sont enregistrées sur le serveur Traccar. Elles ne sont pas transmises par le QR code.'),
    ];

    if ($calendars_error !== NULL) {
      $form['device_details']['calendars_warning'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        '#value' => $this->t('Impossible de charger les calendriers Traccar : @message', [
          '@message' => $calendars_error,
        ]),
      ];
    }

    $form['device_details']['phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Téléphone'),
      '#description' => $this->t('Numéro associé à l’appareil, utilisé notamment pour les commandes SMS.'),
      '#maxlength' => 255,
      '#default_value' => $form_state->getValue('phone', ''),
    ];

    $form['device_details']['model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Modèle'),
      '#description' => $this->t('Modèle ou référence matérielle de l’appareil.'),
      '#maxlength' => 255,
      '#default_value' => $form_state->getValue('model', ''),
    ];

    $form['device_details']['contact'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Contact'),
      '#description' => $this->t('Personne responsable de l’appareil.'),
      '#maxlength' => 255,
      '#default_value' => $form_state->getValue('contact', ''),
    ];

    $form['device_details']['category'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Catégorie'),
      '#description' => $this->t('Catégorie libre utilisée pour regrouper les appareils dans l’interface Traccar.'),
      '#maxlength' => 255,
      '#default_value' => $form_state->getValue('category', ''),
    ];

    $form['device_details']['calendar_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Calendrier'),
      '#description' => $calendars === []
        ? $this->t('Aucun calendrier n’est défini sur le serveur Traccar.')
        : $this->t('Le calendrier filtre les positions de l’appareil selon ses plages horaires.'),
      '#options' => [0 => $this->t('Aucun calendrier')] + $calendars,
      '#default_value' => (int) $form_state->getValue('calendar_id', 0),
    ];

    $form['device_details']['expiration'] = [
      '#type' => 'date',
      '#title' => $this->t('Expiration'),
      '#description' => $this->t('Après cette date, Traccar refuse les nouvelles positions de l’appareil. Les données existantes restent consultables. La date est appliquée en fin de journée, dans le fuseau horaire du site.'),
      '#default_value' => $form_state->getValue('expiration', ''),
    ];

    $form['parameters'] = [
      '#type' => 'details',
      '#title' => $this->t('Paramètres de suivi appliqués'),
      '#open' => TRUE,
      '#description' => $this->t('Ces valeurs sont intégrées dans l’URL et le QR code générés pour cet appareil. Elles sont préremplies avec les valeurs par défaut du module et peuvent être adaptées.'),
    ];

    $form['parameters']['accuracy'] = [
      '#type' => 'select',
      '#title' => $this->t('Précision'),
      '#options' => [
        'highest' => $this->t('Maximale (GPS permanent)'),
        'high' => $this->t('Haute'),
        'medium' => $this->t('Moyenne'),
        'low' => $this->t('Basse'),
      ],
      '#default_value' => $form_state->getValue('accuracy', (string) $config->get('accuracy') ?: 'high'),
      '#required' => TRUE,
    ];

    $number_fields = [
      'distance' => [
        'title' => $this->t('Distance minimale (mètres)'),
        'max' => 100000,
      ],
      'interval' => [
        'title' => $this->t('Intervalle (secondes)'),
        'max' => 86400,
      ],
      'angle' => [
        'title' => $this->t('Angle (degrés)'),
        'max' => 360,
      ],
      'heartbeat' => [
        'title' => $this->t('Heartbeat stationnaire (secondes)'),
        'max' => 86400,
      ],
    ];
    foreach ($number_fields as $key => $definition) {
      $form['parameters'][$key] = [
        '#type' => 'number',
        '#title' => $definition['title'],
        '#default_value' => $form_state->getValue($key, (int) $config->get($key)),
        '#min' => 0,
        '#max' => $definition['max'],
        '#required' => TRUE,
      ];
    }

    $checkbox_fields = [
      'buffer' => $this->t('Activer le buffer hors ligne'),
      'wakelock' => $this->t('Activer le wake lock (Android)'),
      'stop_detection' => $this->t('Activer la détection des arrêts'),
      'prefer_platform_providers' => $this->t('Préférer les fournisseurs de localisation du système (Android)'),
    ];
    foreach ($checkbox_fields as $key => $title) {
      $form['parameters'][$key] = [
        '#type' => 'checkbox',
        '#title' => $title,
        '#default_value' => $form_state->getValue($key, (bool) $config->get($key)),
      ];
    }

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Créer l’appareil et générer le QR code'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $identifier = trim((string) $form_state->getValue('identifier'));
    $error = $this->provisioningManager->validateIdentifier($identifier);
    if ($error !== NULL) {
      $form_state->setErrorByName('identifier', $error);
    }

    $name = trim((string) $form_state->getValue('name'));
    if (mb_strlen($name) > 255) {
      $form_state->setErrorByName('name', $this->t('Le nom ne peut pas dépasser 255 caractères.'));
    }

    $labels = [
      'phone' => $this->t('Téléphone'),
      'model' => $this->t('Modèle'),
      'contact' => $this->t('Contact'),
      'category' => $this->t('Catégorie'),
    ];
    foreach ($labels as $key => $label) {
      if (mb_strlen(trim((string) $form_state->getValue($key))) > 255) {
        $form_state->setErrorByName($key, $this->t('Le champ « @label » ne peut pas dépasser 255 caractères.', [
          '@label' => $label,
        ]));
      }
    }

    $expiration = trim((string) $form_state->getValue('expiration'));
    if ($expiration !== '') {
      $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $expiration);
      $errors = \DateTimeImmutable::getLastErrors();
      if ($date === FALSE || ($errors !== FALSE && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        $form_state->setErrorByName('expiration', $this->t('La date d’expiration est invalide.'));
      }
    }

    if (!in_array((string) $form_state->getValue('accuracy'), ['highest', 'high', 'medium', 'low'], TRUE)) {
      $form_state->setErrorByName('accuracy', $this->t('Niveau de précision invalide.'));
    }

    $number_limits = [
      'distance' => 100000,
      'interval' => 86400,
      'angle' => 360,
      'heartbeat' => 86400,
    ];
    foreach ($number_limits as $key => $max) {
      $value = (int) $form_state->getValue($key);
      if ($value < 0 || $value > $max) {
        $form_state->setErrorByName($key, $this->t('Cette valeur doit être comprise entre 0 et @max.', [
          '@max' => $max,
        ]));
      }
    }

    $config = $this->configFactory()->get('traccar_provisioning.settings');
    if ((bool) $config->get('group_required') && (int) $form_state->getValue('group_id') <= 0) {
      $form_state->setErrorByName('group_id', $this->t('Vous devez sélectionner un groupe.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $identifier = trim((string) $form_state->getValue('identifier'));
    $name = trim((string) $form_state->getValue('name'));
    $group_id = (int) $form_state->getValue('group_id');
    $device_data = [
      'phone' => trim((string) $form_state->getValue('phone')),
      'model' => trim((string) $form_state->getValue('model')),
      'contact' => trim((string) $form_state->getValue('contact')),
      'category' => trim((string) $form_state->getValue('category')),
      'calendar_id' => (int) $form_state->getValue('calendar_id', 0),
      'expiration' => trim((string) $form_state->getValue('expiration')),
    ];
    $tracking = [
      'accuracy' => (string) $form_state->getValue('accuracy'),
      'distance' => (int) $form_state->getValue('distance'),
      'interval' => (int) $form_state->getValue('interval'),
      'angle' => (int) $form_state->getValue('angle'),
      'heartbeat' => (int) $form_state->getValue('heartbeat'),
      'buffer' => (bool) $form_state->getValue('buffer'),
      'wakelock' => (bool) $form_state->getValue('wakelock'),
      'stop_detection' => (bool) $form_state->getValue('stop_detection'),
      'prefer_platform_providers' => (bool) $form_state->getValue('prefer_platform_providers'),
    ];

    try {
      $result = $this->provisioningManager->createProvisioning($identifier, $name, $group_id, $device_data, $tracking);
    }
    catch (ProvisioningException $exception) {
      $this->messenger()->addError($exception->getMessage());
      $form_state->setRebuild(TRUE);
      return;
    }
    catch (TraccarApiException $exception) {
      $this->logger->error('Unable to provision Traccar device @identifier: @message', [
        '@identifier' => $identifier,
        '@message' => $exception->getMessage(),
      ]);
      $this->messenger()->addError($this->t('La création de l’appareil a échoué : @message', [
        '@message' => $exception->getMessage(),
      ]));
      $form_state->setRebuild(TRUE);
      return;
    }
    catch (\RuntimeException $exception) {
      $this->logger->error('Unable to generate the Traccar client configuration for @identifier: @message', [
        '@identifier' => $identifier,
        '@message' => $exception->getMessage(),
      ]);
      $this->messenger()->addError($this->t('La génération de la configuration a échoué : @message', [
        '@message' => $exception->getMessage(),
      ]));
      $form_state->setRebuild(TRUE);
      return;
    }

    $token = bin2hex(random_bytes(16));
    $store = $this->privateTempStoreFactory->get('traccar_provisioning');
    $store->set($token, $result, 86400);

    $form_state->setRedirect('traccar_provisioning.result', ['token' => $token]);
  }

}
