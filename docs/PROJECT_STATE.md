# Estado del proyecto

Última revisión documental y de esquema: **2026-10-15**. Este archivo es la referencia de resultados fechados; no demuestra que procesos locales iniciados entonces sigan activos. Los procedimientos vigentes están en [Operación](OPERATIONS.md), y los cambios en [Changelog](CHANGELOG.md).

## Sistema disponible

CRM local con cartera de prospectos/clientes/colaboradores, contactos de organización, servicios y renovaciones. Incluye campañas y oportunidades independientes, scraper con importación incremental, revisión de duplicados y consolidación, evidencia multifuente, puntuación por oferta, snapshots al contacto, cola asistida, respuestas/propuestas, experimentos, exportaciones y CLI comercial.

El entorno verificado utiliza PHP 8.5, Laravel 13.33.0, SQLite, Blade/JavaScript y Guzzle 8.2.0. Las dependencias reproducibles están en los archivos lock. No se añadieron infraestructura ni llamadas automáticas a APIs de IA. La app sigue siendo local y carece de autenticación para exposición por red.

## Datos y activación verificados

| Observación | Resultado y alcance |
| --- | --- |
| Esquema operativo | 13 migraciones aplicadas; las dos de enriquecimiento/scoring se activaron con autorización el 2026-10-15, batch 4. |
| Cartera | 134 registros: 105 de Google Maps y 29 identidades importadas de Notion. |
| Notion | 29 contactos asociados y 30 procedencias; fichas/contactos/procedencias/tres importaciones permanecieron exactamente iguales durante la activación. Se excluyeron del recálculo y del piloto. |
| Campaña de prueba | La campaña de Rosario se eliminó con autorización; los 105 prospectos se conservaron. En la revisión del esquema hay cero campañas y cero ofertas. |
| Puntuaciones | Se recalcularon localmente los 105 prospectos de Maps. El motor por oferta está activo; sin catálogo no hay filas de puntuación por oferta. |
| Piloto de sitios | Cinco prospectos de Maps consultados con autorización: uno verificado y cuatro desconocidos. Identidad principal conservada. OSM desactivado y sin envíos comerciales. |
| Integridad | SQLite correcto, cero errores de claves foráneas y cola vacía al cerrar la activación. |
| Procesos | Servidor local y worker `sales,default` iniciados al cerrar la activación, sin servicio permanente instalado. Verificar su estado antes de operar. |

La importación original de Notion está completada: 30 filas representan 29 identidades; las decisiones y excepciones se conservan en informes privados. No hay sincronización con su API. La restricción de preservar esa cartera durante mantenimiento continúa vigente.

Los respaldos, huellas y comprobaciones de activación permanecen en `storage/app/private/backups/`; los informes de importación, en `storage/app/private/notion-reports/`. Están fuera de Git y pueden contener datos personales. No se repitieron importaciones ni consultas externas durante la reorganización documental.

## Validación técnica registrada

Suite repetida antes de publicar la consolidación documental: **345 pruebas y 1629 aserciones correctas**. También pasaron Pint, sintaxis de 159 archivos PHP, compilación/sintaxis de 111 vistas temporales, JavaScript y build. La activación anterior verificó HTTP 200 de diez rutas/pantallas/exportaciones locales.

La suite usa SQLite en memoria, almacenamiento/logs/vistas temporales, HTTP bloqueado salvo simulación y procesos/correo simulados. La regresión de transporte inicializa cURL sin ejecutar una conexión; detectó el conflicto de opciones raw de proxy/redirección y pasa con las opciones de alto nivel. El piloto externo autorizado fue una operación separada de los tests.

Adquisición mantuvo siete consultas con uno y 25 prospectos sintéticos, frente a 52/340 de la auditoría inicial. Esto comprueba estabilidad del número de consultas, no carga de producción.

Los informes con detalle, escenarios y limitaciones están en [Archivo histórico](archive/README.md). La reorganización comprobó 246 enlaces locales y sus anclas, referencias a código y ayuda/opciones de 12 comandos Artisan. Conservó los 17 originales del archivo histórico y redujo la documentación vigente a 11 documentos. Las huellas confirmaron que no cambió código de aplicación ni la base operativa durante esta tarea. La revisión de archivos para publicación no encontró bases, ZIP, formatos de credenciales reconocibles ni valores de contacto de Notion.

## Limitaciones pendientes

- Catálogo comercial vacío: falta definir/cargar una oferta real antes de seleccionar por puntuación de producto.
- Pesos iniciales sin eficacia comercial demostrada; no hay ganadores A/B ni ajustes autónomos.
- Históricos sin snapshot permanecen desconocidos; no se rellenaron con información posterior.
- OSM desactivado; habilitarlo requiere revisar configuración y condiciones del endpoint.
- No se comprobaron entrega SMTP real, disponibilidad pública ni carga masiva con cartera operativa.
- JavaScript remoto y pruebas funcionales de compra/formulario quedan fuera del enriquecimiento; una publicación visible no demuestra que funcione toda la operación.

## Siguiente prioridad

Definir la oferta real y preparar una muestra pequeña, con autorización específica para las acciones comerciales. Los criterios están en [Backlog](BACKLOG.md); no hay una campaña histórica preconfigurada que deba reanudarse por defecto.
