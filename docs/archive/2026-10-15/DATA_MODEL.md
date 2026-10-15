> Archivo histórico del 2026-10-15. Conserva el contenido de la etapa; sus instrucciones y estados pueden estar superados. Consulta la [documentación vigente](../../README.md) y el [índice del archivo](../README.md). Las rutas y números de línea citados como texto corresponden al código de aquella revisión.

# Modelo de datos

## Ampliación de evidencias y puntuaciones

- `signals`: referencia, método, confianza, caducidad, versión, metadata, clave lógica e `is_current`. Índices por prospecto/clave y prospecto/vigencia, sin borrar observaciones históricas. Señales automáticas heredadas se archivan; las manuales conservan vigencia/revisión.
- `enrichment_source_states`: único por prospecto/fuente; huella de entrada, última y próxima revisión y resultado resumido.
- `enrichment_request_states`: clave hash por host/política; reserva con token, frecuencia y caché robots. No contiene páginas completas.
- `lead_offer_scores`: único prospecto/oferta; total, dimensiones, explicación, confianza, cobertura, versión y fecha. Representa el cálculo actual, no un historial comercial.
- `contact_attempts.score_snapshot`: JSON nullable inmutable; los contactos anteriores permanecen null. Conserva estado conocido al contactar y razones cuando es desconocido.
- `LeadSource.metadata.coordinates`: latitud/longitud públicas opcionales, con validación de rango.

La consolidación archiva puntuaciones y estados de origen y recalcula el destino; conserva snapshots sin reescribir su contenido. Las dos migraciones del 2026-10-14 se aplicaron a la base operativa el 2026-10-15 (batch 4), con respaldo previo/posterior y preservación exacta de los registros de Notion. Ver [SCORING.md](SCORING.md).


## Principio

El modelo debe explicar el embudo comercial.

No debe guardar datos sin utilidad comercial.

## Entidades principales

### Lead

Campos mínimos:

- `id`
- `name`
- `category`
- `location`
- `country`
- `language`
- `website_url`
- `email`
- `phone`
- `source_urls`
- `source`
- `source_identifier`
- `segment`
- `acquired_at`
- `status`
- `score_total`
- `suggested_offer_id`
- `signals`
- `notes`
- `data_quality`

### Campaign

Campos mínimos:

- `id`
- `name`
- `segment`
- `country`
- `language`
- `source`
- `channel`
- `offer_id`
- `message_id`
- `cta`
- `starts_at`
- `ends_at`
- `daily_limit`
- `status`
- `dry_run`

### ImportRun

Campos mínimos:

- `id`
- `source`
- `campaign_id`
- `query`
- `segment`
- `location`
- `started_at`
- `finished_at`
- `file_path`
- `raw_count`
- `imported_count`
- `duplicate_count`
- `error_count`

### LeadSource

Campos mínimos:

- `id`
- `lead_id`
- `source`
- `source_identifier`
- `source_url`
- `query`
- `import_run_id`
- `observed_at`

### LeadStatusHistory

Campos mínimos:

- `id`
- `lead_id`
- `from_status`
- `to_status`
- `reason`
- `changed_at`

### Signal

Campos mínimos:

- `id`
- `lead_id`
- `type`
- `value`
- `evidence`
- `detected_at`

Ejemplos de `type`:

- `no_website`
- `website_unreachable`
- `no_https`
- `booking_business`
- `no_visible_booking`
- `weak_cta`
- `hard_to_find_contact`

### ScoreComponent

Campos mínimos:

- `id`
- `lead_id`
- `component`
- `weight`
- `value`
- `points`
- `evidence`

### Offer

Campos mínimos:

- `id`
- `name`
- `target_customer`
- `pains`
- `benefits`
- `compatibility_signals`
- `assets`
- `demo_url`
- `portfolio_url`
- `suggested_cta`
- `active`
- `segment`
- `assets`
- `rules`

### CampaignQuery

Campos mínimos:

- `id`
- `campaign_id`
- `query`
- `location`
- `result_limit`
- `status`

