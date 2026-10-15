> Archivo histórico del 2026-10-15. Conserva el contenido de la etapa; sus instrucciones y estados pueden estar superados. Consulta la [documentación vigente](../../README.md) y el [índice del archivo](../README.md). Las rutas y números de línea citados como texto corresponden al código de aquella revisión.

# Auditoría técnica y funcional — Scraper + CRM de CRT Web

Fecha: 13 de octubre de 2026. Alcance: `/home/stefano/Documents/repositorios/scraperphp`, incluido todo el trabajo local sin confirmar en Git. Base histórica: `4708beb` (`feat: improve sales workflows and redesign the UI`); antes de la auditoría había 46 archivos versionados modificados y 30 archivos nuevos. Las referencias de línea corresponden a ese estado, no exclusivamente al último commit.

La auditoría no modifica la aplicación. Este documento es el único archivo entregado. No se ejecutaron scraping real, contactos comerciales, importaciones sobre la cartera real ni migraciones sobre su base de datos.

## A. Diagnóstico ejecutivo

**El sistema tiene un circuito comercial amplio y utilizable con supervisión, pero todavía presenta defectos que impiden tratar su adquisición y seguimiento como operaciones completamente confiables.** El stack actual es adecuado para una herramienta local de una persona. No hay evidencia que justifique migrar de Laravel/SQLite ni añadir infraestructura.

Las fortalezas principales son la separación entre identidad comercial y pertenencia a campañas, los servicios de negocio reutilizables, las transacciones, el registro de procedencia, los seguimientos manuales, las exclusiones, el scoring explicable y la importación de Notion con revisión, hashes e idempotencia. La suite existente pasó: **175 pruebas y 743 aserciones**.

Los cinco hallazgos principales son:

1. **T01: la deduplicación puede consolidar sucursales distintas** cuando comparten dominio y email, incluso si tienen identificadores de Google Maps diferentes.
2. **T02: una importación ocupada se trata como ejecución satisfactoria.** Esto puede consumir un reintento sin completar el trabajo y marcar el scraping como completado mientras la importación sigue en `processing`.
3. **T03: el enriquecimiento no bloquea todos los destinos internos.** El control omite IPv6 entre corchetes y nombres que resuelven a direcciones privadas.
4. **T04: el CSV analítico exporta fórmulas sin neutralizarlas**, aunque la otra ruta CSV sí tiene protección.
5. **T05: varios filtros de campaña consultan datos globales**, por lo que la segmentación puede mostrar una respuesta, vencimiento o canal de otra campaña y omitir la oferta correcta.

**No se ha demostrado un P0 en el contexto local previsto.** No equivale a certificar seguridad ni ausencia de corrupción en datos reales: la auditoría no inspeccionó los registros privados de la cartera. Conviene corregir los P1 antes de ampliar la adquisición o confiar en exportaciones y métricas para decidir campañas. El uso asistido y la revisión humana deben mantenerse.

### Evidencia y límites de la revisión

- Se inspeccionaron README, instrucciones de agentes, arquitectura, modelo de datos, backlog, estado del proyecto, reglas de contacto, piloto y experimentos; rutas, controladores, servicios, modelos, trabajos, migraciones, vistas, JavaScript, configuración y pruebas.
- Versiones verificadas: PHP 8.5.0, Laravel 13.33.0 y PHPUnit 12.5.35. Se consultaron dependencias instaladas y el código del worker de Laravel para comprobar el comportamiento de reintentos. No se realizó una búsqueda externa de vulnerabilidades de dependencias.
- PHPUnit: **175/175, 743 aserciones, 5,099 segundos**, mediante `vendor/bin/phpunit --do-not-cache-result`. Se forzó SQLite en memoria, mail de prueba, sesiones/caché en memoria y almacenamiento/vistas en un directorio temporal. Las pruebas existentes de restauración utilizaron sus bases temporales. Se bloquearon conexiones y ejecución de procesos externos a nivel de PHP.
- Las reproducciones adicionales usaron datos sintéticos, trabajos simulados y bases temporales. Para las comprobaciones HTTP positivas se utilizó además un transporte de prueba sin red; ningún destino de ejemplo recibió una conexión.
- `node --check public/sales.js` y `git diff --check`: correctos. Las pruebas existentes renderizan pantallas; no se efectuó una validación visual interactiva ni una prueba de navegador del JavaScript.
- No se comprobó Docker en ejecución, entrega SMTP, disponibilidad de sitios reales, tasa de conversión ni comportamiento con miles de registros reales. Los tiempos sintéticos de consultas no son un benchmark de producción.
- Se revisaron agregados del log local, sin copiar datos personales: 38 entradas entre el 8 y el 10 de octubre; aparecieron 6 coincidencias de permisos de Docker y 1 de timeout. Son antecedentes, no evidencia de un incidente activo ni de su causa actual. Los logs incluían también errores SQL/vistas de etapas previas.
- No se pudo verificar el puerto/interfaz de escucha: el entorno bloqueó el acceso al socket de inventario de red. No se afirma que la aplicación esté expuesta ni que esté protegida por un servidor externo.
- La huella agregada de archivos del proyecto, excluyendo este informe, permaneció igual antes y después de las comprobaciones: `b034bd89e889fb976580ac56e2edaf00a8d169f33ef8dd1491af57be4367e42a`.

