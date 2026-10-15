> Archivo histórico del 2026-10-15. Conserva el contenido de la etapa; sus instrucciones y estados pueden estar superados. Consulta la [documentación vigente](../../README.md) y el [índice del archivo](../README.md). Las rutas y números de línea citados como texto corresponden al código de aquella revisión.

# Estado del proyecto

## Estado actual — 2026-10-15

Ampliación de enriquecimiento multifuente y scoring por oferta implementada. Evidencia trazable y caducable, extracción DOM/JSON-LD, firma de plataformas, presupuesto global, OSM opcional desactivado, puntajes explicados por oferta, snapshots al contacto y evaluación histórica por cohortes.

Activación autorizada completada el 2026-10-15: ambas migraciones aplicadas en batch 4, con respaldo previo, ensayo sobre copia real y respaldo posterior. Se eliminó únicamente la campaña de prueba de Rosario y sus relaciones de campaña; se conservaron los 134 registros. Las 29 fichas, 29 contactos, 30 procedencias y tres importaciones de Notion se compararon antes/después y permanecen exactamente iguales, incluidas sus fechas. Se excluyeron del recálculo y de las consultas externas. Se recalcularon 105 prospectos de Google Maps.

Aplicación local en `http://127.0.0.1:8000` y worker `sales,default` reiniciados, sin instalar servicios permanentes. Diez rutas/pantallas/exportaciones verificadas con HTTP 200. Cola vacía, integridad SQLite correcta y cero errores de claves foráneas. Piloto autorizado de cinco sitios: uno verificado y cuatro desconocidos; se mantuvo la identidad principal y OSM desactivado. No hubo envíos comerciales ni nuevas búsquedas de Maps.

Se corrigió una incompatibilidad real de Guzzle 8.2.0: las opciones raw `CURLOPT_PROXY` y `CURLOPT_FOLLOWLOCATION` se sustituyeron por las opciones de alto nivel ya configuradas (`proxy=''`, `allow_redirects=false`); se conserva `CURLOPT_RESOLVE`. Una regresión que inicializa el transporte real sin ejecutar la conexión falló antes y pasa después. Suite final: **345 pruebas y 1629 aserciones correctas**, Pint y sintaxis de archivos afectados correctos.

El catálogo operativo está vacío: no se crearon ofertas ficticias ni puntuaciones por oferta sin catálogo. El siguiente paso comercial es definir/cargar la oferta y revisar una muestra antes de ampliar el enriquecimiento. Los contactos históricos continúan sin snapshots inventados. Informes privados y respaldos en `storage/app/private/backups/enrichment-activation-20261015-*.json` y archivos SQLite asociados, fuera de Git.

Validación final: 345 pruebas y 1629 aserciones correctas, formato, 159 archivos PHP, 111 vistas temporales, JavaScript y build correctos. La entrega y validación final se registran en [ENRICHMENT_SCORING_REPORT.md](ENRICHMENT_SCORING_REPORT.md). Los pesos son hipótesis iniciales; no se afirmó mejora de ventas ni se ajustaron para producir distribuciones atractivas.


Referencia histórica de la etapa anterior: 2026-10-14

Implementación iniciada el 2026-10-13 y cerrada el 2026-10-14.

## Implementación integral de la auditoría

La entrega mantiene PHP 8.5, Laravel 13.33.0, SQLite, Blade y JavaScript en `/home/stefano/Documents/repositorios/scraperphp`. Los cambios T01–T12, D01–D05 y O01–O05 están integrados en los flujos existentes; el detalle de trazabilidad se encuentra en [BACKLOG.md](BACKLOG.md) y [ARCHITECTURE.md](ARCHITECTURE.md).

Se corrigieron identidad comercial, bloqueo de duplicados, reservas/reintentos y estados terminales de adquisición; seguridad del enriquecimiento con DNS público fijado y transferencia limitada; neutralización CSV; selección vacía/inválida; filtros por campaña; atribución de métricas; evidencia obsoleta; sincronización del principal; respaldo con claves foráneas; carga de pestañas bajo demanda y aislamiento de pruebas.

