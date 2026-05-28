# API Contract

Este repositorio debe comportarse como backend puro para un frontend externo en React.

## Estado del contrato

- Base estable actual: `/api`
- No se mantiene compatibilidad contractual para aliases `.php` ni para el importador externo legacy.
- El frontend React debe integrarse solo contra rutas Laravel documentadas aqui.

## Base URL

- Desarrollo local: `http://localhost:8000/api`
- Healthcheck: `GET /health`

## CORS

- CORS esta declarado explicitamente en el backend.
- Origenes permitidos por defecto en local: `http://localhost:3000`, `http://127.0.0.1:3000`, `http://localhost:5173`, `http://127.0.0.1:5173`.
- En despliegue, ajustar `CORS_ALLOWED_ORIGINS` con una lista separada por comas.
- Si el frontend usa bearer token por header, `supports_credentials=false` es suficiente.

## Autenticacion

- Login: `POST /auth/login`
- Header requerido para endpoints protegidos: `Authorization: Bearer <token>`
- Sesion actual: `GET /auth/me`
- Capacidades y permisos efectivos: `GET /auth/capabilities`
- Logout: `POST /auth/logout`

Respuesta de login:

```json
{
  "success": true,
  "message": "Login exitoso",
  "token": "jwt-o-token-propio",
  "user": {
    "id": 12,
    "username": "empleado1",
    "name": "Empleado Uno",
    "email": "empleado1@test.local",
    "role": "employee",
    "zone_id": 3
  }
}
```

## Formato de respuesta

- Exito habitual:

```json
{
  "success": true,
  "message": "...",
  "data": {}
}
```

- Error habitual:

```json
{
  "success": false,
  "message": "...",
  "errors": {}
}
```

Reglas de consumo:

- `success` manda sobre cualquier heuristica en cliente.
- Los errores de validacion pueden devolver `422` con `errors` por campo.
- Algunos endpoints legacy-port devuelven payload especifico fuera de `data`; el frontend debe tiparlos por recurso, no asumir un envoltorio unico.

## Codigos HTTP esperados

- `200`: consulta o mutacion correcta
- `201`: recurso creado
- `400`: error funcional legacy o parametros invalidos
- `401`: token ausente, invalido o expirado
- `403`: usuario autenticado sin permisos
- `404`: recurso inexistente o fuera de alcance
- `422`: validacion Laravel
- `500`: error interno

## Recursos principales

- Usuarios: `GET|POST|PUT|DELETE /users`
- Zonas: `GET|POST|PUT|DELETE /zones`
- Clientes: `GET|POST|PUT|DELETE /clients`
- Fichajes: `GET|POST|PUT|DELETE /records`
- Incidencias: `GET|POST|PUT|DELETE /incidencias`
- Solicitudes de vacaciones nuevas: `vacation-requests`
- Vacaciones legacy: `vacations`
- Notificaciones: `notifications`
- Descansos: `breaks`
- Modificaciones: `modifications`
- Cuadrantes y horarios: `quadrants`, `schedules`, `employee-schedules`, `schedule-history`
- Servicios: `services`
- Festivos y calendarios: `zone-holidays`, `calendars`
- Tolerancias: `tolerance`
- Bolsa de horas: `bolsa-anotaciones`
- QR: `qr-generator`

## Endpoints importantes para el nuevo frontend

### Vacation Requests

- `GET /vacation-requests`
- `GET /vacation-requests/stats?employeeId={id}&year={yyyy}`
- `POST /vacation-requests`
- `PUT /vacation-requests/{id}/approve`
- `PUT /vacation-requests/{id}/reject`
- `DELETE /vacation-requests/{id}`

### Vacations Legacy

- `GET /vacations`
- `GET /vacations/{id}`
- `POST /vacations`
- `PUT /vacations/{id}`
- `PUT /vacations/{id}/approve`
- `PUT /vacations/{id}/reject`
- `DELETE /vacations/{id}`

Payload de alta:

