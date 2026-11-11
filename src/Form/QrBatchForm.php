<?php

namespace Drupal\traccar_provisioning\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\traccar_provisioning\Exception\ProvisioningException;
use Drupal\traccar_provisioning\Exception\TraccarApiException;
use Drupal\traccar_provisioning\Service\ManifestationManager;
use Drupal\traccar_provisioning\Service\ProvisioningManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generates a printable sheet of QR codes for a list of device identifiers.
 *
 * Each line can contain the device creation parameters, so a spreadsheet can
 * be pasted directly into the form.
 */
class QrBatchForm extends FormBase {

  /**
   * The provisioning manager.
   */
  protected ProvisioningManager $provisioningManager;

  /**
   * The manifestation storage.
   */
  protected ManifestationManager $manifestationManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = parent::create($container);
    $instance->provisioningManager = $container->get('traccar_provisioning.provisioning_manager');
    $instance->manifestationManager = $container->get('traccar_provisioning.manifestation_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'traccar_provisioning_qr_batch_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->configFactory()->get('traccar_provisioning.settings');

    if (trim((string) $config->get('client_url')) === '') {
      $form['not_configured'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['messages', 'messages--error']],
        '#value' => $this->t('L’URL du client Traccar n’est pas configurée. Renseignez-la dans les paramètres du module avant de générer des QR codes.'),
      ];
      $form['settings_link'] = [
        '#type' => 'link',
        '#title' => $this->t('Ouvrir la configuration du module'),
        '#url' => Url::fromRoute('traccar_provisioning.settings'),
      ];
      return $form;
    }

    $form['#attached']['library'][] = 'traccar_provisioning/provisioning';
    $form['#attributes']['class'][] = 'traccar-qr-batch-form';

    if (trim((string) $config->get('qr_api_key')) === '') {
      $form['api_key_warning'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        '#value' => $this->t('La clé API QR est vide : l’endpoint /traccar/qr est désactivé. La génération en lot ci-dessous fonctionne quand même, mais la macro LibreOffice Calc ne pourra pas télécharger les images.'),
      ];
    }

    $groups = [];
    $groups_error = NULL;
    $calendars = [];
    $calendars_error = NULL;

    if ($this->provisioningManager->isConfigured()) {
      try {
        $groups = $this->provisioningManager->getGroups();
      }
      catch (TraccarApiException $exception) {
        $groups_error = $exception->getMessage();
      }

      try {
        $calendars = $this->provisioningManager->getCalendars();
      }
      catch (TraccarApiException $exception) {
        $calendars_error = $exception->getMessage();
      }
    }
    else {
      $groups_error = (string) $this->t('Le module n’est pas configuré pour accéder à l’API Traccar. Renseignez l’URL de l’API et l’authentification dans les paramètres.');
    }

    $form_state->set('qr_batch_groups_error', $groups_error);
    $form_state->set('qr_batch_calendars_error', $calendars_error);
    $form_state->set('qr_batch_groups', $groups);
    $form_state->set('qr_batch_calendars', $calendars);

    $allowed_categories = $this->provisioningManager->getAllowedCategories();

    $form['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['traccar-qr-batch-form__intro']],
      '#value' => $this->t('Collez une liste d’appareils, un par ligne. Chaque ligne contient l’identifiant et, si nécessaire, ses propres paramètres de création. Les champs vides sont autorisés.'),
    ];

