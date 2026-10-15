> Archivo histórico del 2026-10-15. Conserva el contenido de la etapa; sus instrucciones y estados pueden estar superados. Consulta la [documentación vigente](../../README.md) y el [índice del archivo](../README.md). Las rutas y números de línea citados como texto corresponden al código de aquella revisión.

# Evaluación de repositorios reutilizables

Fecha: 2026-09-24

## Problema

Necesitamos adquirir, enriquecer y gestionar leads sin crear herramientas complejas desde cero.

La estrategia obligatoria es:

BUSCAR -> EVALUAR -> REUTILIZAR -> INTEGRAR -> MODIFICAR SOLO SI ES NECESARIO.

## Criterios

Se evaluó:

- licencia;
- mantenimiento;
- documentación;
- instalación;
- Docker;
- API o CLI;
- formatos de exportación;
- complejidad;
- dependencia de servicios pagos;
- ajuste al MVP;
- riesgos legales y operativos.

Las estrellas no son criterio principal.

## Candidatos

### `gosom/google-maps-scraper`

Fuente: https://github.com/gosom/google-maps-scraper

Problema que resuelve:

- Extrae negocios desde Google Maps.
- Puede exportar datos útiles para prospección.

Ventajas:

- Licencia MIT.
- Tiene Docker.
- Tiene CLI.
- Tiene Web UI y REST API.
- Exporta CSV según la documentación revisada para el flujo CLI usado en el MVP.
- Puede exponer otros formatos o API según modo de ejecución.
- Permite reanudar ejecuciones.
- Puede extraer emails desde sitios web.
- Encaja con Google Maps como fuente prioritaria.
- La documentación muestra uso local.

Desventajas:

- Usa Playwright y navegador headless.
- Puede consumir CPU y memoria.
- Para ejecuciones grandes puede requerir proxies.
- Scraping de Google Maps tiene riesgo de términos de servicio.
- La opción SaaS y LeadsDB no son necesarias para el MVP.

Riesgos:

- Bloqueos, rate limits o cambios en Google Maps.
- Riesgo legal o contractual por scraping.
- No se debe evadir CAPTCHA ni límites técnicos.

Integración requerida:

- Ejecutar por Docker o binario.
- Guardar consultas por campaña.
- Importar CSV en el P0.
- Verificar soporte real antes de agregar JSON o JSONL.
- Conservar fuente, consulta, fecha, ubicación, segmento y campaña.
- Ejecutar siempre primero en dry-run o lote pequeño.

Decisión:

- Seleccionado para adquisición inicial.

Motivo:

- Resuelve la fuente prioritaria con CLI, Docker y formatos integrables.
- Reduce mucho el código propio.

### `reacherhq/check-if-email-exists`

Fuente: https://github.com/reacherhq/check-if-email-exists

Problema que resuelve:

- Verifica si un email puede existir sin enviar un mensaje.

Ventajas:

- Tiene backend HTTP por Docker.
- Tiene CLI.
- Se puede ejecutar localmente.
- Está orientado a validación de emails.

Desventajas:

- Licencia AGPL-3.0 para uso open-source compatible.
- Uso comercial propietario requiere licencia comercial.
- Requiere salida por puerto 25 para verificación SMTP.
- Muchos proveedores bloquean o degradan estas verificaciones.
- Puede dar falsos positivos o falsos negativos.

Riesgos:

- Riesgo legal/licencia si se integra dentro de una aplicación cerrada.
- Riesgo operativo por bloqueo SMTP.
- Puede afectar reputación si se usa sin límites.

Integración requerida:

- Mantenerlo como proceso externo.
- Registrar resultado y confianza.
- No usarlo para decidir envíos automáticamente sin revisión.

Decisión:

- No integrar en MVP inicial.
- Documentar como mejora futura.

Motivo:

- El costo legal y operativo supera el beneficio inicial.
- El MVP puede iniciar con emails obtenidos y revisión manual.

### `Bittencourt/pipelite`

Fuente: https://github.com/Bittencourt/pipelite

Problema que resuelve:

- CRM liviano con pipeline Kanban, contactos, organizaciones y actividades.

Ventajas:

- Licencia MIT.
- Tiene Docker Compose.
- Tiene importación y exportación CSV.
- Tiene REST API.
- Tiene componentes UI existentes.
- Cubre pipeline y seguimiento comercial.

