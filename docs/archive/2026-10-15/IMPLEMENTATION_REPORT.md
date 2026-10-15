> Archivo histórico del 2026-10-15. Conserva el contenido de la etapa; sus instrucciones y estados pueden estar superados. Consulta la [documentación vigente](../../README.md) y el [índice del archivo](../README.md). Las rutas y números de línea citados como texto corresponden al código de aquella revisión.

# Informe de implementación — Scraper + CRM de CRT Web

Inicio: 2026-10-13. Cierre: 2026-10-14. Repositorio: `/home/stefano/Documents/repositorios/scraperphp`.

## A. Resumen ejecutivo

Se implementaron los hallazgos T01–T12, D01–D05 y las funciones O01–O05 sobre Laravel 13, PHP 8.5, SQLite, Blade, JavaScript y la cola existentes. Se conservaron las modificaciones previas y no se crearon commits ni dependencias nuevas. El código está integrado y validado con datos sintéticos; la activación del esquema en la base operativa se completó.

La adquisición conserva sucursales ambiguas, bloquea contacto pendiente de revisión, recupera importaciones ocupadas y vincula el éxito del scraping al de su importación. El enriquecimiento valida y fija destinos públicos, limita el cuerpo antes de cargarlo y reconcilia evidencia. Los filtros, oportunidades, mensajes, experimentos y métricas utilizan el contexto comercial correspondiente.

Se incorporaron comparación y consolidación revisada, trabajo diario, análisis completo de CSV, descarga de rechazos, CLI comercial uniforme y evaluación exploratoria de scoring. Ninguna comprobación envió mensajes, ejecutó scraping real o importó, consolidó, eliminó o restauró registros de la cartera operativa.

## B. Correcciones realizadas

| Hallazgos | Resultado integrado | Evidencia principal |
|---|---|---|
| T01 / D01 | Contradicciones de fuente, identidad y ubicación impiden consolidar recursos compartidos. Coincidencias inequívocas siguen siendo idempotentes. Duplicados posibles quedan fuera de cola y las rutas directas los rechazan hasta resolución explícita. | AcquisitionIntegrityTest, ImplementationIntegrationTest, NotionImportTest |
| T02 | Importador y scraper distinguen ocupado/completado/cancelado/fallido. Reservas con token y caducidad, checkpoint conservado, reprogramación de ocupado y protección frente a propietario anterior. CSV válido reutilizado y scraper no finaliza anticipadamente. | AcquisitionIntegrityTest, ScraperRuntimeTest, ScraperExecutionTest |
| T03 | Normalización IPv4/IPv6/dominio; todas las A/AAAA deben ser públicas. Transporte cURL fijado al destino aprobado, sin proxy/redirecciones, puertos 80/443, descarga máxima de 120000 bytes. | EnrichmentSafetyTest |
| T04 / T06 | CSV normal, analítico, CLI y rechazados comparten neutralización de fórmulas. Selección vacía/nula/inválida nunca devuelve toda la cartera; límite de 2000 IDs conservado. | CommercialAuditTest, CommercialCliTest, AcquisitionIntegrityTest |
| T05 / T07 | Filtros de oferta, fecha, canal, respuesta y estado por campaña. Respuestas asociadas al intento vinculado; propuesta/cierre al último contacto real anterior de esa oportunidad. Exportación analítica incluye oportunidad e intento de respuesta. | CommercialAuditTest, CommercialCliTest |
| T08 | Señales automáticas obsoletas retiradas en transacción; evidencia vigente conserva revisión, evidencia diferente exige revisión; notas manuales intactas. Duplicados automáticos tras fusión reconciliados. | EnrichmentSafetyTest |
| T09 | Lead y contacto principal se actualizan juntos desde ficha, CRM e importación Notion. Eliminar contacto conserva la proyección como antes. | CommercialAuditTest, UnifiedCrmTest, NotionImportTest |
| T10 | Respaldo rechaza relaciones rotas mediante foreign_key_check antes de restauración, además de integridad, estructura y hash. | AcquisitionIntegrityTest, DuplicateConsolidationTest y pruebas de recuperación existentes |
| T11 / T12 | Datos calculados por pestaña, selección sin carga obligatoria de relaciones, IDs reutilizados y CSV por lotes. Pruebas con DB, almacenamiento, logs, vistas, cache y servicios aislados desde bootstrap. | ImplementationIntegrationTest y suite completa |
| D02 / D04 | Cancelar confirmación de formulario impide envío y estado ocupado. Documentación inicia Laravel local y worker sales,default y explica diagnóstico/reanudación. | SalesConfirmationTest; ayuda Artisan y revisión de documentación |
| D03 / D05 | Pipeline global representa cada oportunidad; historial filtrable. Cliente requiere habilitación explícita por campaña. Asignación A/B integrada a preparación y variante realmente utilizada; muestra derivada de contactos reales. | CommercialAuditTest, ImplementationIntegrationTest, OfferMessagingTest |