La ficha compara duplicados y permite consolidación revisada con respaldo, preservación de originales y transacción. La importación CSV analiza columnas, filas inválidas y candidatos antes de confirmar, y permite descargar diagnósticos de rechazo. Las oportunidades globales conservan todos los pivots de campaña y su historial contextual. Los clientes requieren una vinculación/habilitación explícita antes de contacto; no se activa venta adicional por importación.

El inicio muestra trabajo pendiente y renovaciones sin crear nuevas secuencias de negociación. `sales:inspect` ofrece lectura JSON de cartera, campañas, oportunidades, ejecuciones, acciones y métricas, y exportación común. Solo crea un CSV si se indica una ruta local nueva; no sobrescribe archivos ni envía contactos.

Los experimentos conectan asignación, preparación e intento realmente utilizado; las simulaciones y las ediciones manuales no fabrican muestras atribuidas. La evaluación de scoring muestra score/oferta actuales, resultados observados, tamaños de muestra y preparación manual opcional. No modifica pesos, elige ganador ni añade APIs de IA.

### Validación técnica registrada

- PHPUnit: 271 pruebas correctas de 271, 1265 aserciones, 12,138 segundos en la última ejecución en la ejecución registrada.
- PHP: sintaxis correcta en 127 archivos revisados.
- JavaScript: comprobación de sintaxis correcta.
- Frontend: `npm run build` correcto.
- Vistas Blade: 110 vistas compiladas con almacenamiento temporal y sintaxis correcta.
- Artisan: ayuda de CLI comercial y worker verificada.
- Pint aplicado al conjunto de PHP modificado.
- Medición sintética de Adquisición: 7 consultas con 1 lead y 7 con 25 leads en ciudades distintas, frente a 52/340 en la auditoría. Tiempos registrados: 11,39/5,70 ms; no representan rendimiento de producción.

Se usaron datos sintéticos, SQLite en memoria, recursos temporales y transportes/procesos externos simulados. No se hizo scraping real, envío comercial, importación sobre cartera real ni consolidación de registros operativos. El 2026-10-14, tras la autorización para completar los pendientes, se aplicaron las tres migraciones en la base operativa con respaldo verificado y sin tareas activas. Las huellas de las 35 tablas anteriores confirmaron que no cambiaron sus datos; se conservaron 134 registros y 29 contactos. Integridad SQLite correcta y cero errores de claves foráneas.

La aplicación quedó iniciada en `http://127.0.0.1:8000` y el worker `sales,default` con timeout de 900 segundos, sin supervisor ni servicio permanente nuevo. La cola operativa estaba vacía. Se verificaron las consultas CLI reales sin cambiar registros comerciales y Docker 29.1.3 disponible mediante inspección de solo lectura. Se conservaron dos jobs fallidos históricos de scraping del 2026-10-09; no se reintentaron ni borraron, porque un reintento podría iniciar una búsqueda real ajena a estas comprobaciones.

Se revisaron en navegador Inicio, ficha, oportunidades, comparador, cola con variante preparada, importación y métricas sobre una base temporal con datos sintéticos. El flujo CSV incorporó dos filas válidas, registró una rechazada y completó sus jobs. Un contacto sintético conservó la variante A y 30 segundos de preparación; las métricas reflejaron ambos valores. Se corrigieron las etiquetas técnicas del comparador. El navegador integrado no permitió controlar de forma fiable el diálogo nativo de confirmación: la cancelación continúa verificada por SalesConfirmationTest, sin afirmar una comprobación manual de ese diálogo.

La imagen Docker queda fijada a un digest verificado; las referencias heredadas exactamente iguales a `latest` se normalizan. La retención de archivos es manual, sin borrados automáticos. El servidor se mantiene local; exponerlo por red requiere controles de acceso previos. No se crearon commits automáticamente y se conservó el trabajo local anterior.

## CRM unificado — estado histórico de 2026-10-10

La aplicación local en `/home/stefano/Documents/repositorios/scraperphp` usa PHP 8.5 y Laravel 13.33.0. Cartera gestiona prospectos, clientes y colaboradores sobre `Lead`, con contactos de organización, prioridad manual y valores estimados. Servicios registra trabajos, propuestas vinculadas y renovaciones.

Se implementó el importador puntual `crm:import-notion`, con revisión por JSON, respaldo verificable e idempotencia. No hay sincronización con la API de Notion ni automatización nueva de WhatsApp.

