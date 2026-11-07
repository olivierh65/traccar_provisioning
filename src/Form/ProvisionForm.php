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

    $rows = [];
    foreach ($this->provisioningManager->getParameterSummary() as $label => $value) {
      $rows[] = [$label, $value];
    }

    $form['parameters'] = [
      '#type' => 'details',
      '#title' => $this->t('Paramètres de suivi appliqués'),
      '#open' => FALSE,
    ];
    $form['parameters']['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Paramètre'), $this->t('Valeur')],
      '#rows' => $rows,
      '#empty' => $this->t('Aucun paramètre.'),
    ];

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

    try {
      $result = $this->provisioningManager->createProvisioning($identifier, $name, $group_id);
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
