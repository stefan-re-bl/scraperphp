> Archivo histórico del 2026-10-15. Conserva el contenido de la etapa; sus instrucciones y estados pueden estar superados. Consulta la [documentación vigente](../../README.md) y el [índice del archivo](../README.md). Las rutas y números de línea citados como texto corresponden al código de aquella revisión.

# Reglas de contacto

## Principio

El sistema es asistido.

No es un bot vendedor autónomo.

## Acciones automáticas

Permitidas:

- scraping;
- importación;
- deduplicación;
- enriquecimiento básico;
- scoring;
- asignación de campaña;
- métricas;
- logs;
- envío de activos previamente aprobados.

## Acciones asistidas

El sistema puede proponer:

- respuesta;
- argumento comercial;
- oferta;
- CTA;
- diagnóstico;
- propuesta.

La persona decide.

## Acciones humanas

No automatizar:

- conversación comercial importante;
- negociación;
- objeciones complejas;
- audio personal;
- reunión;
- precio final;
- cierre.

## WhatsApp

No usar WhatsApp para cold outreach automatizado.

Usar WhatsApp solo con base válida:

- conversación iniciada por el usuario;
- consentimiento;
- relación existente;
- flujo permitido por la plataforma.

Cuando WhatsApp pase a humano, la persona responde.

## Secuencia inicial

```text
Contacto inicial
-> Follow-up 1
-> Follow-up 2
-> Cerrar secuencia
```

Reglas:

- Configurar tiempos por campaña.
- Pausar todo si hay respuesta.
- No continuar una secuencia después de una respuesta.
- Usar dry-run para toda integración nueva.

## Email

El email usa SMTP configurable.

Variables principales:

- `MAIL_MAILER`
- `MAIL_HOST`
- `MAIL_PORT`
- `MAIL_USERNAME`
- `MAIL_PASSWORD`
- `MAIL_FROM_ADDRESS`

El modo automático está desactivado por defecto.

Usa dry-run salvo configuración explícita.

## Cumplimiento

Definir reglas por país y canal.

Permitir desactivar canales por:

- país;
- campaña;
- segmento.

No evadir:

- CAPTCHA;
- límites técnicos;
- controles anti-spam;
- controles anti-bot.
