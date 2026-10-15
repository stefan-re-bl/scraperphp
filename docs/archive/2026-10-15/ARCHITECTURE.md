> Archivo histórico del 2026-10-15. Conserva el contenido de la etapa; sus instrucciones y estados pueden estar superados. Consulta la [documentación vigente](../../README.md) y el [índice del archivo](../README.md). Las rutas y números de línea citados como texto corresponden al código de aquella revisión.

# Arquitectura

## Enriquecimiento y scoring por oferta

`EnrichLead` → `LeadEnricher` → presupuesto HTTP/robots → descubrimiento y extracción DOM/JSON-LD → conciliación de identidad → `EvidenceReconciler` y estados de fuente → `LeadScorer`/`OfferMatcher`. El transporte seguro se reutiliza y las fuentes secundarias son independientes y opcionales. La escritura de evidencias y estados se realiza en transacción, sin reemplazar identidad principal.

`ContactWorkflow` y la reserva de `EmailContactService` capturan `ScoreSnapshotService` antes de resultados comerciales. Consultas de UI, filtros, exports y CLI solo leen cálculos vigentes; métricas históricas agrupan snapshots inmutables. No se añadieron infraestructura, dependencias ni IA. Configuración, presupuestos y límites: [ENRICHMENT.md](ENRICHMENT.md) y [SCORING.md](SCORING.md).

Las dos migraciones aditivas se activaron el 2026-10-15 con respaldos y ensayo sobre copia real; aplicación y worker locales reiniciados. Los datos importados de Notion se excluyeron de las mutaciones y conservaron su contenido exacto. Guzzle 8.2.0 requiere configurar proxy y redirecciones mediante opciones de alto nivel; solo la fijación DNS se pasa en `curl`. Una prueba inicializa el transporte real sin ejecutar solicitudes.


## Modelo general

El sistema utiliza una aplicación local Laravel y herramientas externas controladas.

La app local orquesta el proceso.

Las herramientas externas realizan trabajos mecánicos.

La persona decide en los puntos de confianza comercial.

## Componentes

```text
Web UI local
  -> Orquestador Laravel
  -> LeadFilterService
  -> Base de datos local
  -> Procesos externos seguros
  -> Herramientas externas
       -> Google Maps scraper
       -> Enriquecimiento determinista
       -> SMTP configurable
       -> Exportaciones
```

## App local

Stack actual:

- Laravel.
- SQLite.
- Jobs simples.
- UI mínima con componentes estándar.

Responsabilidades:

- campañas;
- leads;
- deduplicación;
- scoring;
- ofertas;
- estados del pipeline;
- cola de contacto;
- respuestas;
- propuestas;
- métricas;
- exportación CSV;
- exportación JSON;
- snapshot SQLite.
- scraper orquestado;
- plantillas;
- follow-ups;
- experimentos;
- LLM Bridge sin API;
- métricas segmentadas;
- blacklist y exclusiones.

## Herramientas externas

### Google Maps scraper

Herramienta seleccionada:

- `gosom/google-maps-scraper`.

Uso inicial:

- Docker o binario local.
- Entrada: consultas generadas por campaña.
- Salida inicial: CSV.
- Importación controlada por la app.

La integración inicial importa CSV.

Antes de agregar otro formato, verificar soporte en la versión instalada.

El binario se configura con:

- `GOOGLE_MAPS_SCRAPER_BIN`
- `GOOGLE_MAPS_SCRAPER_TIMEOUT`

## Flujo principal

1. Abrir campaña.
2. Obtener leads.
3. Revisar y filtrar leads.
4. Enviar leads seleccionados a cola.
5. Contactar desde la cola asistida.
6. Registrar respuesta.
7. Avanzar oportunidad en pipeline.
8. Registrar propuesta.
9. Medir campaña.
10. Exportar vista, selección o snapshot.

## Capa compartida de filtros

`LeadFilterService` centraliza consultas de leads.

La usan:

- listado global;
- workspace de campaña;
- acciones masivas;
- exportación CSV;
- exportación JSON;
- `analytics_export.csv`.

