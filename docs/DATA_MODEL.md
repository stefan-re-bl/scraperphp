# Modelo de datos

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
- `variant_id`
- `channel`
- `step`
- `planned_at`
- `sent_at`
- `status`
- `manual_review_required`
- `message_snapshot`
- `result`
- `manual_intervention`

Regla:

Un lead no puede tener dos intentos pendientes dentro de secuencias activas.

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

## Deduplicación

Usar estas señales:

- dominio;
- teléfono;
- email;
- identificador de fuente;
- nombre + ubicación.

Regla:

Un lead no puede tener dos secuencias activas al mismo tiempo.

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
