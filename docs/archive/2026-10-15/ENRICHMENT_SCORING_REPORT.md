> Archivo histórico del 2026-10-15. Conserva el contenido de la etapa; sus instrucciones y estados pueden estar superados. Consulta la [documentación vigente](../../README.md) y el [índice del archivo](../README.md). Las rutas y números de línea citados como texto corresponden al código de aquella revisión.

# Informe de enriquecimiento multifuente y scoring comercial

Entrega de código: 2026-10-15. Repositorio: `/home/stefano/Documents/repositorios/scraperphp`. Rama/HEAD inicial conservado: `4708beb`. No se crearon commits ni se añadieron dependencias.

**Estado operativo actualizado — 2026-10-15**

Activación autorizada completada el 2026-10-15: ambas migraciones aplicadas en batch 4, con respaldo previo, ensayo sobre copia real y respaldo posterior. Se eliminó únicamente la campaña de prueba de Rosario y sus relaciones de campaña; se conservaron los 134 registros. Las 29 fichas, 29 contactos, 30 procedencias y tres importaciones de Notion se compararon antes/después y permanecen exactamente iguales, incluidas sus fechas. Se excluyeron del recálculo y de las consultas externas. Se recalcularon 105 prospectos de Google Maps.

Aplicación local en `http://127.0.0.1:8000` y worker `sales,default` reiniciados, sin instalar servicios permanentes. Diez rutas/pantallas/exportaciones verificadas con HTTP 200. Cola vacía, integridad SQLite correcta y cero errores de claves foráneas. Piloto autorizado de cinco sitios: uno verificado y cuatro desconocidos; se mantuvo la identidad principal y OSM desactivado. No hubo envíos comerciales ni nuevas búsquedas de Maps.

Se corrigió una incompatibilidad real de Guzzle 8.2.0: las opciones raw `CURLOPT_PROXY` y `CURLOPT_FOLLOWLOCATION` se sustituyeron por las opciones de alto nivel ya configuradas (`proxy=''`, `allow_redirects=false`); se conserva `CURLOPT_RESOLVE`. Una regresión que inicializa el transporte real sin ejecutar la conexión falló antes y pasa después. Suite final: **345 pruebas y 1629 aserciones correctas**, Pint y sintaxis de archivos afectados correctos.

El catálogo operativo está vacío: no se crearon ofertas ficticias ni puntuaciones por oferta sin catálogo. El siguiente paso comercial es definir/cargar la oferta y revisar una muestra antes de ampliar el enriquecimiento. Los contactos históricos continúan sin snapshots inventados. Informes privados y respaldos en `storage/app/private/backups/enrichment-activation-20261015-*.json` y archivos SQLite asociados, fuera de Git.

## 1. Diagnóstico y línea base

La implementación anterior mezclaba datos completos, contactabilidad y oportunidad comercial en un score global. La falta de un dominio registrado podía aportar puntos de necesidad; palabras aisladas de portada podían sugerir una plataforma. No había un registro inmutable de la puntuación conocida al contactar. Comparar el score actual con resultados anteriores podía introducir información posterior.

Se inspeccionaron código, modelos, rutas, servicios, jobs, importadores, vistas, CLI y los informes de la auditoría previa. La línea base local pasó 271 pruebas y 1265 aserciones. Se conservaron el trabajo local anterior, las protecciones HTTP, el scraper y el CRM. El plan fue: evidencias y fuentes acotadas; scoring y snapshots; consultas/métricas; UI y validación. Se dividieron responsabilidades entre agentes de enriquecimiento, puntuación y consultas, con integración central de contacto/UI/documentación.

## 2. Componentes implementados

`LeadEnricher` integra `HtmlCommercialExtractor`, `WebsiteDiscovery`, `RobotsPolicy`, `EnrichmentRequestBudget`, `SecondaryIdentityResolver`, `EvidenceReconciler` y `OsmEvidenceSource`. Se reutiliza `PublicWebsiteFetcher` con DNS público fijado y límites de descarga. `EnrichLead` conserva el trabajo en cola y añade exclusión de ejecución por prospecto y actualización forzada explícita.