Los filtros de la web viven en query string. La CLI los recibe mediante un objeto JSON y reutiliza la misma selección.

Esto permite compartir vistas y exportar la misma selección.

## Límites técnicos actuales

No incluir en MVP:

- multiusuario;
- roles complejos;
- Kubernetes;
- microservicios;
- IA autónoma;
- envío masivo sin revisión;
- integración automática con Notion;
- CRM generalista;
- diseño visual avanzado.

No incluir infraestructura pesada:

- Redis;
- microservicios;
- Kubernetes;
- Elasticsearch.

## Seguridad operativa

Cada integración nueva debe iniciar en dry-run.

Debe existir:

- rate limit;
- límite diario;
- lista de exclusión;
- blacklist;
- kill switch de campaña;
- logs;
- cancelación de secuencia;
- historial de estado.

## CRM unificado e importación puntual de Notion (2026-10-10)

Cartera reutiliza `LeadFilterService`, la ficha y las acciones existentes. Los filtros de prospectos, clientes y colaboradores consultan los mismos registros. `CrmController` gestiona contactos y servicios con validación y comprobación del registro propietario. Servicios ofrece búsqueda, filtros y una lista de renovaciones hasta una fecha; incluye vencimientos atrasados y excluye servicios finalizados o cancelados.

`NotionImporter` lee el ZIP en memoria, limita archivos y tamaño, rechaza rutas inseguras, enlaces y formatos ajenos. Selecciona un CSV principal y correlaciona fichas por identificadores o nombre y propiedades comunes inequívocas. Los nombres repetidos sin correlación segura quedan pendientes. Las fichas no asociadas se identifican en el informe sin importar sus notas.

`NotionValueParser` valida importes ARS y fechas españolas; convierte fechas desde la zona configurada a UTC. Los teléfonos importados solo se normalizan cuando traen código internacional explícito. Se conserva el valor original de un teléfono incompleto y se señala para revisión. No se inventan códigos de país.

`NotionNotesSanitizer` descarta la ficha de notas completa cuando encuentra posibles credenciales, bloques de código, tokens o accesos. Esta política conservadora puede omitir notas comerciales junto con los datos sensibles; deben revisarse manualmente fuera de la importación. No se imprimen los fragmentos descartados ni se guardan versiones sin depurar.

### Decisiones reproducibles

El comando `crm:import-notion` admite vista previa por defecto y aplicación con `--apply --approved-report`. El informe identifica cada fila mediante `key`, `notion_id`, identidad comercial y candidatos. Un archivo JSON de decisiones usa esas claves:

```json
{
  "notion:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa": {
    "action": "new",
    "is_customer": true,
    "is_collaborator": false
  },
  "notion:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb": {
    "action": "match",
    "match_key": "notion:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
  },
  "notion:cccccccccccccccccccccccccccccccc": {
    "action": "match",
    "lead_id": 42
  },
  "notion:dddddddddddddddddddddddddddddddd": {
    "action": "skip"
  }
}
```

- `import`: usa la coincidencia confiable o crea una identidad nueva si no hay conflictos.
- `new`: confirma una identidad distinta frente a coincidencias posibles. No permite duplicar una coincidencia confiable.
- `match` con `lead_id`: vincula a una identidad existente, sin reemplazar su clasificación ni historial.
- `match` con `match_key`: vincula a otro registro principal aprobado del mismo export. Ambos contactos quedan bajo un único `Lead`. No se admiten referencias encadenadas ni cíclicas.
- `skip`: excluye la fila de esta aplicación y conserva sus motivos de revisión en el informe.
- `markdown_id`: selecciona explícitamente una de las fichas candidatas ambiguas.
- `is_customer` / `is_collaborator`: resuelven la clasificación de un registro nuevo. El estado `Cerrado 💪` o `En desarrollo` no implica automáticamente una venta ni un servicio.
- `status`: permite `NEW`, `LOST` o `PAUSED` para la clasificación de origen. Las ventas se registran mediante el flujo comercial existente, con su evidencia.
- `entity_type`: permite corregir la interpretación de persona u organización.
- `omit_invalid`: permite importar los demás campos cuando se han revisado los errores de conversión. Una identidad inválida siempre impide importar la fila.