Clasificaciones utilizadas: **defecto confirmado** = demostrado por código y/o reproducción; **riesgo técnico** = mecanismo potencial con evidencia, sin incidente probado; **oportunidad funcional** = capacidad propuesta; **hipótesis** = beneficio pendiente de medir. P1 alto, P2 medio y P3 bajo. El esfuerzo es relativo e incluye pruebas; no constituye una estimación contractual.

## B. Mapa funcional

| Componente | Responsabilidad y flujo real | Estado y límites |
|---|---|---|
| Aplicación local | Laravel, Blade, `public/sales.js`/`sales.css`, SQLite; navegación de cartera, cola, campañas, oportunidades y servicios. | Implementado. Sin autenticación de usuario en las rutas comerciales. Diseñado para uso local individual. |
| Adquisición de Maps | `AcquisitionController` → `GoogleMapsScraperService` → `ExecuteScrapeRun`, cola `sales` → proceso Docker/binario → CSV. | Implementado. Web inicia búsquedas reales; la simulación permanece en el servicio y pruebas. Argumentos de proceso en array; timeout configurable, progreso, cancelación y reintento. Usa imagen `latest` por defecto. |
| Importación CSV | Vista previa de cinco filas y asignación de columnas → confirmación → `ImportLeadFile` → `LeadImporter`. | Implementado. También existe POST `/imports` que encola directamente. Delimitadores coma/punto y coma, BOM, checkpoint, hash de archivo, transacción por fila y contadores. No hay informe previo completo de calidad ni de duplicados. |
| Identidad y procedencia | `ContactNormalizer` + `LeadIdentityMatcher` → `Lead`, `LeadSource`, `campaign_lead`. | Implementado. Coincidencias fuertes reutilizan registros; posibles coincidencias crean otro lead marcado para revisión. Persisten los problemas T01 y D01. |
| Enriquecimiento y scoring | `EnrichLead` → `LeadEnricher` → señales con evidencia → `LeadScorer`; `OfferMatcher` sugiere oferta. | Implementado, determinista. Se analiza principalmente la página inicial. El scoring es global al lead y usa su oferta sugerida; no es un scoring independiente por cada oportunidad. |
| CRM unificado | `Lead` distingue persona/organización y cliente/colaborador; `CrmController` gestiona contactos y servicios. | Implementado. Relaciones de cliente y colaborador pueden coexistir. Importes de servicios y estimaciones en unidades menores enteras; renovaciones consultables. No hay facturación, cobros ni calendario automatizado. |
| Importación Notion | `crm:import-notion` → ZIP limitado y validado → decisiones → hash de plan/cartera → respaldo → transacción. | Implementado como migración puntual. Ambigüedades exigen decisiones, notas sensibles se omiten, repetición del mismo origen no duplica datos. No existe sincronización continua. |
| Contacto y seguimiento | Cola → plantilla/oferta → mensaje editable → envío humano → `ContactWorkflow` → `FollowUpService`. | Implementado. Abrir WhatsApp no registra contacto ni envía automáticamente. Dos seguimientos, posposición, pausa por respuesta y control de exclusiones. |
| Email SMTP | `EmailContactService`, plantilla compatible, límites diarios, reserva de intento, simulación y estado posterior. | Servicio implementado y probado con mail simulado. Envío real depende de modo automático, campaña sin dry-run y transporte real. No se verificó entrega externa. |
| Pipeline, respuestas y propuestas | `LeadPipeline`, historial por campaña, respuestas que pausan secuencias, propuestas agrupadas y versionadas. | Implementado. La vista global continúa dependiendo del último `Lead.status`; no representa todas las oportunidades simultáneas. |
| Métricas y exportaciones | `MetricsService`, `LeadFilterService`, `ExportService`; CSV, JSON, teléfonos y analítica. | Implementado, con T04–T07. El embudo distingue intentos reales y simulados y deduplica negocios, pero la atribución por canal/variante tiene errores. |
| Experimentos A/B | Experimentos, variantes y asignación por menor uso mediante `ExperimentService`. | Parcial: la asignación no se integra automáticamente al contacto registrado; la muestra mostrada puede permanecer en cero. No hay elección automática de ganador. |
| LLM Bridge | Generación de prompt y carga de JSON validado, almacenado como `AI_SUGGESTION`. | Implementado como intercambio manual. No hay IA por API ni aplicación autónoma al CRM. El prompt contiene señales resumidas; no demuestra una mejora comercial. |
| Operación y recuperación | Jobs persistentes, `SystemLog`, registros de scraping, `sales:backup`, `sales:restore`. | Implementado. Snapshot SQLite verificado y respaldo anterior a restauración. Faltan comprobaciones relacionales y recuperación consistente de estados ocupados. |
| CLI para agentes | `scraper:execute`, `sales:backup`, `sales:restore`, `crm:import-notion` y comandos de Laravel. | Implementado para operaciones concretas. No hay una CLI comercial uniforme para consultar y preparar campañas, cartera y próximas acciones. |

Flujo principal verificado en código:

```text
Campaña / CSV / Maps
  → ejecución e importación registradas
  → normalización y comparación de identidad
  → Lead + procedencias + oportunidad por campaña
  → enriquecimiento y scoring
  → cola manual y mensaje editable
  → contacto registrado → seguimiento o respuesta
  → propuesta / cierre → métricas y exportación

Notion → revisión y decisiones → respaldo → cartera y contactos
Cliente → servicios → consulta de renovaciones
```

Conviene conservar el monolito, SQLite, la cola de base de datos ya existente, las transacciones por operación, los importes exactos nuevos y los servicios compartidos. No hace falta Redis, un buscador externo ni un CRM genérico.

## C. Problemas técnicos

| ID / prioridad / tipo | Problema y evidencia | Consecuencia práctica | Solución recomendada | Impacto esperado / esfuerzo |
|---|---|---|---|---|
| **T01 · P1 · Defecto confirmado** | `LeadIdentityMatcher.php:15–19,40–49` acepta dominio + email como identidad fuerte después de no encontrar el identificador externo. No trata como contradicción que el registro existente y el entrante tengan distintos `place_id`, nombres y ubicaciones. Reproducción: sucursales Centro/Norte, mismo dominio/email, IDs distintos → `strong / corroborated_identity`. `LeadImporter.php:123–140` incorpora la nueva procedencia al lead anterior. | Se pierde la ficha independiente del negocio/sucursal entrante y se mezcla su procedencia con otra identidad. El error puede persistir en siguientes importaciones. | Dar prioridad a contradicciones de identidad; con IDs de lugares distintos o identidad física incompatible, dejar coincidencia posible para revisión. Mantener coincidencia fuerte por origen inequívoco. No separar ni fusionar automáticamente datos históricos. | Alto: evita consolidaciones incorrectas. **Medio**. |
| **T02 · P1 · Defecto confirmado; condición de recuperación simulada** | `LeadImporter.php:70–75,157` renueva una reserva de 15 minutos y devuelve normalmente el run ocupado. `ImportLeadFile.php:27–40` no distingue ese retorno de una finalización. `GoogleMapsScraperService.php:125–129` transforma cualquier resultado distinto de cancelado en completado. Reproducción: importación reclamada hace 10 minutos → `handle()` retorna sin completarla; reutilizar esa importación deja scraper `completed` e importador `processing`. | Un reintento puede consumirse sin trabajo y la UI comunicar un éxito falso. En un timeout duro del importador a 600 s, con último checkpoint cerca de ese momento y `retry_after=1200`, el nuevo intento puede llegar antes de vencer su reserva. | Distinguir ocupado, completado, cancelado y fallido. Reprogramar la tarea ocupada de forma compatible con la reserva y los intentos; completar el scraper solo cuando la importación realmente terminó. Permitir recuperación verificable de reservas caducadas. Conservar checkpoints y propiedad del token. | Alto: recuperación real sin perder filas ni repetir scraping. **Medio**. |
| **T03 · P1 · Riesgo técnico; omisión del control confirmada** | `LeadEnricher.php:54–59` compara `localhost` y filtra solo hosts que PHP reconoce directamente como IP. `parse_url('http://[::1]/', PHP_URL_HOST)` produce `[::1]`, que no pasa ese reconocimiento; una prueba con transporte HTTP simulado registró la solicitud. Tampoco se resuelven/verifican direcciones A/AAAA antes de conectar. | Una URL importada o creada puede dirigir el enriquecimiento hacia servicios locales/privados. No se realizó una conexión interna ni se demostró extracción de datos. | Validar host normalizado, IPv4/IPv6 y resoluciones DNS; rechazar destinos no públicos y vincular la conexión a la IP aprobada para evitar cambios de resolución. Mantener redirecciones desactivadas. Añadir límite real de bytes antes de cargar el cuerpo: el corte de 120.000 caracteres ocurre después de descargarlo (`:58–59,95–96`). | Alto: limita acceso a red interna y recursos consumidos por fuentes no confiables. **Medio**. |
| **T04 · P1 · Defecto confirmado** | `ExportService.php:60` neutraliza prefijos de fórmula en CSV normal; `:125–146` escribe los campos analíticos directamente. Reproducción: nombre sintético `=1+1` aparece literalmente como celda del CSV analítico. | Datos externos pueden interpretarse como fórmulas al abrir el archivo en una hoja de cálculo. La ejecución depende del lector y no se probó con una aplicación de escritorio. | Compartir una función de serialización segura entre ambos CSV, conservando tipos de importes, teléfonos y fechas. Probar prefijos peligrosos, tabulaciones, retornos, comillas y saltos de línea. | Alto con poco cambio. **Bajo**. |
| **T05 · P1 · Defecto confirmado** | `LeadFilterService.php:110–111,133–142,165–166` usa `Lead.next_action_at`, `suggested_offer_id` y relaciones sin acotar a campaña para vencimiento/oferta/canal/respuesta. Reproducción en B: respuesta, email y vencimiento solo de A hacen coincidir al lead en B; oferta B en el pivot no encuentra al lead cuya oferta global es A. | Se segmentan y exportan grupos incorrectos; una acción masiva puede partir de una selección comercial equivocada aunque sus IDs y transacción sean válidos. | Aplicar contexto de campaña a todos los filtros dependientes de oportunidad; usar pivot para oferta/próxima acción y relaciones acotadas para respuesta/canal. Conservar semántica global sin campaña. | Alto: segmentación coherente en listado, acciones y exportación. **Medio**. |
| **T06 · P2 · Defecto confirmado** | `LeadFilterService.php:68–70,236–244`: si se envían IDs vacíos/no válidos, la lista se reduce a `[]` y se omite el filtro. Reproducción: `ids=0` devuelve los 7 leads sintéticos. Las acciones masivas sí rechazan selección vacía; el problema está en el acceso directo a filtros/exportaciones. | Un enlace o agente con selección incorrecta puede exportar toda la cartera al esperar cero resultados. | Validar IDs positivos y distinguir filtro ausente de selección presente pero vacía. Rechazar entradas inválidas o devolver cero resultados, sin ampliar su alcance. | Medio: previene exportaciones accidentales. **Bajo**. |
| **T07 · P2 · Defecto confirmado** | `MetricsService.php:33,38–45,87–89`: segmentar por canal/variante usa `_group`, pero el filtro de respuestas por `contact_attempt_id` solo se activa para claves directas. Reproducción: un contacto por email y otro por WhatsApp en la misma campaña; única respuesta ligada al segundo → ambos canales muestran una respuesta y 100 %. | La comparación entre canales/variantes puede orientar mal el esfuerzo comercial. Es distinto del filtrado de cartera T05. | Unificar la representación del grupo y filtrar respuestas según los intentos de ese grupo. Definir explícitamente atribución de propuesta/cierre cuando hubo varios canales; no afirmar causalidad a partir de pertenencia a la misma campaña. | Medio/alto: decisiones con métricas interpretables. **Medio**. |
| **T08 · P2 · Defecto confirmado** | `LeadEnricher.php:36,44–45,72–79,189–207` elimina señales `website/system`, pero no las generadas como `import`. Reproducción con respuesta HTTP simulada correcta: añadir web/email/teléfono y volver a enriquecer mantiene `no_website` y `hard_to_find_contact`. `:88,218–228` también borra/recrea señales web y su revisión. | Fichas y mensajes pueden mostrar evidencia obsoleta; una nueva lectura puede perder una revisión humana. No todas las señales obsoletas afectan el score actual, que calcula algunos componentes desde los campos. | Reconciliar el conjunto completo de señales derivadas, retirando las que ya no aplican; preservar revisión humana cuando la evidencia no cambió y marcar revisión pendiente cuando cambió. Actualizarlo de forma atómica. | Medio: diagnóstico confiable y menos revisión repetida. **Medio**. |
| **T09 · P2 · Defecto confirmado** | `CrmController.php:57–68` proyecta el contacto principal a `Lead`; `LeadController.php:109–117` edita `Lead` sin actualizar ese contacto. Reproducción de PUT válido: ficha `new@example.test`, contacto principal `old@example.test`. | Pantalla, exportación de contactos y acciones pueden presentar direcciones diferentes. Volver a guardar el contacto principal puede reintroducir el dato anterior. | Definir una única ruta de edición de los datos del contacto principal: sincronizar en una transacción o impedir que la ficha edite esos campos mientras haya principal. Mantener el comportamiento documentado al eliminar un contacto. | Medio: evita datos contradictorios. **Medio**. |
| **T10 · P2 · Defecto confirmado** | `BackupService.php:33–50` comprueba estructura, migraciones e `integrity_check`, pero no referencias. Un snapshot sintético con contacto huérfano obtuvo `integrity=ok`; `PRAGMA foreign_key_check` detectó 1 error. | Se puede aceptar para restauración un archivo estructuralmente válido con relaciones rotas. No se constató este problema en respaldos reales. | Añadir verificación de claves foráneas y rechazar antes de sustituir la base. Mantener hash, comprobación de versión y snapshot previo. | Alto en recuperación, acotado a archivos inválidos. **Bajo**. |
| **T11 · P2 · Trabajo redundante confirmado; impacto a escala por medir** | `CampaignController.php:41,54–57` calcula estadísticas/segmentación para todas las pestañas. `MetricsService.php:19–25,74–95` carga relaciones y repite el embudo por grupo. GET de adquisición con 1/25 leads en 1/25 ciudades: **52/340 consultas**, HTTP 200 en ambos; aproximadamente 50/111 ms en SQLite en memoria. `ExportService.php:72–76,185–212` materializa JSON y vuelve a obtener IDs por dataset. | Tiempo y memoria aumentan con grupos/historial aunque la pantalla no muestre métricas. El scroll incremental vuelve a pedir la página completa. No se demuestra un problema actual de capacidad en la cartera real. | Calcular datos solo para la pestaña solicitada; separar filtros de cargas de relaciones; reutilizar IDs y usar agregados SQL donde aporte valor. Medir memoria antes de cambiar JSON. Conservar CSV por lotes; no añadir caché/infraestructura como primer paso. | Medio; reduce trabajo observable. **Medio**. |
| **T12 · P2 · Riesgo técnico** | `phpunit.xml:24–35` aísla DB/caché/sesiones, pero no `storage_path`. `ScraperExecutionTest.php:17–45` llama rutas que crean archivos de consultas; `GoogleMapsScraperService.php:31–36` usa el almacenamiento de la app. Otros tests suben CSV y crean respaldos temporales. | Una ejecución estándar de pruebas puede dejar archivos sintéticos mezclados con los de operación. En esta auditoría se evitó con `LARAVEL_STORAGE_PATH` temporal. | Aislar filesystem, vistas y logs desde la configuración de pruebas y limpiar recursos en teardown. Centralizar bloqueo de solicitudes externas, conservando fakes positivos para HTTP. | Medio: pruebas seguras y repetibles para agentes. **Bajo**. |