La matriz de [IMPLEMENTATION_PROGRESS.md](IMPLEMENTATION_PROGRESS.md) identifica componentes, verificaciones y límites por ID. [CODE_REVIEW.md](CODE_REVIEW.md) conserva la evidencia original; sus conclusiones son históricas y no describen el estado corregido.

## C. Funcionalidades implementadas

**O01 — Revisión y consolidación:** la ficha abre el comparador, permite buscar otra identidad, elegir principal y declarar negocios diferentes. La vista muestra conflictos, datos compatibles y relaciones. Cada diferencia relevante exige decisión: identidad/estado/fecha, contacto principal, oportunidad compartida, secuencia completa, propuesta y variante. La confirmación CONSOLIDAR usa un hash de revisión; cambios posteriores o tareas activas impiden aplicar. Se verifica un respaldo antes de escribir, se archivan originales y decisiones, y se trasladan procedencias, contactos, servicios, intentos, respuestas, propuestas, historial y referencias en una transacción. Un error revierte todos los movimientos; repetir la misma consolidación es idempotente. Para personas, los datos alternativos siguen en el archivo original; organizaciones conservan contactos asociados. Las decisiones sobre estados/variantes activos no eliminan el registro original.

**O02 — Trabajo diario:** Inicio reúne respuestas pendientes, vencimientos, oportunidades sin fecha, negociación/propuestas y renovaciones próximas o vencidas. Orden por prioridad, fecha e ID, con acceso directo a la ficha. CLI consulta el mismo servicio. No fabrica seguimientos para negociaciones ni crea contactos.

**O03 — CSV:** análisis completo con columnas reconocidas/faltantes, asignación de campos, filas inválidas, candidatos dentro del propio archivo y cartera, advertencias y hash antes de confirmar. Se reutiliza el importador con checkpoints. Los errores persistidos incluyen fila/campo/motivo, sin guardar la fila completa. La descarga de rechazos vuelve a leer el original y añade motivos; contiene datos del archivo y requiere el mismo tratamiento privado. El resumen limita incidencias a 500 y la descarga incluye todas. El POST /imports directo continúa por compatibilidad, ejecutando análisis; el nuevo flujo UI exige revisión previa.

**O04 — CLI:** sales:inspect ofrece leads, campaigns, opportunities, runs, actions, metrics y export. Reutiliza filtros y servicios web; salida JSON con IDs estables; códigos 0 éxito, 2 argumentos inválidos y 1 fallo. Límites 100 por defecto/2000 máximo. Solo --output crea un CSV nuevo; no sobrescribe ni modifica registros comerciales. Filtros incompatibles se rechazan. README incluye ejemplos para agentes.

**O05 — Evaluación:** resultados observados por rango de score y oferta recomendada actuales, tiempo de preparación manual opcional y advertencia de muestras inferiores a 30. El Bridge utiliza evidencia confirmada depurada. No hay API IA, pesos nuevos, ganador A/B automático ni inferencia de causalidad.

