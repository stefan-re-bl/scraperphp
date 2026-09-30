# Arquitectura

## Modelo general

El sistema tendrá una app local y herramientas externas.

La app local orquesta el proceso.

Las herramientas externas hacen trabajos mecánicos.

La persona decide en los puntos de confianza comercial.

## Componentes

```text
Web UI local
  -> Orquestador Laravel
  -> LeadFilterService
  -> Base de datos local
  -> Procesos externos seguros
  -> Herramientas externas
       -> Google Maps scraper
       -> Enriquecimiento determinista
       -> SMTP configurable
       -> Exportaciones
```

## App local

Primera opción:

- Laravel.
- SQLite.
- Jobs simples.
- UI mínima con componentes estándar.

Responsabilidades:

- campañas;
- leads;
- deduplicación;
- scoring;
- ofertas;
- estados del pipeline;
- cola de contacto;
- respuestas;
- propuestas;
- métricas;
- exportación CSV;
- exportación JSON;
- snapshot SQLite.
- scraper orquestado;
- plantillas;
- follow-ups;
- experimentos;
- LLM Bridge sin API;
- métricas segmentadas;
- blacklist y exclusiones.

## Herramientas externas

### Google Maps scraper

Herramienta seleccionada:

- `gosom/google-maps-scraper`.

Uso inicial:

- Docker o binario local.
- Entrada: consultas generadas por campaña.
- Salida inicial: CSV.
- Importación controlada por la app.

La integración inicial importa CSV.

Antes de agregar otro formato, verificar soporte en la versión instalada.

El binario se configura con:

- `GOOGLE_MAPS_SCRAPER_BIN`
- `GOOGLE_MAPS_SCRAPER_TIMEOUT`

## Flujo principal

1. Abrir campaña.
2. Obtener leads.
3. Revisar y filtrar leads.
4. Enviar leads seleccionados a cola.
5. Contactar desde la cola asistida.
6. Registrar respuesta.
7. Avanzar oportunidad en pipeline.
8. Registrar propuesta.
9. Medir campaña.
10. Exportar vista, selección o snapshot.

## Capa compartida de filtros

`LeadFilterService` centraliza consultas de leads.

La usan:

- listado global;
- workspace de campaña;
- acciones masivas;
- exportación CSV;
- exportación JSON;
- `analytics_export.csv`.

Los filtros viven en query string.

Esto permite compartir vistas y exportar la misma selección.

## Límites técnicos actuales

No incluir en MVP:

- multiusuario;
- roles complejos;
- Kubernetes;
- microservicios;
- IA autónoma;
- envío masivo sin revisión;
- integración automática con Notion;
- CRM completo;
- diseño visual avanzado.

No incluir infraestructura pesada:

- Redis;
- microservicios;
- Kubernetes;
- Elasticsearch.

## Seguridad operativa

Cada integración nueva debe iniciar en dry-run.

Debe existir:

- rate limit;
- límite diario;
- lista de exclusión;
- blacklist;
- kill switch de campaña;
- logs;
- cancelación de secuencia;
- historial de estado.