La ampliación aditiva del esquema y cada aplicación de datos se realizaron después de un respaldo verificado. En esa fecha la cartera contenía 134 registros y 29 contactos asociados; los registros anteriores conservaban su contenido e historial. Este recuento histórico no es una nueva lectura de la cartera.

La migración del ZIP está resuelta: de las 31 filas, 30 tienen procedencia importada y representan 29 identidades comerciales. Dos fichas de una misma organización se unificaron como cliente y colaborador, con ambos contactos separados. El registro interno se clasificó como colaborador; la plantilla vacía se descartó por instrucción del usuario. No quedan filas pendientes de decisión para esta importación.

Se conservaron los valores originales de dos campos de teléfono que requieren revisión y se excluyeron las notas de siete fichas por seguridad. Una ficha Markdown sin correspondencia en el CSV permanece fuera del CRM. No se añadieron campañas, servicios, propuestas, contactos realizados ni movimientos históricos durante la migración.

Los informes y decisiones están en `storage/app/private/notion-reports/`, fuera de Git. `final-decisions.json` contiene las decisiones autorizadas; `final-applied.json` identifica la aplicación final y su respaldo; `final-resolution.json` registra el descarte de la plantilla. `final-post-import-preview.json` reconoce 30 procedencias ya importadas, omite únicamente la plantilla y no propone importaciones nuevas. Los informes anteriores conservan las etapas previas de revisión.

Se corrigieron dos problemas del importador: los marcadores de teléfono sin dígitos producían coincidencias falsas, y la incorporación de otro contacto podía completar los datos de contacto principal con los de otra persona. Las coincidencias ignoran los teléfonos no comparables y cada contacto conserva sus propios datos.

Verificación histórica de esa etapa: 175 pruebas y 743 aserciones correctas; Pint, claves foráneas, integridad de SQLite y respaldos, preservación de los datos anteriores e idempotencia mediante vista previa posterior. La comprobación previa de pantallas, compilación y JavaScript fue correcta. Los archivos de base, respaldos, informes y `.env` están excluidos de Git; la documentación conserva únicamente descripciones anónimas de los casos.

Las secciones siguientes conservan el historial de decisiones y verificaciones de etapas anteriores. La decisión previa de usar Notion como CRM se sustituye por esta implementación.

Fecha del estado inicial: 2026-09-24

## Objetivo

Construir un sistema local para adquirir, priorizar, contactar y medir oportunidades comerciales.

El objetivo principal es conseguir clientes reales.

El software es un medio. El resultado comercial es la prioridad.

## Entorno histórico de la planificación

- Entorno previsto entonces: WSL. Referencia histórica; la ejecución actual usa el repositorio Ubuntu indicado al inicio.
- Directorio anterior: `/home/stefa1235/ventas`; no es la ruta operativa actual.
- Repositorio Git: inicializado.
- Código existente: no hay código de aplicación.
- Documentación inicial: creada en `docs/`.

Herramientas detectadas:

- PHP: disponible.
- Composer: disponible.
- Node.js: disponible.
- npm: disponible.
- Docker: disponible.
- Git: disponible.
- `pdo_sqlite`: disponible.
- `sqlite3` para PHP: disponible.

## Decisiones de la planificación inicial

1. La fase de planificación está aprobada.
2. El MVP será local y de una sola persona.
3. La arquitectura base será Laravel.
4. Google Maps será la primera fuente de leads.
5. El scraping se integrará con una herramienta externa.
6. El scoring inicial usará reglas configurables.
7. La IA generativa será opcional mediante LLM Bridge.
8. No se usará una API paga como dependencia del MVP.
9. WhatsApp no será canal de cold outreach automatizado.
10. Decisión inicial: Notion como CRM personal. Sustituida el 2026-10-10 por el CRM unificado local.
11. La base de datos del MVP será SQLite.
12. El primer importador usará CSV.
13. La cola de contacto será manual asistida.
14. No habrá envío automático en P0.

## Repositorios evaluados

Ver `docs/REPOSITORY_EVALUATION.md`.

Selección inicial:

