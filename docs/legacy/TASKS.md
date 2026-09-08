# Checklist de implementación

Estado al 2026-09-07: fase 0 completada; fases de código pendientes. No se instalaron dependencias ni se ejecutaron pruebas de aplicación todavía.

## Fase 0 — Arquitectura

- [x] Leer requisitos y revisar repositorio inicial.
- [x] Proponer monolito Laravel/Filament, alcance y reglas en PLAN.md.
- [x] Diseñar entidades, relaciones, índices e integridad en DATABASE.md.
- [x] Dividir implementación y verificaciones en fases.
- [x] Consultar documentación oficial de versiones Laravel/Filament.

Evidencia: repositorio con README inicial; PHP, Composer y Node detectados en PATH. MySQL y extensiones pendientes de comprobación. Documentación creada, sin aplicación iniciada.

## Fase 1 — Base técnica

- [ ] Verificar PHP/extensiones, Composer, Node y MySQL; resolver versiones estables y guardar lockfiles.
- [ ] Instalar Laravel y Filament; configurar español, zona horaria, MXN y .env.example sin secretos.
- [ ] Crear migraciones, modelos, relaciones, casts y enums de DATABASE.md.
- [ ] Crear factories y seeders ficticios; administrador por comando seguro.
- [ ] Autenticación interna, policies y disco privado; deshabilitar registro administrativo público.
- [ ] Probar migraciones y FK en MySQL, login y denegación a invitados/no administradores.
- [ ] Revisar login/panel en escritorio y móvil; registrar evidencia y corregir.

## Fase 2 — Captura y expedientes

- [ ] CRUD docentes y perfil maestro con historial de solicitudes.
- [ ] Formulario de solicitud con todos los campos y validación de fechas/grado/listas.
- [ ] Adjuntos privados y descarga autenticada con validación MIME/extensión/tamaño.
- [ ] Alta pública atómica: docente + perfil + solicitud + adjuntos.
- [ ] Protección CSRF, honeypot, throttle, idempotencia y coincidencias de contactos sin fuga de datos.
- [ ] Enlaces por token con hash, expiración, revocación y consumo atómico.
- [ ] Formulario de docente existente sin recapturar perfil ni exponer historial.
- [ ] Probar alta, rollback/limpieza, doble envío, límites, rutas manipuladas, descarga ajena y token vencido/usado/revocado.
- [ ] Revisar formularios móviles, errores, carga de documentos y confirmación; corregir.

## Fase 3 — Operación diaria

- [ ] Pagos manuales con fecha/monto/método/referencia y confirmación idempotente.
- [ ] Matriz de transiciones, validación de completitud/pago e historial de cambios.
- [ ] Dashboard «Lo que necesita mi atención», prioridades y contadores por estado.
- [ ] Captura rápida de minutos y actividades desde solicitud.
- [ ] Probar bloqueos de producción por datos/pago, doble confirmación e ingresos por fecha.
- [ ] Revisar filtros, acciones siguientes, estados accesibles y responsive; corregir.

## Fase 4 — Paquete para IA

- [ ] Plantilla maestra editable y consolidación completa de perfil + periodo + documentos.
- [ ] Instantáneas versionadas, manifiesto de archivos y detección de datos cambiados.
- [ ] COPIAR PROMPT COMPLETO y descarga .txt/.md autorizada.
- [ ] Indicar datos faltantes y archivos a adjuntar manualmente; no inventar contenidos/PDA.
- [ ] Probar completitud, caracteres españoles, aislamiento por solicitud e inmutabilidad de paquetes.
- [ ] Verificar copia real, descargas y lectura en móvil; corregir.

## Fase 5 — Revisión y entrega

- [ ] Resultados IA con modelo, fecha, observaciones, versión y archivos.
- [ ] Checklist con los 13 controles obligatorios por versión.
- [ ] Correcciones, nuevas versiones e invalidación de revisión al modificar insumos.
- [ ] Bloquear LISTA_PARA_ENTREGAR y entrega sin aprobación/documentos/pago.
- [ ] Registrar fecha, medio, observaciones y archivos exactos de la entrega.
- [ ] Probar salto directo de estado, nueva versión, archivos de otra solicitud y doble entrega.
- [ ] Revisar recorrido paquete → versión → revisión → entrega en interfaz; corregir.

## Fase 6 — Continuidad y métricas

- [ ] Renovaciones al entregar, fechas editables, vencidas/próximas y conversión única.
- [ ] Crear nueva solicitud enlazada preservando solicitud y entrega anteriores.
- [ ] Seis plantillas editables con variables, vista previa y copiar; mensaje de renovación.
- [ ] Referidos, conversión y descuento registrado.
- [ ] Métricas: ingresos, activos, altas, renovaciones, cancelaciones e ingreso promedio.
- [ ] Tiempo humano por solicitud y docente/mes, alerta desde 45 minutos y correcciones.
- [ ] Probar cambio de mes/año, entrega tardía, renovación concurrente, desactivación/reactivación y denominadores vacíos.
- [ ] Revisar copia de mensajes, renovaciones y métricas en escritorio/móvil; corregir.

## Fase 7 — Piloto y puesta en marcha

- [ ] Ejecutar recorrido de alta a renovación con 5–10 docentes ficticias.
- [ ] Ejecutar suite completa en MySQL y build de assets; corregir fallos.
- [ ] Revisar interfaz responsive, navegación por teclado, errores y estados vacíos.
- [ ] Verificar autorización de todas las descargas, temporales privados y ausencia de tokens en logs.
- [ ] Documentar instalación, creación de administrador, uso, mantenimiento y límites conocidos.
- [ ] Definir backup de MySQL + archivos privados y comprobar restauración en entorno de prueba.
- [ ] Documentar configuración de HTTPS, sesiones, permisos, límites de subida y APP_DEBUG=false.
- [ ] Registrar resultados del piloto y tiempos humanos; ajustar solo fricción operativa demostrada.

## Evidencia por fase

Al cerrar cada fase añadir fecha, archivos o funcionalidades terminadas, comandos ejecutados, resultado de pruebas, comprobaciones visuales y limitaciones. Una casilla de verificación solo se marca si la comprobación fue ejecutada; documentar por separado lo bloqueado por entorno. Publicación y contacto con clientes no forman parte de este checklist local.
