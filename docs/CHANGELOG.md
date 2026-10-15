# Changelog

Resumen de cambios por entrega. Los procedimientos están en los manuales y los resultados de verificación en [Estado](PROJECT_STATE.md). El [archivo histórico](archive/README.md) conserva informes completos y el [changelog anterior](archive/2026-10-15/CHANGELOG.md).

## 2026-10-15 — Organización de documentación

- README breve, [índice por tareas](README.md), [guía de uso](USER_GUIDE.md) y [manual de operación](OPERATIONS.md).
- Arquitectura y modelo de datos integrados por responsabilidad, sin bloques sucesivos de ampliaciones.
- Referencias de enriquecimiento/scoring separadas de estado de instalación y ejemplos CLI.
- Estado con evidencia fechada y backlog de pendientes; auditorías y planificación preservadas en archivo.
- Reglas de mantenimiento, enlaces internos actualizados y referencias desde las instrucciones de agentes.
- Corrección documental de la supuesta línea base comercial y de la descripción de asignación aleatoria; sin cambios de lógica ni de datos operativos.

## 2026-10-15 — Activación autorizada de enriquecimiento y scoring

- Dos migraciones aplicadas con respaldo previo/posterior y ensayo sobre copia real.
- Campaña de prueba de Rosario eliminada conservando prospectos; datos importados de Notion preservados exactamente y excluidos del recálculo/piloto.
- Recálculo local de prospectos de Maps, piloto pequeño de sitios y reinicio local de aplicación/worker.
- Compatibilidad del transporte con Guzzle corregida, manteniendo DNS fijado, proxy desactivado y bloqueo de redirecciones; regresión sin red añadida.
- Resultado y límites en [Estado](PROJECT_STATE.md); detalle en el [informe archivado](archive/2026-10-15/ENRICHMENT_SCORING_REPORT.md).

## 2026-10-15 — Enriquecimiento multifuente y scoring por oferta

- Extracción DOM/JSON-LD, firmas deterministas y páginas internas pertinentes, con robots, límites globales, frecuencia y TTL.
- Evidencias con fuente, confianza, revisión, vigencia e historial; conciliación conservadora de entidades y sucursales.
- Perfiles por oferta con cuatro dimensiones, explicación y vigencia; snapshots inmutables al contactar y métricas históricas contextualizadas.
- Integración en ficha, filtros, cola, dashboard, exportaciones y CLI; OSM opcional desactivado.
- [Informe de implementación y activación](archive/2026-10-15/ENRICHMENT_SCORING_REPORT.md).

## 2026-10-14 — Auditoría Scraper + CRM y primera activación

- Identidad y bloqueo de duplicados, recuperación de adquisición/importación, SSRF/DNS, CSV seguro y selección acotada.
- Oportunidades por campaña, sincronización del principal, atribución de variantes/resultados y pruebas aisladas.
- Comparación/consolidación revisada, análisis previo CSV, trabajo diario, CLI y mejoras de consultas por pestaña/lote.
- Migraciones activadas con respaldo y comprobación de preservación; revisión visual sobre datos sintéticos.
- [Auditoría original](archive/2026-10-15/CODE_REVIEW.md), [implementación](archive/2026-10-15/IMPLEMENTATION_REPORT.md) y [trazabilidad](archive/2026-10-15/IMPLEMENTATION_PROGRESS.md).

## 2026-10-10 — CRM unificado e importación revisada de Notion

- Relaciones independientes de cliente/colaborador, contactos de organización, servicios y renovaciones sobre la cartera existente.
- Importador puntual con vista previa, decisiones, huellas, respaldo, privacidad e idempotencia.
- Reconciliación autorizada del export y preservación del contacto principal al añadir otras personas.
- Detalle en el [estado histórico archivado](archive/2026-10-15/PROJECT_STATE.md).

## 2026-09-24 y etapas iniciales

- Laravel/SQLite, ofertas/campañas, adquisición CSV, pipeline, contacto asistido, propuestas, métricas, exportaciones e integraciones iniciales.
- Selección de repositorios, alcance y piloto como antecedentes; [historial completo](archive/2026-10-15/CHANGELOG.md).
