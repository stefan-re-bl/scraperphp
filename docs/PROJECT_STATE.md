# Estado del proyecto

Fecha: 2026-09-24

## Objetivo

Construir un sistema local para adquirir, priorizar, contactar y medir oportunidades comerciales.

El objetivo principal es conseguir clientes reales.

El software es un medio. El resultado comercial es la prioridad.

## Entorno

- Entorno obligatorio: WSL.
- Directorio del proyecto: `/home/stefa1235/ventas`.
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

## Decisiones actuales

1. La fase de planificación está aprobada.
2. El MVP será local y de una sola persona.
3. La arquitectura base será Laravel.
4. Google Maps será la primera fuente de leads.
5. El scraping se integrará con una herramienta externa.
6. El scoring inicial usará reglas configurables.
7. La IA generativa será opcional mediante LLM Bridge.
8. No se usará una API paga como dependencia del MVP.
9. WhatsApp no será canal de cold outreach automatizado.
10. Notion seguirá como CRM personal para clientes ganados.
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

## Cómo ejecutar

Usa siempre el directorio WSL nativo:

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

## UX operativa actual

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

## Enriquecimiento actual

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

## Verificación actual

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

## Próximo bloque operativo

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

## Deuda técnica real

- El scoring tiene reglas iniciales simples.
- El importador CSV cubre encabezados comunes, no todos los formatos posibles.
- La UI de logs todavía es básica.
- Falta importación masiva de exclusiones.
- El pipeline no tiene drag-and-drop. Usa movimientos explícitos con historial.
- Las métricas segmentadas de campaña reutilizan la capa existente y todavía pueden mejorar en período y aislamiento visual.

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
