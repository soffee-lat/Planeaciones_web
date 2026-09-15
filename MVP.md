# MVP actual

Actualizado: 2026-09-15.

Planeaciones_web ya no está en etapa de diseño inicial. Las Fases 1–6 están implementadas y el siguiente objetivo es validar un MVP curricular usable con docentes reales antes de ampliar automatización o edición documental.

## Objetivo de producto

Permitir que un docente configure su contexto una vez, confirme conexiones curriculares válidas y obtenga una planeación pedagógica revisable y exportable a DOCX/PDF con el mínimo trabajo administrativo posible.

## Flujo MVP

```text
Onboarding
  ↓
Escuela / grupo / perfil
  ↓
Nueva planeación
  ↓
Tema, fechas y contexto mínimo
  ↓
Conexiones curriculares propuestas
  ↓
Confirmación docente
  ↓
Autorización comercial
  ↓
Generación canónica
  ↓
Auditoría / corrección
  ↓
Revisión humana si el plan lo incluye
  ↓
Aprobación
  ↓
Standard v2 por defecto
  o formato institucional opcional
  ↓
DOCX / PDF
  ↓
Entrega privada
```

## Incluido

- registro, verificación, recuperación y onboarding;
- escuelas, grupos y perfil pedagógico reutilizable;
- catálogo curricular versionado e inmutable;
- currículo oficial de primaria Fases 3–5 como línea activa de validación;
- propuesta curricular y confirmación docente;
- solicitudes rápidas/avanzadas con snapshot inmutable;
- planes, periodos, cupos, reservas y consumo idempotente;
- pipeline IA estructurado generation/audit/correction;
- modo manual operativo;
- revisión humana con checklist y decisiones;
- versiones documentales y aprobaciones;
- Standard Export v2;
- formatos institucionales opcionales como adaptadores de salida;
- DOCX/PDF privados;
- entregas, descargas, retención y correcciones;
- notificaciones y operación administrativa;
- compensación de revisores y métricas básicas.

## Decisiones vigentes

### Generación independiente del formato

La IA genera contenido pedagógico canónico. El DOCX no define el contrato de generación.

Cambiar Standard/institucional después de la aprobación no debe:

- cambiar el snapshot;
- incrementar `input_revision`;
- consumir otra unidad;
- ejecutar nuevamente generation/audit;
- alterar textos curriculares.

### Standard v2 primero

El camino garantizado del MVP es Standard v2.

Un formato institucional puede mejorar la presentación, pero su ausencia o complejidad no debe impedir generar una planeación pedagógicamente válida. Si un adaptador institucional no puede renderizarse, el sistema debe ofrecer Standard o bloquear únicamente esa exportación, no la generación.

### Currículo oficial antes de venta curricular autoservicio

No se debe lanzar como producto curricular autoservicio mientras el catálogo usado en nuevas solicitudes no haya pasado validación técnica/editorial y `ProductionCurriculumPolicy`.

Los datos DEMO siguen siendo útiles para tests, nunca para solicitudes reales.

## Piloto que queremos validar

El siguiente sprint de producto debe comprobar con al menos dos docentes reales que:

1. entienden cómo crear grupo/contexto;
2. pueden iniciar una planeación sin recaptura innecesaria;
3. comprenden y pueden corregir las conexiones curriculares propuestas;
4. confirman conscientemente contenidos/PDA/ejes;
5. la planeación generada es útil pedagógicamente;
6. Standard v2 es suficientemente claro para trabajo real;
7. el tiempo de creación es competitivo con preparar la planeación manualmente;
8. las correcciones necesarias pueden identificarse y clasificarse.

## Criterios de aceptación

- Cliente A nunca accede a información de B.
- Una solicitud no inicia sin información, currículo y derechos válidos.
- Concurrencia/reintentos no duplican consumo ni pagos.
- Todo texto curricular del plan canónico proviene del snapshot confirmado.
- Una auditoría/revisión se liga a la versión exacta evaluada.
- Correcciones crean una versión hija y vuelven a auditarse.
- Una entrega contiene artefactos de una versión aprobada.
- Standard v2 genera DOCX/PDF legibles.
- Elegir otro formato de exportación no regenera el plan.
- Los estados públicos no exponen detalles internos del proveedor IA.
- La operación fallida es recuperable desde administración sin editar estados arbitrariamente.

## Automatización

El objetivo sigue siendo reducir de forma fuerte la intervención humana, pero no se debe medir automatización por cantidad de jobs.

Medir:

- solicitudes completadas sin intervención administrativa;
- minutos humanos por solicitud;
- tasa de revisión humana;
- tasa de corrección por auditoría;
- costo IA real por solicitud;
- tiempo total hasta entrega;
- fallos/reintentos;
- satisfacción/utilidad en piloto.

Un proveedor API real es requisito para autoservicio automatizado, pero debe integrarse después de estabilizar el contrato canónico y pasar pruebas de errores, idempotencia y costos.

## Comercial

Se mantienen:

- `planning_limit` por periodo;
- `human_review_limit` por periodo cuando aplica;
- `correction_limit` como rondas de corrección del cliente por solicitud;
- `max_planning_days` para cálculo de unidades;
- derechos congelados por periodo/solicitud;
- historial de pagos/devoluciones/consumo.

Reintentos y correcciones internas por calidad no consumen rondas comerciales del cliente.

## Fuera del MVP inmediato

- aplicación móvil nativa;
- organizaciones multiusuario/multiescuela complejas;
- marketplace de revisores;
- editor colaborativo;
- Word completo dentro del navegador;
- conversión perfecta de cualquier DOCX arbitrario;
- multigrado en una sola solicitud;
- scraping/sincronización curricular automática;
- embeddings como autoridad curricular;
- OCR universal;
- múltiples proveedores IA activos simultáneamente;
- fine-tuning;
- facturación fiscal automática;
- analítica predictiva.

## Decisiones antes de lanzamiento comercial

Antes de venta abierta definir y probar:

- catálogo y precios;
- periodicidad/renovación;
- SLA;
- cobertura y ventana de correcciones;
- proveedor de pago real y reconciliación;
- proveedor IA real;
- hosting/workers/backups;
- retención de archivos;
- privacidad/términos;
- calidad pedagógica con docentes;
- catálogo curricular oficial elegible para producción.

Hasta cerrar estos puntos, tratar el producto como piloto controlado, no como autoservicio plenamente automatizado.