`Socio` se importa como colaborador, independiente del pipeline. `Perdido` se importa como `LOST` para registros nuevos; una coincidencia existente conserva su estado. Los estados vacíos, cerrados o en desarrollo requieren decisión humana. Los valores de origen desconocidos se omiten y señalan. No se crean servicios a partir de notas ni importes estimados.

Tras editar decisiones, vuelve a generar y revisar la vista previa con `--decisions`; su hash incluye el ZIP, decisiones, zona horaria y estado actual de cartera/contactos/servicios/procedencias. La aplicación rechaza una revisión desactualizada, espera a que no haya trabajos activos, genera y verifica un snapshot y escribe dentro de una transacción. La clave única de procedencia garantiza que repetir el export no duplique identidades, contactos ni notas. El ZIP original permanece fuera del repositorio.

Verifica después el informe de aplicación, los recuentos, las fichas afectadas, sus contactos y las procedencias de Notion. Una nueva vista previa del mismo export debe indicar las identidades ya importadas. Comprueba también que los registros pendientes continúan excluidos y que no se crearon contactos, propuestas, servicios ni oportunidades ficticias. No declares la migración completa sin esta comprobación.

### Respaldo y recuperación

Antes de ampliar el esquema usa `sales:backup`: reutiliza `BackupService`, ejecuta `VACUUM INTO`, comprueba integridad y claves foráneas y calcula SHA-256. Para revertir una importación utiliza el respaldo indicado en su informe mediante `sales:restore /ruta/respaldo.sqlite --force`, con workers y tareas detenidos. El sistema conserva otra copia antes de restaurar y exige la misma versión de migraciones.

El respaldo anterior a esta ampliación tiene el esquema previo y la restauración normal lo rechaza. Para recuperarlo: detén aplicación y workers, guarda otro snapshot de la base actual, verifica el SHA-256 e integridad de la copia previa, reemplaza el archivo SQLite con esa copia y ejecuta `php artisan migrate --force --no-interaction` antes de reabrir la aplicación. Las migraciones aditivas vuelven a habilitar las columnas del CRM sobre los datos recuperados. Usa la ruta de base configurada; no mezcles archivos WAL de la base reemplazada. Este procedimiento sustituye datos y requiere autorización humana.


## Integración de la auditoría (inicio 2026-10-13, cierre 2026-10-14)

Los cambios conservan el monolito Laravel, SQLite, Blade, JavaScript y la cola existentes. No requieren Redis, servicios adicionales ni APIs de IA. La ejecución local requiere aplicación ligada a `127.0.0.1` y un worker `sales,default` con timeout de 900 segundos; `retry_after` de la cola SQLite es 1200. Consulta el arranque y la recuperación en [README.md](README.md).

| Requisitos | Responsabilidad compartida |
|---|---|
| T01 / D01 | `LeadIdentityMatcher` distingue coincidencias fuertes y ambiguas frente a contradicciones de identificadores, nombre y ubicación. `ExclusionService` y `CampaignMembership` bloquean contacto y cola de duplicados pendientes. |
| T02 / D04 | Scraping e importación distinguen trabajo ocupado de estados terminales; sus reservas tienen token de propietario, caducidad y checkpoint. Los jobs ocupados se reprograman sin agotar reintentos. El CSV válido se reutiliza y no hay éxito hasta terminar la importación. |
| T03 / T08 | `PublicWebsiteFetcher` verifica el destino público y fija DNS en cURL. `LimitedWebsiteStream` limita bytes antes de cargar el cuerpo. `LeadEnricher` reconcilia señales en una transacción, preservando evidencia humana vigente. |
| T04 / T05 / T06 / T11 | `LeadFilterService` separa selección de relaciones cargadas. Campaña utiliza pivot y eventos contextualizados. IDs explícitos vacíos o inválidos no amplían consultas. `CsvSerializer` protege exportaciones web, CLI y reportes. |
| T07 / D05 | Métricas relaciona respuestas con el intento real; atribuye avances al último contacto real anterior en esa oportunidad. Variantes registradas, muestras y agrupaciones usan eventos reales y el identificador de experimento. |
| T09 / D03 | `LeadContactSynchronizer` proyecta datos principales de `Lead` al contacto principal. Las oportunidades se representan por `campaign_lead`; clientes requieren permiso explícito de contacto. |
| T10 / T12 / D02 | Respaldo rechaza claves foráneas rotas; pruebas aíslan todos los recursos externos; formularios de eliminación requieren confirmación efectiva. |
| O01 / O03 | Consolidación manual con conflictos resueltos, plan revisado, respaldo e historial original; análisis CSV previo comparte normalizador, parser e importador incremental. |
| O02 / O04 / O05 | `CommercialWorkService` reúne trabajo pendiente sin crear secuencias. CLI reutiliza servicios. `MetricsService` conserva exploración actual separada y evalúa snapshots históricos por cohorte; no cambia pesos automáticamente. |

