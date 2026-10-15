> Archivo histórico del 2026-10-15. Conserva el contenido de la etapa; sus instrucciones y estados pueden estar superados. Consulta la [documentación vigente](../../README.md) y el [índice del archivo](../README.md). Las rutas y números de línea citados como texto corresponden al código de aquella revisión.

# CRM comercial de CRT Web

Aplicación local en Laravel 13 y PHP 8.5 para adquisición, prospección y gestión de clientes y colaboradores. Usa SQLite y conserva el flujo de campañas, contactos, propuestas y métricas.

## Enriquecimiento y puntuación por oferta — 2026-10-15

Se incorporaron evidencias multifuente, hasta cinco páginas pertinentes del sitio, firmas deterministas, puntuación por oferta y snapshots inmutables al contactar. La ficha explica señales, incertidumbre, procedencia y vigencia; listados, cola, exports y CLI comparten el contexto de oferta. Las métricas históricas usan puntuaciones conocidas al contactar.

**Activada el 2026-10-15**, con respaldo previo/posterior y ensayo sobre una copia de la base real. Se borró la campaña de prueba de Rosario conservando los prospectos; las 29 fichas y 29 contactos importados de Notion permanecen exactamente iguales. Aplicación y worker locales reiniciados. Piloto de cinco sitios de Google Maps: uno verificado y cuatro desconocidos, sin envíos comerciales. El catálogo de ofertas sigue vacío; requiere definición comercial antes de obtener puntuaciones por oferta.

Documentación: [enriquecimiento](ENRICHMENT.md), [scoring y CLI](SCORING.md) e [informe de entrega](ENRICHMENT_SCORING_REPORT.md). OpenStreetMap está desactivado y requiere configuración y revisión de condiciones antes de usarlo.

## Ejecutar