### Seguridad y operación: alcance del riesgo

No se observó construcción de comandos con texto de usuario sin escapar: el scraper usa argumentos separados y el adaptador `sg` escapa cada argumento. Consultas de filtros usan parámetros y el orden está acotado por una lista. Blade escapa los campos de texto en las vistas inspeccionadas. Esto no constituye una prueba exhaustiva de seguridad.

Las rutas comerciales están bajo middleware web, pero **sin autenticación** (`routes/web.php:15–104`, `bootstrap/app.php:17–18`). Para la herramienta local individual esto es un límite de diseño, no una exigencia de incorporar roles. Si se comparte mediante red o túnel, la lectura de cartera, exportaciones y acciones quedaría accesible sin identidad de usuario. Antes de ese cambio de uso corresponde proteger el acceso; no se ha pedido ni realizado un despliegue.

Los archivos de base, ZIP y `.env` no aparecieron entre los archivos versionados revisados; existen exclusiones para almacenamiento privado y SQLite. No se publicó ni leyó el contenido de credenciales. Los CSV importados, backups y `SystemLog` contienen o pueden contener datos personales (`LeadImporter.php:146` guarda la fila inválida completa). Conviene minimizar ese contexto a fila/campo/motivo y establecer retención manual revisable. No hay urgencia demostrada de almacenamiento: los directorios inspeccionados tenían aproximadamente 0,68 MB de scraping, 2,61 MB de backups y 0,016 MB de CSV importados. No se recomienda borrar material durante esta auditoría.

