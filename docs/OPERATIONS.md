# Operación

Procedimientos técnicos de la aplicación local. Para tareas comerciales utiliza la [Guía de uso](USER_GUIDE.md); para conocer la última comprobación consulta [Estado](PROJECT_STATE.md). Ejecuta los comandos desde la raíz del repositorio. Los IDs, fechas y rutas de ejemplo deben sustituirse por los del caso revisado.

## Instalación y actualización

### Instalación nueva

Este procedimiento crea el entorno y el esquema. Requiere PHP y Composer compatibles con [composer.json](../composer.json) y [composer.lock](../composer.lock), extensiones cURL, PDO SQLite y DOM, además de Node.js/npm para compilar los recursos. Las versiones verificadas se registran en Estado.

En una carpeta sin base ni configuración anterior:

```bash
composer install --no-interaction
cp .env.example .env
php artisan key:generate --no-interaction
touch database/database.sqlite
php artisan migrate --no-interaction
npm ci --ignore-scripts
npm run build
```

Revisa `.env` antes de migrar: `DB_CONNECTION=sqlite`, ruta de base si usas `DB_DATABASE`, `QUEUE_CONNECTION=database` y correo inicialmente en `log`. Mantén `.env` fuera de Git. Generar una clave, copiar configuración o ejecutar migraciones son acciones de escritura; no repitas este bloque sobre una instalación existente.

### Actualizar una instalación existente

1. Revisa cambios, migraciones pendientes y configuración. Confirma que es la base correcta y que el trabajo tiene autorización.
2. Detén aplicación y worker mediante sus terminales. Comprueba que no hay scraping, importaciones ni reservas activas.
3. Crea y verifica un respaldo con el código compatible con el esquema actual. Conserva el código/commit al que corresponde.
4. Ensaya la actualización sobre una copia de la base real, aislando también almacenamiento y configuración. Compara los datos que deban permanecer intactos.
5. Aplica las migraciones revisadas sobre la base autorizada, comprueba integridad y relaciones y crea un respaldo posterior.
6. Reinicia aplicación/worker y verifica las pantallas y consultas necesarias. Un cambio de código o configuración requiere reiniciar el worker.

Consultas previas, sin aplicar migraciones:

```bash
php artisan migrate:status --no-interaction
php artisan sales:inspect runs --limit=20 --no-interaction
php artisan queue:failed --no-interaction
```

Escrituras, después de completar la revisión y el respaldo:

```bash
php artisan sales:backup --no-interaction
php artisan migrate --no-interaction
```

No uses `migrate:fresh`, `migrate:refresh` ni `migrate:reset` en una base con cartera real. No ejecutes `composer run setup` como actualización operativa: su secuencia incluye migraciones y no sustituye este procedimiento.

## Arranque y parada

En dos terminales:

```bash
php artisan serve --host=127.0.0.1 --port=8000 --no-interaction
```

```bash
php artisan queue:work --queue=sales,default --timeout=900 --tries=3 --no-interaction
```

