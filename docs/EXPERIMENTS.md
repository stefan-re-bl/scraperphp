# Experimentos

## Objetivo

Mejorar el embudo comercial con pruebas pequeñas y medibles.

No optimizar solo por respuestas.

Priorizar interés, propuestas y cierres.

## Línea base

La línea base actual es:

```text
100 contactos
-> 18 respuestas
-> 4 interesados
-> 2 propuestas
```

## Variables permitidas

Probar variantes de:

- apertura;
- propuesta directa;
- problema detectado;
- CTA;
- longitud;
- oferta;
- follow-up.

## Asignación

Asignar leads aleatoriamente por campaña y experimento.

Guardar siempre la variante recibida por cada lead.

Implementación actual:

- Se crean experimentos por campaña.
- Se crean variantes.
- Se asigna la variante menos usada.
- Se guarda la asignación por lead.
- No se declara ganador automáticamente.

## Métricas

Mostrar:

- enviados;
- respuestas;
- respuestas positivas;
- interesados;
- propuestas;
- cierres;
- tasa de respuesta;
- tasa de respuesta positiva;
- tasa de interés;
- tasa de propuesta;
- tasa de cierre;
- tamaño de muestra.

Separar números absolutos y porcentajes.

## Reglas de interpretación

No declarar ganadora una variante con muestra pequeña.

No considerar buena una variante que genere muchas respuestas negativas.

Priorizar métricas posteriores del embudo.
