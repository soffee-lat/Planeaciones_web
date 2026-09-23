# Catálogo Preescolar NEM v1

Fuente editorial: **Programa de Estudio para la Educación Preescolar: Programa Sintético de la Fase 2**, Secretaría de Educación Pública, primera edición 2024.

## Alcance

- Currículo interno: `MX-NEM-PRESCHOOL`
- Nivel: `preschool`
- Fase: `F2`
- Grados internos: `P1`, `P2`, `P3`
- Campos: `LEN`, `SPC`, `ENS`, `DHC`

Los códigos son internos de Planeaciones Soffee y no se presentan como identificadores oficiales SEP.

## Conteos editoriales verificados

| Campo | Contenidos | PDA P1 | PDA P2 | PDA P3 |
| --- | ---: | ---: | ---: | ---: |
| Lenguajes | 9 | 27 | 30 | 30 |
| Saberes y Pensamiento Científico | 9 | 36 | 44 | 51 |
| Ética, Naturaleza y Sociedades | 8 | 23 | 26 | 21 |
| De lo Humano y lo Comunitario | 8 | 26 | 30 | 29 |
| **Total** | **34** | **112** | **130** | **131** |

Total PDA: **373**.

## Páginas de tablas usadas

- Lenguajes: páginas 20–26.
- Saberes y Pensamiento Científico: páginas 32–41.
- Ética, Naturaleza y Sociedades: páginas 46–51.
- De lo Humano y lo Comunitario: páginas 56–62.

## Integridad

Fuente PDF SHA-256:

`f981c30ea9619f5841f4729a6f697951e035eff78c1e1042d02d0d5d663c8702`

JSON generado SHA-256:

`532202229710635271fd05f156e49ac3fde4a0042abf4f59a964ab458d378333`

El catálogo conserva cada PDA en la columna de grado donde aparece en la fuente. No se copia un PDA entre grados ni se infiere progresión no escrita.

## Flujo de carga

El archivo se valida primero:

```powershell
.\tools\php.ps1 artisan curriculum:import .\curriculum_mx_nem_preschool_v1.json --actor=<correo-admin> --dry-run
```

Sólo si el reporte tiene cero errores se crea el borrador:

```powershell
.\tools\php.ps1 artisan curriculum:import .\curriculum_mx_nem_preschool_v1.json --actor=<correo-admin>
```

La importación no publica automáticamente y no cambia la versión seleccionable. La publicación continúa siendo una operación editorial separada.
