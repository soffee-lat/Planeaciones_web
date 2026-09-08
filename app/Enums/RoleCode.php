<?php
namespace App\Enums;
enum RoleCode: string
{
    case Customer = 'DOCENTE_CLIENTE';
    case Reviewer = 'DOCENTE_REVISOR';
    case Administrator = 'ADMINISTRADOR';
}