Desde `/home/stefano/Documents/repositorios/scraperphp`, con PHP 8.5, Composer y las extensiones SQLite y cURL disponibles. La aplicación es de uso local: abre [http://127.0.0.1:8000](http://127.0.0.1:8000).

Antes de actualizar una base existente, detén la aplicación y el worker, comprueba que no haya tareas activas y crea un respaldo:

```bash
php artisan sales:backup --no-interaction
php artisan migrate --no-interaction
```

En una instalación nueva sin base anterior, ejecuta las migraciones sin el paso de respaldo. La auditoría anterior se activó el 2026-10-14 con respaldo verificado. Las dos migraciones de enriquecimiento/scoring se activaron después, el 2026-10-15, con nueva autorización y preservación exacta de Notion. Los comandos anteriores describen el procedimiento para futuras actualizaciones.

Inicia la aplicación y el procesamiento de colas en dos terminales:

```bash
php artisan serve --host=127.0.0.1 --port=8000 --no-interaction
```

```bash
php artisan queue:work --queue=sales,default --timeout=900 --tries=3 --no-interaction
```

`artisan serve` no inicia el worker. Las búsquedas, importaciones y enriquecimientos permanecen pendientes si falta el segundo proceso. La cola SQLite usa `retry_after=1200` segundos por defecto, superior al timeout del worker; conserva esa relación si cambias los límites. Reinicia el worker después de actualizar código o configuración.

### Revisar y recuperar tareas detenidas

Consulta los estados sin iniciar trabajo:

```bash
php artisan sales:inspect runs --limit=20 --no-interaction
php artisan queue:failed --no-interaction
```

Revisa también Adquisición e Importaciones de la campaña: muestran fase, contadores y errores. Confirma primero que el worker esté activo y revisa el diagnóstico local en `storage/logs/`. Una reserva vigente indica trabajo ocupado; la aplicación lo reprograma y solo recupera reservas caducadas. No cambies tokens ni checkpoints manualmente.

Usa **Reintentar** sobre una ejecución fallida o cancelada revisada. Si existe un CSV válido, se reutiliza y la importación continúa desde su checkpoint; un CSV cambiado se rechaza. El scraping se declara completado después de completar la importación. Si no hay CSV reutilizable, el reintento puede iniciar una búsqueda real: no lo uses para comprobar el sistema. La suite sintética verifica caídas y recuperación sin Docker, scraping ni contactos reales.

## Flujo operativo

1. Obtén registros desde la adquisición de una campaña o créalos en Cartera.
2. Revisa filtros, scoring y posibles duplicados.
3. Gestiona la cola manual, respuestas, oportunidades y propuestas.
4. Clasifica los registros como clientes o colaboradores. Ambas relaciones pueden coexistir.
5. Añade contactos a organizaciones y servicios a clientes desde su ficha.
6. Consulta Servicios para ver renovaciones pendientes, incluidas las vencidas.
7. Exporta la vista o descarga un respaldo completo.

`campaign_lead.status` representa cada oportunidad por campaña; el pipeline global muestra todas ellas. `Lead.status` conserva una proyección compatible y el estado de los registros sin campaña. El estado de ejecución de un servicio y la prioridad manual son campos independientes. Registrar un cliente o servicio no crea ventas, pagos ni contactos históricos.

## Importar el ZIP de Notion

El importador usa el CSV más completo, preferentemente `_all.csv`, y correlaciona fichas Markdown por identificador o propiedades inequívocas. No extrae el ZIP al disco ni guarda notas con posibles credenciales. Los informes incluyen identidades comerciales, identificadores y motivos de revisión; se guardan con permisos privados y deben permanecer fuera de Git.

Genera una vista previa y un archivo inicial de decisiones:

```bash
php artisan crm:import-notion /ruta/export-notion.zip \
  --report=storage/app/private/notion-reports/preview.json \
  --write-decisions=storage/app/private/notion-reports/decisions.json \
  --no-interaction
```

La vista previa no modifica registros comerciales. El archivo inicial usa `import` para registros listos y `skip` para registros pendientes. Revisa y edita las decisiones usando las claves del informe. Consulta el procedimiento y los ejemplos en [docs/ARCHITECTURE.md](ARCHITECTURE.md).

Después de editar las decisiones, genera otra vista previa:

```bash
php artisan crm:import-notion /ruta/export-notion.zip \
  --decisions=storage/app/private/notion-reports/decisions.json \
  --report=storage/app/private/notion-reports/reviewed-preview.json \
  --no-interaction
```

Solo con autorización expresa para importar el resultado revisado:

```bash
php artisan crm:import-notion /ruta/export-notion.zip \
  --decisions=storage/app/private/notion-reports/decisions.json \
  --approved-report=storage/app/private/notion-reports/reviewed-preview.json \
  --apply --report=storage/app/private/notion-reports/applied.json \
  --no-interaction
```

La aplicación verifica que el ZIP, las decisiones y la cartera coincidan con la vista previa. Genera un respaldo verificable antes de escribir y aplica los registros listos en una transacción. Repetir un export no duplica identidades, contactos ni notas. Los registros pendientes no se importan. El estado `Cerrado 💪` requiere clasificación humana y no se convierte automáticamente en `WON`.

## Scraper y contacto

La adquisición usa `gosom/google-maps-scraper` con búsquedas reales, en segundo plano. El tiempo límite configurado es 600 segundos. Las coincidencias por fuente consideran los registros y sus procedencias. Un email o dominio compartido no basta para consolidar sucursales con identidad o ubicación contradictoria. Las coincidencias ambiguas se conservan como posibles duplicados y quedan bloqueadas en la cola y en las acciones directas de contacto. Los clientes reconocidos no se incorporan automáticamente a otra campaña ni a la cola.

Los mecanismos de contacto existentes conservan sus controles y la confirmación manual. Esta ampliación no añade automatización de WhatsApp ni sincronización permanente con Notion.

## Importación CSV y duplicados

Desde Importar, sube el CSV, selecciona campaña y correspondencia de columnas, y analiza el archivo antes de confirmar. La vista previa muestra columnas reconocidas/faltantes, filas inválidas, posibles duplicados y advertencias. La confirmación verifica el archivo y el análisis aprobado. La importación usa el parser existente, checkpoints y reservas; el resultado permite descargar un CSV privado de filas rechazadas, que relee el archivo original e incluye sus celdas y motivos. Las incidencias almacenadas y logs conservan solo fila, campo y motivo, sin duplicar filas completas.

La ficha de un posible duplicado abre un comparador. Puedes confirmar que son negocios distintos o seleccionar una ficha principal, resolver conflictos de campos, contactos, campañas y propuestas, revisar la vista previa y confirmar la consolidación. Se verifica un respaldo antes de escribir y se conserva el historial original en una transacción. No hay fusión automática de registros históricos.

## Oportunidades, trabajo diario y experimentos

Cada oportunidad conserva oferta, estado, próxima acción e historial de su campaña. La ficha permite filtrar actividad por campaña. Los clientes existentes requieren vinculación o habilitación expresa de la oportunidad para contactar; las importaciones y el cambio a cliente no activan venta adicional. El inicio reúne respuestas pendientes, vencimientos, propuestas, oportunidades sin fecha y renovaciones de los próximos 30 días, con acceso a la ficha. Una negociación no genera otra secuencia inicial.

En Experimentos, crea variantes con una plantilla compatible y asigna una al prospecto. La cola prepara su mensaje. El intento registra la variante cuando el texto y asunto realizados coinciden con lo preparado; una edición manual pierde esa atribución automática. Las muestras proceden de contactos reales, excluyen simulaciones y distinguen experimentos con variantes del mismo nombre. No se elige un ganador automáticamente.

Métricas evalúa rangos del **puntaje conocido al contactar**, por campaña, oferta y versión. Los contactos anteriores sin snapshot figuran como desconocidos. Los intentos repetidos de una cohorte no duplican el denominador. La exploración del estado actual permanece separada de esta evaluación histórica. El tiempo de preparación se puede registrar manualmente en segundos al contactar. Las muestras pequeñas son exploratorias: no prueban causalidad ni justifican cambios automáticos de pesos. El LLM Bridge conserva el intercambio manual con evidencia confirmada y depurada; no incorpora llamadas automáticas a APIs de IA.

## CLI comercial para agentes

`sales:inspect` consulta los mismos servicios y filtros que la web. Siempre devuelve JSON con `ok`, `resource` y `data`, o `ok=false` y `error`. Códigos de salida: `0` correcto, `2` argumentos inválidos, `1` fallo operativo. El límite predeterminado es 100 filas; acepta entre 1 y 2000. Una selección `ids` vacía o inválida devuelve cero filas.

```bash
php artisan sales:inspect leads --filters='{"q":"Consultorio","min_score":45,"has_email":true}' --limit=50 --no-interaction
php artisan sales:inspect scores --filters='{"score_offer_id":1}' --no-interaction
php artisan sales:inspect evidence --ids=1 --filters='{"evidence_review":"confirmed"}' --no-interaction
php artisan sales:inspect campaigns --no-interaction
php artisan sales:inspect opportunities --campaign=1 --filters='{"status":"PROPOSAL"}' --no-interaction
php artisan sales:inspect runs --campaign=1 --no-interaction
php artisan sales:inspect actions --campaign=1 --ids=1,2 --no-interaction
php artisan sales:inspect metrics --campaign=1 --dimension=channel --filters='{"from":"2026-10-01","to":"2026-10-13"}' --no-interaction
php artisan sales:inspect export --dataset=leads --campaign=1 --ids=1,2 --no-interaction
```

Los IDs son ejemplos: consulta la cartera para obtener los reales. `campaigns` y `runs` admiten únicamente el filtro de campaña. Para filtrar estado o actividad de oportunidades indica `--campaign`; así no se mezcla su pivot con el estado global. `actions` admite filtros de prospectos, incluida una selección de IDs.

`export` devuelve filas JSON por defecto. La única escritura de este comando es crear un CSV local cuando indicas una ruta explícita y nueva; conserva la selección y el límite y rechaza sobrescribir archivos:

```bash
php artisan sales:inspect export --dataset=leads --campaign=1 --limit=100 --output=storage/app/private/campaign-list-new.csv --no-interaction
```

Los comandos de consulta no programan contactos, envían mensajes ni modifican registros comerciales.

## Enriquecimiento y operación local

El enriquecimiento solo acepta HTTP/HTTPS, puertos 80/443 y destinos públicos. Verifica IPv4/IPv6 y resoluciones A/AAAA, rechaza respuestas DNS con direcciones no públicas, fija el destino validado mediante cURL y desactiva proxies y redirecciones. Limita la descarga a 120000 bytes por defecto (`LEAD_ENRICHMENT_MAX_RESPONSE_BYTES`); requiere cURL y rechaza transportes incapaces de fijar la conexión. El presupuesto agregado, robots y límites por host están en `config/enrichment.php`; OSM está apagado por defecto. Las señales automáticas se reconcilian de forma atómica; la evidencia humana vigente se conserva y los cambios requieren nueva revisión.

Docker queda fijado al digest verificado `gosom/google-maps-scraper@sha256:e205c02913c5a69c16fc2094b8e5b194a2f655166d2c1126d50548d216e6b1b2`. Una configuración heredada exactamente igual a `gosom/google-maps-scraper:latest` se normaliza a ese digest. No se descargó ni ejecutó una búsqueda real como validación de esta entrega.

La app local conserva su modelo sin autenticación adicional. Antes de exponerla por red se requieren controles de acceso; mantén el servidor ligado a `127.0.0.1`.

## Exportación y recuperación

Se mantienen CSV, JSON, teléfonos, análisis comercial y snapshot SQLite. CSV y JSON también incluyen contactos y servicios. Los importes nuevos se exportan en unidades menores enteras (`amount_minor`, `estimated_value_minor`) junto con su moneda; 100 unidades menores equivalen a una unidad monetaria. `NULL` representa un importe desconocido; cero representa un importe conocido igual a cero.

Los CSV normales y analíticos usan una serialización común que neutraliza fórmulas y conserva comillas, saltos de línea, teléfonos y UTF-8. El CSV analítico contiene una fila por oportunidad, conserva los registros sin campaña y añade `reply_attempt_id`, `reply_channel` y `reply_variant`. Las respuestas se atribuyen al intento vinculado; los avances segmentados usan el último contacto real anterior de la misma oportunidad, como asociación y no como causalidad.

La verificación de respaldos revisa integridad, SHA-256, esquema y claves foráneas SQLite; rechaza relaciones rotas antes de restaurar.

Para verificar un respaldo compatible sin restaurarlo:

```bash
php artisan sales:restore /ruta/respaldo.sqlite --no-interaction
```

Para restaurarlo, detén antes el worker y las tareas activas. Tras revisar el archivo y autorizar la sustitución:

```bash
php artisan sales:restore /ruta/respaldo.sqlite --force --no-interaction
```

La restauración conserva un snapshot previo. Un respaldo anterior a la ampliación del esquema requiere el procedimiento de recuperación descrito en `docs/ARCHITECTURE.md`.

## Privacidad y revisión de archivos

Revisa manualmente CSV, informes, logs y respaldos cada 30 días. Conserva el último respaldo verificado, los respaldos previos a cambios y los archivos referenciados por importaciones o consolidaciones. Revisa si los temporales antiguos aún son necesarios antes de decidir su eliminación. Los datos privados permanecen fuera de Git; no compartas informes completos con credenciales o datos personales. No se incorporó eliminación automática ni se borraron archivos existentes.

## Verificar

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
php artisan view:cache --no-interaction
```

Las pruebas usan datos sintéticos, SQLite en memoria, almacenamiento/logs/vistas temporales, transporte HTTP y procesos externos simulados. La validación final tras activación y corrección del transporte es de 345 pruebas y 1629 aserciones; formato, sintaxis de 159 archivos PHP, 111 vistas temporales, JavaScript y build correctos. El detalle y los límites constan en `docs/PROJECT_STATE.md`. No ejecutes `migrate:fresh` sobre datos reales.

## Activación verificada — 2026-10-14

El 2026-10-14, tras la autorización para completar los pendientes, se aplicaron las tres migraciones en la base operativa con respaldo verificado y sin tareas activas. Las huellas de las 35 tablas anteriores confirmaron que no cambiaron sus datos; se conservaron 134 registros y 29 contactos. Integridad SQLite correcta y cero errores de claves foráneas.

La aplicación quedó iniciada en `http://127.0.0.1:8000` y el worker `sales,default` con timeout de 900 segundos, sin supervisor ni servicio permanente nuevo. La cola operativa estaba vacía. Se verificaron las consultas CLI reales sin cambiar registros comerciales y Docker 29.1.3 disponible mediante inspección de solo lectura. Se conservaron dos jobs fallidos históricos de scraping del 2026-10-09; no se reintentaron ni borraron, porque un reintento podría iniciar una búsqueda real ajena a estas comprobaciones.

La instancia visual de pruebas se cerró al terminar. Las capturas con datos sintéticos y el informe de activación se conservan en almacenamiento privado. Las búsquedas reales y la entrega SMTP no se utilizaron para validar esta actualización.
