# MVP de validación curricular

Inicio: 2026-09-11

Base: `main` después del cierre de Fase 6.

## Tesis que vamos a validar

Planeaciones no debe competir sólo por «generar un documento». El valor que queremos comprobar es que el sistema ayude al docente a **decidir y conectar correctamente lo curricular** y después materialice esa decisión en la planeación y en el formato institucional que necesita entregar.

Tesis de producto:

> Planeaciones convierte las obligaciones curriculares de un docente en una experiencia de enseñanza viable y en el documento que necesita entregar.

Hipótesis principal:

> Si el docente indica grado, periodo y qué necesita trabajar, el sistema puede proponer conexiones curriculares suficientemente útiles para que el docente las confirme y vuelva a usar el producto en su siguiente planeación real.

## Usuarios del experimento

Piloto inicial con dos docentes reales:

- una docente de preescolar;
- una docente de primaria.

No se requieren entrevistas técnicas extensas. La propia aplicación debe capturar señales de comportamiento y tres preguntas de salida.

## Flujo delgado

```text
¿Qué necesitas trabajar?
    ↓
Contexto mínimo
    ↓
Sistema consulta motor curricular
    ↓
Muestra conexiones sugeridas
    ↓
Docente acepta / rechaza / agrega
    ↓
Confirma mapa curricular
    ↓
Revisa resumen final y confirma planeación
    ↓
Activa / inicia generación desde seguimiento
    ↓
Usa formato institucional configurado
    ↓
DOCX / PDF
    ↓
Feedback de salida
```

## Contexto mínimo

El primer experimento debe pedir sólo lo necesario para producir sugerencias útiles:

- grupo/grado;
- periodo o rango de fechas;
- tema, proyecto o necesidad a trabajar;
- observación contextual breve opcional;
- duración/sesiones cuando no pueda inferirse del perfil del grupo.

La información ya disponible en `GroupProfile` debe reutilizarse y no recapturarse.

## Pantalla de conexiones curriculares

Antes de generar el documento se debe mostrar una pantalla intermedia que permita entender y confirmar la decisión curricular.

Debe presentar, según disponibilidad del catálogo:

- contenidos;
- PDA;
- campos formativos;
- ejes articuladores;
- explicación breve de por qué se propone cada conexión;
- origen de la propuesta: coincidencia directa, relación del catálogo o selección añadida por el docente.

Acciones mínimas:

- aceptar sugerencia;
- rechazar sugerencia;
- agregar otra selección compatible;
- confirmar mapa curricular.

La generación **no** debe comenzar automáticamente al introducir el tema. Primero se valida si el mapa curricular propuesto aporta valor. Después de confirmar el mapa, el docente pasa a un resumen final de sólo lectura; desde ahí confirma la planeación y posteriormente inicia la generación desde el seguimiento cuando la solicitud está activada.

## Instrumentación mínima

Eventos de producto a registrar sin datos sensibles de estudiantes:

```text
planning_started
curriculum_suggestions_shown
curriculum_suggestion_accepted
curriculum_suggestion_rejected
curriculum_selection_added
curriculum_map_confirmed
plan_generated
plan_section_edited
plan_section_regenerated
plan_section_deleted
format_sample_generated
docx_downloaded
planning_completed
```

Cada evento debe incluir sólo identificadores internos y metadatos de producto necesarios, por ejemplo:

- user_id;
- planning_request_id o draft id;
- group_id;
- curriculum_version_id;
- grade_id;
- event_type;
- metadata JSON sanitizado;
- occurred_at.

No almacenar nombres de alumnos, diagnósticos identificables ni contenido sensible en analytics.

## Feedback al terminar

Sólo tres preguntas:

1. ¿Cuánto tiempo calculas que te ahorró esta planeación?
2. ¿Qué fue lo que más te ayudó?
   - encontrar contenidos/PDA;
   - conectar campos/ejes;
   - proyectos/materiales;
   - actividades;
   - evaluación;
   - adaptar al grupo;
   - llenar mi formato;
   - otro.
