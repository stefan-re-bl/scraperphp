# Ventas

Sistema local para adquisición, seguimiento y medición comercial.

El MVP usa Laravel y SQLite.

Trabaja siempre en WSL nativo:

```bash
cd /home/stefa1235/ventas
```

## Ejecutar

```bash
php artisan migrate
php artisan serve
```

Abre:

```text
http://127.0.0.1:8000
```

## Tests

```bash
php artisan test
```

## Flujo operativo

1. Abre Inicio.
2. Entra a una campaña.
3. Obtén leads desde la pestaña Adquisición.
4. Revisa leads con filtros persistentes.
5. Envía leads seleccionados a cola.
6. Contacta desde la cola diaria.
7. Registra respuestas y propuestas.
8. Mueve oportunidades en Pipeline.
9. Revisa métricas dentro de la campaña.
10. Exporta la vista actual o la selección.

## Suite comercial

La app también permite:

- definir queries por campaña;
- ejecutar scraper externo;
- enriquecer leads;
- ver señales y score;
- usar plantillas;
- registrar email con dry-run;
- planificar follow-ups;
- ejecutar experimentos;
- revisar métricas segmentadas;
- usar LLM Bridge;
- gestionar exclusiones;
- detener campañas;
- exportar `analytics_export.csv`.

## Scraper

Configura el binario externo:

```bash
GOOGLE_MAPS_SCRAPER_BIN=google-maps-scraper
GOOGLE_MAPS_SCRAPER_TIMEOUT=300
```

Uso validado con Docker:

```bash
GOOGLE_MAPS_SCRAPER_IMAGE=gosom/google-maps-scraper:latest
GOOGLE_MAPS_SCRAPER_USE_DOCKER=true
```

Versión validada:

```text
v1.18.1-549e4b5
```

La app no evade CAPTCHA ni límites.

Empieza siempre con dry-run.

## Exportaciones

La app permite descargar:

- CSV de la vista filtrada;
- CSV de leads seleccionados;
- TXT de teléfonos argentinos normalizados;
- JSON con leads filtrados;
- `analytics_export.csv` filtrado;
- snapshot SQLite.

El snapshot SQLite sigue siendo completo.

## Límites

El P0 no envía mensajes.

El P0 no usa IA.

El P0 no automatiza WhatsApp, email ni redes sociales.