### ScrapeRun

Campos mínimos:

- `id`
- `campaign_id`
- `campaign_query_id`
- `source`
- `query`
- `location`
- `result_limit`
- `status`
- `dry_run`
- `timeout_seconds`
- `output_file`
- `command`
- `exit_code`
- `stdout`
- `stderr`
- métricas de importación

### MessageTemplate

Campos mínimos:

- `id`
- `offer_id`
- `name`
- `language`
- `channel`
- `segment`
- `stage`
- `subject`
- `body`
- `active`

### Experiment

Campos mínimos:

- `id`
- `campaign_id`
- `name`
- `variable`
- `status`
- `started_at`
- `ended_at`

### ExperimentVariant

Campos mínimos:

- `id`
- `experiment_id`
- `name`
- `content`
- `sent_count`
- `reply_count`
- `positive_reply_count`
- `interest_count`
- `proposal_count`
- `won_count`

### LlmBridgeRun

Campos mínimos:

- `id`
- `use_case`
- `status`
- `prompt`
- `lead_ids`
- `imported_json`
- `validation_errors`

### LlmSuggestion

Campos mínimos:

- `id`
- `llm_bridge_run_id`
- `lead_id`
- `type`
- `payload`
- `status`

### ContactAttempt

Campos mínimos:

- `id`
- `lead_id`
- `campaign_id`
- `experiment_variant_id`
- `variant`
- `message_template_id`
- `channel`
- `step`
- `planned_at`
- `performed_at`
- `due_at`
- `status`
- `dry_run`
- `preparation_seconds`
- `message_snapshot`
- `result`
- `manual_intervention`

Regla:

Una oportunidad lead/campaña no puede tener dos intentos pendientes dentro de secuencias activas.

### Reply

Campos mínimos:

- `id`
- `lead_id`
- `contact_attempt_id`
- `category`
- `body`
- `received_at`
- `requires_human`

Categorías:

- `negative`
- `not_now`
- `existing_provider`
- `no_budget`
- `price_question`
- `information_request`
- `interested`
- `meeting`
- `proposal`
- `won`
- `lost`
- `other`

`no_response` no se registra como respuesta real.

Se calcula por ausencia de respuesta cuando corresponde.

### Proposal

Campos mínimos:

- `id`
- `lead_id`
- `service`
- `amount`
- `currency`
- `version`
- `sent_at`
- `response`
- `objections`
- `modifications`
- `result`

## CRM unificado (2026-10-10)

`Lead` conserva su identificador y todas sus relaciones existentes. Las dimensiones nuevas son:

- `entity_type`: `person` u `organization`.
- `is_customer` e `is_collaborator`: indicadores independientes. Un cliente puede ser colaborador.
- `relationship_type`: proyección compatible de la relación principal (`customer`, `collaborator`, `prospect`). Los filtros usan los indicadores y muestran ambas relaciones cuando corresponde.
- `classification_pending`: clasificación pendiente de revisión humana.
- `manual_priority`: `high`, `medium`, `low` o `NULL`, independiente de `score_total`.
- `phone_original`: valor original; la migración conserva los teléfonos anteriores.
- `estimated_value_minor`, `estimated_currency`: valor estimado de oportunidad en centavos, independiente de propuestas y servicios.
- `origin_created_at`, `imported_last_contact_at`, `delivery_due_at`: fechas de origen declaradas. No generan eventos históricos de contacto ni pipeline.

La migración conserva la clasificación anterior. Los registros `WON` pasan a tener relación de cliente sin modificar su estado, historial ni campaña. Una venta ganada registrada mediante el pipeline también establece `is_customer`, sin crear servicios.

### LeadContact