Docker utiliza `latest` (`config/services.php:42`). Fijar una versión/digest validado mejoraría reproducibilidad, pero primero hay que verificar la imagen realmente utilizada; no se ejecutó ni actualizó Docker. La cancelación del proceso local está implementada, pero no se verificó la terminación efectiva de un contenedor en curso.

## D. Problemas funcionales y de UX

| ID / prioridad / tipo | Fricción y evidencia | Consecuencia | Recomendación | Impacto / esfuerzo |
|---|---|---|---|---|
| **D01 · P1 · Defecto confirmado** | Un posible duplicado puede ser enviado automáticamente a cola por score (`LeadImporter.php:126–135`); `CampaignMembership.php:77–86` no filtra `duplicate_status`. Reproducción: lead `possible_duplicate`, score 50, `QUEUED`, visible en `ready()`. La ficha pide revisar antes de contactar (`lead-detail.blade.php:7`). | La recomendación de revisión no protege el flujo real; aumenta el riesgo de contactar dos veces a la misma organización. | Retener candidatos ambiguos fuera de cola hasta una resolución humana y aplicar el mismo control en acciones de contacto. La consolidación visual completa es O01, un alcance posterior. | Alto / **bajo–medio**. |
| **D02 · P1 · Defecto confirmado por inspección del flujo** | Los formularios de eliminar contacto/servicio declaran `data-confirm` (`partials/crm-detail.blade.php:17,29`), pero el único `confirm()` de `public/sales.js:148` es para operaciones masivas; el listener de submit `:349–357` no atiende ese atributo. Los métodos `CrmController.php:73–78,116–121` borran directamente. | Un clic en Eliminar ejecuta un borrado permanente sin la confirmación que el marcado sugiere. No se realizó un borrado en la cartera real ni una prueba de navegador. | Añadir confirmación efectiva para esos formularios, con cancelación que no envíe el request. Eliminar un servicio debe diferenciarse claramente de cambiarlo a cancelado. No hace falta introducir soft delete global. | Alto con poco cambio / **bajo**. |
| **D03 · P2 · Defecto de representación confirmado; política de clientes pendiente** | Cada movimiento escribe `Lead.status` además del pivot (`LeadPipeline.php:34–52`); el pipeline global agrupa por ese único campo (`ContactController.php:230–241`). `ready()` excluye todos los clientes. Reproducción: ganar en A y poner B en cola conserva B=`QUEUED`, pero B no aparece en la cola por `is_customer=true`. | Un negocio con varias oportunidades solo ocupa una columna global y una oportunidad adicional de un cliente puede quedar fuera del trabajo diario. | Representar oportunidades por campaña en la vista global. Conservar protección contra captación accidental de clientes; decidir si una vinculación explícita habilita seguimiento de otra oferta o si la UI debe rechazarlo. No activar venta adicional automáticamente. | Medio / **medio**. |
| **D04 · P1 · Defecto de documentación operativa confirmado** | README `:5–15` inicia solo `artisan serve`; `.env.example:38` usa cola persistente. `AppServiceProvider.php:27` registra el worker para `artisan dev`, no para `serve`. | Una persona que sigue el arranque documentado puede dejar búsquedas, importaciones y enriquecimientos en cola sin procesamiento. No se afirma que falte un worker en la sesión real. | Documentar un arranque local completo, por ejemplo aplicación y worker `sales,default`, y cómo reconocer trabajo detenido. Un aviso de worker inactivo puede añadirse después con evidencia de heartbeat. | Alto / **bajo**. |
| **D05 · P2 · Función parcial confirmada** | `ExperimentService.php:20–39` guarda la asignación; la cola/formulario no la usa (`queue.blade.php:19–21`, `ContactWorkflow.php:40–49`). La muestra suma `sent_count` (`campaign-workspace.blade.php:164`), sin actualización encontrada. Reproducción: variante asignada, contacto real sintético registrado → `experiment_variant_id=null`, `variant=null`, `sent_count=0`. | Se puede creer que se está midiendo un experimento cuando el contacto no lleva su atribución. | Mostrar la función como parcial hasta conectar asignación, plantilla y snapshot del intento. Derivar la muestra de contactos reales; no mantener contadores redundantes sin actualización. Completarlo solo si se utiliza un piloto A/B. | Medio / **medio**. |

