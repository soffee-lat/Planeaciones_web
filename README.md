# Planeaciones

Aplicación Laravel 13 + Filament 5 + PostgreSQL para generar, revisar y entregar planeaciones docentes personalizadas.

La aplicación usa tres paneles aislados:

- `/app`: docente cliente.
- `/review`: docente revisor.
- `/admin`: administración.

## Estado actual

Las Fases 1–6 están cerradas funcionalmente. El trabajo activo se concentra en:

1. currículo oficial de primaria para Fases 3–5;
2. consolidación del flujo docente y validación curricular;
3. Standard Export v2 como salida predeterminada;
4. validación del MVP con docentes reales;
5. integración posterior de un proveedor IA real sobre el contrato canónico.

Consultar primero [`docs/CURRENT_STATE.md`](docs/CURRENT_STATE.md).

## Regla arquitectónica principal

La generación pedagógica es canónica e independiente del formato de salida.

```text
Solicitud + currículo confirmado
        ↓
Snapshot inmutable
        ↓
Generación pedagógica
        ↓
Auditoría / correcciones
        ↓
Revisión humana cuando aplica
        ↓
Planeación aprobada
        ↓
Exportación Standard v2 o institucional
        ↓
DOCX / PDF
        ↓
Entrega privada
```

Un formato DOCX, `FormatVersion`, `template_contract` o preferencia histórica de grupo no condiciona la generación. El formato se resuelve al exportar una planeación aprobada.

## Documentación vigente

- [`docs/CURRENT_STATE.md`](docs/CURRENT_STATE.md): estado y prioridades actuales.
- [`ARCHITECTURE.md`](ARCHITECTURE.md): arquitectura y fronteras de dominio.
- [`CURRICULUM.md`](CURRICULUM.md): catálogo curricular y reglas de producción.
- [`WORKFLOWS.md`](WORKFLOWS.md): estados y flujo de negocio.
- [`AI_PIPELINE.md`](AI_PIPELINE.md): contratos y pipeline IA.
- [`DATABASE.md`](DATABASE.md): modelo de datos e invariantes.
- [`PERMISSIONS.md`](PERMISSIONS.md): permisos y aislamiento.
- [`MVP.md`](MVP.md): alcance actual de producto.
- [`TASKS.md`](TASKS.md): backlog vigente.
- [`docs/STANDARD_EXPORT_V2.md`](docs/STANDARD_EXPORT_V2.md): contrato de salida estándar.
- [`docs/PHASE6_CLOSURE.md`](docs/PHASE6_CLOSURE.md): evidencia histórica del cierre de Fase 6.

La documentación histórica o experimental no tiene precedencia sobre código/tests actuales.

## Desarrollo local en Windows

El proyecto usa PostgreSQL local aislado y requiere `pdo_pgsql`. Ejecutar PHP mediante `tools/php.ps1`.

Arrancar PostgreSQL local existente:

```powershell
& .runtime/postgresql/pgsql/bin/pg_ctl.exe -D .runtime/pgdata -l .runtime/postgresql.log -w start
& .runtime/postgresql/pgsql/bin/pg_isready.exe -h 127.0.0.1 -p 55432
```

Suite completa:

```powershell
.\tools\php.ps1 -d memory_limit=512M vendor/phpunit/phpunit/phpunit --do-not-cache-result
```

Artisan:

```powershell
.\tools\php.ps1 artisan <comando>
```

Composer:

```powershell
.\tools\php.ps1 composer <comando>
```

`.env.testing` debe usar PostgreSQL y una base terminada en `_test`. No sustituir el clúster aislado por SQLite ni por otra PostgreSQL durante pruebas sin cambiar explícitamente la arquitectura del proyecto.

## Pipeline IA operativo

El pipeline actual soporta ejecución manual versionada para `generation`, `audit` y `correction`. Los paquetes y resultados se conservan en almacenamiento privado y pasan por los mismos validadores e invariantes que deberá usar un proveedor API.

La generación devuelve diseño pedagógico estructurado; el servidor reconstruye el plan canónico usando el snapshot curricular confirmado. La IA no es autoridad sobre textos curriculares oficiales.

## Documentos y exportación

Standard v2 es la salida predeterminada. Los formatos institucionales son adaptadores opcionales de exportación y no forman parte del contrato de generación.

La expansión del diseñador institucional como editor Word general está congelada mientras se valida el MVP curricular.

## Fuente de verdad

Cuando exista una contradicción, usar este orden:

1. código y tests de la rama activa;
2. `docs/CURRENT_STATE.md`;
3. documentación vigente de arquitectura/dominio;
4. documentación específica reciente;
5. cierres de fase y Git history.
