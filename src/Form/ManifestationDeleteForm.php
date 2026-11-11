<?php

namespace Drupal\traccar_provisioning\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\traccar_provisioning\Exception\TraccarApiException;
use Drupal\traccar_provisioning\Service\ManifestationManager;
use Drupal\traccar_provisioning\Service\TraccarApiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Deletes all Traccar devices associated with a manifestation.
 */
class ManifestationDeleteForm extends FormBase {

  /**
   * The manifestation storage.
   */
  protected ManifestationManager $manifestationManager;

  /**
   * The Traccar API client.
   */
  protected TraccarApiClient $apiClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = parent::create($container);
    $instance->manifestationManager = $container->get('traccar_provisioning.manifestation_manager');
    $instance->apiClient = $container->get('traccar_provisioning.api_client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'traccar_provisioning_manifestation_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $manifestations = $this->manifestationManager->getManifestationsWithDeviceCounts();

    if ($manifestations === []) {
      $form['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Aucune manifestation n’est enregistrée. Les appareils créés sans manifestation ne peuvent pas être supprimés depuis cette page.'),
      ];
      return $form;
    }

    $form['warning'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      '#value' => $this->t('Cette action supprime définitivement tous les appareils associés à la manifestation sélectionnée du serveur Traccar. Les QR codes correspondants ne fonctionneront plus.'),
    ];

    $form['manifestation_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Manifestation'),
      '#options' => ['' => $this->t('- Sélectionnez une manifestation -')] + $manifestations,
      '#required' => TRUE,
      '#default_value' => $form_state->getValue('manifestation_id', ''),
      '#description' => $this->t('Le nombre indiqué correspond aux appareils associés dans la base Drupal.'),
    ];

    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Je confirme vouloir supprimer définitivement ces appareils du serveur Traccar.'),
      '#required' => TRUE,
      '#default_value' => $form_state->getValue('confirm', FALSE),
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Supprimer les appareils'),
      '#button_type' => 'danger',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $manifestation_id = (int) $form_state->getValue('manifestation_id');
    if ($manifestation_id <= 0 || $this->manifestationManager->getManifestationName($manifestation_id) === NULL) {
      $form_state->setErrorByName('manifestation_id', $this->t('Sélectionnez une manifestation valide.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $manifestation_id = (int) $form_state->getValue('manifestation_id');
    $manifestation_name = $this->manifestationManager->getManifestationName($manifestation_id) ?? '';
    $device_ids = $this->manifestationManager->getDeviceIds($manifestation_id);

    if ($device_ids === []) {
      $this->messenger()->addWarning($this->t('Aucun appareil n’est associé à la manifestation « @name ».', [
        '@name' => $manifestation_name,
      ]));
      return;
    }

    $deleted = 0;
    $errors = [];

    foreach ($device_ids as $device_id) {
      try {
        $this->apiClient->deleteDevice($device_id);
      }
      catch (TraccarApiException $exception) {
        if ($exception->getStatusCode() !== 404) {
          $errors[] = $this->t('Appareil #@id : @message', [
            '@id' => $device_id,
            '@message' => $exception->getMessage(),
          ]);
          continue;
        }
        // The device is already gone from Traccar; remove the stale link.
      }
      catch (\Exception $exception) {
        $errors[] = $this->t('Appareil #@id : @message', [
          '@id' => $device_id,
          '@message' => $exception->getMessage(),
        ]);
        continue;
      }

      try {
        $this->manifestationManager->deleteDevice($device_id);
        $deleted++;
      }
      catch (\Exception $exception) {
        $errors[] = $this->t('Appareil #@id supprimé de Traccar mais association non supprimée : @message', [
          '@id' => $device_id,
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    if ($deleted > 0) {
      $this->messenger()->addStatus($this->formatPlural(
        $deleted,
        '1 appareil supprimé de la manifestation « @name ».',
        '@count appareils supprimés de la manifestation « @name ».',
        ['@name' => $manifestation_name],
      ));
    }

    if ($errors !== []) {
      $this->messenger()->addError($this->t('Certains appareils n’ont pas pu être supprimés : @errors', [
        '@errors' => implode(' ', $errors),
      ]));
    }
  }

}