Desventajas:

- Stack distinto al preferido: Next.js, PostgreSQL y Drizzle.
- Incluye autenticación y módulos que el MVP no necesita.
- Puede competir con Notion y aumentar alcance.
- Adaptarlo al flujo de experimentos puede costar más que crear una capa mínima.

Riesgos:

- Convertir el proyecto en un CRM generalista.
- Invertir tiempo en adaptar módulos no críticos.

Integración requerida:

- Evaluar API.
- Mapear leads, campañas y estados.
- Evitar duplicar el CRM personal de Notion.

Decisión:

- No integrar en MVP inicial.
- Usarlo como referencia de UI y flujo CRM.

Motivo:

- El prompt exige una herramienta interna, no un CRM completo.

### `AI-Invention/lead-pipeline`

Fuente: https://github.com/AI-Invention/lead-pipeline

Problema que resuelve:

- Pipeline de scraping, pitch y seguimiento outbound.

Ventajas:

- Licencia MIT.
- Enfocado en lead generation.
- Incluye scraping, envío y tracking.
- Código Python simple.

Desventajas:

- Requiere Apify, Google Sheets y SMTP.
- La configuración pide API keys.
- Automatiza envío de pitches.
- No encaja bien con intervención humana obligatoria.
- Tiene poca señal pública de adopción.

Riesgos:

- Dependencia de servicios externos.
- Mayor riesgo de automatizar outreach real.
- Puede empujar a un bot vendedor, que está prohibido por el alcance.

Integración requerida:

- Separar scraping, envío y tracking.
- Desactivar automatización de envío.
- Reescribir mucho flujo para cumplir reglas.

Decisión:

- No integrar.

Motivo:

- No cumple bien con MVP local, sin APIs pagas y asistido por humano.

### `XgeniousLLC/geniousCRM`

Fuente: https://github.com/XgeniousLLC/geniousCRM

Problema que resuelve:

- CRM completo en Laravel con contactos, leads, deals, tareas, reportes y API.

Ventajas:

- Licencia MIT.
- Stack compatible con Laravel.
- Tiene Docker.
- Incluye pipeline y reportes.
- Tiene API REST.

Desventajas:

- Es un CRM amplio.
- Incluye roles, permisos, módulos y funciones no necesarias.
- Puede aumentar el alcance por encima del MVP.
- Requiere adaptación fuerte para campañas, experimentos y adquisición.

Riesgos:

- Construir sobre una base demasiado grande.
- Dificultar cambios rápidos orientados a aprendizaje comercial.

Integración requerida:

- Instalar y evaluar localmente.
- Desactivar módulos no necesarios.
- Adaptar modelo de datos.

Decisión:

- No integrar en MVP inicial.
- Reconsiderar si el CRUD propio crece demasiado.

Motivo:

- Aunque usa Laravel, excede el alcance de una herramienta interna de una persona.

## Selección inicial

Seleccionar `gosom/google-maps-scraper` para adquisición desde Google Maps.

No seleccionar herramientas CRM completas para el MVP.

No seleccionar validación SMTP en el MVP inicial.

## Arquitectura de integración propuesta

1. Crear campañas y consultas en la app local.
2. Exportar consultas a un archivo de entrada.
3. Ejecutar `gosom/google-maps-scraper` fuera del núcleo.
4. Importar resultados CSV o JSONL.
5. Deduplicar por dominio, teléfono, email, fuente y nombre + ubicación.
6. Enriquecer con reglas deterministas simples.
7. Calcular score explicable.
8. Asignar oferta sugerida y cola de contacto.

## Fuentes consultadas

- `gosom/google-maps-scraper`: README de GitHub. Indica Docker, CLI, CSV/JSON, REST API y licencia MIT.
- `reacherhq/check-if-email-exists`: README de GitHub. Indica Docker, CLI y licencia AGPL-3.0/comercial.
- `Bittencourt/pipelite`: README de GitHub. Indica Docker Compose, REST API, CSV y licencia MIT.
- `AI-Invention/lead-pipeline`: README de GitHub. Indica Apify, Google Sheets, SMTP y licencia MIT.
- `XgeniousLLC/geniousCRM`: README de GitHub. Indica Laravel, Docker, REST API y licencia MIT.