## D. Simplificaciones y rendimiento

Se separó selección de carga de relaciones en LeadFilterService, se centralizó sincronización del contacto y se utiliza el pivot para oportunidades. Los contadores históricos de experimentos se conservan por compatibilidad, pero la muestra visible se deriva de intentos reales. Se mantuvo NotionImporter y los servicios que ya resolvían su responsabilidad; no se añadieron capas genéricas ni infraestructura.

| Escenario sintético de adquisición | Auditoría anterior | Implementación |
|---|---:|---:|
| 1 prospecto / 1 ciudad | 52 consultas | 7 consultas |
| 25 prospectos / 25 ciudades | 340 consultas | 7 consultas |

Ambas mediciones usaron SQLite temporal y HTTP 200. La última ejecución midió 11,39 y 5,70 ms frente a aproximadamente 50 y 111 ms de la auditoría. Los tiempos dependen del entorno; la conclusión reproducible es la eliminación del crecimiento de consultas al abrir adquisición. No constituyen un benchmark de cartera real ni una medición de memoria para miles de registros. CSV conserva procesamiento incremental; JSON conserva materialización por compatibilidad.

La imagen Docker predeterminada usa el digest instalado verificado `gosom/google-maps-scraper@sha256:e205c02913c5a69c16fc2094b8e5b194a2f655166d2c1126d50548d216e6b1b2`. La configuración normaliza ese antiguo latest oficial al digest y respeta imágenes personalizadas. Se inspeccionó la imagen local sin ejecutarla ni descargar otra. README y documentación separan operación vigente de antecedentes WSL/Notion.

## E. Pruebas

**Suite definitiva: 271/271 pruebas satisfactorias, 1265 aserciones, 12,138 segundos en la última ejecución.** Base anterior: 175 pruebas y 743 aserciones. Se conservó la cobertura previa y se añadieron 96 pruebas.

Se verificaron los escenarios integrados solicitados:

- A: proceso Maps simulado → CSV → análisis y confirmación → importación → normalización/duplicados → HTTP simulado → score y oportunidad.
- B: cola → mensaje de variante → contacto sintético → seguimiento → respuesta que pausa → propuesta/cierre e historial.
- C: sucursales ambiguas bloqueadas, declaración explícita de diferencia y consolidación de equivalentes preservando relaciones y respaldo.
- D: checkpoint, reserva activa/caducada, nuevo propietario, reprogramación y recuperación, cancelación/reintento.
- E: varias campañas con ofertas, contactos/estados distintos, métricas por intento y exportación analítica por oportunidad.
- F: CLI y web comparten selección; consultas no alteran tablas comerciales y exportación explícita no sobrescribe archivos.

Comprobaciones: Pint dirty global; sintaxis de 127 archivos PHP y public/sales.js; build Vite; compilación y sintaxis de 110 vistas en almacenamiento temporal; ayudas Artisan; git diff --check. PHPUnit también verifica actualización desde esquema anterior y rollback sintético. Las confirmaciones JavaScript se ejecutaron con DOM simulado.

Se revisaron en navegador Inicio, ficha, oportunidades, comparador, cola con variante preparada, importación y métricas sobre una base temporal con datos sintéticos. El flujo CSV incorporó dos filas válidas, registró una rechazada y completó sus jobs. Un contacto sintético conservó la variante A y 30 segundos de preparación; las métricas reflejaron ambos valores. Se corrigieron las etiquetas técnicas del comparador. El navegador integrado no permitió controlar de forma fiable el diálogo nativo de confirmación: la cancelación continúa verificada por SalesConfirmationTest, sin afirmar una comprobación manual de ese diálogo. La evidencia visual con datos sintéticos se conserva en `storage/app/private/browser-validation-metrics-20261014.jpg` y las capturas de panel, cola e importación del mismo directorio.