Contacto adicional de una organización: `lead_id`, `name`, `role`, `phone`, `phone_original`, `email`, `is_primary`, `import_key`. Solo puede haber un contacto marcado como principal por organización. Los campos de `Lead` son la fuente de verdad compatible; `LeadContactSynchronizer` mantiene el principal en la misma transacción. Elegir o editar el principal actualiza `Lead` y su proyección; editar `Lead` sincroniza el principal. Añadir otros contactos no la altera. Eliminar un contacto asociado conserva los datos principales de la ficha. Una persona independiente utiliza sus propios datos en `Lead`. Una organización con contactos asociados requiere revisar esos contactos antes de cambiar su identidad a persona.

### ClientService

Servicio contratado: `lead_id`, `proposal_id` opcional, `name`, `type`, `status`, `amount_minor`, `currency`, `starts_on`, `ends_on`, `renews_on`, `reference_url`, `notes`.

Tipos: `web`, `hosting`, `maintenance`, `ecommerce`, `automation`, `other`. Estados de ejecución: `planned`, `active`, `paused`, `completed`, `cancelled`. Un cliente admite varios servicios. La propuesta vinculada debe pertenecer al mismo cliente. Crear, editar o finalizar un servicio no mueve el pipeline.

Los importes nuevos usan enteros de centavos y se presentan como cadenas decimales. No se calcula ningún ingreso cobrado. Se distingue `NULL` de cero. Las propuestas existentes conservan su columna e importe propios, con cast decimal.

### Procedencia de Notion

`LeadSource` añade `import_key` único y `metadata` depurada. Conserva el identificador de Notion, el estado original reconocido, advertencias, errores por campo y el hash de las notas seguras. No conserva fichas originales, credenciales ni valores inválidos sensibles. `ImportRun` de Notion tiene `campaign_id = NULL` y no crea campañas, oportunidades, propuestas, servicios ni historial.

Los registros nuevos de Notion sin campañas quedan fuera del embudo de adquisición; clasificarlos como clientes tampoco fabrica cierres. Una vinculación comercial posterior explícita puede incorporarlos al flujo existente.

## Deduplicación

El comparador compartido `LeadIdentityMatcher` usa estas señales:

- dominio;
- teléfono;
- email;
- identificador de fuente;
- nombre + ubicación.

Regla:

Una oportunidad lead/campaña no puede tener dos secuencias activas al mismo tiempo.

## Exportación

Exportar:

- leads;
- fuentes;
- campañas;
- scoring;
- señales;
- intentos de contacto;
- respuestas;
- pipeline;
- propuestas;
- experimentos cuando existan.

Formatos P0:

- CSV para datasets por entidad;
- JSON estructurado completo;
- snapshot SQLite.

Una fuente e identificador únicos constituyen una coincidencia confiable. Una identidad corroborada por nombre y ubicación, nombre y dominio/email, o dominio y email también puede ser confiable si existe una sola coincidencia inequívoca y todos los demás candidatos presentan contradicciones explícitas; un candidato compatible ambiguo mantiene la revisión pendiente. Las señales compartidas sin corroboración, las coincidencias múltiples y la identidad compartida dentro del export requieren revisión. Se consideran los contactos asociados. El scraper conserva sus posibles duplicados con trazabilidad; Notion los deja pendientes hasta una decisión explícita.

Las importaciones no sobrescriben estados, relaciones, prioridad manual, datos de contacto ya existentes ni valores económicos existentes. Solo completan campos vacíos autorizados y añaden una vez notas comerciales seguras con marca de procedencia. Los datos económicos se completan como importe y moneda juntos.

La comparación de teléfonos ignora marcadores de texto, números de menos de ocho o más de quince dígitos y valores compuestos solo por ceros. Se conservan en el campo original para revisión; no se utilizan como evidencia de identidad compartida ni se inventa un código de país.

Al importar una segunda persona de una organización, sus datos se guardan en su contacto asociado. Si la ficha ya identifica otra persona como contacto principal, no se completan su teléfono ni email con los de la segunda persona.


## Cambios aditivos de la auditoría (inicio 2026-10-13, cierre 2026-10-14)

El 2026-10-14, tras la autorización para completar los pendientes, se aplicaron las tres migraciones en la base operativa con respaldo verificado y sin tareas activas. Las huellas de las 35 tablas anteriores confirmaron que no cambiaron sus datos; se conservaron 134 registros y 29 contactos. Integridad SQLite correcta y cero errores de claves foráneas.

