<?php

namespace App\Services\Documents;

use App\Models\FormatVersion;

/**
 * Compatibilidad con el nombre usado por V5 inicial. La resolución ya no
 * contiene reglas especiales para formatos diarios, sesiones, grados ni
 * ninguna plantilla concreta; todo se delega al resolvedor genérico.
 */
final class InstitutionalDynamicFieldResolver
{
    private GenericInstitutionalFieldResolver $resolver;

    public function __construct(?GenericInstitutionalFieldResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new GenericInstitutionalFieldResolver();
    }

    /**
     * @param array<string,mixed> $mapping
     * @return array<string,mixed>
     */
    public function augment(FormatVersion $version, array $mapping): array
    {
        return $this->resolver->augment($version, $mapping);
    }
}