`LeadScorer` evalúa perfiles por oferta, `OfferMatcher` selecciona candidatos compatibles, `LeadOfferScore` determina vigencia y `ScoreSnapshotService` captura conocimiento al contactar. `LeadFilterService`, `CampaignMembership`, `MetricsService`, `ExportService` y `sales:inspect` comparten el contexto de oferta. `ContactWorkflow` y `EmailContactService` capturan antes del resultado comercial. La consolidación conserva snapshots y archiva puntuaciones/estados del origen.

## 3. Fuentes y límites de acceso

- Google Maps conserva la adquisición y procedencia ya existentes; no se sustituyó el scraper ni se ejecutaron búsquedas reales.
- Sitio registrado: hasta cinco páginas pertinentes, DOM/JSON-LD, contactos, servicios, productos, formularios, reservas y firmas de plataformas. Solo enlaces internos explícitos sin parámetros; no hay navegación autenticada ni ejecución JavaScript.
- DNS/HTTP: validación pública de destino y estado técnico limitado. No prueban entrega de email, actividad comercial ni necesidad de una web. Observar HTTP no demuestra que falte HTTPS.
- CRM: contexto de historial propio, identificado como no predictor. Los resultados comerciales no aportan puntos al score que después se evalúa.
- OpenStreetMap: integración Overpass opcional, desactivada por defecto. Exige configuración explícita, contacto del operador, condiciones revisadas y coordenadas. Radio inicial 100 m, máximo 150, hasta 20 candidatos y frecuencia mínima de 60 s por host. No usa Nominatim ni búsquedas masivas por nombre.

