<?php

namespace Drupal\traccar_provisioning\Exception;

/**
 * Exception thrown when the Traccar API returns an error or is unreachable.
 */
class TraccarApiException extends \RuntimeException {

  /**
   * The HTTP status code returned by Traccar, or 0 for a transport error.
   */
  protected int $statusCode;

  /**
   * The raw response body returned by Traccar.
   */
  protected string $responseBody;

  /**
   * Constructs a new TraccarApiException.
   *
   * @param string $message
   *   The human-readable error message.
   * @param int $status_code
   *   The HTTP status code, or 0 for a transport error.
   * @param string $response_body
   *   The raw response body.
   * @param \Throwable|null $previous
   *   The previous exception.
   */
  public function __construct(string $message, int $status_code = 0, string $response_body = '', ?\Throwable $previous = NULL) {
    parent::__construct($message, $status_code, $previous);
    $this->statusCode = $status_code;
    $this->responseBody = $response_body;
  }

  /**
   * Returns the HTTP status code.
   */
  public function getStatusCode(): int {
    return $this->statusCode;
  }

  /**
   * Returns the raw response body.
   */
  public function getResponseBody(): string {
    return $this->responseBody;
  }

}