3. ¿Usarías Planeaciones para tu siguiente planeación real?

El feedback complementa, pero no sustituye, el comportamiento observado.

## Métrica primaria

La señal principal es **retención de tarea**:

> El docente vuelve por iniciativa propia para crear su siguiente planeación real.

Indicadores secundarios:

- proporción de sugerencias aceptadas/rechazadas;
- cantidad de selecciones manuales añadidas;
- tiempo desde inicio hasta confirmación curricular;
- tiempo hasta generación/descarga;
- secciones que más se editan/regeneran;
- uso de formato institucional;
- tiempo ahorrado declarado.

## Criterios de aprendizaje

El piloto debe permitir distinguir al menos estas rutas de producto:

- si la mayor utilidad es encontrar/relacionar currículo → profundizar motor curricular y transversalidad;
- si el mayor valor está en proyectos/libros/materiales → priorizar motor de fuentes/proyectos;
- si el problema principal es adaptar al grupo → priorizar contextualización pedagógica;
- si la mayor utilidad es evaluación → profundizar evidencias/instrumentos/evaluación;
- si el valor dominante es llenar el formato → volver a priorizar motor documental;
- si las necesidades de preescolar y primaria divergen demasiado → elegir segmento/nivel inicial antes de ampliar.

## Reutilización del sistema existente

Este sprint debe construir sobre lo ya implementado, no reemplazarlo:

- catálogo curricular versionado;
- `CurriculumSuggestionService` y selección curricular existentes donde sean reutilizables;
- grupos y perfil pedagógico;
- `PlanningRequest` y snapshots;
- pipeline IA existente;
- `CanonicalPlanV1`;
- renderer estándar e institucional;
- formatos institucionales self-service;
- entrega privada.

Revisores, pagos, suscripciones y administración permanecen en el repositorio, pero no deben ampliar el alcance del experimento salvo que sean indispensables para ejecutar el flujo.

## Fuera de alcance durante la validación

- convertir el diseñador DOCX en un editor Word completo;
- Google Workspace / Google Docs add-on;
- proveedor IA API definitivo;
- checkout/gateway de pago definitivo;
- ampliar panel de revisores;
- dashboards ejecutivos complejos;
- analytics de marketing;
- generar campos personalizados arbitrarios sin versionar antes el contrato IA;
- recopilar datos identificables de estudiantes.

## Hardening pendiente de Fase 6, no bloqueante del piloto local

El diseñador institucional usa actualmente `JSZip 3.10.1` y `docx-preview 0.4.0` desde CDN. Antes de producción pública se deben vendorizar/bundlear y validar CSP/build. No se ampliará funcionalidad del diseñador mientras tanto.

## Entregables del sprint

### V1 — Instrumentación y entrada del experimento

- tabla/modelo de eventos de producto append-only;
- servicio único para registrar eventos;
- entrada simplificada «¿Qué necesitas trabajar?»;
- reutilización automática de grupo/perfil;
- tests de ownership y privacidad de eventos.

### V2 — Mapa curricular confirmable

- ejecutar sugerencias sobre catálogo publicado;
- mostrar contenidos/PDA/campos/ejes;
- aceptar/rechazar/agregar;
- persistir confirmación y fingerprint;
- instrumentar decisiones.

### V3 — Generación y salida

- pasar mapa confirmado al pipeline de generación existente;
- generar/visualizar la planeación;
- usar formato preferido/institucional;
- descargar DOCX/PDF;
- registrar eventos de generación/descarga.

### V4 — Feedback y piloto

- formulario de tres preguntas;
- resumen mínimo de comportamiento por docente/planeación para análisis del piloto;
- prueba real con preescolar y primaria;
- decisión documentada del siguiente sprint.

## Regla del sprint

No agregar una capacidad grande sólo porque «sería útil». Cada cambio debe responder a una pregunta concreta del experimento o ser necesario para que las dos docentes completen una planeación real.
