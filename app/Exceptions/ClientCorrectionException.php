<?php

namespace App\Exceptions;

use RuntimeException;

final class ClientCorrectionException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }

    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'CLIENT_CORRECTION_NOT_INCLUDED' => 'Tu solicitud no incluye rondas de corrección.',
            'CLIENT_CORRECTION_WINDOW_EXPIRED' => 'La ventana para solicitar correcciones de esta planeación ya terminó.',
            'CLIENT_CORRECTION_LIMIT_REACHED' => 'Ya utilizaste todas las rondas de corrección incluidas para esta planeación.',
            'CLIENT_CORRECTION_ALREADY_OPEN' => 'Ya existe una corrección abierta para esta planeación.',
            'CLIENT_CORRECTION_DESCRIPTION_REQUIRED' => 'Describe con claridad qué necesitas corregir.',
            'CLIENT_CORRECTION_SCOPE_REQUIRED' => 'Selecciona al menos una sección que necesite corrección.',
            'CLIENT_CORRECTION_SOURCE_NOT_CURRENT' => 'La versión entregada cambió. Recarga la planeación antes de solicitar la corrección.',
            default => 'No pudimos procesar la corrección. Recarga la planeación o solicita ayuda.',
        };
    }
}
