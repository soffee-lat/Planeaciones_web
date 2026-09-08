<?php

namespace App\Enums;

enum SchoolType: string
{
    case Public = 'public';
    case Private = 'private';

    /** @return array<string,string> */
    public static function options(): array
    {
        return [
            self::Public->value => 'Pública',
            self::Private->value => 'Privada',
        ];
    }
}