- Adquisición desde Google Maps: `gosom/google-maps-scraper`.
- Validación de email: no integrar en MVP inicial.
- CRM externo: no integrar en MVP inicial.
- Pipeline comercial completo externo: no integrar.

## P0 implementado

El primer incremento P0 está implementado.

Funciona este circuito:

1. Crear oferta.
2. Crear campaña.
3. Importar leads desde CSV.
4. Deduplicar por fuente, dominio, email, teléfono o nombre + ubicación.
5. Calcular scoring determinista básico.
6. Enviar leads útiles a cola manual.
7. Registrar contacto realizado fuera de la app.
8. Registrar respuesta.
9. Pausar seguimientos pendientes tras respuesta.
10. Clasificar respuesta.
11. Actualizar pipeline con historial.
12. Registrar propuesta con versión.
13. Registrar cierre o pérdida.
14. Ver métricas.
15. Exportar CSV.
16. Exportar JSON estructurado.
17. Descargar snapshot SQLite.

## Verificación histórica

- `php artisan migrate:fresh`: correcto.
- `php artisan test`: 7 tests correctos.
- `php artisan route:list`: rutas P0 registradas.
- Prueba manual de circuito completo: correcta.

La prueba manual importó dos leads.

También registró:

- contacto externo;
- respuesta interesada;
- follow-up pausado;
- propuesta ganada;
- métricas actualizadas.

## Arranque histórico en WSL

Este bloque conserva la instrucción de 2026-09-24; para operar hoy utiliza aplicación y worker descritos en README. En esa etapa se utilizaba:

```bash
cd /home/stefa1235/ventas
php artisan serve
```

Luego abre:

```text
http://127.0.0.1:8000
```

## Suite comercial implementada

Se amplió el sistema más allá del P0.

Ahora incluye:

- queries por campaña;
- orquestación de `gosom/google-maps-scraper`;
- dry-run de scraping;
- importación automática del CSV generado;
- enriquecimiento determinista;
- señales con evidencia;
- scoring configurable;
- asignación simple de oferta;
- cola operativa ampliada;
- plantillas con variables;
- email SMTP con dry-run;
- follow-ups;
- experimentos A/B;
- métricas segmentadas;
- LLM Bridge;
- exclusiones;
- kill switch;
- exportación analítica.

## UX de la etapa inicial

La aplicación ahora se organiza por:

- Inicio;
- Campañas;
- Leads;
- Configuración.

La campaña es el workspace principal.

Cada campaña contiene:

- Resumen;
- Leads;
- Adquisición;
- Contacto;
- Pipeline;
- Experimentos;
- Métricas.

La creación de campaña muestra solo decisiones operativas actuales:

- nombre;
- segmento;
- país;
- ciudad;
- oferta;
- fuente;
- límite de adquisición.

La fuente es un selector cerrado.

Canal, modo, CTA y follow-ups no son decisiones principales.

El modo queda fijo en `manual` mientras no exista mensajería automática real.

La vista de Leads tiene filtros en query string.

Esos filtros se reutilizan en:

- listado;
- exportación CSV;
- exportación JSON;
- `analytics_export.csv`;
- teléfonos normalizados;
- acciones masivas.

La exportación de teléfonos genera `telefonos.txt`.

El archivo contiene una sola línea.

Los números salen separados por comas.

El formato es `+54` seguido solo por dígitos.

## Enriquecimiento de la etapa inicial

El enriquecimiento sigue siendo determinista.

No usa IA.

No cambió el scoring.

Ahora detecta:

- teléfono válido o inválido;
- teléfono probablemente móvil;
- WhatsApp visible;
- email visible en la web;
- formulario visible;
- Instagram, Facebook y Doctoralia;
- sistemas de turnos conocidos;
- dependencia manual para pedir turnos;
- complejidad operativa por equipo, sedes o especialidades;
- sitio débil por poco contenido o título ausente.

El scoring preserva estas señales.

Los pesos y reglas de scoring no cambiaron.

La ficha de lead muestra:

- identidad;
- diagnóstico;
- score;
- señales;
- posibles duplicados;
- actividad;
- acciones comerciales.

## Verificación histórica de la suite inicial

