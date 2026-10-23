<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Support\Guzzle;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * The shape of Guzzle 7's RequestException: a nullable getResponse() (Guzzle 8 moved it to ResponseException).
 */
final class Guzzle7RequestException extends RuntimeException
{
    public function __construct(
        private readonly ?ResponseInterface $response,
    ) {
        parent::__construct('request failed');
    }

    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }
}