Los defectos T05, T08 y T09 también producen fricción visible: filtros que contradicen la ficha, señales antiguas y contactos principales divergentes. Se describen una sola vez para no duplicar prioridades.

La búsqueda global, vistas guardadas, paginación/scroll progresivo, mensajes editables, historial y transacciones de operaciones masivas ya existen. No corresponde proponerlos como funciones nuevas. La selección masiva tiene límite de 2.000 IDs y verifica que pertenezcan a la vista; conviene conservar ambas protecciones.

## E. Oportunidades de implementación

| ID / prioridad / tipo | Oportunidad, evidencia y justificación comercial | Alcance mínimo recomendado | Impacto esperado / esfuerzo / cómo validarlo |
|---|---|---|---|
| **O01 · P2 · Oportunidad funcional** | La revisión de duplicados solo ofrece “son distintos” (`LeadController.php:280–284`, `lead-detail.blade.php:7`). No hay una acción segura para resolver “son el mismo”. | Comparador de dos fichas con elección de principal, vista previa de conflictos y consolidación transaccional de contactos, procedencias e historial. Resolver explícitamente colisiones de campaña y versiones de propuesta. Requiere respaldo. | Menos registros repetidos y revisión manual fuera de la app. **Alto**, por integridad de relaciones. Validar preservación de referencias, cantidades y repetición idempotente. Depende de T01/D01. |
| **O02 · P2 · Oportunidad funcional** | Ya existen `next_action_at`, posposición, pendientes, `renews_on` y Servicios. El Inicio se concentra en captación (`SalesController.php:27–55`); la cola solo acepta `QUEUED/CONTACTED` (`CampaignMembership.php:63–87`). | Añadir al resumen diario próximas acciones de oportunidades interesadas/propuestas y renovaciones vencidas, reutilizando fechas existentes. Una fecha de respuesta “más adelante” debe poder revisarse sin reiniciar una secuencia de contacto inicial. | Menos oportunidades y renovaciones olvidadas. **Medio**. Medir pendientes sin próxima fecha y tiempo para localizar el siguiente trabajo. Depende de corregir T05 y aclarar D03; no necesita calendario externo. |
| **O03 · P2 · Oportunidad funcional** | CSV muestra cinco filas (`LeadImporter.php:24–33`), pero errores/duplicados se descubren al aplicar; se conserva solo un contador visible y errores en logs. | Resumen previo de columnas requeridas, filas inválidas y candidatos; al terminar, resultado descargable con número de fila y motivo depurado. Mantener confirmación y reanudación existentes; no replicar todo el importador de Notion. | Menos tiempo para corregir y repetir archivos. **Medio**. Medir filas corregidas y reintentos, probar mezcla de filas válidas/inválidas sin divulgar notas o credenciales. |
| **O04 · P2 · Oportunidad funcional** | La CLI cubre scraping, backup/restauración e importación Notion, pero el trabajo comercial se opera principalmente por controladores (`routes/console.php`, `app/Console/Commands`, `routes/web.php`). | Primero comandos de solo lectura para listar cartera, runs y próximas acciones, con IDs, filtros, JSON y códigos de salida. Después operaciones de preparación usando servicios existentes, sin automatizar envíos comerciales. | Reduce navegación y facilita tareas de agentes auditables. **Medio**. Comparar resultados con los mismos filtros web y demostrar ausencia de escrituras en comandos de consulta. Depende de T05/T06. |
| **O05 · P3 · Hipótesis** | Scoring determinista, señales revisables y LLM Bridge ya existen (`LeadScorer.php:11–70`, `LlmBridgeService.php:15–29`). Falta demostrar que el orden actual selecciona mejor a quién contactar. | Usar un piloto pequeño: registrar tiempo de preparación y resultados por rangos de score/oferta; ajustar pocos pesos a la vez. Si el Bridge se evalúa, incluir evidencia confirmada y depurada en lugar de solo nombres de señales. | Beneficio comercial **por medir**, no garantizado. **Bajo** para medir; cualquier integración IA queda fuera. Conservar IA opcional solo si ahorra tiempo verificable sin aumentar correcciones o afirmaciones no sustentadas. |

