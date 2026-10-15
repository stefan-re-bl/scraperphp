> Archivo histórico del 2026-10-15. Conserva el contenido de la etapa; sus instrucciones y estados pueden estar superados. Consulta la [documentación vigente](../../README.md) y el [índice del archivo](../README.md). Las rutas y números de línea citados como texto corresponden al código de aquella revisión.

# Changelog

## 2026-10-15 — Activación autorizada de enriquecimiento/scoring

Activación autorizada completada el 2026-10-15: ambas migraciones aplicadas en batch 4, con respaldo previo, ensayo sobre copia real y respaldo posterior. Se eliminó únicamente la campaña de prueba de Rosario y sus relaciones de campaña; se conservaron los 134 registros. Las 29 fichas, 29 contactos, 30 procedencias y tres importaciones de Notion se compararon antes/después y permanecen exactamente iguales, incluidas sus fechas. Se excluyeron del recálculo y de las consultas externas. Se recalcularon 105 prospectos de Google Maps.

Aplicación local en `http://127.0.0.1:8000` y worker `sales,default` reiniciados, sin instalar servicios permanentes. Diez rutas/pantallas/exportaciones verificadas con HTTP 200. Cola vacía, integridad SQLite correcta y cero errores de claves foráneas. Piloto autorizado de cinco sitios: uno verificado y cuatro desconocidos; se mantuvo la identidad principal y OSM desactivado. No hubo envíos comerciales ni nuevas búsquedas de Maps.

Se corrigió una incompatibilidad real de Guzzle 8.2.0: las opciones raw `CURLOPT_PROXY` y `CURLOPT_FOLLOWLOCATION` se sustituyeron por las opciones de alto nivel ya configuradas (`proxy=''`, `allow_redirects=false`); se conserva `CURLOPT_RESOLVE`. Una regresión que inicializa el transporte real sin ejecutar la conexión falló antes y pasa después. Suite final: **345 pruebas y 1629 aserciones correctas**, Pint y sintaxis de archivos afectados correctos.

El catálogo operativo está vacío: no se crearon ofertas ficticias ni puntuaciones por oferta sin catálogo. El siguiente paso comercial es definir/cargar la oferta y revisar una muestra antes de ampliar el enriquecimiento. Los contactos históricos continúan sin snapshots inventados. Informes privados y respaldos en `storage/app/private/backups/enrichment-activation-20261015-*.json` y archivos SQLite asociados, fuera de Git.

## 2026-10-15 — Enriquecimiento y puntuación por oferta

- Extracción DOM/JSON-LD y hasta cinco páginas pertinentes, robots, límites globales, TTL y fuentes opcionales; OSM apagado.
- Evidencia con procedencia, confianza, revisión, caducidad e historial; identidad principal preservada y sucursales ambiguas pendientes.
- Cuatro dimensiones y perfiles versionados por oferta, sin premio por web desconocida ni inflación por duplicados.
- Snapshots inmutables antes del resultado comercial y métricas históricas por cohorte/campaña/oferta/versión.
- Ficha, filtros, cola, dashboard, exports y CLI integrados sin recalcular desde lecturas.
- Suite final: 344 pruebas y 1624 aserciones; Pint, PHP, 111 vistas, JavaScript, build y diff correctos.
- Dos migraciones aditivas verificadas en bases temporales; no se aplicaron a la base operativa. Se detuvieron aplicación/worker de la etapa anterior. Validación final en el informe de entrega.


## 2026-10-14 — cierre de la implementación iniciada el 2026-10-13

