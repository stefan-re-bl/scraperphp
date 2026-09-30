# Piloto P0

Fecha de configuración: 2026-09-24

## Objetivo

Medir respuesta, interés y propuestas con una muestra limpia.

No optimizar durante el piloto.

## Configuración

- Segmento: psicólogos, psiquiatras y consultorios pequeños.
- País: Argentina.
- Tamaño: 50 a 100 leads.
- Oferta: gestor de consultorio/turnos.
- Canal inicial: email manual.
- Fuente inicial: Google Maps.
- Campaña en la app: `Piloto P0 salud Argentina`.
- Límite diario operativo: 25 contactos.

## Secuencia

1. Contacto inicial.
2. Follow-up 1.
3. Follow-up 2.
4. Cerrar secuencia.

Detén la secuencia si el lead responde.

No automatices envíos.

## Variables fijas

No cambiar durante los primeros 50 contactos:

- segmento;
- oferta;
- canal;
- CTA;
- criterio de registro;
- cantidad máxima de follow-ups.

Si aparece un problema grave, pausar campaña.

Registrar el motivo antes de cambiar algo.

## Datos antes de contactar

Guardar para cada lead:

- fuente;
- profesión o especialidad;
- ciudad;
- contacto;
- web;
- señales detectadas;
- score;
- oferta.

## Datos durante la campaña

Registrar:

- mensaje enviado;
- fecha;
- respuesta;
- categoría;
- interés;
- propuesta;
- resultado.

## Criterio de primer análisis

Analizar al completar 50 contactos procesados.

Exportar:

- CSV;
- JSON;
- snapshot SQLite.

Revisar dónde se pierde valor:

- selección de leads;
- mensaje;
- oferta;
- seguimiento;
- cierre.

## Decisión posterior

No iniciar P1 antes de analizar los primeros datos.

La suite completa ya existe.

Usa el piloto para validar qué partes generan valor real.

El análisis debe definir si conviene priorizar:

- métricas segmentadas;
- scoring;
- experimentos A/B;
- LLM Bridge;
- mejoras de seguimiento;
- calidad de importación.
