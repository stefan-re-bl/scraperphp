# Backlog

Pendientes ordenados para decidir el siguiente trabajo. No es una autorización para ejecutarlos. El estado comprobado está en [Estado](PROJECT_STATE.md); lo ya entregado se consulta en [Changelog](CHANGELOG.md) y el [archivo](archive/README.md).

## Prioridad comercial

| Prioridad | Trabajo | Criterio de finalización |
| --- | --- | --- |
| 1 | Definir y cargar la primera oferta real. | Oferta con destinatario, alcance, beneficios, CTA, perfil y plantilla revisados; no se usan ofertas ficticias como catálogo operativo. |
| 2 | Preparar una campaña y una muestra pequeña revisada. | Identidades sin ambigüedad, canales válidos, oferta explícita y evidencias/limitaciones revisadas; registros protegidos excluidos salvo autorización específica. |
| 3 | Ejecutar el piloto comercial supervisado. | Contactos realizados por la persona con mensajes, fechas y resultados registrados; análisis que muestra muestra y desconocidos sin atribuciones inventadas. |
| 4 | Evaluar pesos y pertinencia de ofertas. | Comparación de snapshots y resultados por campaña/oferta/versión con muestra suficiente y sesgos documentados, sin declarar causalidad o ajustar automáticamente. |

La [Guía de uso](USER_GUIDE.md#preparar-un-piloto-comercial) define el procedimiento. La ausencia de evidencia no es motivo para cambiar pesos buscando una distribución más atractiva.

## Mejoras técnicas candidatas

Estas mejoras requieren confirmar el problema y su valor antes de implementarse:

| Tema | Criterio para considerarlo terminado |
| --- | --- |
| Importación/exportación masiva de exclusiones | Vista previa, normalización y selección verificadas sin habilitar por error un contacto bloqueado. |
| Consulta de logs en la interfaz | Diagnóstico útil por ejecución con privacidad, sin exponer filas completas o secretos. |
| Comparación visual de versiones de propuesta | Diferencias relevantes visibles, manteniendo versiones e importes originales. |
| Horarios de contacto | Reglas actuales contrastadas con casos reales y cualquier ampliación justificada por una necesidad concreta. |
| Análisis temporal adicional | Pregunta comercial concreta que no resuelvan período, contexto y snapshots existentes; resultados sin contaminación futura. |
| Interacción del pipeline | Mejora validada con uso real; conserva transiciones e historial, incluida cualquier propuesta de arrastrar y soltar. |
| Etiquetado de ediciones de reglas persistidas | Definición reproducible de qué cambios requieren etiqueta nueva; ver el [alcance actual de versión](SCORING.md#versión-explicación-y-vigencia). |
| Rendimiento con cartera mayor | Medición representativa de consultas de vigencia y exportaciones; cambios solo ante un cuello de botella reproducible. |

## Fuera del alcance actual

No se planifican por defecto multiusuario, infraestructura distribuida, Redis, microservicios, proxies, nuevas APIs pagas, entrenamiento automático ni IA autónoma. Una propuesta futura debe justificar necesidad, coste y condiciones de uso.

La sincronización o exportación automática a Notion no es necesaria para operar el CRM local. La importación puntual ya existente conserva su revisión, privacidad e idempotencia.

## Mantener este backlog

Cada trabajo nuevo debe identificar problema, evidencia, alcance y criterio de finalización. Al completarlo, retíralo de los pendientes y enlaza su entrega desde Changelog; evita acumular secciones de tareas terminadas aquí. Las decisiones de prioridad comercial corresponden a la persona responsable del proyecto.
