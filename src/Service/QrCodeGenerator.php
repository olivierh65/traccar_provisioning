<?php

namespace Drupal\traccar_provisioning\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Generates QR codes as inline SVG.
 */
class QrCodeGenerator {

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs a new QrCodeGenerator.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Generates an inline SVG QR code.
   *
   * @param string $data
   *   The data to encode.
   * @param int|null $size
   *   The requested width and height in pixels. Defaults to module config.
   * @param string|null $error_correction
   *   One of L, M, Q or H. Defaults to module config.
   *
   * @return string
   *   The SVG markup.
   *
   * @throws \RuntimeException
   *   When the QR code library is unavailable or generation fails.
   */
  public function generateSvg(string $data, ?int $size = NULL, ?string $error_correction = NULL): string {
    $data = trim($data);
    if ($data === '') {
      throw new \RuntimeException('Impossible de générer un QR code sans données.');
    }

    $config = $this->configFactory->get('traccar_provisioning.settings');
    $size = $size ?? (int) ($config->get('qr_size') ?: 320);
    $size = max(100, min(1000, $size));
    $error_correction = strtoupper($error_correction ?? (string) ($config->get('qr_error_correction') ?: 'M'));
    if (!in_array($error_correction, ['L', 'M', 'Q', 'H'], TRUE)) {
      $error_correction = 'M';
    }

    // The QR encoder is provided by tecnickcom/tcpdf, which Drupal's Composer
    // classmap exposes as a global class.
    if (!class_exists('\\QRcode')) {
      throw new \RuntimeException('La bibliothèque tecnickcom/tcpdf est requise pour générer les QR codes. Exécutez « composer require tecnickcom/tcpdf ».');
    }

    try {
      $qr = new \QRcode($data, $error_correction);
      $barcode = $qr->getBarcodeArray();
    }
    catch (\Throwable $exception) {
      throw new \RuntimeException('Impossible d\'encoder le QR code : ' . $exception->getMessage(), 0, $exception);
    }

    if (empty($barcode['bcode']) || empty($barcode['num_rows']) || empty($barcode['num_cols'])) {
      throw new \RuntimeException('La bibliothèque QR code a renvoyé des données invalides.');
    }

    $columns = (int) $barcode['num_cols'];
    $rows = (int) $barcode['num_rows'];

    // The QR specification requires a four-module quiet zone.
    $quiet_zone = 4;
    $view_width = $columns + ($quiet_zone * 2);
    $view_height = $rows + ($quiet_zone * 2);

    // Merge consecutive black modules horizontally to keep the SVG compact.
    $path = '';
    foreach ($barcode['bcode'] as $y => $row) {
      for ($x = 0; $x < $columns; $x++) {
        if (!empty($row[$x])) {
          $start = $x;
          while ($x < $columns && !empty($row[$x])) {
            $x++;
          }
          $length = $x - $start;
          $path .= 'M' . $start . ' ' . $y . 'h' . $length . 'v1h-' . $length . 'z';
          // The for loop will increment $x once more.
          $x--;
        }
      }
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . (-$quiet_zone) . ' ' . (-$quiet_zone) . ' ' . $view_width . ' ' . $view_height . '" width="' . $size . '" height="' . $size . '" role="img" aria-label="QR code de configuration Traccar" focusable="false" shape-rendering="crispEdges">'
      . '<rect x="' . (-$quiet_zone) . '" y="' . (-$quiet_zone) . '" width="' . $view_width . '" height="' . $view_height . '" fill="#ffffff"/>'
      . '<path d="' . $path . '" fill="#000000"/>'
      . '</svg>';
  }

}