Se aplica robots para cada página, con caché, prioridad de Allow/Disallow y espera declarada. Si no puede verificarse, se omite el rastreo sin impedir las otras fuentes. No se añadieron bases externas de firmas: el catálogo pequeño está en configuración. Referencias primarias: [RFC 9309](https://www.rfc-editor.org/rfc/rfc9309), [recursos Overpass](https://dev.overpass-api.de/overpass-doc/en/preface/commons.html), [OpenStreetMap/ODbL](https://www.openstreetmap.org/copyright). Las condiciones de un endpoint deben revisarse antes de habilitarlo; esta entrega no certifica autorización contractual de cada sitio.

## 4. Conciliación de identidad

Se comparan nombre, dominio, teléfono y ciudad/dirección normalizados. Coincidir solo en dominio o teléfono no fusiona entidades. Un sitio compartido por sucursales requiere datos que distingan la entidad concreta. Conflictos fuertes conservan observaciones y solicitan revisión; las tecnologías de dominio pueden mantenerse como observaciones técnicas, sin trasladar servicios/contactos ajenos al prospecto.

No se sustituyen automáticamente identidad ni contactos principales. El email publicado necesita revisión vigente y una acción explícita para adoptarse. El importador conserva coordenadas públicas validadas en metadata de procedencia. Las exclusiones, bloqueos y habilitación de clientes siguen independientes del score.

## 5. Modelo de evidencia y actualización

Se amplía `signals` con referencia, método, confianza, caducidad, versión, metadata, clave lógica e `is_current`. Se distinguen hechos `observed`, inferencias `inferred`, desconocidos `unknown` y conflictos `conflict`. Los hechos nuevos pueden quedar confirmados; las inferencias requieren revisión humana. No se guarda HTML completo.

La clave fuente/referencia/tipo evita copias por reprocesamiento. Una observación cambiada vuelve a pendiente y conserva hasta cinco estados previos; las señales desaparecidas se archivan. Se preservan notas/revisiones manuales. Las filas automáticas heredadas se retiran del conjunto vigente al migrar, sin borrarlas. Estados de fuente y evidencia se actualizan en transacción. TTL inicial de 24 h, evidencia de 30 días; una entrada cambiada invalida la caché. La revisión forzada no evita seguridad, robots ni frecuencia. Un error técnico no es un defecto comercial.

## 6. Algoritmo, versión y snapshots

Cada prospecto/oferta obtiene adecuación, necesidad, contactabilidad y evidencia en escala 0–100. Las señales se agrupan por concepto; los aportes de adecuación/necesidad usan el máximo y no suman duplicados. Sitio, DNS y HTTP del mismo dominio forman un origen. Solo evidencia confirmada, vigente y compatible con identidad aporta necesidad; desconocido no equivale a ausencia.

`commercial-v1` más huella de configuración identifica el algoritmo. La explicación incluye reglas, referencias, incertidumbre, contradicciones, confianza y cobertura. La vigencia comprueba inputs, oferta, reglas y observaciones, incluso cambios en el mismo segundo. Las lecturas muestran sin evaluar cuando falta un cálculo vigente, sin generarlo implícitamente.

El score global permanece para compatibilidad, sin el premio por web desconocida. Las ofertas activas y las elegidas explícitamente se evalúan sin sustituir la elección de campaña. Un score alto nunca habilita contactos bloqueados.

Al realizar contacto manual o reservar un envío real se captura un JSON inmutable con contexto, total, dimensiones, versión, fechas y evidencia utilizada. Los estados internos de validación no se copian al snapshot. Un envío fallido conserva la auditoría pero no entra como contacto real. Simulaciones/planificados no crean muestra. Los contactos antiguos siguen desconocidos y no se rellenan retrospectivamente con datos actuales. Los contactos retroactivos solo pueden usar un cálculo conservado que ya existía antes de su fecha.

## 7. Pesos iniciales y ejemplo sintético

Total inicial: 35 % adecuación + 35 % necesidad + 15 % contactabilidad + 15 % evidencia. La configuración contempla booking, web, ecommerce y generic. Una solución de reserva o compra observada limita inicialmente la necesidad a 35 cuando aplica. La confianza y cobertura son índices de evidencia, no probabilidades de venta. Umbrales de recomendación iniciales: 0,35 y 0,25; la UI muestra advertencia por debajo de 0,50.

Estos pesos son hipótesis iniciales. No fueron ajustados para producir una distribución atractiva ni se afirmó mejora comercial. El ejemplo siguiente usa datos totalmente sintéticos y tres evaluaciones del mismo negocio:

Negocio: «Centro sintético», consultorio de salud en Argentina, email y teléfono utilizables, sitio registrado. Evidencia del mismo dominio: actividad con reservas y web observadas; dependencia manual inferida **confirmada por revisión humana**. No hay evidencia de venta de productos. Los nombres, contactos y fuentes son ficticios. Se fijaron todos los campos para que el ejemplo sea reproducible en `OfferScoringTest`.

| Oferta | Adecuación | Necesidad | Contactabilidad | Evidencia | Total |
| --- | ---: | ---: | ---: | ---: | ---: |
| Gestión de turnos | 80 | 100 | 100 | 58 | 87 |
| Desarrollo web | 70 | 0 | 100 | 55 | 48 |
| Ecommerce | 0 | 0 | 100 | 44 | 22 |

Confianza 0,775 para las tres evaluaciones; cobertura 0,75 / 0,7143 / 0,5714. Web obtiene compatibilidad por las palabras clave de su oferta, sin necesidad confirmada. Ecommerce conserva contactabilidad pero no inventa adecuación ni necesidad. El valor 100 de necesidad de turnos corresponde a la regla inicial revisada, no a una probabilidad o urgencia demostrada. Si se detecta reserva online, la regla de solución existente limita su necesidad.

## 8. UI, filtros, exportación, CLI y métricas

La ficha muestra puntuaciones por oferta, mejor puntuación vigente, dimensiones, reglas relevantes, datos desconocidos/conflictivos, procedencia y fechas, con detalles desplegables. El historial muestra la puntuación conocida al contactar o su estado desconocido. Listados y cola identifican score por oferta y fallback global únicamente cuando no hay oferta. Los mínimos/máximos excluyen los desconocidos. La oferta explícita de campaña determina el contexto.

Export incluye dataset `offer_scores` y campos de contexto/versión en el analítico. CLI añade `scores` y `evidence`, `score_offer_id` y `--rule-version`. Son lecturas sin solicitudes externas ni recálculos. Ejemplos completos y códigos de salida en [docs/SCORING.md](SCORING.md).

Las métricas históricas agrupan por prospecto/campaña/oferta/versión y fijan la puntuación del primer contacto real de cada cohorte. Seguimientos y canales repetidos no duplican denominadores. Solo eventos posteriores y atribuibles aportan respuesta, interés, propuesta o cierre. Los eventos ambiguos entre ofertas no se asignan arbitrariamente. Se muestran muestra conocida/desconocida, rangos, tasas y comparaciones por versión/campaña/oferta. La exploración del score actual permanece separada. Las asociaciones observadas no demuestran causalidad ni permiten validar pesos con una muestra pequeña. Un mismo prospecto puede aparecer en versiones diferentes: esas cohortes no son muestras independientes y no deben compararse como un experimento aleatorizado. El origen CRM y la marca `not_a_score_predictor` se excluyen incluso si una regla personalizada intenta utilizarlos.

## 9. Migraciones y estado operativo

Dos migraciones aditivas nuevas:

1. `2026_10_14_000001_enrichment_evidence`: columnas e índices de señales, retiro de automáticas heredadas y tablas de estados de fuente/reserva.
2. `2026_10_14_000002_offer_scoring_snapshots`: `lead_offer_scores` y snapshot nullable en contactos, sin backfill.

Se probaron actualización desde esquema anterior con datos, conservación de señales manuales/contactos, ausencia de snapshots inventados, rollback de ambas, reaplicación y claves foráneas sobre un archivo SQLite temporal.

En el cierre inicial no se aplicaron a la base operativa por la restricción del adjunto. La autorización posterior del usuario permitió completar la activación descrita al inicio de este informe, con protección estricta de los registros importados desde Notion.

Respaldo previo verificado: `ventas-20261015-155327-dfba253f-3abc-45b4-8ba7-6297e5ccbb76.sqlite`. Respaldo final verificado: `ventas-20261015-160028-a4b574c0-50bf-4c92-852b-461dff95f58b.sqlite`. La bitácora privada conserva huellas, resultados del ensayo, aplicación, piloto y comparación de Notion. No hay archivos rastreados eliminados y no se crearon commits.

## 10. Pruebas y revisión

Validación de la entrega inicial: **344 pruebas / 1624 aserciones**, sin fallos. Tras la activación y la corrección de transporte: **345 pruebas / 1629 aserciones / 13810 ms**, sin fallos. Frente a la línea base de 271 pruebas se incorporaron 73 casos, además de actualizar fixtures y expectativas afectadas por el nuevo comportamiento conservador. Los cambios de expectativas mantienen controles de seguridad e integridad: firmas reales de reserva, archivo de señales retiradas y filtros globales separados de los filtros por oferta.

Comprobaciones adicionales correctas: `vendor/bin/pint --dirty --format agent`, sintaxis de 159 archivos PHP, compilación y sintaxis de 111 vistas en almacenamiento temporal, `node --check public/sales.js`, `npm run build` y `git diff --check`. Actualización/rollback/reaplicación de migraciones y claves foráneas verificadas en base temporal. Lectura posterior a la activación: migraciones nuevas aplicadas 2, cola 0, integridad `ok` y errores de claves foráneas 0.

Cobertura específica: JSON-LD válido/inválido y tipos inesperados, HTML malformado, contactos/tecnologías, límites de páginas/bytes, robots y rutas codificadas, fallos/timeouts, dominio compartido y sucursales, coincidencia fuerte/ambigua, idempotencia/caducidad/revisión humana, OSM apagado/configurado/sin respuesta, perfiles distintos, duplicados/contradicciones, cambios de versión, restricciones independientes, snapshots antes de SMTP y retroactivos, inmutabilidad incluso con modelo desactualizado, métricas de varias campañas/ofertas/versiones, filtros/export/CLI, consolidación y migraciones.

Toda la suite usa SQLite y almacenamiento/logs/vistas temporales, DNS/HTTP/procesos/mailer simulados. Se bloquean solicitudes HTTP inesperadas; no hubo scraping ni SMTP reales. La skill `testing-best-practices` no estaba disponible; se siguieron las instrucciones del repositorio y las convenciones PHPUnit existentes. No se instalaron dependencias para suplirla. Las vistas se verificaron por respuestas HTTP de integración y compilación, sin afirmar una revisión visual interactiva de esta ampliación.

## 11. Rendimiento y coste

Máximos iniciales: cinco páginas, 120000 bytes/página, 600000 bytes agregados incluyendo robots/fallos/OSM, 25 s de presupuesto de enriquecimiento, ocho segundos por solicitud. Las páginas se procesan secuencialmente y SQLite controla una reserva por host entre jobs. La reserva libera por token y caduca si el trabajo se interrumpe. El job existente conserva timeout de 45 s; el presupuesto limita nuevas solicitudes y sus tiempos de transporte, sin prometer latencia exacta de la resolución DNS del sistema o procesamiento del proceso PHP.

No se descargan recursos ni se ejecuta JavaScript. Las respuestas con tamaño/contenido no permitido se rechazan, sin interpretar HTML parcial. TTL y huella de entrada evitan reanálisis reciente; lectura comercial no tiene coste de red. Hay un registro de puntuación por prospecto/oferta, además de evidencia limitada y snapshots por contacto real. No hay coste de API de IA ni infraestructura nueva.

La regresión de adquisición mantiene siete consultas con 1 y 25 prospectos sintéticos; última medición local de suite: 11,28/5,82 ms. Esto verifica estabilidad del número de consultas, no carga de producción. Los filtros de vigencia incluyen comprobaciones SQL de JSON: en carteras grandes debe medirse el coste con volumen real antes de modificar índices o simplificar la validez. No se realizó una prueba de carga operativa.

## 12. Limitaciones y decisiones conservadoras

OSM permanece desactivado y ningún endpoint se validó con tráfico real. Las políticas de las fuentes, robots, bloqueos, HTTPS obligatorio, páginas dinámicas o falta de datos pueden reducir cobertura. No se ejecutan JavaScript, pruebas funcionales de compra/formularios ni comprobaciones de entregabilidad. Un enlace visible demuestra su publicación, no que complete una operación. Las firmas detectan presencia observable, no ausencia.

La conciliación prefiere revisión ante nombres/ubicaciones contradictorios; puede dejar sin incorporar entidades verdaderas. La necesidad comercial requiere evidencia revisada y no se presume por falta de datos. Los contactos retrospectivos sin cálculo conservado son desconocidos. Las oportunidades con atribución de resultados ambigua quedan sin asignar. No se validó eficacia comercial de reglas/pesos y no hay ajuste autónomo.

La entrega conserva cambios locales anteriores y queda sin commit. La activación operativa y un piloto de cinco sitios de prospectos de Google Maps se completaron después de la entrega de código, con autorización posterior. La eficacia comercial de los pesos sigue sin validar. Los registros de Notion permanecieron intactos. Documentación de uso: [enriquecimiento](ENRICHMENT.md), [scoring/CLI](SCORING.md), README, arquitectura, modelo de datos, estado, backlog y changelog actualizados.