### campaign_lead

Representa una oportunidad independiente: `id`, `campaign_id`, `lead_id`, `offer_id`, `status`, `next_action_at`, `interested_at`, `won_at`, `lost_at`, `contact_enabled`, timestamps. La relación campaña/lead es única. El pivot es la fuente del estado, oferta y fechas en contexto de campaña. El pipeline global representa cada pivot; un lead sin campañas conserva su estado global.

`contact_enabled` se añade con valor predeterminado `false`, sin habilitar automáticamente a clientes históricos. La vinculación o habilitación explícita permite trabajar esa oportunidad de un cliente; no crea contacto ni secuencia. Estados pausados/cerrados, exclusiones y posibles duplicados siguen bloqueando el contacto.

### ContactAttempt y experimentos

`experiment_variant_id` identifica la variante realmente utilizada; `variant` conserva su nombre como snapshot. `message_template_id`, `subject` y `message_snapshot` conservan lo preparado/registrado. `performed_at` indica realización; `dry_run=false` y `status=sent` son necesarios para la muestra real. `preparation_seconds` es un entero opcional registrado manualmente, sin inventar tiempos para eventos anteriores.

`lead_experiment_variant` conserva la asignación estable y fecha. Los campos de contador heredados en `experiment_variants` permanecen por compatibilidad; las pantallas calculan la muestra desde `contact_attempts`. No se incrementan contadores paralelos ni se obtiene ganador automático. Una edición de texto/asunto evita atribuir el contacto a un contenido que no se usó.

`Reply.contact_attempt_id` fundamenta la atribución por canal/variante; los registros sin intento se conservan, pero no se asignan arbitrariamente a una variante. Propuestas y estados usan la regla del último contacto real anterior en la misma oportunidad, con las fechas de los eventos; es una asociación conservadora.

### Reservas y filas rechazadas

`ScrapeRun.claim_token` añade propiedad de reserva a los campos existentes de fase, heartbeat y reclamación. `ImportRun` conserva `claim_token`, `claimed_at`, `processed_rows`, hash del archivo y contadores. Un estado ocupado no equivale a completado. Solo el propietario puede escribir o liberar su reserva; una reserva caducada permite recuperar el checkpoint.

`import_row_issues`: `id`, `import_run_id`, `row_number`, `errors` JSON y timestamps. La pareja importación/fila es única. Guarda errores por campo y número de fila, sin copiar la fila completa. El CSV privado descargable relee el archivo original e incluye sus celdas con ese diagnóstico; requiere el mismo cuidado de privacidad que el CSV de entrada.

### lead_merges

Registro de consolidación manual: `id`, `source_lead_id` único, `target_lead_id`, `plan_sha256`, `original_records` JSON, `decisions` JSON, `backup_path`, `backup_sha256` y timestamps. Conserva la referencia de la ficha absorbida sin una FK que desaparezca al consolidar; la ficha principal tiene FK con borrado restringido. Los originales y decisiones preservan historial y conflictos resueltos. El respaldo se verifica antes de la transacción y se conserva su hash.

### Métricas y exportaciones

La exploración `currentScoreEvaluation()` conserva rangos de `Lead.score_total` actual y oferta sugerida. La evaluación histórica principal `scoreEvaluation()` usa exclusivamente snapshots al contacto, cohortes por prospecto/campaña/oferta/versión, muestras conocidas/desconocidas y resultados posteriores atribuibles. No calcula scores históricos con datos actuales. Ver [SCORING.md](SCORING.md).

El CSV analítico emite una fila por oportunidad, o por lead sin campañas, e incorpora `reply_attempt_id`, `reply_channel`, `reply_variant`. CSV y CLI usan el mismo serializador seguro. JSON exporta contactos, servicios, oportunidades y relaciones seleccionadas; reutiliza IDs y mantiene el contexto de campaña.