### Fuentes de verdad y consultas

Los datos principales de contacto residen en `Lead`, compatible con UI, exportaciones y servicios existentes. Editar el principal asociado actualiza esos campos mediante el mismo servicio; editar la ficha actualiza su principal en la transacción. Eliminar un contacto conserva los campos principales, sin recrearlo automáticamente.

Estado, oferta y próxima acción pertenecen al pivot de cada oportunidad. `Lead.status` conserva compatibilidad y representa registros sin campaña; no se utiliza para agrupar todas las oportunidades globales. La ficha filtra historial por `history_campaign`. La importación no habilita venta adicional de clientes; la vinculación o habilitación humana explícita establece `contact_enabled`.

La consulta compartida no carga relaciones por defecto. Cada pantalla solicita lo que usa; el workspace calcula métricas, adquisiciones, experimentos y cartera solo cuando corresponde a su pestaña. Pipeline y CSV analítico agrupan ofertas por lote; JSON reutiliza el conjunto seleccionado de IDs. El CSV analítico representa cada oportunidad separada y añade la atribución de respuesta a sus columnas anteriores.

### Enriquecimiento seguro

Se admiten HTTP/HTTPS y puertos 80/443. Se normalizan dominios, IPv4 e IPv6; se rechazan direcciones privadas, locales y reservadas, así como resoluciones DNS A/AAAA mixtas. cURL fija la IP validada con `CURLOPT_RESOLVE`, conserva hostname/TLS, desactiva proxies y redirecciones y limita la transferencia a 120000 bytes por defecto. Se rechaza contenido comprimido incompatible con el límite. Un transporte que no permita fijar el destino falla de forma explícita. Los errores comerciales muestran mensajes depurados.

La imagen Docker usa el digest verificado `gosom/google-maps-scraper@sha256:e205c02913c5a69c16fc2094b8e5b194a2f655166d2c1126d50548d216e6b1b2`. La configuración heredada exactamente igual a `gosom/google-maps-scraper:latest` se normaliza a ese valor; una configuración explícita diferente permanece bajo control del operador. La validación de esta entrega no ejecutó scraping real.

### Consolidación e importación

La revisión de duplicados muestra identidad, diferencias y relaciones. La persona decide que son distintos o elige principal, resuelve cada colisión y revisa los datos resultantes. `LeadMergeService` verifica hash del plan vigente y respaldo antes de escribir; registra originales, decisiones y hash del respaldo en `lead_merges`. Los contactos, procedencias, eventos, oportunidades, propuestas y servicios se conservan según decisiones explícitas. Una revisión desactualizada o con conflictos sin resolver se rechaza. No se consolidan automáticamente registros anteriores.

La vista previa CSV comparte parser, mapeo, validación y normalizadores con la importación. Solo la confirmación inicia la aplicación. `import_row_issues` conserva número de fila y errores por campo; el CSV privado descargable de rechazos relee el archivo original y contiene las celdas rechazadas con sus motivos. Las incidencias persistidas y los logs no copian las filas completas. Una importación ocupada se vuelve a programar; solo su propietario puede escribir checkpoints o liberar la reserva. Los reintentos conservan contadores y verifican el archivo antes de reutilizarlo.

