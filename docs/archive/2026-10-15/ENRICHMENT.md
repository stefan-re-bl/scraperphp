> Archivo histórico del 2026-10-15. Conserva el contenido de la etapa; sus instrucciones y estados pueden estar superados. Consulta la [documentación vigente](../../README.md) y el [índice del archivo](../README.md). Las rutas y números de línea citados como texto corresponden al código de aquella revisión.

# Enriquecimiento determinista

Estado de código: 2026-10-15. Las dos migraciones de 2026-10-14 se activaron con autorización el 2026-10-15. El piloto real se limitó a cinco prospectos de Maps: uno verificado y cuatro desconocidos; Notion quedó excluido e intacto. Se corrigió la configuración de proxy/redirecciones para Guzzle 8.2.0, conservando las protecciones.

## Flujo y fuentes

`EnrichLead` conserva la cola existente y evita trabajos simultáneos del mismo prospecto. `LeadEnricher` obtiene observaciones de importación/Google Maps, web registrada, DNS/HTTP y contexto del CRM. OpenStreetMap es opcional y está desactivado. El historial CRM se marca como contexto y no se usa como predictor de éxito.

La web registrada aporta evidencia antes que identidad: no reemplaza nombre, email, teléfono ni dirección principales. `SecondaryIdentityResolver` compara nombre, dominio, teléfono y ciudad/dirección normalizados. Un dominio o teléfono compartidos no bastan para distinguir sucursales. Las correspondencias ambiguas y los conflictos quedan pendientes; los servicios de otra entidad no se incorporan como hechos propios. La persona puede confirmar un email público vigente desde la ficha.

## Presupuesto y transporte

Valores iniciales en `config/enrichment.php`: cinco páginas HTML como máximo, 120000 bytes por página, 600000 bytes totales y 25 segundos totales. Los límites máximos del código son cinco páginas, 600000 bytes y 30 segundos. Cada solicitud tiene hasta ocho segundos. `robots.txt` usa hasta 16000 bytes dentro del presupuesto total; por ello cinco páginas completas de 120000 bytes no están garantizadas. Los fallos reservan su límite de bytes de forma conservadora.

Las páginas se procesan secuencialmente. Una reserva compartida en SQLite limita a una solicitud simultánea por host entre trabajos, con intervalo inicial de un segundo. No se crean servicios adicionales. El descubrimiento sigue hasta cuatro enlaces internos explícitos pertinentes, sin parámetros ni fragmentos, priorizados por contacto, servicios, reservas y catálogo. No recorre el sitio ni abre enlaces de terceros, recursos, archivos, sesiones o checkout.

Cada destino pasa por el transporte seguro existente: solo HTTP/HTTPS en 80/443, IPv4/IPv6 públicos, todas las respuestas DNS públicas, DNS fijado a cURL, sin redirecciones, proxies, ejecución JavaScript ni autenticación. No se interpreta HTML parcial o contenido binario. DNS/HTTP no prueban entregabilidad de correo, actividad ni necesidad comercial.

`RobotsPolicy` selecciona reglas para el bot, aplica Allow/Disallow, codificación de rutas y Crawl-delay. Conserva políticas hasta 24 horas; si robots no puede verificarse, omite el rastreo y conserva el flujo de otras fuentes. Un 404/410 permite continuar. Esta implementación es conservadora y acotada; no certifica por sí sola el permiso contractual de cada sitio. Referencia: [RFC 9309](https://www.rfc-editor.org/rfc/rfc9309).

## Extracción y evidencia

`HtmlCommercialExtractor` usa DOM/XPath y JSON-LD, con límites de tamaño/profundidad; un bloque inválido no invalida contactos válidos del resto de la página. Extrae organizaciones/negocios, ContactPoint, productos, servicios, metadatos, mailto/tel, redes, WhatsApp, formularios, reservas y controles de compra. Las firmas pequeñas de plataformas son propias y están en configuración. Una palabra aislada como Calendly no demuestra instalación.

`signals` conserva tipo, valor, fuente, referencia, fecha, método, confianza 0–1, revisión, caducidad, versión, clave lógica e indicador `is_current`. Los tipos de evidencia son `observed`, `inferred`, `unknown` y `conflict`. Las inferencias requieren revisión. El HTML completo no se guarda. La misma fuente/referencia/tipo se reconcilia de forma idempotente; un cambio significativo vuelve a pendiente, conserva hasta cinco observaciones previas y archiva señales desaparecidas. No se borran revisiones manuales.

La caché por prospecto/fuente compara la huella de entrada; TTL inicial 24 horas y vigencia de evidencia 30 días. Una entrada cambiada invalida la caché. La acción de revisar sitio de la ficha solicita actualización forzada: no omite seguridad, robots ni frecuencia. Los errores son datos desconocidos; no crean defectos de venta.

## OpenStreetMap opcional

Para habilitarlo se requieren endpoint Overpass explícito, aceptación de sus condiciones, User-Agent con contacto y coordenadas públicas válidas en `LeadSource.metadata.coordinates`. CSV acepta latitude/longitude, lat/lon/lng. No hay geocodificación ni consultas por nombres de toda una ciudad, ni Nominatim.

La consulta limita radio a 100 metros inicialmente (máximo 150), categorías amenity/shop/office y hasta 20 resultados, con timeout de consulta de cinco segundos, respuesta hasta 120000 bytes y frecuencia mínima de 60 segundos por host. La aceptación exige correspondencia fuerte, proximidad comprobada y un único candidato. TTL positivo mínimo 24 horas, inicial 168; fallo o ambigüedad una hora. Un fallo no bloquea el enriquecimiento independiente.

La ficha conserva atribución y licencia; los exports mantienen metadata. Antes de habilitar, revisar condiciones del endpoint y la distribución pretendida: [recursos públicos Overpass](https://dev.overpass-api.de/overpass-doc/en/preface/commons.html), [OpenStreetMap y ODbL](https://www.openstreetmap.org/copyright). La configuración explícita no garantiza que una instancia pública acepte una carga determinada. No se validó ninguna instancia con solicitudes reales.

## Operación y pruebas

Revisar señales y procedencia desde la ficha o `sales:inspect evidence`. Ejecutar jobs de enriquecimiento solo después de migrar una base autorizada. Las lecturas de UI, export y CLI no disparan enriquecimiento ni recalculan puntuaciones. Las pruebas usan HTTP simulado y bloquean solicitudes no previstas. Sin comprobación SMTP ni envíos comerciales.
