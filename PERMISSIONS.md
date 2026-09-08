# Permisos y aislamiento

Deny-by-default; roles extensibles mediante roles/role_user y capacidades en Policies. Registro público asigna solo DOCENTE_CLIENTE. Middleware de autenticación, correo verificado y usuario activo; onboarding limita acciones productivas. Revisores y administradores creados/invitados por acción autorizada. Un usuario multirrol recibe solo accesos explícitos y el panel mantiene su contexto.

## Matriz

| Recurso/acción | Cliente | Revisor | Administrador |
|---|---|---|---|
| /app, registro, perfil propio | Sí | Solo si tiene rol cliente | Acceso explícito según contexto |
| /review | No | Sí | Sí |
| /admin | No | No | Sí |
| Escuela/grupos/perfil | Propios, límites de plan | Snapshot necesario de asignación | Todos |
| Solicitud crear/editar borrador | Propia | No | Sí, como operación auditada |
| Solicitud enviada | Ver propia; cambios por acción controlada | Leer solo asignada | Gestionar con invariantes |
| Resultados internos IA | No; solo entrega publicada | Versión/auditoría asignada | Sí |
| Descargar archivos | Propios habilitados y entregados | Solo necesarios de asignación activa | Sí, auditado |
| Solicitar corrección | Propia entregada y con derechos | Interna de trabajo asignado | Sí |
| Aprobar calidad | No | Asignación activa, versión actual, checklist | Solo acción de revisión válida con trazabilidad |
| Planes y prompts/checklist | Ver catálogo publicado | Ver checklist congelado | Publicar versiones |
| Pago/suscripción | Ver propios e iniciar checkout | No | Confirmar/conciliar/devolver |
| Asignar/reasignar | No | No | Sí; automático por sistema |
| Honorarios | No | Solo propios | Gestionar todos |
| Auditoría global/costos IA/margen | No | No | Sí |
| Roles, límites, estado directo | No | No | Roles mediante acción; estados y límites no por bypass |

## Aplicación

canAccessPanel es puerta de entrada, nunca autorización suficiente. Policies para Group, School, PlanningRequest, File, DocumentVersion, CorrectionRequest, Payment, Subscription, ReviewAssignment, Review, PromptTemplate y ReviewerSettlement; acciones sensibles con métodos específicos submit, approve, assign, deliver, refund y download.

Consultas Filament, búsqueda global, opciones de selects, widgets, exports, contadores y relaciones limitados antes de paginar. Route binding y Policy verifican cada ID; respuesta 404 para recursos ajenos cuando convenga evitar enumeración. Nunca confiar en owner_id, rol, precio, estado, reviewer_id o path enviados por cliente. Derivar propietario de sesión y validar FK anidadas. Form Requests/validadores de acciones hacen validación server-side, no solo esquema UI.

Revisor obtiene DTO mínimo: grado/perfil pedagógico, periodo, datos curriculares, resultado y auditoría. Excluir email/teléfono, pagos, datos de cuenta y documentos de otros trabajos. Archivo institucional solo si imprescindible para esa revisión. Al reasignar pierde acceso al contenido; conserva resumen de trabajo propio para honorarios. Aprobación antigua no permite abrir datos privados después de revocar asignación.

Descarga siempre verifica pertenencia, categoría, scan_status y publicación/asignación actual; resultados aún no entregados no se muestran al cliente. URLs temporales solo después de Policy y TTL corto; revisión por proxy para revocación inmediata. Temporales Livewire también privados. No exposición por storage público ni previsualización sin autorización.

CSRF en sesiones, rate limits configurables para login/registro/reset/envío/uploads/descargas; webhook sin CSRF exige firma y deduplicación. Recuperación de cuenta con respuesta genérica y token de un uso. Cookies seguras, HTTPS, APP_DEBUG=false, secretos fuera de Git/logs. Acciones administrativas sensibles registran actor y motivo. El administrador conserva acceso completo a gestión, pero no puede saltar integridad o simular una aprobación.

## Pruebas obligatorias

Dos clientes A/B, dos revisores R1/R2 y administrador. Probar acceso cruzado por URL, Livewire, select, búsqueda, export, contador y archivo; cliente A no ve ni modifica B. Revisor solo asignado y pierde permisos tras reasignación. Rechazar IDs anidados ajenos, mass assignment de rol/precio/estado, correo sin verificar y cuenta suspendida. Probar URL expirada, cuarentena, resultado sin publicar y descarga sensible auditada. Probar también accesos positivos; ocultar un botón no cuenta como seguridad.