### CLI, experimentos y evaluación

`sales:inspect` ofrece lectura de `leads`, `campaigns`, `opportunities`, `runs`, `actions`, `metrics` y `export`. JSON devuelve IDs estables y salida 0/2/1 para éxito/argumentos inválidos/fallo operativo. Límite: 100 por defecto, máximo 2000. Solo `export --output` crea un CSV local nuevo y no sobrescribe archivos. Los filtros y controles se comparten con la web; campañas/runs admiten campaña, y filtros de estado o actividad de oportunidades requieren contexto de campaña. El README contiene ejemplos para agentes.

La asignación A/B es estable y la plantilla compatible prepara el mensaje. Solo un contacto real con texto/asunto correspondientes recibe atribución automática; editarlo la retira. Una asignación ambigua entre experimentos activos tampoco se atribuye. Los contadores heredados no determinan la muestra y no se elige ganador. Las propuestas/cierres se atribuyen conservadoramente por último contacto anterior; describen asociación, no causalidad.

O05 conserva la exploración de score y oferta actuales como `currentScoreEvaluation()`. La evaluación principal usa los nuevos snapshots al contactar; sin snapshot el histórico es desconocido. No reconstruye puntuaciones anteriores ni modifica pesos automáticamente. `preparation_seconds` sigue siendo opcional y las muestras menores a 30 son exploratorias. El Bridge manual recibe evidencia confirmada y depurada, sin llamadas automáticas.

### Operación y privacidad

Antes de aplicar las migraciones nuevas, detén tareas y crea un respaldo verificado. El 2026-10-14, tras la autorización para completar los pendientes, se aplicaron las tres migraciones en la base operativa con respaldo verificado y sin tareas activas. Las huellas de las 35 tablas anteriores confirmaron que no cambiaron sus datos; se conservaron 134 registros y 29 contactos. Integridad SQLite correcta y cero errores de claves foráneas. La suite utiliza SQLite en memoria, almacenamiento/logs/vistas temporales, HTTP y procesos simulados; no hace scraping ni envíos.

Revisa archivos manualmente cada 30 días. Conserva respaldos verificables previos a cambios y archivos referenciados por importaciones/consolidaciones. No se programan borrados ni se eliminaron archivos existentes. Mantén CSV, ZIP, informes, SQLite y credenciales fuera de Git. La app sigue siendo local: cualquier exposición de red requiere controles de acceso antes de habilitarla.

### Activación y validación operativa (2026-10-14)

La aplicación quedó iniciada en `http://127.0.0.1:8000` y el worker `sales,default` con timeout de 900 segundos, sin supervisor ni servicio permanente nuevo. La cola operativa estaba vacía. Se verificaron las consultas CLI reales sin cambiar registros comerciales y Docker 29.1.3 disponible mediante inspección de solo lectura. Se conservaron dos jobs fallidos históricos de scraping del 2026-10-09; no se reintentaron ni borraron, porque un reintento podría iniciar una búsqueda real ajena a estas comprobaciones.

Respaldo previo: `storage/app/private/backups/ventas-20261014-155908-f522eaa1-5b77-41aa-a04c-3df448575113.sqlite`, SHA-256 `b41c5dddbba3105fa5578357d5a84331021d5ea56c735fa953ff9b7bb8867015`. Respaldo posterior compatible con el nuevo esquema: `storage/app/private/backups/ventas-20261014-160333-43af4fbb-7e5f-4b5d-8c8b-2d84e3aca389.sqlite`, SHA-256 `5cbefcb6b0f29450c3960c22d9b1671a26ac73b402ee287fbb704bbfc6f708ad`. Ambos verificados y con permisos privados; detalle agregado en `storage/app/private/backups/activation-20261014.json`.

Para una instancia de prueba con variables de entorno temporales, `artisan serve` requiere `--no-reload` para conservar esas variables en el servidor hijo. Verifica siempre los datos de prueba visibles antes de interactuar.