- `php artisan view:clear`: correcto.
- `./vendor/bin/pint --dirty`: correcto.
- `php artisan test`: 19 tests correctos, 65 assertions.
- `php artisan route:list`: 49 rutas registradas.
- Render HTTP por tests: Inicio, Campañas, Workspace, Adquisición, Leads, Cola, Pipeline y Configuración.
- No se enviaron emails reales.
- No se contactaron leads reales.

## Corrección de adquisición real

Fecha: 2026-09-25

El scraper real ya no bloquea el request web.

Ahora la pantalla inicia el run y vuelve al workspace.

El comando `scraper:execute {run}` ejecuta o completa el run en segundo plano.

Se recuperó el ScrapeRun #3.

Resultado:

- estado: `completed`;
- filas crudas: 56;
- leads importados: 56;
- duplicados fuertes: 0;
- errores: 0.

## Validación real de scraper

Fecha: 2026-09-24

Integración validada con Docker:

- Imagen: `gosom/google-maps-scraper:latest`.
- Image ID: `e205c02913c5`.
- Versión del scraper: `v1.18.1-549e4b5`.
- Comando base real: `google-maps-scraper -input <archivo> -results <csv>`.
- CSV es el formato por defecto.
- `-json` cambia la salida a JSON.
- `-input` requiere un archivo con una query por línea.
- `-results` define el archivo de salida.

Ajuste aplicado:

- Se eliminó `-csv`, porque no existe.
- Se dejó de usar `-results` como límite, porque es ruta de archivo.
- Se eliminó `-fast-mode`, porque exige `-geo`.
- Se agregó archivo de entrada por query.
- Se usa Docker con volumen sobre `storage/app/scrapes`.
- Se mantiene `-lang es`, `-c 1` y `-depth` bajo.
- Como el scraper no tiene flag exacto de cantidad, la app limita filas importadas con `result_limit`.

Prueba real:

- Query: `psicólogos Córdoba Argentina`.
- Proxies: no usados.
- Evasión: no usada.
- Contactos: no realizados.
- Resultado del scraper: `completed`.
- CSV generado: correcto.
- Filas crudas: 20.
- Límite importado: 5 filas procesadas.
- Leads importados: 2.
- Duplicados detectados: 3.
- Errores: 0.
- Leads en cola: 2.
- Leads enriquecidos: 2.
- Componentes de scoring: 11.
- Contact attempts: 0.

Flujo validado:

```text
scraper -> CSV -> importación -> deduplicación -> enriquecimiento -> scoring -> cola
```

## Próximo bloque operativo previsto entonces

Referencia histórica: no demuestra que la campaña siga configurada ni autoriza ejecutarla.

Ejecutar piloto comercial P0.

Campaña configurada:

- `Piloto P0 salud Argentina`.
- Segmento: psicólogos, psiquiatras y consultorios pequeños.
- País: Argentina.
- Oferta: gestor de consultorio/turnos.
- Canal: email manual.
- Tamaño objetivo: 50 a 100 leads.
- Primer análisis: al completar 50 contactos procesados.

Ver protocolo en `docs/PILOT.md`.

Ejecutar campaña real y validar datos.

No continuar con:

- integraciones externas nuevas.

## Deuda técnica identificada en esa etapa

- El scoring tiene reglas iniciales simples.
- El importador CSV cubre encabezados comunes, no todos los formatos posibles.
- La UI de logs todavía es básica.
- Falta importación masiva de exclusiones.
- El pipeline no tiene drag-and-drop. Usa movimientos explícitos con historial.
- El período y la atribución de métricas eran pendientes de esa etapa; se corrigieron en la implementación cerrada el 2026-10-14.

## Documentos creados

- `docs/ARCHITECTURE.md`
- `docs/BACKLOG.md`
- `docs/CHANGELOG.md`
- `docs/DATA_MODEL.md`
- `docs/EXPERIMENTS.md`
- `docs/MVP_SCOPE.md`
- `docs/OUTREACH_RULES.md`
- `docs/PROJECT_STATE.md`
- `docs/REPOSITORY_EVALUATION.md`

## Riesgos abiertos

- Scraping de Google Maps puede infringir términos de servicio.
- La validación SMTP de emails puede fallar por bloqueos de proveedores.
- El envío outbound requiere reglas por país y canal.
- El sistema debe evitar automatizar mensajes reales durante pruebas.
