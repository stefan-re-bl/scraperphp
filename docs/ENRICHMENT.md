# Enriquecimiento determinista

Referencia de fuentes, extracción y vigencia. Para solicitar/revisar evidencia utiliza la [Guía de uso](USER_GUIDE.md#revisar-evidencia-y-puntuaciones); para configurar y diagnosticar consulta [Operación](OPERATIONS.md). Las puntuaciones se explican en [Scoring](SCORING.md).

## Fuentes y responsabilidades

| Fuente | Aporte y límite |
| --- | --- |
| Google Maps/importación | Descubrimiento e identificadores existentes; conserva procedencia sin sustituir el scraper. |
| Sitio registrado | Contactos publicados, servicios, catálogo, reservas y firmas técnicas observables. No reemplaza automáticamente identidad principal. |
| DNS/HTTP | Validación de destino público y observaciones de transporte. No prueba entrega de email ni actividad comercial. |
| CRM | Contexto de historial propio, marcado como no predictor. No aporta resultados de la oportunidad como señales de éxito. |
| OpenStreetMap | Corroboración pública opcional mediante consulta geográfica acotada. Está desactivado por defecto. |

[LeadEnricher](../app/Services/LeadEnricher.php) coordina el flujo. [EnrichLead](../app/Jobs/EnrichLead.php) conserva la cola y evita trabajos simultáneos del mismo prospecto. Las fuentes secundarias no son requisitos obligatorios; sus fallos no crean defectos comerciales.

## Presupuesto y frecuencia

Valores de [enrichment.php](../config/enrichment.php):

| Control | Valor inicial |
| --- | ---: |
| Páginas HTML por prospecto | 5 como máximo. |
| Cuerpo por página | 120000 bytes; prevalece un límite anterior menor de `services.enrichment.max_response_bytes`. |
| Bytes contabilizados totales | 600000, incluidos robots, fallos y OSM. |
| Presupuesto temporal | 25 segundos, con tope de configuración de 30. |
| Solicitud individual | Hasta 8 segundos. |
| Robots | Hasta 16000 bytes dentro del total; caché hasta 24 horas. |
| Intervalo por host | 1 segundo inicialmente; se respeta un Crawl-delay mayor. |
| TTL por prospecto/fuente | 24 horas inicialmente; compara también la huella de entrada. |
| Vigencia de evidencia | 30 días inicialmente. |

Las páginas se procesan secuencialmente. [EnrichmentRequestBudget](../app/Services/EnrichmentRequestBudget.php) usa reservas compartidas en SQLite para una solicitud simultánea por host entre trabajos. Los fallos contabilizan su límite reservado de forma conservadora. Cinco páginas completas de 120000 bytes no están garantizadas porque otras solicitudes comparten el presupuesto.

El presupuesto limita nuevas solicitudes y sus tiempos; no promete una latencia exacta del DNS del sistema o del proceso PHP. La reserva tiene token y caducidad para recuperarse de una interrupción.

## Descubrimiento y seguridad de transporte

[WebsiteDiscovery](../app/Services/WebsiteDiscovery.php) toma hasta cuatro enlaces internos explícitos desde la portada, priorizando contacto, servicios, reservas y catálogo. Exige el mismo origen, elimina fragmentos y omite parámetros, recursos, archivos, sesiones, checkout y recorridos repetitivos. No rastrea todo el sitio ni ejecuta JavaScript.

Cada destino pasa por [PublicWebsiteFetcher](../app/Services/PublicWebsiteFetcher.php): HTTP/HTTPS, puertos 80/443, IPv4/IPv6 públicos y todas las respuestas DNS A/AAAA públicas. cURL fija la dirección validada, conserva hostname/TLS, desactiva proxy/redirecciones y limita el cuerpo mediante [LimitedWebsiteStream](../app/Services/LimitedWebsiteStream.php). Respuestas comprimidas o tipos/contenidos no permitidos se rechazan; no se interpreta HTML parcial.

El cliente configura `proxy=''` y `allow_redirects=false` mediante opciones de alto nivel; solo la fijación de DNS utiliza `CURLOPT_RESOLVE`. La prueba de compatibilidad inicializa el transporte real sin ejecutar una conexión. No desactives esas garantías para lograr mayor cobertura.

## Robots

[RobotsPolicy](../app/Services/RobotsPolicy.php) selecciona el grupo del bot y resuelve Allow/Disallow con patrones, codificación de rutas y prioridades. Cada página debe estar permitida. Un 404/410 de robots permite continuar; si su política no puede verificarse, se omite el rastreo y se mantienen las otras fuentes. Los fallos de política se conservan temporalmente para evitar repeticiones inmediatas.

La implementación es conservadora y acotada; no certifica permiso contractual de cada sitio. Referencia técnica: [RFC 9309](https://www.rfc-editor.org/rfc/rfc9309). Los límites, bloqueos y condiciones de la fuente siguen siendo aplicables.

## Extracción y firmas

[HtmlCommercialExtractor](../app/Services/HtmlCommercialExtractor.php) utiliza DOM/XPath y JSON-LD con límites de tamaño/profundidad. Un bloque malformado no invalida los contactos válidos del resto del documento. Extrae organizaciones/negocios, ContactPoint, productos, servicios, metadatos de generador, enlaces mailto/tel, redes, WhatsApp, formularios, reservas y controles de compra.

El catálogo de firmas es pequeño y propio: metadatos, encabezados, hosts/rutas de recursos, clases y enlaces específicos. Contempla WordPress, WooCommerce, Shopify, Wix, Tiendanube y plataformas de reservas/formularios/widgets. Una palabra aislada como Calendly no demuestra instalación. Una firma ausente tampoco demuestra ausencia de tecnología.

Una publicación o un enlace demuestra que existe ese elemento visible; no se prueban transacciones, formularios, disponibilidad del servicio ni todo el funcionamiento de la web. Consultar HTTP no afirma que falte HTTPS.

## Identidad y conciliación

[SecondaryIdentityResolver](../app/Services/SecondaryIdentityResolver.php) compara nombre, dominio, teléfono y ciudad/dirección normalizados. Compartir dominio o teléfono no distingue sucursales: la página debe aportar identidad suficiente para atribuir contactos y servicios. Un conflicto fuerte conserva observaciones y requiere revisión; los datos comerciales de otra entidad no se incorporan como propios.

Sin contradicciones, el dominio ya registrado puede aportar evidencia con corroboración limitada. La confianza refleja esa limitación. Las tecnologías de dominio pueden mantenerse como hechos técnicos sin trasladar servicios/contactos de una sucursal ambigua.

La fuente secundaria no sustituye nombre, email, teléfono o dirección principales. Un contacto público discrepante se conserva para revisión. Adoptar un email requiere una acción explícita sobre evidencia confirmada, vigente y no desconocida/conflictiva.

## Evidencia y actualización

[Signal](../app/Models/Signal.php) conserva tipo/valor, fuente/referencia, observación, método, confianza, revisión, expiración, versión, metadata y clave lógica. [Modelo de datos](DATA_MODEL.md#signals--signal) describe sus campos.

| Clase | Significado y uso |
| --- | --- |
| `observed` | Hecho publicado o comprobado dentro del alcance declarado. Puede confirmarse al extraerse. |
| `inferred` | Inferencia respaldada, por ejemplo dependencia manual a partir de un texto explícito. Requiere revisión y conserva la etiqueta de inferencia. |
| `unknown` | Información no verificada o fallo técnico; no demuestra ausencia o necesidad. |
| `conflict` | Observaciones o identidad incompatibles; conserva procedencia y solicita revisión. |

[EvidenceReconciler](../app/Services/EvidenceReconciler.php) reconcilia fuente/referencia/tipo sin duplicar observaciones lógicas. Un cambio significativo vuelve a pendiente y conserva hasta cinco observaciones previas. Las desaparecidas se archivan, no se borran; las manuales y sus revisiones se preservan. No se guarda HTML completo.

La huella de entrada invalida la caché cuando cambia un dato relevante. La acción de revisión explícita fuerza actualización, pero no evita robots, validación pública ni frecuencia. Lecturas de ficha, CLI y export no disparan enriquecimiento.

## OpenStreetMap opcional

Habilitarlo requiere `ENRICHMENT_OSM_ENABLED`, endpoint Overpass explícito, aceptación de condiciones, User-Agent con contacto y coordenadas públicas válidas en `LeadSource.metadata.coordinates`. CSV admite latitude/longitude y alias lat/lon/lng, validados por rango. No usa Nominatim ni busca nombres de toda una ciudad.

[OsmEvidenceSource](../app/Services/OsmEvidenceSource.php) limita radio inicialmente a 100 m (máximo 150), categorías amenity/shop/office y hasta 20 candidatos, con timeout de consulta de cinco segundos, respuesta hasta 120000 bytes y frecuencia mínima de 60 segundos por host. Solo acepta una coincidencia fuerte, próxima y única. TTL inicial positivo 168 horas, mínimo 24; fallo/ambigüedad una hora.

Las evidencias conservan referencia, atribución y licencia; la ficha las muestra y metadata las acompaña al exportar. Antes de habilitar o distribuir datos revisa [condiciones de recursos Overpass](https://dev.overpass-api.de/overpass-doc/en/preface/commons.html) y [OpenStreetMap/ODbL](https://www.openstreetmap.org/copyright). La configuración no garantiza que una instancia pública permita una carga determinada.
