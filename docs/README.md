# Documentación de Scraper + CRM

Este índice organiza las instrucciones vigentes. Los informes de entregas anteriores están en el [archivo histórico](archive/README.md).

## Elegir un recorrido

| Necesidad | Documento principal |
| --- | --- |
| Empezar a usar el CRM | [Guía de uso](USER_GUIDE.md) |
| Arrancar o actualizar la aplicación | [Operación: instalación y actualización](OPERATIONS.md#instalación-y-actualización) |
| Importar un CSV | [Guía de uso: importar un CSV](USER_GUIDE.md#importar-un-csv) |
| Importar un ZIP de Notion con revisión | [Operación: importación de Notion](OPERATIONS.md#importación-de-notion) |
| Crear o recuperar un respaldo | [Operación: respaldos y recuperacion](OPERATIONS.md#respaldos-y-recuperación) |
| Consultar desde un agente o terminal | [Operación: CLI comercial](OPERATIONS.md#cli-comercial) |
| Entender una puntuación | [Scoring](SCORING.md) |
| Entender una evidencia o un sitio no verificado | [Enriquecimiento](ENRICHMENT.md) |
| Modificar el sistema | [Arquitectura](ARCHITECTURE.md) y [Modelo de datos](DATA_MODEL.md) |
| Conocer lo verificado y lo pendiente | [Estado](PROJECT_STATE.md) y [Backlog](BACKLOG.md) |
| Consultar cambios y evidencia de entregas | [Changelog](CHANGELOG.md) y [Archivo](archive/README.md) |

## Responsabilidad de cada documento

- [README del repositorio](../README.md): presentación, arranque rápido y acceso a los manuales.
- [Guía de uso](USER_GUIDE.md): tareas de la persona que opera el CRM, reglas de contacto y piloto comercial.
- [Operación](OPERATIONS.md): procedimientos técnicos, configuración, CLI, diagnóstico y recuperación.
- [Arquitectura](ARCHITECTURE.md): responsabilidades, dependencias y garantías compartidas.
- [Modelo de datos](DATA_MODEL.md): entidades, campos relevantes, relaciones y restricciones.
- [Enriquecimiento](ENRICHMENT.md): fuentes, extracción, conciliación, actualización y presupuestos.
- [Scoring](SCORING.md): perfiles, pesos, versiones, snapshots y evaluación histórica.
- [Estado](PROJECT_STATE.md): observaciones fechadas y límites de la última verificación.
- [Backlog](BACKLOG.md): pendientes priorizados con criterio de finalización.
- [Changelog](CHANGELOG.md): cambios resumidos y enlaces a su evidencia.

## Mantener la documentación

1. Actualiza el documento responsable cuando cambie un comportamiento. Desde otros documentos enlaza esa explicación.
2. Contrasta instrucciones con rutas, configuración, servicios y ayuda de Artisan. Distingue una función implementada de una propuesta o una prueba comercial pendiente.
3. Registra comandos de consulta y comandos que escriben o contactan por separado. Los ejemplos usan datos ficticios y requieren sustituir IDs y rutas.
4. Guarda recuentos operativos y resultados de pruebas en Estado, con fecha, alcance y limitaciones. Un proceso iniciado en una fecha no demuestra que siga activo después.
5. Añade un resumen al Changelog. Archiva los informes de entregas cerradas, con fecha y acceso al índice vigente; conserva sus resultados históricos.
6. Comprueba enlaces locales, anclas y rutas después de mover archivos. Si queda una contradicción sin resolver, documéntala como pendiente.
7. Mantén fuera de Git bases, ZIP, CSV, credenciales, logs, capturas e informes con datos personales. Sus ubicaciones y su manejo están en Operación.

Las reglas para agentes permanecen en [AGENTS.md](../AGENTS.md) y [CLAUDE.md](../CLAUDE.md); remiten a este índice sin duplicar los manuales.
