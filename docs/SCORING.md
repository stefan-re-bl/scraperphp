# Scoring por oferta y evaluación histórica

Referencia de puntuación, recomendación y medición. Para usarlo desde la ficha consulta la [Guía de uso](USER_GUIDE.md#revisar-evidencia-y-puntuaciones); para consultar o exportar resultados utiliza [CLI comercial](OPERATIONS.md#cli-comercial). El catálogo y los datos de una instalación se registran en [Estado](PROJECT_STATE.md).

## Dimensiones y total

Cada par prospecto/oferta conserva un cálculo de 0 a 100 en `lead_offer_scores`:

| Dimensión | Qué representa | Peso inicial |
| --- | --- | ---: |
| `fit` | Adecuación de rubro, características y oferta. | 35 % |
| `need` | Oportunidad de mejora respaldada por observaciones revisadas. | 35 % |
| `contact` | Canales principales con formato utilizable. | 15 % |
| `evidence` | Cobertura y confianza de la evidencia utilizada. | 15 % |

El total es la suma ponderada, redondeada y acotada. Confianza, cobertura y necesidad son índices de reglas iniciales; no probabilidades de compra ni urgencias demostradas. Un contacto utilizable no demuestra entrega, disponibilidad o consentimiento.

Las reglas están en [commercial_scoring.php](../config/commercial_scoring.php) y [LeadScorer](../app/Services/LeadScorer.php). Los pesos son hipótesis sin validación comercial; no se ajustan para producir una distribución atractiva.

## Perfiles y aportes

La oferta permite elegir `rules.scoring_profile`: `booking`, `web`, `ecommerce` o `generic`. Si falta, se detecta por el nombre; en ausencia de coincidencia se usa `generic`.

| Perfil | Ejemplos de adecuación | Ejemplos de necesidad |
| --- | --- | --- |
| `booking` | Actividad con reservas 80; servicio publicado 30. | Dependencia manual revisada 100; complejidad revisada 40. |
| `web` | Oferta clara 50; servicio 40; web verificada 30. | Problema observado 80; formulario roto confirmado 90. |
| `ecommerce` | Venta de productos 80; catálogo 60. | Pedidos manuales revisados 100; catálogo sin compra inferida y revisada 70. |
| `generic` | Oferta clara 50. | Problema observado 60. |

La compatibilidad por palabras clave aporta inicialmente 70. Dentro de adecuación y necesidad se toma el mayor aporte aplicable; copias y conceptos correlacionados no se suman. Una reserva o compra online observada limita inicialmente la necesidad de su perfil a 35 cuando corresponde. Las reglas persistidas por oferta conservan sus puntos/pesos; una regla inactiva no contribuye.

La cobertura cuenta conceptos esperados conocidos y la confianza agrupa orígenes. Observaciones de web, HTTP y DNS del mismo dominio forman un origen; más páginas no simulan corroboración independiente. Las inferencias tienen confianza limitada incluso después de revisión.

Se excluyen pendientes, rechazadas, vencidas, retiradas, desconocidas o incompatibles con identidad. Las contradicciones eliminan aportes incompatibles y reducen confianza. El origen CRM y `metadata.not_a_score_predictor` quedan fuera incluso si una regla personalizada intenta utilizarlos. Un resultado comercial de la oportunidad no predice su propio éxito.

No tener web registrada, no detectar calendario o sufrir un timeout no genera necesidad ni penalización. Observar HTTP tampoco demuestra ausencia de HTTPS. Las verificaciones funcionales no realizadas permanecen desconocidas.

## Recomendación y compatibilidad

[OfferMatcher](../app/Services/OfferMatcher.php) recomienda ofertas activas con adecuación de al menos 40, total igual o superior al mínimo de la oferta (20 por defecto), confianza mínima 0,35, cobertura mínima 0,25 y sin contradicciones. Los empates se resuelven de forma estable por ID. La interfaz advierte evidencia limitada por debajo de 0,50 de confianza o cobertura.

La sugerencia no modifica la oferta explícita de campaña. Un puntaje alto no evita bloqueos por exclusión, duplicación pendiente, oportunidad pausada/cerrada ni permiso de contacto de clientes.

`LeadScorer::score()` conserva `score_total` y componentes globales por compatibilidad y calcula ofertas activas y las elegidas explícitamente aunque estén archivadas. El premio histórico por web desconocida ya no contribuye.

## Versión, explicación y vigencia

La etiqueta combina `commercial-v1` con una huella de `config/commercial_scoring.php`. La explicación guarda perfil, pesos, reglas concretas, evidencia utilizada, desconocidos, contradicciones y estados relevantes de oferta/entradas/reglas.

[LeadOfferScore::current()](../app/Models/LeadOfferScore.php) contrasta entradas, oferta, reglas y señales, incluidos cambios en el mismo segundo y expiración. Estado CRM y resultados posteriores no son predictores. Las lecturas de UI/CLI/export no recalculan: una puntuación ausente o desactualizada se muestra sin evaluar.

La huella de versión identifica la configuración del algoritmo, no cada edición de `scoring_rules` o `Offer.rules` en la base. Esas ediciones quedan en la explicación e invalidan el cálculo vigente, pero no generan por sí solas una etiqueta distinta. Para comparar formalmente un cambio de reglas persistidas debe planificarse una versión explícita y conservar su definición; no presupongas que dos filas con la misma etiqueta usaron idénticas reglas personalizadas.

## Contexto de listados y cola

`score_offer_id` elige qué oferta puntuar en un listado. En campaña se utiliza la oferta de la oportunidad; la cola global primero selecciona ese contexto. Si existe oferta y falta cálculo vigente, se muestra desconocido, sin sustituirlo por el global. Solo los contextos sin oferta mantienen compatibilidad global, identificada como tal.

Los mínimos/máximos excluyen puntuaciones desconocidas. El dataset exportado conserva fecha y versión; el recurso CLI `scores` añade `fresh`. Cambiar una oferta desde la interfaz programa recálculo; no confundir una tarea en cola con un cálculo completado.

## Snapshot inmutable al contactar

[ScoreSnapshotService](../app/Services/ScoreSnapshotService.php) captura una vez el conocimiento local al registrar contacto manual o reservar un envío real antes de SMTP. Guarda identidad/campaña/oportunidad/oferta originales, total, dimensiones, confianza/cobertura, explicación, evidencia utilizada, versión y fechas.

Un envío fallido conserva auditoría y queda fuera de la muestra de enviados. Simulaciones, tareas planificadas y contactos sin realización no crean muestra. El snapshot omite los estados internos usados solo para comprobar vigencia. Un modelo desactualizado tampoco puede sobrescribir uno ya capturado.

Un histórico sin snapshot permanece desconocido. Un contacto retroactivo solo puede utilizar un cálculo conservado anterior o igual a su fecha; no se recalcula con datos actuales. Si falta, registra el motivo. La consolidación conserva JSON e identidad originales; la atribución exige el vínculo archivado hacia la ficha principal.

## Métricas históricas

[MetricsService::scoreEvaluation()](../app/Services/MetricsService.php) agrupa contactos reales enviados por prospecto/campaña/oferta/versión. Usa el primer contacto del grupo; seguimientos y canales repetidos no aumentan ese denominador. Una versión distinta crea otra cohorte identificada. Un mismo prospecto en varias versiones no constituye muestras independientes.

Se muestran rangos, tamaño conocido/desconocido, respuesta, interés, propuesta y cierre, con comparaciones por campaña/oferta/versión. Los filtros de score comparan snapshots; los desconocidos excluidos se cuentan por separado.

Solo eventos posteriores y compatibles se atribuyen: respuestas vinculadas tienen prioridad y propuestas requieren oferta coincidente. Eventos ambiguos entre ofertas quedan sin asignar. Las métricas no sustituyen el snapshot por el score actual ni por el estado global final. `currentScoreEvaluation()` conserva exploración actual separada de esta evaluación histórica.

Las tasas muestran asociación y dependen de selección, seguimiento y atribución. Muestras pequeñas no validan pesos ni causalidad. No hay entrenamiento, ganador automático ni ajuste autónomo.

## Ejemplo sintético reproducible

Un consultorio ficticio conserva rubro salud, email/teléfono utilizables y sitio registrado. En un mismo dominio se observaron actividad con reservas y web funcional; una persona confirmó la inferencia de dependencia manual. No hay evidencia de venta de productos.

| Oferta | Adecuación | Necesidad | Contactabilidad | Evidencia | Total |
| --- | ---: | ---: | ---: | ---: | ---: |
| Gestión de turnos | 80 | 100 | 100 | 58 | 87 |
| Desarrollo web | 70 | 0 | 100 | 55 | 48 |
| Ecommerce | 0 | 0 | 100 | 44 | 22 |

Confianza 0,775; cobertura 0,75 / 0,7143 / 0,5714. Web obtiene compatibilidad por palabras clave sin necesidad confirmada; ecommerce conserva contactabilidad sin inventar adecuación. El 100 de necesidad de turnos representa la regla revisada, no probabilidad de compra. Una solución de reservas existente limitaría ese aporte.

La prueba [OfferScoringTest](../tests/Feature/OfferScoringTest.php) fija los campos y comprueba los resultados; no son mediciones comerciales de la cartera.
