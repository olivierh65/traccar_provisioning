<?php

namespace Drupal\traccar_provisioning\Service;

use Drupal\Core\Database\Connection;

/**
 * Stores manifestations and the Traccar devices associated with them.
 */
class ManifestationManager {

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * Constructs a new ManifestationManager.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Returns all manifestations keyed by ID.
   *
   * @return array<int, string>
   *   Manifestation labels keyed by manifestation ID.
   */
  public function getManifestations(): array {
    $query = $this->database->select('traccar_manifestation', 'm');
    $query->fields('m', ['id', 'manifestation']);
    $query->orderBy('manifestation');
    return $query->execute()->fetchAllKeyed();
  }

  /**
   * Returns manifestations with their associated device count.
   *
   * @return array<int, string>
   *   Labels keyed by manifestation ID, including the device count.
   */
  public function getManifestationsWithDeviceCounts(): array {
    $query = $this->database->select('traccar_manifestation', 'm');
    $query->leftJoin('traccar_manifestation_device', 'd', 'd.manifestation_id = m.id');
    $query->addField('m', 'id');
    $query->addField('m', 'manifestation');
    $query->addExpression('COUNT(d.device_id)', 'device_count');
    $query->groupBy('m.id');
    $query->groupBy('m.manifestation');
    $query->orderBy('m.manifestation');

    $options = [];
    foreach ($query->execute()->fetchAll() as $row) {
      $options[(int) $row->id] = $this->formatLabel((string) $row->manifestation, (int) $row->device_count);
    }
    return $options;
  }

  /**
   * Returns a manifestation name.
   */
  public function getManifestationName(int $manifestation_id): ?string {
    if ($manifestation_id <= 0) {
      return NULL;
    }

    $name = $this->database->select('traccar_manifestation', 'm')
      ->fields('m', ['manifestation'])
      ->condition('id', $manifestation_id)
      ->execute()
      ->fetchField();

    return $name !== FALSE ? (string) $name : NULL;
  }

  /**
   * Returns an existing manifestation ID or creates a new one.
   *
   * @throws \InvalidArgumentException
   *   When the manifestation name is empty or too long.
   */
  public function getOrCreate(string $name): int {
    $name = trim($name);
    if ($name === '') {
      throw new \InvalidArgumentException('Le nom de la manifestation est obligatoire.');
    }
    if (mb_strlen($name) > 255) {
      throw new \InvalidArgumentException('Le nom de la manifestation ne peut pas dépasser 255 caractères.');
    }

    $existing = $this->database->select('traccar_manifestation', 'm')
      ->fields('m', ['id'])
      ->condition('manifestation', $name)
      ->execute()
      ->fetchField();
    if ($existing !== FALSE) {
      return (int) $existing;
    }

    try {
      return (int) $this->database->insert('traccar_manifestation')
        ->fields(['manifestation' => $name])
        ->execute();
    }
    catch (\Exception $exception) {
      // A concurrent request may have inserted the same name first.
      $existing = $this->database->select('traccar_manifestation', 'm')
        ->fields('m', ['id'])
        ->condition('manifestation', $name)
        ->execute()
        ->fetchField();
      if ($existing !== FALSE) {
        return (int) $existing;
      }
      throw $exception;
    }
  }

  /**
   * Associates a device with a manifestation.
   *
   * A device can only belong to one manifestation. Associating it again moves
   * the device to the selected manifestation.
   */
  public function associateDevice(int $manifestation_id, int $device_id): void {
    if ($manifestation_id <= 0 || $device_id <= 0) {
      return;
    }

    $this->database->merge('traccar_manifestation_device')
      ->keys(['device_id' => $device_id])
      ->fields(['manifestation_id' => $manifestation_id])
      ->execute();
  }

  /**
   * Returns the device IDs associated with a manifestation.
   *
   * @return int[]
   *   Traccar device IDs.
   */
  public function getDeviceIds(int $manifestation_id): array {
    if ($manifestation_id <= 0) {
      return [];
    }

    $ids = $this->database->select('traccar_manifestation_device', 'd')
      ->fields('d', ['device_id'])
      ->condition('manifestation_id', $manifestation_id)
      ->orderBy('device_id')
      ->execute()
      ->fetchCol();

    return array_map('intval', $ids);
  }

  /**
   * Removes a device association.
   */
  public function deleteDevice(int $device_id): void {
    if ($device_id <= 0) {
      return;
    }

    $this->database->delete('traccar_manifestation_device')
      ->condition('device_id', $device_id)
      ->execute();
  }

  /**
   * Formats a manifestation option label.
   */
  protected function formatLabel(string $name, int $device_count): string {
    return sprintf('%s (%d appareil%s)', $name, $device_count, $device_count > 1 ? 's' : '');
  }

}
