# Cierre de Fase 6 — documentos, entrega y formatos institucionales

Fecha de cierre: 2026-09-11

Esta nota registra el estado **realmente verificado** de Fase 6. Complementa el historial de `README.md`, `TASKS.md`, `DATABASE.md`, `WORKFLOWS.md` y `PERMISSIONS.md` y prevalece sobre descripciones anteriores de la fase cuando se refieran al flujo institucional antiguo administrado desde `/admin`.

## Resultado

Fase 6 queda cerrada funcionalmente con estas capacidades:

- almacenamiento privado y catálogo de formatos;
- render estándar DOCX/PDF;
- entrega privada, historial de descargas y retención;
- correcciones del cliente por ventana/cuota conservando entregas previas;
- pipeline institucional DOCX con análisis, mapping, muestra, aprobación y publicación;
- notificaciones operativas deduplicadas y aviso de renovación próxima;
- diseñador visual de formatos institucionales gestionado por el propio docente;
- bindings precisos por fragmento de texto para conservar etiquetas fijas y soportar varios campos en una misma línea.

## Arquitectura final de formatos institucionales

El formato institucional pertenece al docente cliente. Administración no crea, analiza, mapea, aprueba ni publica formatos ajenos como flujo ordinario.

Flujo vigente:

```text
Docente sube DOCX
    ↓
Sistema analiza estructura y propone campos
    ↓
Docente abre diseñador visual
    ↓
Selecciona el valor que cambia
    ↓
Asigna campo canónico / personalizado / ignorar
    ↓
Genera muestra DOCX/PDF
    ↓
Aprueba muestra
    ↓
Publica versión
    ↓
Planeaciones futuras pueden usar ese formato
```

La regla conceptual es **seleccionar el valor, no la etiqueta**. Ejemplos:

```text
FECHA: Miércoles 15 de abril de 2026
       [valor reemplazable]

GRADO: Fase 4 (3ero y 4to)    GRUPO: Únicos
       [valor 1]                     [valor 2]
```

`FECHA:`, `GRADO:` y `GRUPO:` permanecen fijos. Los fragmentos se persisten con offsets sobre la versión fuente inmutable. Los bindings precisos tienen prioridad sobre heurísticas de línea completa y sobreviven a reanálisis.

## Mapping institucional v2

`InstitutionalFormatMapping` soporta:

- `anchors`: binding de zona completa para celdas/zonas que son íntegramente un valor;
- `fragments`: binding preciso dentro de una zona con `start`, `end`, texto fuente y campo;
- `custom_fields`: definición de campos particulares del formato;
- `ignored_zones`: zonas declaradas como texto fijo/no modificable.

Un párrafo puede contener varios fragmentos independientes. El renderer aplica reemplazos de forma segura sin destruir texto hermano ni etiquetas.

Los campos personalizados quedan persistidos y pueden validarse en muestras. El contrato IA vigente `generated_plan_draft_v1` **no genera todavía valores arbitrarios para campos personalizados**; habilitar esa capacidad requiere un contrato/schema IA versionado posterior. No se considera implementada por Fase 6.

## Renderer institucional

- `FormatVersion.renderer`: `institutional-v1`.
- versión de renderer verificada: `institutional-v1.1.0`.
- la huella de una muestra incluye fuente, SHA-256, mapping normalizado, hash de análisis y versión del renderer;
- PostgreSQL valida que la muestra aprobada corresponde exactamente a la versión/mapping/renderer que se publica;
- la migración `2026_10_01_000002_update_institutional_format_renderer_integrity.php` alinea las funciones de integridad con `institutional-v1.1.0` sin reescribir la migración histórica de 6E.

## Evidencia automatizada de cierre

Pruebas dirigidas del diseñador visual:

```text
10 tests
59 assertions
0 failures
```

Bloque documental final:

```text
48 tests
224 assertions
0 failures
```

Renderer documental:

```text
9 tests
64 assertions
0 failures
```

Suite completa final:

```text
577 tests
2169 assertions
0 failures
```

La suite completa requirió elevar el `memory_limit` de PHP para la ejecución larga:

```powershell
.\tools\php.ps1 -d memory_limit=512M vendor/phpunit/phpunit/phpunit --do-not-cache-result
```

Con el límite aislado de 128M, PHP terminaba prematuramente cerca del final de la suite sin una assertion fallida. `SchoolTest` y el caso señalado por PHPUnit fueron ejecutados de forma aislada y quedaron verdes; no se detectó regresión funcional.

## QA manual final

Se validó con un DOCX institucional real que:

- el valor de `FECHA` puede seleccionarse sin reemplazar la etiqueta;
- `GRADO` y `GRUPO` pueden mapearse de forma independiente aun compartiendo una línea;
- `Generar y ver ejemplo` conserva etiquetas y estructura y sustituye únicamente valores configurados;
- los bindings precisos persisten después de recargar el diseñador y pueden volver a renderizarse.

Resultado reportado: cambios aplicados correctamente.

## Seguridad y propiedad

Las operaciones de formato institucional exigen cliente activo/verificado y ownership exacto para crear, analizar, configurar, previsualizar, revisar, publicar y descargar fuente/muestras. Un administrador no obtiene acceso implícito a un formato ajeno por tener rol administrativo.

El DOCX fuente y las muestras permanecen en storage privado. El navegador obtiene el archivo únicamente mediante endpoint autenticado/autorizado.

## Limitaciones conocidas no bloqueantes para el MVP de validación

1. El diseñador visual usa actualmente versiones fijadas de `JSZip 3.10.1` y `docx-preview 0.4.0` desde CDN. El DOCX no se sube intencionalmente al CDN, pero JavaScript de tercero se ejecuta en la página. Antes de un despliegue productivo público se debe vendorizar/bundlear estas dependencias y validar CSP/build.
2. `docx-preview` es una representación HTML del DOCX; no se promete fidelidad visual 100 % con Word.
3. Headers, footers y text boxes no forman parte todavía de la selección visual interactiva.
4. Campos personalizados no tienen generación IA arbitraria mientras el contrato `generated_plan_draft_v1` no evolucione.
5. El diseñador no debe crecer hacia un editor Word completo sin evidencia de uso que lo justifique.

## Decisión de producto posterior al cierre

Después de Fase 6 se congela temporalmente la expansión del motor documental. El siguiente sprint no continúa automáticamente con el roadmap histórico de automatización comercial: se abre un **MVP de validación curricular** con dos docentes reales.

Objetivo del siguiente flujo:

```text
¿Qué necesitas trabajar?
    ↓
contexto mínimo / grado / periodo / tema
    ↓
motor curricular propone conexiones
    ↓
docente acepta, rechaza o agrega
    ↓
generación de secuencia/planeación
    ↓
formato institucional configurado
    ↓
DOCX/PDF
```

La validación buscará comprobar si el mayor valor está en conectar currículo, contextualizar, localizar proyectos/materiales, generar actividades/evaluación o materializar el formato institucional. La señal principal no será una opinión aislada, sino que el docente vuelva por voluntad propia a crear su siguiente planeación.