Durante integración se corrigieron fallos reproducibles en rollback SQLite de dos migraciones anteriores (índices antes de quitar columnas), sincronización del principal desde Notion, secuencias completas en fusión, fecha global sin campaña, señales repetidas, proyección antigua en fusiones consecutivas y sintaxis del nuevo resumen de comparación. También se corrigió la idempotencia de sucursales sin identificador compartiendo dominio/email, y el reintento de una importación cancelada vinculada al scraper. Las nuevas regresiones fallaron antes de los cambios y pasan después. No se eliminaron ni debilitaron pruebas para ocultar defectos.

## F. Limitaciones

El 2026-10-14, tras la autorización para completar los pendientes, se aplicaron las tres migraciones en la base operativa con respaldo verificado y sin tareas activas. Las huellas de las 35 tablas anteriores confirmaron que no cambiaron sus datos; se conservaron 134 registros y 29 contactos. Integridad SQLite correcta y cero errores de claves foráneas.

Respaldo previo: `storage/app/private/backups/ventas-20261014-155908-f522eaa1-5b77-41aa-a04c-3df448575113.sqlite`, SHA-256 `b41c5dddbba3105fa5578357d5a84331021d5ea56c735fa953ff9b7bb8867015`. Respaldo posterior compatible con el nuevo esquema: `storage/app/private/backups/ventas-20261014-160333-43af4fbb-7e5f-4b5d-8c8b-2d84e3aca389.sqlite`, SHA-256 `5cbefcb6b0f29450c3960c22d9b1671a26ac73b402ee287fbb704bbfc6f708ad`. Ambos verificados y con permisos privados; detalle agregado en `storage/app/private/backups/activation-20261014.json`.

La aplicación quedó iniciada en `http://127.0.0.1:8000` y el worker `sales,default` con timeout de 900 segundos, sin supervisor ni servicio permanente nuevo. La cola operativa estaba vacía. Se verificaron las consultas CLI reales sin cambiar registros comerciales y Docker 29.1.3 disponible mediante inspección de solo lectura. Se conservaron dos jobs fallidos históricos de scraping del 2026-10-09; no se reintentaron ni borraron, porque un reintento podría iniciar una búsqueda real ajena a estas comprobaciones. No se efectuó ninguna reparación de identidades históricas.

No se verificaron scraping/contenedor real, terminación de contenedor, entrega SMTP, disponibilidad HTTP pública, conversión comercial ni carga masiva. Los transportes externos fueron simulados. ext-curl es obligatorio para enriquecimiento seguro; sin él se rechaza la solicitud. Se restringe HTTP a puertos estándar y respuestas sin compresión.

La evaluación de score utiliza el valor actual y no captura el valor que tenía al contactar. Sus resultados son exploratorios, sensibles a selección y cambios posteriores. La variante registra el mensaje preparado/usado, sin demostrar efecto causal. El tiempo de preparación depende de registro manual.

Las rutas continúan sin autenticación para uso estrictamente local. Compartir la herramienta por red o túnel requiere controles de acceso previos. La retención de CSV, ZIP, logs y respaldos es manual, sin borrado automático; respaldos y archivos de consolidación pueden contener datos personales. Credenciales existentes no se modificaron ni publicaron.

No estaba disponible la skill testing-best-practices referida por AGENTS.md. Se aplicaron reglas del repositorio y convenciones PHPUnit; no se instaló infraestructura ni se eludió la validación.

## G. Recomendaciones

Con el esquema activado, realizar un piloto comercial supervisado que registre tiempos y resultados con una muestra suficiente. Si se necesita evaluar retrospectivamente el poder predictivo del score, definir primero una captura del score al contacto: los datos actuales no prueban esa hipótesis. Ajustes de pesos o nuevas APIs IA deberán justificarse con esa evidencia. No hay otro defecto confirmado pendiente de código dentro del alcance revisado; las verificaciones externas permanecen separadas de la activación y la validación local.
