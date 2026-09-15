# Backlog vigente

Actualizado: 2026-09-15.

Este archivo contiene únicamente trabajo pendiente o activo. La evidencia histórica de Fases 1–6 se conserva en Git y en los documentos de cierre correspondientes.

## Estado base

- [x] Fases 1–6 cerradas funcionalmente.
- [x] Generación pedagógica canónica separada del formato de exportación.
- [x] Standard Export v2 definido como salida predeterminada.
- [x] Formatos institucionales desacoplados de la generación.
- [x] Currículo versionado e inmutable implementado.
- [x] Pipeline manual generation/audit/correction implementado.
- [x] Revisión humana, entregas y correcciones implementadas.
- [x] Rediseño principal del flujo docente integrado en la rama curricular activa.

## P0 — Coherencia de arquitectura y documentación

- [x] Crear `docs/CURRENT_STATE.md` como resumen de autoridad actual.
- [x] Alinear `README.md` con generación canónica + exportación posterior.
- [x] Alinear `ARCHITECTURE.md` con la arquitectura vigente.
- [x] Alinear `CURRICULUM.md` con currículo oficial y `ProductionCurriculumPolicy`.
- [x] Alinear `AI_PIPELINE.md` con generación independiente del formato.
- [x] Alinear `WORKFLOWS.md` y retirar formato del snapshot pedagógico.
- [x] Actualizar `DATABASE.md` para describir el esquema implementado, no un diseño previo.
- [x] Sustituir el diario histórico de `TASKS.md` por backlog vigente.
- [ ] Revisar referencias restantes a `preferred_format_id`, `template_contract`, `adaptive_template_generation_v1` y `format_version_id` que impliquen acoplamiento de generación.
- [ ] Mover documentación experimental/adaptativa a `docs/history` o marcarla explícitamente como no vigente.
- [ ] Añadir ADRs cortos para decisiones irreversibles de arquitectura.

## P0 — Currículo oficial primaria Fases 3–5

- [x] Construir herramientas de extracción/importación del currículo oficial.
- [x] Implementar validación específica para primaria oficial.
- [x] Implementar `ProductionCurriculumPolicy`.
- [x] Corregir falsos positivos editoriales como `democracia`/texto pedagógico legítimo.
- [ ] Validar exhaustivamente el catálogo ya construido antes de reimportar.
- [ ] Confirmar conteos esperados por fase, grado, campo, contenido y PDA.
- [ ] Confirmar procedencia/source locators y metadatos de la versión oficial.
- [ ] Ejecutar validación editorial final.
- [ ] Publicar/activar únicamente cuando todas las validaciones pasen.
- [ ] Verificar que el catálogo DEMO nunca sea elegible en flujo productivo.

## P0 — Flujo docente MVP

- [x] Grupo + perfil pedagógico reutilizable.
- [x] Nueva planeación con contexto mínimo.
- [x] Propuesta/selección curricular.
- [x] Confirmación curricular explícita.
- [x] Eliminación del selector heredado de formato del flujo canónico.
- [x] Correcciones recientes del selector de grado y dropdowns.
- [ ] Recorrer el flujo completo en desktop y móvil con datos reales de prueba.
- [ ] Medir tiempo desde “Nueva planeación” hasta confirmación/envío.
- [ ] Reducir pasos/campos que no sean indispensables.
- [ ] Validar mensajes de error/bloqueo con lenguaje comprensible para docentes.

## P0 — Standard Export v2

- [x] Definir contrato de Standard v2.
- [x] Establecer Standard como fallback/default global.
- [x] Desacoplar renderer del contrato de generación IA.
- [ ] Validar visualmente DOCX generado con varias planeaciones reales.
- [ ] Validar PDF equivalente.
- [ ] Revisar tablas, saltos de página, sesiones largas, instrumentos y notas.
- [ ] Confirmar que exportar otra vez no consume IA ni modifica `input_revision`.

## P1 — Pruebas de arquitectura

- [x] Test de independencia entre generación y formato.
- [ ] Añadir regresión que impida volver a meter `format_version_id` en `RequestInputVersion` pedagógico.
- [ ] Añadir regresión que impida incluir `template_contract` institucional en paquetes generation.
- [ ] Añadir regresión que confirme Standard disponible aun sin formato institucional.
- [ ] Añadir regresión para cambio de formato sin nueva `AiExecution` generation.
- [ ] Añadir regresión para currículo productivo vs DEMO.
- [ ] Ejecutar suite completa después de la consolidación documental/código.

## P1 — Validación con docentes

Objetivo: validar el MVP curricular antes de ampliar el diseñador o automatización.

- [ ] Preparar 2 escenarios reales de primaria con Fases 3–5.
- [ ] Docente piloto 1: crear grupo → planeación → confirmar currículo → revisar salida.
- [ ] Docente piloto 2: repetir con otro grado/contexto.
- [ ] Registrar fricción, errores curriculares, calidad de actividades y utilidad del documento.
- [ ] Priorizar correcciones por impacto real, no por sofisticación técnica.

## P2 — Proveedor IA real

Rama existente: `phase-7a-openai-provider`.

- [x] Cliente inicial de Responses API con salida estructurada y `store=false` en rama separada.
- [x] Manejo inicial de autenticación, rate limit, errores temporales y timeout ambiguo.
- [ ] Rebasar/integrar la rama sobre la arquitectura canónica vigente.
- [ ] Confirmar schemas soportados por el proveedor.
- [ ] Integrar adapter en `AI_MODE=api` sin alterar el modo manual.
- [ ] Añadir pruebas sandbox sin datos personales de alumnos.
- [ ] Registrar uso/costos reales e idempotencia.
- [ ] Validar generation, audit y correction end-to-end.
- [ ] Mantener fallback manual explícito de operación; nunca fallback silencioso a fake.

## P2 — Comercial productivo

- [ ] Elegir proveedor de pagos real.
- [ ] Implementar sandbox/webhooks/reconciliación.
- [ ] Definir precios finales, límites, SLA y cobertura de correcciones.
- [ ] Validar métricas de costo/margen con IA real.
- [ ] Definir políticas de privacidad/retención antes de venta abierta.

## Congelado hasta validar MVP

No ampliar de momento:

- diseñador institucional como clon de Word;
- generación gobernada por plantilla DOCX;
- `adaptive_template_generation_v1` como arquitectura principal;
- OCR universal;
- multigrado en una solicitud;
- sincronización automática de fuentes SEP;
- editor colaborativo;
- app móvil nativa;
- microservicios.

## Definition of Done para la consolidación actual

La consolidación se considera cerrada cuando:

1. documentación vigente y código no contradicen la arquitectura canónica;
2. el currículo oficial Fases 3–5 supera validaciones técnicas/editoriales;
3. la suite completa queda verde;
4. Standard v2 genera salidas revisadas visualmente;
5. el flujo completo puede probarse con al menos dos docentes sin depender de un formato institucional;
6. `main` puede recibir la rama consolidada sin arrastrar arquitectura adaptativa obsoleta.
