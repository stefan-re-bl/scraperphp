# Guía de uso

El circuito diario es: preparar una oferta → organizar una campaña → adquirir o importar prospectos → revisar identidad y evidencia → contactar → registrar resultados. Para arrancar la aplicación o usar comandos, consulta [Operación](OPERATIONS.md).

## Preparar una oferta y una campaña

1. En Configuración, abre Ofertas y crea la propuesta que vas a trabajar. Define destinatario, segmento, beneficios, activos y llamada a la acción.
2. Elige un perfil de puntuación: turnos, web, ecommerce o general. La explicación de sus criterios está en [Scoring](SCORING.md).
3. Crea una campaña con nombre, segmento, país, ciudad y oferta. Revisa sus límites y utiliza el modo manual o de simulación al preparar una integración nueva.
4. Prepara una plantilla compatible con oferta, canal, idioma y paso de la secuencia.

**Resultado esperado:** la campaña identifica qué ofreces y a quién. Una sugerencia de oferta no sustituye la selección explícita de campaña. Si no hay ofertas configuradas, no habrá puntuaciones por oferta.

## Gestionar la cartera

Cartera reúne prospectos, clientes y colaboradores. Una organización puede ser cliente y colaborador a la vez. La prioridad manual y el valor estimado ayudan a organizar el trabajo; no representan una venta cobrada ni modifican automáticamente la puntuación.

En la ficha puedes consultar identidad, contactos asociados, servicios, evidencia, puntuaciones y actividad. Una organización admite varios contactos, con uno principal. Editar el principal sincroniza sus datos con la ficha; añadir otra persona conserva el principal. Eliminar un contacto asociado conserva los datos principales de la ficha. Revisa contactos antes de convertir una organización en persona.

Un cliente existente necesita vinculación o habilitación explícita por campaña para entrar al contacto comercial. Importarlo o clasificarlo como cliente no crea una oportunidad, una propuesta ni un contacto realizado.

## Obtener prospectos desde una campaña

1. Abre Adquisición de la campaña y revisa consulta, ubicación y límite.
2. Confirma si realizarás una simulación o una búsqueda real. Una búsqueda real requiere el scraper configurado y el worker activo.
3. Inicia el trabajo y consulta su estado. El proceso termina cuando también termina la importación.
4. Revisa contadores, filas rechazadas y posibles duplicados antes de enviar registros a la cola.

