# Flujos vigentes

Actualizado: 2026-09-15.

Este documento describe el flujo actual del producto. La historia de implementación por fases permanece en Git y en documentos de cierre.

## Regla principal

La solicitud, el snapshot y la generación pedagógica son independientes del formato de exportación. Un DOCX o `FormatVersion` no es requisito para enviar ni generar una planeación.

## Flujo de una planeación

```text
BORRADOR
  ↓
ESPERANDO_INFORMACION / ESPERANDO_PAGO
  ↓
LISTA_PARA_PROCESAR
  ↓
GENERACION_IA
  ↓
AUDITORIA_IA
  ├─ APROBADA
  ├─ REVISION_HUMANA
  └─ CORRECCION_IA → AUDITORIA_IA

APROBADA
  ↓
GENERANDO_DOCUMENTO
  ↓
LISTA_PARA_ENTREGAR
  ↓
ENTREGADA
  ↓
COMPLETADA
```

Después de entrega puede existir `CORRECCION_SOLICITADA`. Una corrección aceptada crea una nueva versión, vuelve a auditoría/revisión cuando corresponda y produce una nueva entrega sin borrar la anterior.

## Envío

Antes de enviar deben estar resueltos:

- propietario y grupo válidos;
- grado y versión curricular elegibles;
- fechas y contexto pedagógico requeridos;
- contenidos/PDA/ejes compatibles y confirmados;
- cálculo comercial y derechos suficientes.

Al enviar se crea un `RequestInputVersion` inmutable con contexto docente, selección curricular textual, materiales permitidos, manifest, cálculo comercial e `input_revision`.

**El formato de exportación no forma parte de este snapshot pedagógico.**

## Consumo comercial

- `planning` se reserva antes del pipeline y se consume una sola vez al iniciar la primera generación.
- `human_review` se consume al iniciar el primer ciclo humano efectivo cuando el derecho lo exige.
- `client_correction` corresponde únicamente a rondas contractuales posteriores a entrega.
- reintentos técnicos, auditorías y correcciones internas no vuelven a consumir unidades.

Todas las operaciones deben ser idempotentes.

## Generación y auditoría

La generación produce `GeneratedPlanDraftV1`. El servidor reconstruye `CanonicalPlanV1` desde el snapshot confirmado.

La auditoría pertenece a una `DocumentVersion` exacta. Si una corrección crea una versión hija, esa versión debe auditarse nuevamente. Ninguna aprobación se hereda automáticamente.

Hallazgos que intenten modificar currículo oficial o contexto congelado no se autocorrigen.

## Revisión humana

Cuando aplica, una revisión congela asignación, `DocumentVersion` y checklist. Aprobar exige criterios obligatorios completos.

Una petición de cambios crea una nueva versión y regresa a auditoría antes de volver a revisión humana.

## Exportación

Solo después de `APROBADA` se resuelve el formato:

- sin selección explícita → Standard v2;
- formato institucional válido → adaptador institucional publicado/ready.

Cambiar formato no modifica el contenido canónico, no incrementa `input_revision` y no ejecuta IA de nuevo.

## Entrega

`ENTREGADA` significa que una versión aprobada y sus archivos exactos están publicados privadamente. Un fallo de email no revierte la entrega y una descarga no crea una entrega nueva.

Las correcciones conservan las versiones y entregas anteriores.

## Fallos técnicos

Errores de IA, render, worker, almacenamiento o notificación abren `request_blocks` y permiten reintento idempotente. No equivalen a cancelación ni provocan otro consumo comercial por sí mismos.

## Invariantes

1. las transiciones críticas se realizan mediante acciones, no editando `status` libremente;
2. cada aprobación pertenece a una versión exacta;
3. cada corrección de contenido crea una nueva versión;
4. toda nueva versión se reaudita;
5. una respuesta obsoleta no puede promoverse;
6. una reserva no se consume dos veces;
7. la exportación no cambia el contenido canónico;
8. el formato institucional nunca condiciona la generación.