No se recomienda incorporar nuevos canales, pagos, CRM externo, IA autónoma ni notificaciones complejas antes de cerrar los defectos de integridad y terminar un piloto observable. Tampoco es necesario drag-and-drop para mejorar el seguimiento.

## F. Simplificaciones recomendadas

| Propuesta | Evidencia / alcance | Beneficio / esfuerzo / prioridad / clasificación |
|---|---|---|
| Separar filtrado de relaciones cargadas y cargar solo la pestaña visible | T11; `LeadFilterService.php:56–66` mezcla selección con ocho relaciones. Listado, teléfonos y métricas no requieren el mismo grafo. Reutilizar el servicio, sin crear repositorios genéricos. | Menos consultas y menor acoplamiento entre vistas/exportaciones. **Medio · P2 · oportunidad de simplificación**, vinculada a T11. |
| Establecer una fuente de verdad para contacto principal y estado de oportunidad | T09/D03. Mantener campos compatibles en `Lead`, pero definir y probar cómo se proyectan desde contacto/pivot; no crear un segundo CRM. | Menos contradicciones y cambios más seguros para agentes. **Medio · P2 · oportunidad de simplificación**, resuelve esos hallazgos. |
| Reducir contadores y cálculos paralelos de experimentos | D05: usar intentos reales como base de muestra, no `sent_count` desconectado. No añadir analítica nueva antes de definir atribución. | Menos estados que mantener y números verificables. **Medio · P2 · oportunidad de simplificación**. |
| Mantener un resumen operativo actual y apartar instrucciones históricas | `docs/PROJECT_STATE.md:35–40` conserva WSL/ruta anterior dentro de su bloque histórico; `docs/BACKLOG.md:68` conserva exportación a Notion aunque ahora el CRM es propio. El documento ya avisa que hay historia: no se considera que toda esa historia sea un defecto. Actualizar principalmente arranque/worker y enlaces de recuperación. | Menos ambigüedad para operadores/agentes. **Bajo · P3 · oportunidad documental**, salvo D04 que es P1. |

`NotionImporter` tiene 463 líneas, pero atiende una migración concreta con reglas y pruebas. Su tamaño, por sí solo, no justifica dividirlo. Su comparación interna por pares y fingerprint completo pueden costar con exports grandes; para una importación puntual limitada no se recomienda optimizar sin necesidad medida.

## G. Plan de acción

### 1. Correcciones críticas y contención

No se confirmó un P0. El primer bloque son **tres correcciones P1 independientes**, desarrolladas en H: identidad/retención de ambiguos, recuperación de importación y seguridad del enriquecimiento. Mientras estén pendientes, revisar los candidatos antes de contactar y no tomar un estado de scraping como prueba suficiente de importación terminada.

No se debe reparar automáticamente ninguna consolidación histórica sospechosa: eso necesita vista previa, evidencia por registro y autorización para alterar los datos.

### 2. Quick wins de alto impacto y bajo esfuerzo

Cambios separados, sin dependencia entre ellos:

- T04: reutilizar escape seguro en el CSV analítico.
- D02: conectar la confirmación real de eliminación.
- D04: documentar arranque con worker; incluir esta parte documental en H2 por su relación con ejecución de tareas.
- T06: impedir que una selección vacía o inválida amplíe la exportación.
- T10: rechazar respaldos con referencias rotas.
- T12: aislar almacenamiento de pruebas como quedó aislado durante esta auditoría.

La finalización de una tarea no debe esperar a las otras para su revisión. Los cambios de código requieren sus pruebas de regresión; la corrección puramente documental de arranque requiere verificar instrucciones, no crear una suite nueva.

### 3. Mejoras funcionales prioritarias

- **T05 primero**: hacer coherentes los filtros de oportunidad. Es requisito para confiar en operaciones masivas y para O04.
- **T07 por separado**: corregir agrupación/atribución de métricas; no usar comparaciones A/B para decidir antes de esto y D05.
- **T08 y T09**: corregir calidad de evidencia y fuente del contacto principal, cada uno con su regresión.
- **D03 → O02**: decidir política explícita de oportunidades adicionales de clientes y mostrar próximas acciones sin reabrir contacto inicial por defecto.
- **T01/D01 → O01**: una vez segura la detección, añadir consolidación manual con revisión de conflictos.
- **T11**: eliminar cálculo innecesario por pestaña y repetir la medición sintética; ampliar a carga mayor solo si persiste un problema.

### 4. Evoluciones opcionales

O03 y O04 se justifican si importaciones o tareas repetidas consumen tiempo apreciable. Completar D05 solo si se usa realmente un experimento. O05 requiere medición antes de cambiar pesos o integrar IA. Mantener límites de alcance local y operativo.

La retención de archivos y el acceso por red se revisan cuando cambie el volumen o el modo de uso. No crear infraestructura anticipada ni ejecutar limpiezas automáticas con este informe.

## H. Próximas tres tareas para Codex

### 1. Impedir consolidaciones ambiguas y contacto de duplicados pendientes

**Objetivo:** que la adquisición conserve identidades diferentes y no habilite contacto mientras una coincidencia esté sin resolver. Resuelve T01 y D01 como una misma frontera de entrada a la cartera contactable.