Si el trabajo queda pendiente o falla, sigue [Diagnóstico de tareas](OPERATIONS.md#diagnóstico-de-tareas). Reintentar puede iniciar una búsqueda real cuando no hay CSV reutilizable.

## Importar un CSV

1. Abre el flujo de importación de la campaña y selecciona el archivo.
2. Revisa columnas reconocidas y asigna los campos necesarios.
3. Examina filas inválidas, coincidencias dentro del archivo y candidatos existentes en la cartera.
4. Confirma el análisis revisado. Si cambia el archivo, genera otra vista previa.
5. Espera el procesamiento y revisa el resumen. Descarga el diagnóstico de rechazos cuando necesites corregir filas.

**Resultado esperado:** filas válidas procesadas, errores identificados y procedencia conservada. Un CSV parcialmente válido no implica que todas sus filas se hayan incorporado. El archivo de rechazos contiene datos de entrada y debe guardarse como información privada.

La importación puntual desde Notion utiliza otro procedimiento: [Importación de Notion](OPERATIONS.md#importación-de-notion). La cartera ya importada no debe modificarse como parte de una comprobación o una nueva ejecución de ejemplo.

## Revisar duplicados

Un posible duplicado queda fuera del contacto hasta resolver su identidad. Compartir dominio, email o teléfono puede corresponder a sucursales o personas diferentes.

1. Abre Comparar y resolver desde la ficha.
2. Si son negocios diferentes, confirma esa decisión.
3. Si corresponden a la misma entidad, elige una ficha principal y resuelve los conflictos de datos, contactos, oportunidades y secuencias.
4. Revisa el resultado y confirma la consolidación solicitada por la interfaz.

El sistema exige una revisión vigente, un respaldo y ausencia de tareas que impidan consolidar. Conserva originales y decisiones. Si la revisión queda desactualizada, vuelve a comparar; no fuerces una identidad ambigua.

## Revisar evidencia y puntuaciones

Desde la ficha, solicita una revisión del sitio cuando sea necesario. Es una consulta externa en cola y respeta límites y políticas de acceso. Un sitio no verificable queda como desconocido.

Examina procedencia, fecha, vigencia y estado de cada señal. Confirma, deja pendiente o descarta según lo observado. Una inferencia necesita revisión; no encontrar una web o calendario no demuestra una necesidad comercial. Los contactos publicados se conservan como evidencia; adoptar un email para la ficha requiere una acción explícita.

Las puntuaciones muestran adecuación, necesidad, contactabilidad y calidad de evidencia por oferta. Consulta los detalles cuando haya baja confianza, poca cobertura o contradicciones. Un resultado sin actualizar no se usa como puntuación vigente. En una campaña se evalúa su oferta; en el listado general puedes elegir la oferta para filtrar. Más detalle en [Scoring](SCORING.md).

## Contactar y registrar lo realizado

1. Abre la cola y verifica prospecto, campaña, oferta y paso.
2. Revisa el mensaje editable y completa los datos faltantes. Una exclusión, duplicación pendiente o campaña pausada impide contactar aunque el puntaje sea alto.
3. Realiza el contacto por el canal aprobado. En WhatsApp, abrir el mensaje no lo envía ni registra contacto.
4. Registra lo efectivamente realizado, con fecha, canal y mensaje utilizado. Puedes indicar tiempo de preparación en segundos.
5. Verifica el registro y el siguiente seguimiento. Una tarea planificada o una simulación no cuentan como contacto real.

La secuencia disponible es inicial → seguimiento 1 → seguimiento 2. Los tiempos dependen de la campaña. Una respuesta o un estado que pausa/cierra la oportunidad detiene los seguimientos pendientes correspondientes. Los contactos reales nuevos guardan la puntuación conocida en ese momento; los antiguos sin captura permanecen desconocidos.

## Reglas de contacto

La persona decide el mensaje final, la conversación, negociación, precio y cierre. No automatices contacto frío por WhatsApp: usa ese canal con conversación iniciada, consentimiento, relación existente u otra base válida. Respeta exclusiones, límites, políticas de cada fuente y reglas aplicables al país/canal; no evadas CAPTCHA ni controles antiabuso.

Email admite SMTP y simulación. La entrega real exige configuración y habilitación explícitas; el procedimiento está en [Operación: email](OPERATIONS.md#email). La planificación de un seguimiento no significa que se envíe automáticamente.

## Registrar respuestas, propuestas y servicios

Asocia la respuesta a su campaña e intento cuando sea posible. Clasifica interés, objeción y necesidad de intervención humana. El silencio no se registra como una respuesta ficticia.

La oportunidad mantiene estado, oferta y próxima acción propios por campaña. Un mismo prospecto puede tener oportunidades diferentes. Registra propuesta, importe, moneda y versión; actualiza el resultado con evidencia. Una propuesta ganada clasifica al registro como cliente, sin crear automáticamente un servicio ni un pago.

En Servicios registra trabajos y renovaciones por cliente, con fechas, estado e importe cuando lo conozcas. La propuesta vinculada debe pertenecer al mismo cliente. Completar o pausar un servicio no cambia por sí solo el pipeline. Los importes desconocidos se distinguen de cero.

## Organizar el trabajo diario

Inicio reúne respuestas pendientes, seguimientos vencidos, oportunidades sin fecha, propuestas y renovaciones. Abre la ficha correspondiente, revisa el contexto y registra el resultado o próxima acción. Las negociaciones no generan secuencias de contacto nuevas por sí solas.

Los filtros y vistas guardadas ayudan a retomar el trabajo. La exportación de una vista o selección debe conservar sus filtros; una selección explícita vacía no equivale a toda la cartera. Guarda exportaciones fuera de Git.

## Preparar un piloto comercial

1. Elige una oferta, segmento, canal, llamada a la acción y criterio de registro. No copies una campaña histórica como si siguiera configurada.
2. Revisa primero una muestra pequeña: identidad, contactos, evidencia y pertinencia de la oferta.
3. Define un límite diario y un criterio de análisis adecuados a tu capacidad y al canal. No existe un tamaño o una tasa de conversión validados para esta cartera.
4. Mantén las variables estables durante el bloque observado. Si aparece un problema, pausa y registra el motivo del cambio.
5. Registra contacto real, mensaje utilizado, respuesta, interés, propuesta y resultado. Analiza dónde se pierde valor antes de ampliar el volumen.

Las pruebas de software y el enriquecimiento de sitios no demuestran resultados comerciales. Los pesos iniciales requieren validación con una muestra real suficiente.

## Experimentos y métricas

Crea un experimento por campaña y variantes con plantillas compatibles. El sistema asigna una variante de las menos utilizadas y conserva la asignación; no garantiza una distribución aleatoria. La cola prepara el mensaje asignado. Si modificas texto o asunto, se pierde la atribución automática al contenido original. Varias asignaciones activas ambiguas tampoco se atribuyen arbitrariamente.

Las métricas usan contactos reales, muestran tamaños de muestra y distinguen cifras de tasas. Las del scoring comparan capturas al contacto por campaña, oferta y versión; no sustituyen un histórico desconocido por el puntaje de hoy. Seguimientos repetidos no multiplican el denominador de la misma cohorte. Consulta [Evaluación histórica](SCORING.md#métricas-históricas).

No hay ganador automático ni ajuste autónomo de pesos. Una mejora observada con pocos registros no demuestra causalidad. Prioriza interés, propuestas y cierres, además de respuestas. Si hay poca evidencia o atribución ambigua, conserva esa incertidumbre.

## Bridge manual

El LLM Bridge permite preparar un prompt con evidencia confirmada e importar sugerencias revisables mediante JSON. No llama automáticamente a una API de IA ni convierte sugerencias en hechos o contactos realizados. Revisa el contenido y la privacidad antes de usar una herramienta externa.
