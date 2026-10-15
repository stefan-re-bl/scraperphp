<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;
use Throwable;

class AcquisitionFailure
{
    /** SQL exceptions can contain complete bound rows, including contact details. */
    public static function publicException(Throwable $exception): Throwable
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof QueryException || $cause instanceof PDOException) {
                return new RuntimeException('La operación de adquisición falló en la base de datos. Revisa su disponibilidad e integridad antes de reintentar.');
            }
        }

        return $exception;
    }

    public static function message(?Throwable $exception): ?string
    {
        return $exception === null ? null : self::publicException($exception)->getMessage();
    }
}
