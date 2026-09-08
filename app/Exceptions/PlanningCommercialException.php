<?php

namespace App\Exceptions;

class PlanningCommercialException extends \RuntimeException
{
    public function __construct(string $code, public readonly ?int $needed = null, public readonly ?int $available = null)
    {
        parent::__construct($code);
    }

    public function userMessage(): string
    {
        return match ($this->getMessage()) {
            'COMMERCIAL_RIGHTS_REQUIRED' => 'Tu solicitud está confirmada. Necesitas un plan activo con un periodo vigente para activar el procesamiento.',
            'INSUFFICIENT_PLANNING_UNITS' => "Esta planeación necesita {$this->needed} unidades y tienes {$this->available} disponibles. Tu solicitud se conserva para activarla cuando tengas saldo suficiente.",
            'INSUFFICIENT_HUMAN_REVIEW_UNITS' => "Esta planeación necesita {$this->needed} unidades de revisión humana y tienes {$this->available} disponibles. No se reservó ninguna unidad.",
            'GROUP_LIMIT_EXCEEDED' => "Tienes {$this->needed} grupos activos y tu plan permite {$this->available}. Archiva los grupos que elijas antes de activar el procesamiento.",
            'PLANNING_GROUP_ARCHIVED' => 'El grupo de esta solicitud está archivado. Actívalo antes de continuar.',
            default => 'No pudimos activar esta solicitud. Revisa que esté confirmada o solicita ayuda.',
        };
    }
}