    $form['fields'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['traccar-qr-batch-form__fields']],
    ];

    $form['fields']['identifiers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Appareils à traiter'),
      '#description' => $this->t('Un appareil par ligne, champs séparés par des points-virgules : <code>identifiant;libellé;groupe;téléphone;modèle;contact;catégorie;calendrier;date d’expiration</code>.<br>Exemple : <code>M1;Marshall 1;;;;;;;15/11/2026</code>. Une ligne d’en-tête est ignorée. Les lignes vides sont ignorées et les doublons sont signalés en erreur.'),
      '#rows' => 12,
      '#required' => TRUE,
      '#default_value' => $form_state->getValue('identifiers', ''),
    ];

    $form['fields']['help'] = [
      '#type' => 'details',
      '#title' => $this->t('Format et valeurs disponibles'),
      '#open' => FALSE,
    ];

    $form['fields']['help']['format'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Colonnes : <code>identifiant;libellé;groupe;téléphone;modèle;contact;catégorie;calendrier;date d’expiration</code>.<br>Dates acceptées : <code>jj/mm/aaaa</code>, <code>jj-mm-aaaa</code>, <code>jj.mm.aaaa</code> ou <code>aaaa-mm-jj</code>.<br>Pour un groupe ou un calendrier, vous pouvez utiliser son nom ou son ID. Si un champ contient un point-virgule, entourez-le de guillemets doubles.'),
    ];

    if ($groups !== []) {
      $items = [];
      foreach ($groups as $id => $name) {
        $items[] = $this->t('@name (ID : @id)', [
          '@name' => $name,
          '@id' => $id,
        ]);
      }
      $form['fields']['help']['groups'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('<strong>Groupes disponibles :</strong> @groups', [
          '@groups' => implode(', ', $items),
        ]),
      ];
    }

    if ($calendars !== []) {
      $items = [];
      foreach ($calendars as $id => $name) {
        $items[] = $this->t('@name (ID : @id)', [
          '@name' => $name,
          '@id' => $id,
        ]);
      }
      $form['fields']['help']['calendars'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('<strong>Calendriers disponibles :</strong> @calendars', [
          '@calendars' => implode(', ', $items),
        ]),
      ];
    }

    $form['fields']['help']['categories'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $allowed_categories === []
        ? $this->t('Aucune liste de catégories autorisées n’est configurée : les catégories sont acceptées comme texte libre. Vous pouvez définir une liste dans les paramètres du module.')
        : $this->t('<strong>Catégories autorisées :</strong> @categories', [
          '@categories' => implode(', ', array_values($allowed_categories)),
        ]),
    ];

    $form['fields']['create_devices'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Créer les appareils sur le serveur Traccar'),
      '#description' => $this->t('Si cette option est cochée, chaque appareil est créé sur le serveur Traccar avec les paramètres de sa ligne. Les champs serveur vides ne sont pas envoyés. Les identifiants déjà existants seront signalés en erreur.'),
      '#default_value' => (bool) ($form_state->getValue('create_devices') ?? $this->shouldCreateDevices($form_state)),
    ];

    $manifestation_options = [0 => $this->t('Aucune manifestation')] + $this->manifestationManager->getManifestations();
    $manifestation_options['new'] = $this->t('Créer une nouvelle manifestation…');

    $form['fields']['manifestation'] = [
      '#type' => 'details',
      '#title' => $this->t('Manifestation'),
      '#open' => TRUE,
      '#description' => $this->t('Rattache tous les appareils créés par ce lot à une manifestation. Cela permet de les supprimer en une seule fois à la fin de l’événement.'),
      '#states' => [
        'visible' => [
          ':input[name="create_devices"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['fields']['manifestation']['manifestation_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Manifestation associée'),
      '#options' => $manifestation_options,
      '#default_value' => $form_state->getValue('manifestation_id', 0),
      '#description' => $this->t('Laissez « Aucune manifestation » pour ne pas suivre ces appareils dans un lot de suppression.'),
    ];

    $form['fields']['manifestation']['manifestation_new'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nom de la nouvelle manifestation'),
      '#maxlength' => 255,
      '#default_value' => $form_state->getValue('manifestation_new', ''),
      '#states' => [
        'visible' => [
          ':input[name="manifestation_id"]' => ['value' => 'new'],
        ],
      ],
    ];

    $form['fields']['creation_warnings'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="create_devices"]' => ['checked' => TRUE],
        ],
      ],
    ];

    if ($groups_error !== NULL) {
      $form['fields']['creation_warnings']['groups_warning'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        '#value' => $this->t('Impossible de charger les groupes Traccar : @message', [
          '@message' => $groups_error,
        ]),
      ];
    }

    if ($calendars_error !== NULL) {
      $form['fields']['creation_warnings']['calendars_warning'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        '#value' => $this->t('Impossible de charger les calendriers Traccar : @message', [
          '@message' => $calendars_error,
        ]),
      ];
    }

    $form['fields']['parameters'] = [
      '#type' => 'details',
      '#title' => $this->t('Paramètres de suivi appliqués'),
      '#open' => TRUE,
      '#description' => $this->t('Ces valeurs sont intégrées dans toutes les URL et tous les QR codes générés. Elles ne concernent pas les paramètres de création des appareils. Elles sont préremplies avec les valeurs par défaut du module.'),
    ];

    $form['fields']['parameters']['accuracy'] = [
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
      $form['fields']['parameters'][$key] = [
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
      $form['fields']['parameters'][$key] = [
        '#type' => 'checkbox',
        '#title' => $title,
        '#default_value' => $form_state->getValue($key, (bool) $config->get($key)),
      ];
    }

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Générer les QR codes'),
      '#button_type' => 'primary',
    ];

    $results = $form_state->get('qr_results');
    if (is_array($results) && $results !== []) {
      $form['results'] = [
        '#theme' => 'traccar_qr_batch_results',
        '#results' => $results,
        '#weight' => 100,
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $create_devices = $this->shouldCreateDevices($form_state);
    $parsed = $this->parseRows($form_state, $create_devices);
    $rows = $parsed['rows'];
    $errors = $parsed['errors'];

    if ($rows === [] && $errors === []) {
      $form_state->setErrorByName('identifiers', $this->t('Saisissez au moins un appareil.'));
    }

    if ($errors !== []) {
      $form_state->setErrorByName('identifiers', implode(' ', $errors));
    }

    if ($create_devices) {
      $groups_error = $form_state->get('qr_batch_groups_error');
      if ($groups_error !== NULL) {
        $form_state->setErrorByName('create_devices', $this->t('Impossible de créer les appareils : @message', [
          '@message' => $groups_error,
        ]));
      }

      $manifestation_id = (string) $form_state->getValue('manifestation_id', '0');
      if ($manifestation_id === 'new') {
        $manifestation_name = trim((string) $form_state->getValue('manifestation_new'));
        if ($manifestation_name === '') {
          $form_state->setErrorByName('manifestation_new', $this->t('Saisissez le nom de la nouvelle manifestation.'));
        }
        elseif (mb_strlen($manifestation_name) > 255) {
          $form_state->setErrorByName('manifestation_new', $this->t('Le nom de la manifestation ne peut pas dépasser 255 caractères.'));
        }
      }
      elseif ((int) $manifestation_id > 0 && $this->manifestationManager->getManifestationName((int) $manifestation_id) === NULL) {
        $form_state->setErrorByName('manifestation_id', $this->t('La manifestation sélectionnée n’existe plus.'));
      }
    }

    $form_state->set('qr_batch_parsed_rows', $rows);

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
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $create_devices = $this->shouldCreateDevices($form_state);
    $rows = $form_state->get('qr_batch_parsed_rows');

    if (!is_array($rows)) {
      $parsed = $this->parseRows($form_state, $create_devices);
      $rows = $parsed['rows'];
      if ($parsed['errors'] !== []) {
        $this->messenger()->addError($this->t('Certains appareils n’ont pas pu être traités : @errors', [
          '@errors' => implode(' ', $parsed['errors']),
        ]));
        return;
      }
    }

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

    $manifestation_id = 0;
    if ($create_devices) {
      $manifestation_selection = (string) $form_state->getValue('manifestation_id', '0');
      if ($manifestation_selection === 'new') {
        $manifestation_id = $this->manifestationManager->getOrCreate((string) $form_state->getValue('manifestation_new'));
      }
      elseif ((int) $manifestation_selection > 0) {
        $manifestation_id = (int) $manifestation_selection;
      }
    }

    $results = [];
    $errors = [];

    foreach ($rows as $row) {
      $id = $row['id'];
      $label = $row['label'];

      try {
        if ($create_devices) {
          $device_data = [
            'phone' => $row['phone'],
            'model' => $row['model'],
            'contact' => $row['contact'],
            'category' => $row['category'],
            'calendar_id' => $row['calendar_id'],
            'expiration' => $row['expiration'],
          ];
          $provisioned = $this->provisioningManager->createProvisioning(
            $id,
            $label,
            $row['group_id'],
            $device_data,
            $tracking,
            $manifestation_id
          );
          $results[] = [
            'id' => $provisioned['identifier'],
            'label' => $label,
            'url' => $provisioned['config_url'],
            'svg' => $provisioned['qr_svg'],
            'created' => TRUE,
            'device_id' => $provisioned['device_id'],
            'name' => $provisioned['name'],
            'group_name' => $provisioned['group_name'],
            'manifestation_name' => $provisioned['manifestation_name'] ?? '',
            'device_details' => $provisioned['device_details'],
          ];
        }
        else {
          $results[] = [
            'id' => $id,
            'label' => $label,
            'url' => $this->provisioningManager->buildConfigUrl($id, $tracking),
            'svg' => $this->provisioningManager->generateQrSvg($id, $tracking),
            'created' => FALSE,
          ];
        }
      }
      catch (ProvisioningException $exception) {
        $errors[] = $this->t('@id : @message', [
          '@id' => $id,
          '@message' => $exception->getMessage(),
        ]);
      }
      catch (TraccarApiException $exception) {
        $errors[] = $this->t('@id : erreur API Traccar : @message', [
          '@id' => $id,
          '@message' => $exception->getMessage(),
        ]);
      }
      catch (\RuntimeException $exception) {
        $errors[] = $this->t('@id : @message', [
          '@id' => $id,
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    if ($errors !== []) {
      $this->messenger()->addError($this->t('Certains appareils ou QR codes n’ont pas pu être traités : @errors', [
        '@errors' => implode(' ', $errors),
      ]));
    }

    if ($results === []) {
      $this->messenger()->addError($this->t('Aucun QR code n’a pu être généré.'));
    }

    $form_state->set('qr_results', $results);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Determines whether devices should be created on the Traccar server.
   */
  protected function shouldCreateDevices(FormStateInterface $form_state): bool {
    $input = $form_state->getUserInput();
    if (!empty($input['create_devices'])) {
      return TRUE;
    }

    return (bool) $form_state->getValue('create_devices', FALSE);
  }

  /**
   * Parses the pasted device list and resolves server-side values.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param bool $validate_server
   *   Whether to resolve and validate group, category, calendar and expiration.
   *
   * @return array
   *   An array with rows and errors keys.
   */
  protected function parseRows(FormStateInterface $form_state, bool $validate_server): array {
    $raw = (string) $form_state->getValue('identifiers');
    $lines = preg_split('/\R/', $raw);
    if (!is_array($lines)) {
      return ['rows' => [], 'errors' => []];
    }

    $groups = $form_state->get('qr_batch_groups') ?? [];
    $calendars = $form_state->get('qr_batch_calendars') ?? [];
    $groups_error = $form_state->get('qr_batch_groups_error');
    $calendars_error = $form_state->get('qr_batch_calendars_error');
    $allowed_categories = $this->provisioningManager->getAllowedCategories();
    $group_required = (bool) $this->configFactory()->get('traccar_provisioning.settings')->get('group_required');

    $rows = [];
    $errors = [];
    $seen = [];

    foreach ($lines as $index => $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      $line_number = $index + 1;

      $delimiter = str_contains($line, ';') ? ';' : (str_contains($line, "\t") ? "\t" : ';');
      $fields = str_getcsv($line, $delimiter, '"', '\\');
      if (!is_array($fields)) {
        $errors[] = $this->t('Ligne @line : format invalide.', [
          '@line' => $line_number,
        ]);
        continue;
      }

      $fields = array_map(static fn($value): string => trim((string) $value), $fields);

      if ($this->isHeaderRow($fields)) {
        continue;
      }

      if (count($fields) > 9) {
        $errors[] = $this->t('Ligne @line : trop de colonnes (maximum 9). Utilisez des guillemets si un champ contient un point-virgule.', [
          '@line' => $line_number,
        ]);
        $fields = array_slice($fields, 0, 9);
      }
      $fields = array_pad($fields, 9, '');

      [$id, $label, $group_input, $phone, $model, $contact, $category_input, $calendar_input, $expiration_input] = $fields;

      if ($id === '') {
        if (implode('', $fields) === '') {
          continue;
        }
        $errors[] = $this->t('Ligne @line : l’identifiant est obligatoire.', [
          '@line' => $line_number,
        ]);
        continue;
      }

      $row_errors = [];
      $error = $this->provisioningManager->validateIdentifier($id);
      if ($error !== NULL) {
        $row_errors[] = $error;
      }
      if (isset($seen[$id])) {
        $row_errors[] = $this->t('L’identifiant « @id » est présent plusieurs fois.', [
          '@id' => $id,
        ]);
      }
      $seen[$id] = TRUE;

      if (mb_strlen($label) > 255) {
        $row_errors[] = $this->t('Le libellé ne peut pas dépasser 255 caractères.');
      }

      $row = [
        'line' => $line_number,
        'id' => $id,
        'label' => $label !== '' ? $label : $id,
        'group_id' => 0,
        'group_name' => '',
        'phone' => $phone,
        'model' => $model,
        'contact' => $contact,
        'category' => $category_input,
        'calendar_id' => 0,
        'calendar_name' => '',
        'expiration' => $expiration_input,
      ];

      if ($validate_server) {
        if ($groups_error === NULL) {
          $group = $this->resolveGroup($group_input, $groups);
          if ($group['error'] !== NULL) {
            $row_errors[] = $group['error'];
          }
          elseif ($group['id'] <= 0 && $group_required) {
            $row_errors[] = $this->t('Un groupe est obligatoire.');
          }
          else {
            $row['group_id'] = $group['id'];
            $row['group_name'] = $group['name'];
          }
        }

        if ($calendars_error === NULL) {
          $calendar = $this->resolveCalendar($calendar_input, $calendars);
          if ($calendar['error'] !== NULL) {
            $row_errors[] = $calendar['error'];
          }
          else {
            $row['calendar_id'] = $calendar['id'];
            $row['calendar_name'] = $calendar['name'];
          }
        }
        elseif ($calendar_input !== '') {
          $row_errors[] = $this->t('Impossible de vérifier le calendrier : @message', [
            '@message' => $calendars_error,
          ]);
        }

        $category = $this->resolveCategory($category_input, $allowed_categories);
        if ($category['error'] !== NULL) {
          $row_errors[] = $category['error'];
        }
        else {
          $row['category'] = $category['value'];
        }

        $labels = [
          'phone' => $this->t('Téléphone'),
          'model' => $this->t('Modèle'),
          'contact' => $this->t('Contact'),
          'category' => $this->t('Catégorie'),
        ];
        foreach ($labels as $key => $field_label) {
          if (mb_strlen((string) $row[$key]) > 255) {
            $row_errors[] = $this->t('Le champ « @label » ne peut pas dépasser 255 caractères.', [
              '@label' => $field_label,
            ]);
          }
        }

        $expiration_error = $this->provisioningManager->validateExpiration($expiration_input);
        if ($expiration_error !== NULL) {
          $row_errors[] = $expiration_error;
        }
      }

      foreach ($row_errors as $row_error) {
        $errors[] = $this->t('Ligne @line (@id) : @error', [
          '@line' => $line_number,
          '@id' => $id,
          '@error' => $row_error,
        ]);
      }

      $rows[] = $row;
    }

    return ['rows' => $rows, 'errors' => $errors];
  }

  /**
   * Detects an optional header row.
   */
  protected function isHeaderRow(array $fields): bool {
    $first = mb_strtolower(trim((string) ($fields[0] ?? '')));
    if (!in_array($first, ['identifiant', 'id', 'uniqueid', 'unique_id'], TRUE)) {
      return FALSE;
    }

    $second = mb_strtolower(trim((string) ($fields[1] ?? '')));
    return str_starts_with($second, 'libell') || in_array($second, ['label', 'nom', 'name'], TRUE);
  }

  /**
   * Resolves a group name, path or ID.
   *
   * @return array{id: int, name: string, error: string|null}
   *   The resolved group and an optional error.
   */
  protected function resolveGroup(string $value, array $groups): array {
    $value = trim($value);
    if ($value === '') {
      return ['id' => 0, 'name' => '', 'error' => NULL];
    }

    if (ctype_digit($value)) {
      $id = (int) $value;
      if (isset($groups[$id])) {
        return ['id' => $id, 'name' => $groups[$id], 'error' => NULL];
      }
    }

    $normalized = mb_strtolower($value);
    $matches = [];
    $leaf_matches = [];
    foreach ($groups as $id => $name) {
      $normalized_name = mb_strtolower(trim($name));
      if ($normalized_name === $normalized) {
        $matches[$id] = $name;
      }

      $parts = explode(' > ', $name);
      $leaf = mb_strtolower(trim((string) end($parts)));
      if ($leaf === $normalized) {
        $leaf_matches[$id] = $name;
      }
    }

    if (count($matches) === 1) {
      $id = (int) array_key_first($matches);
      return ['id' => $id, 'name' => $matches[$id], 'error' => NULL];
    }
    if (count($matches) > 1) {
      return [
        'id' => 0,
        'name' => '',
        'error' => $this->t('Le groupe « @group » est ambigu. Utilisez son ID ou son chemin complet.', [
          '@group' => $value,
        ]),
      ];
    }
    if (count($leaf_matches) === 1) {
      $id = (int) array_key_first($leaf_matches);
      return ['id' => $id, 'name' => $leaf_matches[$id], 'error' => NULL];
    }
    if (count($leaf_matches) > 1) {
      return [
        'id' => 0,
        'name' => '',
        'error' => $this->t('Le groupe « @group » est ambigu. Utilisez son ID ou son chemin complet.', [
          '@group' => $value,
        ]),
      ];
    }

    return [
      'id' => 0,
      'name' => '',
      'error' => $this->t('Le groupe « @group » n’existe pas sur le serveur Traccar.', [
        '@group' => $value,
      ]),
    ];
  }

  /**
   * Resolves a calendar name or ID.
   *
   * @return array{id: int, name: string, error: string|null}
   *   The resolved calendar and an optional error.
   */
  protected function resolveCalendar(string $value, array $calendars): array {
    $value = trim($value);
    if ($value === '') {
      return ['id' => 0, 'name' => '', 'error' => NULL];
    }

    if (ctype_digit($value)) {
      $id = (int) $value;
      if (isset($calendars[$id])) {
        return ['id' => $id, 'name' => $calendars[$id], 'error' => NULL];
      }
    }

    $normalized = mb_strtolower($value);
    $matches = [];
    foreach ($calendars as $id => $name) {
      if (mb_strtolower(trim($name)) === $normalized) {
        $matches[$id] = $name;
      }
    }

    if (count($matches) === 1) {
      $id = (int) array_key_first($matches);
      return ['id' => $id, 'name' => $matches[$id], 'error' => NULL];
    }
    if (count($matches) > 1) {
      return [
        'id' => 0,
        'name' => '',
        'error' => $this->t('Le calendrier « @calendar » est ambigu. Utilisez son ID.', [
          '@calendar' => $value,
        ]),
      ];
    }

    return [
      'id' => 0,
      'name' => '',
      'error' => $this->t('Le calendrier « @calendar » n’existe pas sur le serveur Traccar.', [
        '@calendar' => $value,
      ]),
    ];
  }

  /**
   * Resolves a category against the optional allowed list.
   *
   * @return array{value: string, error: string|null}
   *   The resolved category and an optional error.
   */
  protected function resolveCategory(string $value, array $allowed): array {
    $value = trim($value);
    if ($value === '') {
      return ['value' => '', 'error' => NULL];
    }

    if ($allowed === []) {
      return ['value' => $value, 'error' => NULL];
    }

    $normalized = mb_strtolower($value);
    if (isset($allowed[$normalized])) {
      return ['value' => $allowed[$normalized], 'error' => NULL];
    }

    return [
      'value' => $value,
      'error' => $this->t('La catégorie « @category » n’est pas autorisée. Catégories autorisées : @allowed.', [
        '@category' => $value,
        '@allowed' => implode(', ', array_values($allowed)),
      ]),
    ];
  }

}