Abre [http://127.0.0.1:8000](http://127.0.0.1:8000). Mantén ambas terminales activas. Para detener el entorno, deja terminar las tareas en curso y detén cada proceso con Ctrl+C; una interrupción no equivale a una tarea completada. No se instala supervisor ni servicio de arranque automático.

La cola SQLite utiliza `retry_after=1200` segundos por defecto, superior al timeout del worker. Si cambias los límites conserva esa relación. `artisan serve` no inicia el worker; las tareas pueden quedar pendientes aunque las pantallas respondan.

Para una instancia de ensayo con variables temporales, aísla `DB_DATABASE`, `DB_URL`, `APP_CONFIG_CACHE`, `LARAVEL_STORAGE_PATH` y `VIEW_COMPILED_PATH`, usa otro puerto y añade `--no-reload` a `artisan serve` para conservar esas variables en el proceso hijo. Verifica el destino antes de cualquier escritura y usa correo `array` o `log`. No apuntes un worker de ensayo a la cola operativa.

## Configuración

| Área | Referencia y variables principales |
| --- | --- |
| Base y cola | [database.php](../config/database.php), [queue.php](../config/queue.php): `DB_CONNECTION`, `DB_DATABASE`, `DB_URL`, `QUEUE_CONNECTION`, `DB_QUEUE_RETRY_AFTER`. |
| Scraper | [services.php](../config/services.php): `GOOGLE_MAPS_SCRAPER_BIN`, `GOOGLE_MAPS_SCRAPER_IMAGE`, `GOOGLE_MAPS_SCRAPER_USE_DOCKER`, `GOOGLE_MAPS_SCRAPER_TIMEOUT`. |
| Sitios y fuentes | [Enriquecimiento](ENRICHMENT.md) y [enrichment.php](../config/enrichment.php): presupuestos, TTL, identificación del bot y OSM opcional. |
| Puntuaciones | [Scoring](SCORING.md), [commercial_scoring.php](../config/commercial_scoring.php) y reglas por oferta. |
| Correo | [mail.php](../config/mail.php): transporte, servidor, puerto y credenciales. |
| Preferencias | Configuración de la interfaz: zona horaria y valores comerciales. Las fechas operativas se convierten a UTC. |

El scraper puede ejecutarse mediante Docker o binario local. La imagen predeterminada queda fijada al digest de [services.php](../config/services.php); el antiguo `latest` oficial se normaliza a ese valor. Las imágenes personalizadas se respetan y requieren su propia revisión. Obtener/iniciar el scraper puede realizar solicitudes reales a la fuente.

OpenStreetMap está desactivado por defecto. Su habilitación exige revisar endpoint, condiciones, identificación y coordenadas; no es un requisito para operar el CRM.

## Diagnóstico de tareas

1. Revisa estado persistido y logs antes de reintentar. La campaña muestra fase, contadores y errores de adquisición/importación.
2. Comprueba el worker y consulta `sales:inspect runs` y `queue:failed`.
3. Distingue pendiente, ocupado, cancelado, fallido y completado. Una reserva vigente debe esperar; solo el propietario puede actualizar o liberar su trabajo.
4. Revisa si existe un CSV válido y sin cambios. El reintento puede continuar su importación; sin ese archivo puede lanzar scraping real.
5. Usa Reintentar o Cancelar sobre la ejecución revisada. No borres jobs ni cambies tokens, hashes o checkpoints manualmente para aparentar éxito.

`storage/logs/` contiene diagnóstico local; evita copiar trazas o datos personales a issues. `import_row_issues` guarda fila/campo/motivo y la descarga de rechazos relee el archivo de entrada. Esa descarga debe mantenerse privada.

Si una página no puede consultarse por robots, DNS, TLS, contenido o presupuesto, queda sin verificar. No desactives la validación pública, DNS fijado, TLS, límites o bloqueo de redirecciones para aumentar cobertura. Consulta [Enriquecimiento](ENRICHMENT.md).

## CLI comercial

Consulta la ayuda instalada:

```bash
php artisan sales:inspect --help --no-interaction
php artisan crm:import-notion --help --no-interaction
php artisan sales:restore --help --no-interaction
```

`sales:inspect` devuelve JSON con `ok`, `resource` y `data`, o `ok=false` y `error`. Códigos: `0` correcto, `2` entrada inválida, `1` fallo operativo. Límite predeterminado 100 filas, máximo 2000. Consultar no recalcula puntuaciones, no solicita sitios ni envía mensajes.

| Recurso | Contenido |
| --- | --- |
| `leads` | Cartera filtrada y contexto de puntuación. |
| `campaigns` | Campañas; solo admite selección por campaña. |
| `opportunities` | Relaciones prospecto/campaña; los filtros de actividad o estado requieren campaña. |
| `runs` | Trabajos de scraping/importación; solo admite filtro de campaña. |
| `actions` | Trabajo pendiente mediante el mismo servicio de Inicio. |
| `metrics` | Embudo, segmentación y evaluación histórica del scoring. |
| `scores` | Cálculos por oferta, explicación, versión y campo `fresh` de vigencia. |
| `evidence` | Observaciones con fuente y revisión; incluye su estado de vigencia. |
| `export` | Dataset en JSON; crea CSV únicamente con `--output` explícito. |

Ejemplos de lectura; `1` representa un ID del caso y no una campaña configurada por defecto:

```bash
php artisan sales:inspect leads --filters='{"q":"Consultorio","has_email":true}' --limit=50 --no-interaction
php artisan sales:inspect campaigns --no-interaction
php artisan sales:inspect opportunities --campaign=1 --filters='{"status":"PROPOSAL"}' --no-interaction
php artisan sales:inspect runs --limit=20 --no-interaction
php artisan sales:inspect actions --campaign=1 --ids=1,2 --no-interaction
php artisan sales:inspect scores --filters='{"score_offer_id":1}' --no-interaction
php artisan sales:inspect evidence --ids=1 --filters='{"evidence_review":"confirmed"}' --no-interaction
php artisan sales:inspect metrics --dimension=offer --filters='{"from":"2026-10-01","to":"2026-10-15","offer_id":1}' --no-interaction
php artisan sales:inspect export --dataset=offer_scores --filters='{"score_offer_id":1}' --no-interaction
```

`--filters` acepta un objeto JSON. Comparte selección por texto, IDs, campaña, estado, relación de cartera, ubicación, presencia de canales, actividad y puntuación con la web; los filtros desconocidos o incompatibles se rechazan. `offer_id` filtra oferta de oportunidad y `score_offer_id` elige la oferta evaluada. Una selección explícita de IDs vacía/inválida no selecciona toda la cartera. Los filtros mínimo/máximo de puntuación excluyen cálculos desconocidos.

Para comparar una versión histórica usa `metrics --rule-version=VERSION_EXACTA`, con la versión devuelta por el sistema. `--version` es una opción global de Artisan y no sirve para este filtro.

Exportación con escritura explícita en un archivo nuevo:

```bash
php artisan sales:inspect export --dataset=leads --campaign=1 --limit=100 --output=storage/app/private/campaign-new.csv --no-interaction
```

El archivo no puede existir previamente. Los datasets aceptados están en [ExportService::DATASETS](../app/Services/ExportService.php); incluyen cartera, contactos, servicios, oportunidades, evidencias y puntuaciones por oferta. Un cálculo exportado conserva su fecha/versión; su presencia no demuestra vigencia. CSV usa serialización segura compartida y neutraliza fórmulas. JSON y SQLite pueden contener datos personales y no deben publicarse.

`scraper:execute ID` ejecuta trabajo y puede iniciar scraping real. No es un comando de diagnóstico de solo lectura.

## Respaldos y recuperación

### Crear y verificar

Crear una copia SQLite verificada es una escritura de archivo:

```bash
php artisan sales:backup --no-interaction
```

Conserva la ruta y SHA-256 devueltos. `BackupService` utiliza `VACUUM INTO`, comprueba integridad, claves foráneas, estructura y lista de migraciones. El código de la copia y el esquema deben ser compatibles.

Para verificar una copia compatible sin restaurarla:

```bash
php artisan sales:restore /ruta/respaldo.sqlite --no-interaction
```

### Restaurar una copia compatible

La restauración sustituye los datos actuales. Requiere autorización específica y revisión de los cambios que se perderían.

1. Detén aplicación, worker y tareas activas; conserva una copia actual verificada.
2. Verifica respaldo de destino, hash y versión de migraciones.
3. Confirma la sustitución:

```bash
php artisan sales:restore /ruta/respaldo.sqlite --force --no-interaction
```

4. Comprueba integridad, relaciones y registros afectados y reinicia los procesos. La restauración conserva una copia previa, pero no sustituye una revisión de los datos que vas a reemplazar.

### Copias de un esquema anterior

La restauración normal rechaza una lista de migraciones diferente. No fuerces ese control ni uses un rollback de migraciones como recuperación de datos.

Prepara la copia antigua en un entorno aislado con código compatible; verifica hash, integridad y claves foráneas y aplica allí las migraciones necesarias. Revisa los datos resultantes y ensaya la recuperación antes de sustituir el archivo operativo. Una sustitución autorizada requiere detener procesos, preservar otra copia actual y tratar correctamente los archivos SQLite WAL/SHM de la base reemplazada. No mezcles auxiliares de dos bases. Tras la sustitución verifica esquema/datos y crea otro respaldo compatible.

## Importación de Notion

Es una importación puntual de un ZIP, no una sincronización con la API. La [protección de datos](#protección-de-datos-y-retención) y las restricciones del caso siguen vigentes. La importación anterior completada se registra en Estado y el archivo histórico; no debe repetirse como prueba sobre la cartera real.

### Vista previa y decisiones

Vista previa: lee el ZIP y escribe informes privados, sin modificar fichas comerciales. El archivo inicial de decisiones debe ser nuevo:

```bash
php artisan crm:import-notion /ruta/export-notion.zip \
  --report=storage/app/private/notion-reports/preview.json \
  --write-decisions=storage/app/private/notion-reports/decisions.json \
  --no-interaction
```

Elige el CSV principal y correlaciona fichas Markdown con identificadores inequívocos. El parser señala fechas/importes/teléfonos dudosos. Las notas con indicios de credenciales se omiten de forma conservadora; no deben recuperarse automáticamente ni copiarse al informe público.

Cada decisión usa la clave de su fila. Ejemplo ficticio:

```json
{
  "notion:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa": {"action":"new","is_customer":true,"is_collaborator":false},
  "notion:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb": {"action":"match","match_key":"notion:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"},
  "notion:cccccccccccccccccccccccccccccccc": {"action":"match","lead_id":42},
  "notion:dddddddddddddddddddddddddddddddd": {"action":"skip"}
}
```

| Decisión/campo | Efecto |
| --- | --- |
| `import` | Utiliza coincidencia confiable o crea registro sin conflictos. |
| `new` | Confirma una identidad distinta frente a candidatos; no duplica una coincidencia confiable. |
| `match` + `lead_id` | Vincula a un registro existente sin sustituir su clasificación/historial. |
| `match` + `match_key` | Vincula otra fila aprobada del mismo export; sin cadenas ni ciclos. |
| `skip` | Mantiene la fila fuera de esa aplicación. |
| `markdown_id` | Resuelve qué ficha candidata aporta notas. |
| `is_customer`, `is_collaborator`, `entity_type` | Resuelven clasificación de una identidad nueva. |
| `status` | Admite clasificación de origen `NEW`, `LOST` o `PAUSED`; no inventa una venta. |
| `omit_invalid` | Permite omitir campos inválidos revisados; no admite identidad inválida. |

Los estados cerrados o en desarrollo requieren interpretación humana. `Socio` es una relación de colaborador; no equivale a etapa comercial. Los importes estimados y notas no crean propuestas ni servicios.

Después de editar decisiones, genera y revisa otra vista previa:

```bash
php artisan crm:import-notion /ruta/export-notion.zip \
  --decisions=storage/app/private/notion-reports/decisions.json \
  --report=storage/app/private/notion-reports/reviewed-preview.json \
  --no-interaction
```

### Aplicación revisada

Solo para un caso autorizado, sin tareas activas, con la vista previa y decisiones aprobadas:

```bash
php artisan crm:import-notion /ruta/export-notion.zip \
  --decisions=storage/app/private/notion-reports/decisions.json \
  --approved-report=storage/app/private/notion-reports/reviewed-preview.json \
  --apply \
  --report=storage/app/private/notion-reports/applied.json \
  --no-interaction
```

El plan incorpora huellas de ZIP, decisiones, zona horaria y estado de la cartera. Una revisión desactualizada se rechaza. Antes de escribir se genera un respaldo verificado; la aplicación es transaccional e idempotente por procedencia. Verifica recuentos, fichas y contactos, pendientes excluidos y ausencia de relaciones ficticias. Otra vista previa del mismo export debe reconocer lo ya importado. No declares el proceso terminado solo porque el comando devolvió éxito.

## Email

SMTP se configura mediante `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` y `MAIL_FROM_ADDRESS`. Mantén credenciales en `.env`, nunca en documentación o Git.

`EmailContactService` fuerza simulación si se solicita, si la campaña está en dry-run, si su modo no es `automatic` o si el transporte es `log`/`array`. El envío real exige configuración explícita y supera controles de exclusión, estado, plantilla, datos faltantes y límites. El snapshot de puntuación se guarda antes del envío; un fallo no cuenta como contacto enviado en métricas.

No habilites correo real para validar una instalación. La cola de trabajo y la planificación de seguimientos no constituyen autorización para realizar envíos comerciales.

## Protección de datos y retención

Mantén bases SQLite y auxiliares, ZIP/CSV, exportaciones, logs, respaldos, capturas e informes personales fuera de Git. Los informes de Notion y de activación están en `storage/app/private/`; una referencia a su ubicación no autoriza publicarlos.

Los registros importados de Notion deben conservarse durante mantenimiento documental y verificaciones técnicas. Una operación de datos necesita alcance autorizado; cuando se exige preservación exacta compara fichas, contactos, procedencias e importaciones antes/después y exclúyelos de recálculos o consultas externas que modifiquen sus campos.

Revisa retención manualmente cada 30 días. Conserva copias verificadas previas a cambios y archivos referenciados por importaciones/consolidaciones. No hay borrado automático. La aplicación no tiene autenticación para exposición por red; cualquier túnel o publicación requiere controles de acceso previos.

## Validación técnica

La suite usa SQLite en memoria y almacenamiento/logs/vistas temporales; bloquea HTTP inesperado y simula procesos de scraping y correo:

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
node --check public/sales.js
npm run build
```

La compilación de vistas puede realizarse con `php artisan view:cache --no-interaction`; para comprobar sin tocar cachés operativas configura antes almacenamiento y vistas temporales como en un ensayo. No cambies el destino de base de las pruebas por la base operativa.

Los resultados y el alcance de la última ejecución se registran únicamente en [Estado](PROJECT_STATE.md); no prueban disponibilidad pública, entrega SMTP ni eficacia comercial.
