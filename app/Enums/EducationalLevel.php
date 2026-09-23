<?php

namespace App\Enums;

enum EducationalLevel: string
{
    case Preschool = 'preschool';
    case Primary = 'primary';

    public function label(): string
    {
        return match ($this) {
            self::Preschool => 'Preescolar (kínder)',
            self::Primary => 'Primaria',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return [
            self::Preschool->value => self::Preschool->label(),
            self::Primary->value => self::Primary->label(),
        ];
    }

    public static function labelFor(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        $known = self::tryFrom($value);

        return $known?->label() ?? ($value !== '' ? ucfirst($value) : 'Nivel por definir');
    }
}
