# Planeaciones

Aplicación Laravel 13 + Filament 5 sobre PostgreSQL. Tres paneles aislados: `/app` (docente cliente), `/review` (revisor) y `/admin` (administración). Diseño y fases en `ARCHITECTURE.md`, `TASKS.md`, `PERMISSIONS.md`, `WORKFLOWS.md`, `AI_PIPELINE.md`, `DATABASE.md`, `CURRICULUM.md` y `MVP.md`.

## Desarrollo local en Windows

Este proyecto usa una PostgreSQL local aislada en `.runtime/postgresql/` y requiere la extensión `pdo_pgsql`. El PHP global de Windows normalmente **no** trae `pdo_pgsql` habilitada, por lo que ejecutar `php`, `composer` o `vendor/bin/phpunit` directamente falla con:

```
PDOException: could not find driver (Connection: pgsql)
```

Para evitarlo, todo comando PHP debe pasar por el envoltorio [`tools/php.ps1`](tools/php.ps1), que fija `PHPRC` a `.runtime/php.ini` (php.ini con `extension=pdo_pgsql` y `extension=pgsql` habilitados) y delega en `php`.

### Ejemplos de ejecución

Si la instancia aislada está detenida, arrancar **el clúster existente** desde la raíz del repositorio:

```powershell
& .runtime/postgresql/pgsql/bin/pg_ctl.exe -D .runtime/pgdata -l .runtime/postgresql.log -w start
& .runtime/postgresql/pgsql/bin/pg_isready.exe -h 127.0.0.1 -p 55432
```

`.runtime/pgdata/postgresql.conf` fija `127.0.0.1:55432`; no es Docker ni un túnel. `.env.testing` usa `planeaciones_test` y `tests/TestCase.php` exige PostgreSQL con nombre terminado en `_test`. No sustituir el puerto por 5432 ni inicializar otro clúster para resolver una instancia detenida.

Pruebas (suite completa):

```powershell
.\tools\php.ps1 vendor/phpunit/phpunit/phpunit --testdox
```

Pruebas específicas de contratos IA / Fase 4A:

```powershell
.\tools\php.ps1 vendor/phpunit/phpunit/phpunit tests/Unit/GeneratedPlanDraftValidatorTest.php --do-not-cache-result
.\tools\php.ps1 vendor/phpunit/phpunit/phpunit tests/Feature/AI --do-not-cache-result
```

Pruebas específicas del arranque manual / Fase 4B:

```powershell
.\tools\php.ps1 vendor/phpunit/phpunit/phpunit tests/Unit/PlanningRequestStateMachineTest.php --do-not-cache-result
.\tools\php.ps1 vendor/phpunit/phpunit/phpunit tests/Feature/AI/PlanningGenerationDispatchTest.php --do-not-cache-result
.\tools\php.ps1 vendor/phpunit/phpunit/phpunit tests/Feature/AI/ManualGenerationOutboxTest.php --do-not-cache-result
.\tools\php.ps1 vendor/phpunit/phpunit/phpunit tests/Feature/AI/PlanningGenerationIntegrityTest.php --do-not-cache-result
```

Iniciar manualmente una solicitud ya autorizada (operación interna) y, opcionalmente, preparar su paquete en el mismo comando. Requiere que `/admin` tenga activa una `PromptVersion` de categoría `generation` con key `planning.generation` y el schema versionado de `resources/schemas/ai/generated_plan_draft_v1.schema.json`:

```powershell
.\tools\php.ps1 artisan ai:dispatch-generation 123
.\tools\php.ps1 artisan ai:dispatch-generation 123 --process-outbox
```

Procesar/reintentar outbox manual pendiente:

```powershell
.\tools\php.ps1 artisan ai:process-outbox --limit=25
```

`AI_MODE=manual` es el único modo operacional de Fase 4B. Los paquetes se guardan en el disco privado (`storage/app/private/ai/manual/...`) y no se publican mediante `storage:link`. `AI_MODE=api` permanece bloqueado hasta integrar y verificar un proveedor real.

### Fase 4C — importar resultado manual de generación

Antes de importar, `/admin` debe tener una `PromptVersion` publicada y activa de categoría `audit` con key `planning.audit`, schema exacto `AuditResultV1` y variables mínimas `canonical_plan` + `output_schema`. 4D valida ese contrato antes de congelarlo para impedir ejecuciones audit imposibles de procesar.

```powershell
.\tools\php.ps1 artisan ai:import-generation-result 45 C:\ruta\resultado.json
```

Metadatos reales opcionales; si se desconocen se omiten y permanecen `null`:

```powershell
.\tools\php.ps1 artisan ai:import-generation-result 45 C:\ruta\resultado.json `
  --provider=proveedor-real --model=modelo-real `
  --actual-cost=0.01234567 --currency=MXN
```

El JSON debe cumplir `GeneratedPlanDraftV1`. El servidor reconstruye `CanonicalPlanV1` desde el snapshot curricular congelado, crea `Document`/`DocumentVersion` inmutable, marca la ejecución de generación `succeeded` y transiciona `GENERACION_IA → AUDITORIA_IA` preparando una ejecución `audit` pendiente y su outbox. Repetir el mismo payload es idempotente; un payload distinto para la misma ejecución se rechaza.

Procesa después el outbox para generar el paquete privado de auditoría:

```powershell
.\tools\php.ps1 artisan ai:process-outbox
```

