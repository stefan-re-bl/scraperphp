# Alcance del MVP

## Requisitos mínimos

El MVP debe permitir este ciclo:

```text
ENCONTRAR
-> PRIORIZAR
-> CONTACTAR
-> OBTENER RESPUESTA
-> DETECTAR INTERES
-> PROPONER
-> MEDIR
-> MEJORAR
```

## Funciones incluidas

### Adquisición

- Crear campañas.
- Generar consultas para Google Maps.
- Ejecutar scraping con herramienta externa.
- Importar resultados CSV.
- Conservar procedencia de cada lead.

### Procesamiento

- Deduplicar leads.
- Detectar señales deterministas.
- Calcular score explicable.
- Sugerir oferta.
- Mantener historial de estado.

### Operación comercial

- Revisar cola de contacto.
- Registrar contacto inicial y follow-ups.
- Pausar secuencia cuando llega una respuesta.
- Clasificar respuestas.
- Registrar propuestas con versiones.

### Medición

- Medir el embudo completo.
- Separar cantidades y porcentajes.
- Mostrar tamaño de muestra.
- Comparar campañas, segmentos, ofertas y variantes.

### IA opcional

- Excluida del P0.
- Se evaluará después.

### Exportación

- Exportar datasets CSV.
- Exportar JSON estructurado completo.
- Obtener snapshot de la base SQLite.

## Funciones excluidas

No incluir:

- CRM generalista;
- multiusuario;
- roles complejos;
- campañas reales durante pruebas;
- cold outreach automatizado por WhatsApp;
- envío autónomo de conversaciones comerciales;
- IA generativa;
- LLM Bridge;
- scraping extensivo sin hipótesis comercial;
- validación SMTP como dependencia del MVP;
- integración automática con Notion;
- experimentos A/B avanzados;
- envío automático;
- diseño visual avanzado.

## Primer incremento implementable

Construir una app local que permita:

1. Crear una campaña.
2. Registrar oferta y segmento.
3. Importar un archivo CSV de Google Maps.
4. Deduplicar leads.
5. Priorizar leads.
6. Mostrar cola de contacto.
7. Registrar contacto externo.
8. Registrar y clasificar respuesta.
9. Actualizar pipeline.
10. Registrar propuesta.
11. Mostrar métricas.
12. Exportar datos.
13. Obtener snapshot SQLite.

Este incremento mejora la métrica de oportunidades procesadas.