- Se integró la auditoría T01–T12, D01–D05 y O01–O05 conservando Laravel/SQLite, servicios, rutas y trabajo local previo.
- Se corrigió identidad contradictoria y se bloquearon duplicados pendientes en cola y acciones directas.
- Se separaron estados ocupado/completado/cancelado/fallido, se protegieron reservas con tokens y se recuperaron checkpoints sin éxito prematuro ni consumo indebido de reintentos; se reutiliza el CSV válido.
- Se protegió el enriquecimiento mediante validación pública A/AAAA, cURL con DNS fijado, HTTP/HTTPS en 80/443, sin redirecciones/proxies y descarga limitada a 120000 bytes por defecto.
- Se reconciliaron señales de forma atómica, conservando evidencia humana vigente y requiriendo revisión cuando cambia.
- Se unificó CSV seguro, se corrigieron filtros por campaña e IDs vacíos/inválidos y se rechazaron respaldos con claves foráneas rotas.
- Se sincronizaron los datos de `Lead` y contacto principal mediante transacciones; eliminar el asociado conserva los campos principales. Se conectaron confirmaciones reales para eliminar contactos y servicios.
- Se representaron oportunidades por pivot y se añadió permiso explícito de contacto para clientes; el historial se filtra por campaña.
- Se conectaron asignación A/B, preparación y variante utilizada con muestras calculadas desde contactos reales; las simulaciones y ediciones manuales no fabrican atribución.
- Se añadieron comparador de duplicados, consolidación revisada con respaldo y preservación de originales, análisis previo CSV y descarga de diagnósticos de rechazos.
- Se amplió el panel diario con acciones, oportunidades y renovaciones; se agregó `sales:inspect` con JSON, filtros compartidos, códigos 0/2/1 y CSV local explícito sin sobrescritura.
- Se añadió medición exploratoria del score/oferta actuales y preparación manual opcional, sin alterar pesos ni integrar IA por API. El Bridge manual utiliza evidencia confirmada y depurada.
- Se cargaron datos por pestaña, relaciones mínimas y ofertas por lote; JSON reutiliza IDs y el analítico conserva una fila por oportunidad con atribución explícita de respuesta.
- Se fijó Docker al digest verificado y se normalizó la referencia heredada exacta a `latest`. Se documentaron aplicación local más worker `sales,default`, recuperación, retención manual y controles previos a exposición por red.
- Se aisló toda la suite con SQLite en memoria, almacenamiento/logs/vistas temporales y servicios/procesos simulados. Validación registrada: 271 pruebas y 1265 aserciones, sintaxis correcta en 127 archivos PHP y comprobación de JavaScript, build, 110 vistas temporales, ayuda CLI y Pint correctos.
- Adquisición pasó de 52/340 consultas de auditoría a 7/7 con 1/25 leads sintéticos; los tiempos locales registrados no prueban rendimiento operativo.
- En el cierre inicial de código las migraciones no se habían aplicado a la cartera real; la activación autorizada posterior se registra arriba. No hubo scraping, envíos, importaciones ni consolidaciones reales durante esta implementación, ni commits automáticos.


## 2026-10-14 — Activación autorizada y revisión visual

- Se aplicaron las tres migraciones nuevas con respaldo previo verificado y sin tareas activas. Se conservaron los datos de las 35 tablas anteriores, 134 registros y 29 contactos.
- Se verificó integridad y claves foráneas y se creó otro respaldo compatible con el esquema actualizado.
- Se iniciaron aplicación y worker locales y se verificó la CLI operativa y Docker disponible.
- Se revisaron panel, ficha, oportunidades, comparador, cola, CSV y métricas en navegador con datos sintéticos. El CSV terminó con dos filas válidas y una rechazada; el contacto de prueba conservó variante y tiempo de preparación.
- Se tradujeron etiquetas técnicas del comparador y se añadió etiqueta accesible al selector de contacto principal.
- Suite posterior: 271 pruebas y 1265 aserciones correctas. El diálogo nativo del navegador integrado no fue controlable de forma fiable; la cancelación sigue cubierta por SalesConfirmationTest.
- Se conservaron los errores históricos de jobs y no se ejecutaron búsquedas ni envíos reales.

## 2026-10-10

- Se amplió `Lead` con identidad, relaciones independientes de cliente/colaborador, prioridad manual y valor estimado exacto.
- Se añadieron contactos asociados y servicios con propuestas vinculadas y renovaciones.
- Se integraron Cartera y Servicios en la navegación existente, con filtros y exportaciones compatibles.
- Se compartió la deduplicación entre scraper e importación de Notion, considerando procedencias y contactos.
- Se protegieron clientes existentes frente a la incorporación automática a nuevas campañas y a la cola.
- Se implementó la importación del ZIP de Notion con vista previa, decisiones reproducibles, revisión de estados, exclusión de credenciales, respaldo e idempotencia.
- Se mantuvieron los registros de origen Notion sin campaña fuera de las métricas de adquisición.
- Se aplicó la ampliación aditiva tras verificar un respaldo de 105 registros y una campaña, conservando los datos anteriores.
- Se generó una vista previa real de 31 registros. Tras autorización se importaron 14 registros y 13 contactos asociados; los otros 17 permanecen pendientes. Se verificaron el respaldo, la preservación de los 105 registros anteriores y la idempotencia mediante una vista previa posterior. Después de confirmar el significado de los estados se incorporaron otros 13 clientes y 13 contactos; se corrigió la comparación de marcadores de teléfono que producía coincidencias falsas.
- Se añadieron pruebas sintéticas del CRM, compatibilidad comercial e importador.
- Se completó la reconciliación autorizada: 30 filas importadas en 29 identidades, una organización unificada con ambos contactos, un registro interno como colaborador y una plantilla vacía descartada.
- Se protegió la proyección del contacto principal al vincular otras personas de la misma organización y se añadió su prueba de regresión.

## 2026-09-24