Tras procesar el paquete fuera del sistema, importa un `AuditResultV1`:

```powershell
.\tools\php.ps1 artisan ai:import-audit-result <execution_id> <ruta-audit-result.json> --provider=<real> --model=<real>
```

Proveedor/modelo/costo pueden omitirse si realmente se desconocen. La importación cierra la ejecución audit y deja la solicitud en `AUDITORIA_IA`. Fase 4E enruta el resultado:

```powershell
.\tools\php.ps1 artisan ai:route-audit-result <audit_execution_id>
```

Si el audit pasa, crea Approval AI y la solicitud queda `APROBADA` o `REVISION_HUMANA` según el derecho congelado. Si falla y el alcance es corregible, crea una ejecución correction + outbox y cambia a `CORRECCION_IA`. Procesa el outbox para obtener el paquete privado de corrección:

```powershell
.\tools\php.ps1 artisan ai:process-outbox
```

Importa después `CorrectionResultV1`:

```powershell
.\tools\php.ps1 artisan ai:import-correction-result <correction_execution_id> C:\ruta\correction-result.json
```

La importación crea una `DocumentVersion` hija y prepara reauditoría, por lo que vuelve a `AUDITORIA_IA`. Los ciclos internos se limitan con `AI_INTERNAL_CORRECTION_MAX_ROUNDS` (default 2) y **no consumen `correction_limit` del cliente**. Alcance no seguro o límite agotado abre `ai_quality_attention` y conserva la solicitud en auditoría.

Pruebas específicas de 4E:

```powershell
.\tools\php.ps1 vendor/phpunit/phpunit/phpunit tests/Unit/CorrectionResultValidatorTest.php --do-not-cache-result
.\tools\php.ps1 vendor/phpunit/phpunit/phpunit tests/Feature/AI/AuditRoutingTest.php --do-not-cache-result
.\tools\php.ps1 vendor/phpunit/phpunit/phpunit tests/Feature/AI/ManualCorrectionPipelineTest.php --do-not-cache-result
.\tools\php.ps1 vendor/phpunit/phpunit/phpunit tests/Feature/AI/ManualCorrectionResultImportTest.php --do-not-cache-result
.\tools\php.ps1 vendor/phpunit/phpunit/phpunit tests/Feature/AI/PlanningCorrectionIntegrityTest.php --do-not-cache-result
```

Prueba integral verificada de cierre de Fase 4 (4F):

```powershell
.\tools\php.ps1 vendor/phpunit/phpunit/phpunit tests/Feature/AI/Phase4EndToEndTest.php --do-not-cache-result
```

4F no agrega endpoints, estados ni persistencia nuevos: verifica que 4B→4E funcionen unidos y que los replays no dupliquen versiones, aprobaciones, outbox ni consumo. Cierre local: **4 tests / 32 assertions** en `Phase4EndToEndTest`; hardening comercial **44 / 146**; suite completa **432 tests / 1521 assertions**, sin fallos.

### Fase 5A — capacidad y asignación humana

Candidato de asignación automática al entrar a `REVISION_HUMANA`, con consumo humano únicamente cuando existe un revisor elegible. Reintento operativo:

```powershell
.\tools\php.ps1 artisan review:assign <request_id>
```

Pruebas dirigidas de 5A:

```powershell
.\tools\php.ps1 vendor/phpunit/phpunit/phpunit tests/Feature/Review/ReviewerAssignmentTest.php --do-not-cache-result
.\tools\php.ps1 vendor/phpunit/phpunit/phpunit tests/Feature/Review/ReviewerAssignmentIntegrityTest.php --do-not-cache-result
.\tools\php.ps1 vendor/phpunit/phpunit/phpunit tests/Feature/Review/ReviewerAssignmentConcurrencyTest.php --do-not-cache-result
```

Pruebas específicas de 4C:

```powershell
.\tools\php.ps1 vendor/phpunit/phpunit/phpunit tests/Feature/AI/ManualGenerationResultImportTest.php --do-not-cache-result
.\tools\php.ps1 vendor/phpunit/phpunit/phpunit tests/Feature/AI/PlanningGenerationResultIntegrityTest.php --do-not-cache-result
```

Los contratos versionados viven en `resources/schemas/ai/` y su explicación en `docs/ai/CANONICAL_PLAN_CONTRACT_V1.md`.

Un solo archivo o filtro:

```powershell
.\tools\php.ps1 vendor/phpunit/phpunit/phpunit --filter IdentityAccess
```

Artisan:

```powershell
.\tools\php.ps1 artisan migrate
.\tools\php.ps1 artisan serve --host=127.0.0.1 --port=8000
.\tools\php.ps1 artisan tinker
```

Composer (solo cuando el paso invoca scripts PHP que abren conexión a PostgreSQL, por ejemplo `post-autoload-dump` con `package:discover`; en la mayoría de comandos de solo dependencia el `composer` global sirve):

```powershell
$env:PHPRC = "$PWD\.runtime\php.ini"; composer install
$env:PHPRC = "$PWD\.runtime\php.ini"; composer require vendor/paquete
```

Si `pdo_pgsql` ya está habilitada en el PHP global (por ejemplo en macOS, Linux o Windows con `pdo_pgsql` cargado en `php.ini`), el envoltorio no es necesario; se puede invocar `php`, `composer` y `vendor/bin/phpunit` directamente. En Windows es obligatorio.

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