```json
{
  "employeeId": 12,
  "startDate": "2026-09-01",
  "endDate": "2026-09-03",
  "type": "vacation",
  "reason": "Descanso familiar"
}
```

### Calendars

- `GET /calendars`
- `POST /calendars`
- `PUT /calendars/{id}`
- `DELETE /calendars/{id}`
- `GET /calendars/{id}/holidays?year=2026`
- `POST /calendars/{id}/holidays`
- `POST /calendars/{id}/holidays?importNager=1&year=2026`
- `DELETE /calendars/{id}/holidays/{holidayId}`

### Zone Holidays

- `GET /zone-holidays?zoneId={id}&year=2026`
- `POST /zone-holidays`
- `POST /zone-holidays?importNager=1&year=2026`
- `DELETE /zone-holidays?id={id}`
- `DELETE /zone-holidays/{id}`

### Tolerance

- `GET /tolerance/zone?zoneId={id}`
- `PUT /tolerance/zone`
- `GET /tolerance/employee/{employeeId}`
- `PUT /tolerance/employee/{employeeId}`
- `PUT /tolerance/employee/{employeeId}/all`
- `GET /tolerance/presets`

### Bolsa Anotaciones

- `GET /bolsa-anotaciones?employeeId={id}&month=2026-06`
- `GET /bolsa-anotaciones?employeeId={id}&start_date=2026-06-01&end_date=2026-06-30`
- `POST /bolsa-anotaciones`
- `PUT /bolsa-anotaciones/{id}`
- `DELETE /bolsa-anotaciones/{id}`

### QR

- `GET /qr-generator?code={valor}&size=300`
- Respuesta: `image/png`

## Operaciones internas del backend

- El cron legacy de fichajes faltantes se porta como comando `php artisan legacy:check-missing-checkins`.
- Debe programarse cada 15 minutos con el scheduler de Laravel.
- El umbral de auto-confirmacion de fichajes pendientes se controla por `LEGACY_AUTO_CONFIRM_PENDING_RECORDS_DAYS`.

## Limitaciones deliberadas

- El importador externo legacy no forma parte de este backend.
- El contrato no cubre HTML, Blade, Vite ni recursos de frontend.
- El frontend React no debe leer ni inferir comportamiento desde `index.html` legacy.

## Recomendaciones para React

- Centralizar una unica instancia HTTP con `baseURL=/api`.
- Enviar siempre bearer token tras login.
- No depender de aliases `.php` del frontend legado; consumir solo rutas Laravel `/api/...`.
- Tratar `success=false` como error funcional aunque el status HTTP sea `200` en algunos endpoints legacy-port.
- Mantener tipos separados para recursos nuevos (`vacation-requests`) y legacy (`vacations`) porque no comparten exactamente el mismo modelo.
- Modelar por separado respuestas JSON y respuestas binarias como `qr-generator`.
- Centralizar el manejo de `401` para forzar logout o refresco de sesion de cliente.
- No acoplar el frontend al orden de listas si la API no lo garantiza explicitamente.

## Capabilities para navegacion

- El endpoint `GET /auth/capabilities` expone navegacion, acceso por recurso y permisos efectivos del usuario autenticado.
- La referencia funcional esta en [docs/CAPABILITIES.md](CAPABILITIES.md).
- React debe usar este endpoint para guards y menus en lugar de deducir reglas desde `role` o desde el frontend legacy.

## OpenAPI

- Especificacion base disponible en [docs/openapi.yaml](openapi.yaml).
- Su objetivo es generar tipos y cliente HTTP en React a partir del contrato actual.
- Es una base deliberadamente simple: cubre autenticacion, capacidades y los recursos principales con esquemas detallados o genericos segun el endpoint.

## Decision sobre Vite y Tailwind

Se recomienda eliminarlos de este repositorio mientras siga siendo backend puro:

- no aportan nada a la API
- añaden mantenimiento Node innecesario
- pueden confundir con la idea de que aqui vive el frontend

El frontend React debe vivir en un repositorio o workspace separado y apuntar a este backend por HTTP.