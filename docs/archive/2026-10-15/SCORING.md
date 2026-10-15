> Archivo histórico del 2026-10-15. Conserva el contenido de la etapa; sus instrucciones y estados pueden estar superados. Consulta la [documentación vigente](../../README.md) y el [índice del archivo](../README.md). Las rutas y números de línea citados como texto corresponden al código de aquella revisión.

# Scoring por oferta y evaluación histórica

Activación del esquema completada el 2026-10-15. Se recalcularon los 105 prospectos de Google Maps; los 29 registros y 29 contactos de Notion se preservaron sin cambios. La base operativa no tiene ofertas configuradas: hasta cargar el catálogo no hay evaluaciones por oferta, aunque el motor y las consultas están activos. No se generaron snapshots históricos.

## Algoritmo inicial

Cada par prospecto/oferta tiene un resultado 0–100 en `lead_offer_scores`. Se calculan adecuación (`fit`), necesidad observable (`need`), contactabilidad (`contact`) y calidad de evidencia (`evidence`). El total es la suma ponderada, redondeada y acotada: 35 % adecuación + 35 % necesidad + 15 % contactabilidad + 15 % evidencia. No es una probabilidad de compra.

`config/commercial_scoring.php` define perfiles booking, web, ecommerce y generic. La oferta puede seleccionar perfil explícito en la UI (`rules.scoring_profile`); en su ausencia se detecta por el nombre y se conserva generic como alternativa prudente. Reglas por oferta de `scoring_rules` conservan sus pesos. Los pesos son hipótesis iniciales, sin validación comercial ni ajuste automático.

Dentro de adecuación y necesidad se toma el mayor aporte aplicable, sin sumar copias o conceptos correlacionados. Las plataformas existentes limitan necesidad: reservas y compra online aplican un máximo inicial de 35 cuando corresponde. Tener datos completos no demuestra intención ni máxima prioridad. Una web desconocida, un timeout o no detectar calendario no aportan necesidad ni penalización. Las evidencias pendientes, rechazadas, vencidas, retiradas o de identidad conflictiva no generan oportunidades confirmadas.

| Perfil | Ejemplos de adecuación | Ejemplos de necesidad |
| --- | --- | --- |
| booking | Negocio con reservas 80; servicio publicado 30 | Dependencia manual revisada 100; complejidad revisada 40 |
| web | Oferta clara 50; servicio 40; web verificada 30 | Problema observado 80; formulario roto confirmado 90 |
| ecommerce | Venta de productos 80; catálogo 60 | Pedidos manuales revisados 100; catálogo sin compra inferida y revisada 70 |
| generic | Oferta clara 50 | Problema observado 60 |

La compatibilidad de palabras clave aporta inicialmente 70. Los hechos del mismo sitio, incluidos DNS y HTTP, se agrupan como un origen; aumentar el número de páginas o señales no simula corroboración independiente. Confianza y cobertura se muestran separadas. Umbrales iniciales de recomendación: confianza 0,35 y cobertura 0,25; la UI advierte evidencia limitada por debajo de 0,50.

## Versiones y validez

La versión combina `commercial-v1` con huella de la configuración. La explicación guarda perfil, pesos, reglas, evidencia utilizada, incertidumbre, contradicciones y estado relevante de oferta, reglas e inputs. `current()` comprueba estos estados, cambios incluso en el mismo segundo y caducidad. Lecturas nunca recalculan: una puntuación ausente o desactualizada se muestra sin evaluar. El estado CRM y resultados posteriores no son predictores ni invalidan por sí solos un cálculo.

`LeadScorer::score()` conserva `score_total` y componentes globales por compatibilidad, sin el antiguo premio por web desconocida, y calcula las ofertas activas y las seleccionadas explícitamente aunque estén archivadas. La sugerencia no cambia el pivot ni reemplaza una oferta seleccionada en campaña. Bloqueos de identidad, exclusiones y consentimiento siguen siendo independientes.

En listados, `score_offer_id` selecciona una oferta. En campaña se usa la oferta de su oportunidad; la cola global determina primero ese contexto y aplica su puntaje. La ausencia de una fila vigente no se sustituye por el puntaje global. Solo contextos sin oferta conservan la compatibilidad global, identificada como tal. Los filtros mínimo/máximo excluyen puntuaciones desconocidas.

## Snapshot inmutable al contactar

`ScoreSnapshotService` registra JSON una sola vez al realizar un contacto manual o al reservar un envío real antes de SMTP. Incluye prospecto, campaña/oportunidad, oferta, total, dimensiones, confianza/cobertura, versión, fechas y evidencia utilizada. Si el envío falla, conserva la auditoría, pero no entra en las métricas de contactos reales. Simulaciones y tareas planificadas no crean muestras.

Un contacto antiguo sin snapshot permanece desconocido. Un contacto registrado retroactivamente solo puede usar un cálculo conservado disponible antes de su fecha, nunca recalcular con información actual. Si no existe ese cálculo, la razón queda explícita. No se hace backfill. No se permite cambiar un snapshot existente, incluso desde una instancia de modelo desactualizada. Consolidar duplicados conserva el JSON original y su identidad de procedencia.

## Métricas históricas

`MetricsService::scoreEvaluation()` agrupa contactos reales enviados por prospecto, campaña, oferta y versión. Usa el primer contacto del grupo como cohorte; seguimientos y canales repetidos no aumentan el denominador. Una nueva versión crea otra cohorte identificada. Muestra rango de puntuación, muestra conocida/desconocida, respuesta, interés, propuesta y cierre, con comparaciones por oferta/campaña/versión.

Solo atribuye eventos posteriores y compatibles con el contexto. Respuestas vinculadas tienen prioridad; propuestas deben coincidir con oferta. Cuando no se puede resolver una atribución entre varias ofertas, queda sin asignar. Las métricas no sustituyen el snapshot por `score_total`, puntuaciones actuales ni el estado final actual. Los filtros por puntuación operan sobre snapshots; los desconocidos excluidos se cuentan por separado. `currentScoreEvaluation()` permanece como exploración explícita del estado actual, no como validación predictiva.

Los resultados muestran asociación, tamaño de muestra e incertidumbre; no demuestran causalidad ni validan pesos con pocas observaciones. No hay entrenamiento automático ni recomendaciones automáticas de nuevos pesos.

## Consulta y exportación

Después de activar el esquema en una base autorizada:

```bash
php artisan sales:inspect scores --filters='{"score_offer_id":1}' --no-interaction
php artisan sales:inspect evidence --ids=1 --filters='{"evidence_review":"confirmed"}' --no-interaction
php artisan sales:inspect metrics --campaign=1 --rule-version=commercial-v1-HUELLA --no-interaction
php artisan sales:inspect export --dataset=offer_scores --filters='{"score_offer_id":1}' --no-interaction
```

`--rule-version` recibe la versión exacta devuelta por scores o métricas. `--version` es una opción global de Artisan y no se usa para esta función. CLI devuelve JSON y códigos 0 (correcto), 2 (entrada inválida), 1 (fallo). `--output=/ruta/nueva.csv` crea exclusivamente un archivo nuevo; no sobrescribe. El dataset `offer_scores` y el analítico conservan contexto, fecha y versión. La evidencia puede contener contactos públicos: mantener exports fuera de Git y compartir solo lo necesario.
