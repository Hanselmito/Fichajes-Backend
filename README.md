# Fichaje Backend

Backend Laravel 12 para portar la API legacy de la aplicacion de gestion de fichajes sobre la base de datos existente.

## Objetivo

Este repositorio debe mantenerse como backend puro.

- Expone la API consumida antes por el frontend legacy PHP/HTML.
- No contiene frontend de producto.
- El nuevo frontend debe vivir en un proyecto React separado y conectarse por HTTP a este backend.

## Estado actual

- API legacy portada y validada con tests feature.
- Compatibilidad mantenida para rutas usadas por la aplicacion antigua.
- Scaffold web de Laravel eliminado para evitar confundir este repo con un frontend.

## Puesta en marcha

Requisitos:

- PHP 8.2+
- Composer
- MySQL con el esquema legacy

Comandos:

```bash
composer install
php artisan key:generate
php artisan serve
```

Tests:

```bash
php artisan test --filter=LegacyApi
```

## Contrato API

La documentacion resumida para el futuro frontend React esta en [docs/API_CONTRACT.md](docs/API_CONTRACT.md).

## Notas de integracion

- Base URL local recomendada: `http://localhost:8000/api`
- Autenticacion: bearer token
- El frontend nuevo no debe depender de aliases `.php` del proyecto legado
- Varias tablas legacy requieren IDs manuales; los controladores ya lo manejan