**Componentes:** `app/Services/LeadIdentityMatcher.php`, `LeadImporter.php`, `CampaignMembership.php`, el control compartido de contacto en `ExclusionService.php`/`LeadPipeline.php` según el diseño final, y pruebas de importación, CRM y flujo general. Revisar el mensaje de ficha si necesita explicar el bloqueo.

**Modificación esperada:** cuando exista contradicción de identificadores/lugar o solo recursos compartidos con identidad incompatible, devolver coincidencia posible; conservar la fila/procedencia y retenerla para revisión. La resolución “negocios distintos” permite volver al flujo. Los candidatos pendientes deben quedar bloqueados también si se intenta contactar directamente por una ruta existente. No añadir fusión automática ni modificar datos anteriores durante la implementación.

**Criterios de aceptación:**

- Dos sucursales con distintos IDs y ubicación, pero mismo dominio/email, no se consolidan automáticamente.
- Mismo identificador de fuente confiable mantiene idempotencia.
- Recursos compartidos no borran historial ni sustituyen datos principales.
- Un posible duplicado no aparece en cola ni supera controles de contacto; confirmar identidad distinta permite una habilitación explícita compatible con el pipeline.
- El reconocimiento de clientes conocidos y la importación de Notion conservan sus garantías.

**Pruebas necesarias:** casos de sucursales, recursos compartidos, identidad corroborada, múltiples candidatos, repetir CSV, candidatos importados con score alto, acceso directo a contacto y resolución manual. Reejecutar las pruebas de Notion/CRM y de seguimiento afectadas. Todo sintético, sin scraping ni envíos.

### 2. Recuperar importaciones sin declarar éxitos prematuros

**Objetivo:** alinear estado de importación, reserva y job para que un reintento no desaparezca sin completar el trabajo. Resuelve T02 y aclara el arranque D04.

**Componentes:** `app/Services/LeadImporter.php`, `GoogleMapsScraperService.php`, `app/Jobs/ImportLeadFile.php`, `ExecuteScrapeRun.php`, `AcquisitionController.php`, configuración de cola si se demuestra necesario y README. Mantener base de datos/cola existentes.

**Modificación esperada:** distinguir devolución por reserva vigente de finalización real; liberar/reprogramar el job cuando corresponda con demora compatible con caducidad e intentos; completar scraping únicamente tras importación terminal satisfactoria. Recuperar reservas caducadas sin arrebatar una vigente. Documentar aplicación + worker y cómo revisar una ejecución detenida.

**Criterios de aceptación:**

- Un run `processing` ocupado nunca transforma al scraper en `completed`.
- Simular muerte del worker tras un checkpoint y reintento antes de caducar la reserva deja trabajo recuperable, no éxito aparente.
- Al caducar una reserva se reanuda desde la siguiente fila y se conservan contadores correctos.
- Dos ejecuciones no adquieren simultáneamente la misma importación; un dueño anterior no borra ni sobrescribe la reserva nueva.
- Cancelar y reintentar respeta límites y no vuelve a ejecutar Maps si el CSV previo válido basta.
- Seguir README inicia también el procesamiento de `sales`.

**Pruebas necesarias:** reloj controlado para reserva activa/caducada, job liberado frente a consumido, importación parcial, CSV modificado, ejecución duplicada y cancelación. Simular la caída a partir del último heartbeat, no solo desde el inicio del job. Probar la transición scraper/importador con proceso externo sustituido; no ejecutar Docker real.

### 3. Limitar el enriquecimiento a sitios públicos y respuestas acotadas

**Objetivo:** cerrar la omisión de T03 sin introducir servicios externos.

**Componentes:** `app/Services/LeadEnricher.php`, `ContactNormalizer.php` si se comparte la validación, configuración del límite de respuesta y pruebas HTTP del enriquecimiento.

**Modificación esperada:** normalización correcta de hosts, control IPv4/IPv6 y DNS de destinos no públicos; conservar esquemas HTTP/HTTPS y redirecciones desactivadas. Evitar que una segunda resolución conecte a una IP distinta no validada. Detener la lectura al superar el máximo de bytes. El rechazo debe dejar un motivo comprensible sin datos internos sensibles y sin inventar un problema comercial del negocio.

**Criterios de aceptación:**

- `localhost`, `localhost.`, loopback IPv4 e IPv6 entre corchetes, direcciones privadas/reservadas y nombres con resoluciones privadas no generan solicitudes.
- Las respuestas DNS mixtas no permiten elegir una dirección privada; la conexión usa el destino público aprobado.
- Una web pública de prueba puede analizarse y conservar evidencia válida.
- Redirecciones y cuerpos sobredimensionados no evaden el control ni se cargan íntegramente en memoria.
- El rechazo técnico no elimina notas manuales ni marca como hecho confirmado una inferencia comercial.

**Pruebas necesarias:** tablas de URL IPv4/IPv6, resolución DNS controlada, cambio de resolución, redirección y lectura excedida; respuestas válidas y fallo HTTP. Usar transporte y resolvedor simulados: no contactar hosts internos ni sitios públicos reales.

**Cierre de la auditoría:** informe entregable y diagnóstico verificado sobre el árbol local descrito. Las correcciones y oportunidades anteriores están propuestas; ninguna fue implementada.
