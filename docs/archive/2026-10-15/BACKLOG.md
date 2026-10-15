> Archivo histórico del 2026-10-15. Conserva el contenido de la etapa; sus instrucciones y estados pueden estar superados. Consulta la [documentación vigente](../../README.md) y el [índice del archivo](../README.md). Las rutas y números de línea citados como texto corresponden al código de aquella revisión.

# Backlog

## Enriquecimiento/scoring — 2026-10-15

Implementado: extracción multifuente acotada, conciliación conservadora, evidencia versionada/caducable, puntuación por oferta, snapshots e integración UI/CLI/export/métricas.

Activación autorizada completada el 2026-10-15: respaldos, ensayo sobre copia real, dos migraciones aplicadas, recálculo de 105 prospectos de Maps y aplicación/worker reiniciados. Campaña de prueba de Rosario eliminada; 29 fichas/29 contactos de Notion intactos. Piloto de cinco sitios realizado. Pendiente comercial: definir el catálogo de ofertas, actualmente vacío, y validar los pesos con resultados. OSM permanece desactivado; su habilitación exige revisar endpoint y condiciones. La validación comercial de pesos necesita una muestra real adecuada y no se sustituye por pruebas sintéticas. Ver informe de entrega.


## Implementación de la auditoría — cierre 2026-10-14 (inicio 2026-10-13)

| Requisitos | Entregado en código |
|---|---|
| T01 / D01 | Identidad con contradicciones y bloqueo de duplicados pendientes en cola y contacto directo. |
| T02 / D04 | Reservas con propiedad, reprogramación de trabajo ocupado, checkpoints, recuperación y arranque con worker. |
| T03 / T08 | DNS público fijado en cURL, límites de transferencia y reconciliación atómica de evidencia. |
| T04 / T05 / T06 | CSV común seguro, filtros de oportunidad y selección vacía/inválida sin ampliación. |
| T07 / T09 / D03 | Atribución por eventos, contacto principal sincronizado y oportunidades independientes con habilitación explícita de clientes. |
| T10 / T12 / D02 | Claves foráneas en respaldo, aislamiento completo de pruebas y confirmaciones efectivas de eliminación. |
| T11 / D05 | Pestañas bajo demanda, relaciones mínimas, consultas por lote y experimentos basados en contactos reales. |
| O01 / O03 | Comparación/consolidación revisada con respaldo; vista previa CSV y diagnóstico descargable de rechazos. |
| O02 / O04 | Trabajo diario y CLI JSON de lectura/exportación que reutilizan los servicios web. |
| O05 | Medición del score/oferta actuales y preparación manual opcional, sin modificar pesos ni incorporar IA por API. |

Validación técnica registrada: 271 pruebas, 1265 aserciones y comprobaciones de PHP, JavaScript, compilación y vistas temporales. El resultado es sintético/local; no prueba resultados comerciales. La activación autorizada del 2026-10-14 aplicó las migraciones y verificó preservación de los datos anteriores.

## DONE

- Cartera unificada de prospectos, clientes y colaboradores.
- Clientes que también son colaboradores, con prioridad manual separada del scoring.
- Contactos múltiples por organización y servicios múltiples por cliente.
- Gestión de renovaciones sin calendario ni notificaciones automáticas.
- Importación puntual del ZIP de Notion con vista previa, decisiones, idempotencia y exclusión de credenciales.
- Respaldo SQLite verificable por comando y exportaciones de contactos/servicios.

## Migración de datos completada

- Se importaron 30 filas del export como 29 identidades comerciales, con autorización del usuario y sin duplicaciones.
- Se unificaron dos contactos de una organización con relación de cliente y colaborador; se clasificó el registro interno como colaborador y se descartó la plantilla vacía.
- Se verificaron los respaldos, los registros persistidos, la preservación del historial y la idempotencia.
- Los teléfonos no comparables y las notas excluidas por seguridad conservan su señal de revisión; la ficha sin correspondencia en el CSV permanece excluida.

## DONE histórico

- Laravel local con SQLite.
- Ofertas y campañas.
- Queries de adquisición.
- Orquestación de `gosom/google-maps-scraper`.
- Importación CSV.
- Deduplicación.
- Enriquecimiento determinista.
- Señales con evidencia.
- Scoring explicable y configurable.
- Asignación simple de oferta.
- Cola operativa.
- Plantillas.
- Contactos manuales.
- Email SMTP con dry-run.
- Follow-ups planificados.
- Pausa de follow-ups tras respuesta.
- Respuestas y objeciones.
- Pipeline con historial.
- Propuestas con versiones.
- Experimentos y variantes.
- LLM Bridge sin API paga.
- Métricas globales y segmentadas.
- Exclusiones.
- Kill switch.
- Límites diarios por campaña.
- Exportaciones CSV, JSON, SQLite y `analytics_export.csv`.
- Tests de comportamientos principales.
- Navegación reducida a Inicio, Campañas, Leads y Configuración.
- Workspace operativo por campaña.
- Filtros persistentes por query string.
- Exportación contextual de leads y analytics.
- Selección múltiple y acciones masivas.
- Ficha completa de lead con timeline.
- Kanban de oportunidades activas.
- Cola diaria enfocada en contacto manual.

## Activación y siguiente validación comercial

- Activación completada: respaldo, migraciones, integridad, preservación de datos, aplicación y worker locales verificados.
- Evaluar el piloto comercial con contactos reales realizados por la persona, muestras y tiempos registrados; el software no demuestra todavía mejora comercial.
- Revisar scoring/ofertas cuando exista evidencia suficiente. La implementación mantiene los pesos actuales y no elige un ganador A/B.

## REMAINING

- Importación/exportación masiva de exclusiones.
- UI específica de logs.
- Comparación visual de propuestas modificadas.
- Análisis temporal avanzado, si los datos del piloto lo justifican; los filtros de período y la atribución contextual ya existen.
- Gestión de horarios de contacto más estricta.
- Mejorar drag-and-drop visual del pipeline si aporta valor real.

## DEFERRED

- Exportación de `WON` a Notion: referencia histórica; el CRM actual es local y no necesita esa integración para operar.

- Proxies.
- APIs pagas.
- IA por API.
- Multiusuario.
- CRM completo.
- Infraestructura distribuida.