- Se creó la documentación inicial del proyecto.
- Se inspeccionó el entorno WSL.
- Se evaluaron repositorios reutilizables.
- Se seleccionó `gosom/google-maps-scraper` para adquisición inicial.
- Se descartó integrar CRM externo en el MVP inicial.
- Se descartó validar emails por SMTP en el MVP inicial.
- Se definió arquitectura mínima.
- Se definió modelo de datos inicial.
- Se definieron reglas de contacto.
- Se creó backlog priorizado por impacto comercial.
- Se aprobó la fase de planificación.
- Se definió SQLite como base local del MVP.
- Se corrigió el primer formato de importación a CSV.
- Se amplió P0 para cerrar el circuito comercial medible.
- Se documentaron exportaciones CSV, JSON y snapshot SQLite.
- Se estableció que P0 no incluye envío automático ni LLM Bridge.
- Se creó una aplicación Laravel local con SQLite.
- Se implementó CRUD mínimo de ofertas y campañas.
- Se implementó importación CSV de leads.
- Se implementó deduplicación básica.
- Se implementó scoring determinista básico.
- Se implementó cola manual de contacto.
- Se implementó `ContactAttempt`.
- Se implementó registro y clasificación de respuestas.
- Se implementó pausa de seguimientos pendientes tras respuesta.
- Se implementó historial de pipeline.
- Se implementó registro de propuestas con versiones.
- Se implementaron métricas básicas del embudo.
- Se implementaron exportaciones CSV, JSON y snapshot SQLite.
- Se añadieron tests del circuito P0.
- Se verificó el circuito manual completo.
- Se limpió la base SQLite para iniciar el piloto real.
- Se configuró la oferta `Gestor de consultorio/turnos`.
- Se configuró la campaña `Piloto P0 salud Argentina`.
- Se documentó el protocolo del piloto en `docs/PILOT.md`.
- Se agregó orquestación de `gosom/google-maps-scraper`.
- Se agregaron queries por campaña y runs de scraping.
- Se agregó enriquecimiento determinista con señales.
- Se amplió scoring configurable.
- Se agregó motor simple de asignación de ofertas.
- Se agregaron plantillas de mensajes.
- Se agregó soporte email SMTP con dry-run.
- Se agregaron follow-ups planificados.
- Se agregaron experimentos y variantes.
- Se agregaron métricas segmentadas.
- Se agregó LLM Bridge con validación JSON.
- Se completó UI de exclusiones.
- Se agregó kill switch operativo.
- Se agregó exportación analítica consolidada.
- Se ampliaron tests a 12 casos.
- Se descargó y validó `gosom/google-maps-scraper:latest`.
- Se confirmó versión `v1.18.1-549e4b5`.
- Se corrigió el comando real del scraper.
- Se validó adquisición real con query de psicólogos en Córdoba, Argentina.
- Se confirmó el flujo scraper -> CSV -> importación -> deduplicación -> enriquecimiento -> scoring -> cola.
- Se confirmó que no se crearon contactos durante la validación.
- Se rediseñó la UX alrededor de Campaña y Lead.
- Se redujo la navegación principal a Inicio, Campañas, Leads y Configuración.
- Se agregó workspace de campaña con resumen, leads, adquisición, contacto, pipeline, experimentos y métricas.
- Se agregó capa compartida `LeadFilterService`.
- Se agregó tabla central de leads con filtros persistentes por query string.
- Se agregó selección múltiple con acciones masivas.
- Se agregó exportación contextual de CSV, JSON y `analytics_export.csv`.
- Se agregó ficha completa de lead con diagnóstico, posibles duplicados, timeline y acciones.
- Se rediseñó la cola como pantalla diaria de contacto.
- Se agregó Kanban para oportunidades activas.
- Se agruparon funciones administrativas en Configuración.
- Se agregaron tests para filtros, exportación filtrada, seleccionados, workspace, duplicados posibles, acciones masivas y render de pantallas.
- Se simplificó la creación de campañas.
- Se cambió `fuente` a selector cerrado.
- Se ocultaron canal, modo, CTA y follow-ups como decisiones principales.
- Se fijó `modo` en `manual` mientras no exista mensajería automática real.
- Se cambió la adquisición real para ejecutar el scraper en segundo plano.
- Se agregó el comando `scraper:execute` para completar e importar runs.
- Se recuperó el ScrapeRun #3 que había quedado `running` e importó el CSV generado.
- Se agregó exportación de teléfonos en una línea separada por comas.
- La exportación de teléfonos normaliza números argentinos con prefijo `+54`.
- La exportación de teléfonos reutiliza los filtros actuales de leads.
- Se mejoró el enriquecimiento de leads sin cambiar criterios ni pesos de scoring.
- El enriquecimiento ahora detecta canales visibles: WhatsApp, email en web, formularios, Instagram, Facebook y Doctoralia.
- El enriquecimiento ahora detecta sistemas de turnos conocidos como Calendly, Doctoralia, AgendaPro, TurnosWeb, Bookly y SimplyBook.
- Se agregaron señales de dependencia manual, complejidad operativa, sitio débil y formato de teléfono.
- El scoring ya no borra señales de enriquecimiento al recalcular.
