# API Contract

Este repositorio debe comportarse como backend puro para un frontend externo en React.

## Base URL

- Desarrollo local: `http://localhost:8000/api`
- Healthcheck: `GET /health`

## Autenticacion

- Login: `POST /auth/login`
- Header requerido para endpoints protegidos: `Authorization: Bearer <token>`
- Sesion actual: `GET /auth/me`
- Logout: `POST /auth/logout`

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
  "message": "..."
}
```

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

## Recomendaciones para React

- Centralizar una unica instancia HTTP con `baseURL=/api`.
- Enviar siempre bearer token tras login.
- No depender de aliases `.php` del frontend legado; consumir solo rutas Laravel `/api/...`.
- Tratar `success=false` como error funcional aunque el status HTTP sea `200` en algunos endpoints legacy-port.
- Mantener tipos separados para recursos nuevos (`vacation-requests`) y legacy (`vacations`) porque no comparten exactamente el mismo modelo.

## Decision sobre Vite y Tailwind

Se recomienda eliminarlos de este repositorio mientras siga siendo backend puro:

- no aportan nada a la API
- añaden mantenimiento Node innecesario
- pueden confundir con la idea de que aqui vive el frontend

El frontend React debe vivir en un repositorio o workspace separado y apuntar a este backend por HTTP.