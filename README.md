# CRM comercial de CRT Web

Aplicación local para gestionar cartera, servicios, adquisición y oportunidades comerciales. Usa Laravel, SQLite, Blade y JavaScript; integra Google Maps, enriquecimiento determinista y puntuaciones por oferta.

## Documentación

- [Guía de uso](docs/USER_GUIDE.md): ofertas, campañas, importaciones, contactos, propuestas y métricas.
- [Operación](docs/OPERATIONS.md): instalación, arranque, CLI, respaldos y recuperación.
- [Arquitectura](docs/ARCHITECTURE.md): componentes y flujos del sistema.
- [Índice completo](docs/README.md): referencias técnicas, estado y archivo histórico.

## Arranque rápido

Para una instalación ya configurada y con el esquema actualizado, inicia la aplicación y el worker en dos terminales desde la raíz del repositorio:

```bash
php artisan serve --host=127.0.0.1 --port=8000 --no-interaction
```

```bash
php artisan queue:work --queue=sales,default --timeout=900 --tries=3 --no-interaction
```

Abre [la aplicación local](http://127.0.0.1:8000). Ambos procesos deben permanecer activos; el servidor web por sí solo no procesa la cola. El arranque no instala un servicio permanente. Para una instalación nueva o una actualización, sigue [Operación](docs/OPERATIONS.md#instalación-y-actualización).

## Primera tarea

Configura una oferta y su perfil de puntuación, crea una campaña y revisa una muestra pequeña de prospectos. La [guía de uso](docs/USER_GUIDE.md#preparar-una-oferta-y-una-campaña) describe el circuito completo y las condiciones de contacto.

Las puntuaciones son hipótesis explicadas mediante evidencia. No prueban interés de compra ni habilitan contactos excluidos. WhatsApp se prepara como un mensaje editable y lo envía la persona; abrirlo no registra un contacto realizado.

El estado de la última verificación está en [Estado del proyecto](docs/PROJECT_STATE.md). La aplicación es de uso local y no incorpora autenticación para exponerla por red.
