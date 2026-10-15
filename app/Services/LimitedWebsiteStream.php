<?php

namespace App\Services;

use GuzzleHttp\Psr7\Stream;

/** Stops cURL's sink before bytes beyond the configured limit enter memory. */
class LimitedWebsiteStream extends Stream
{
    public function __construct(private readonly int $byteLimit)
    {
        parent::__construct(fopen('php://temp', 'w+'));
    }

    public function write($string): int
    {
        if ($this->tell() + strlen($string) > $this->byteLimit) {
            throw new \RuntimeException('El contenido del sitio supera el límite permitido.');
        }

        return parent::write($string);
    }
}
