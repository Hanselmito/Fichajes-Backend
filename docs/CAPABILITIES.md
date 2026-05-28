# Capabilities y Permisos

Este documento define la respuesta de `GET /api/auth/capabilities` para que el frontend React construya navegacion y guards sin inferir reglas desde el frontend legacy.

## Objetivo

- Exponer una vista estable de capacidades efectivas del usuario autenticado.
- Separar permisos raw del legacy, acceso por recurso y visibilidad de navegacion.
- Evitar que React tenga que deducir reglas desde `role`, columnas legacy o `index.html`.

## Endpoint

- `GET /api/auth/capabilities`
- Requiere `Authorization: Bearer <token>`

## Estructura de respuesta

```json
{
  "success": true,
  "capabilities": {
    "user": {
      "id": 5,
      "username": "admin",
      "name": "Administrador",
      "role": "admin",
      "zone_id": 1
    },
    "navigation": {
      "dashboard": true,
      "records": true,
      "work_hours": true,
      "notifications": true,
      "breaks": true,
      "modifications": true,
      "vacation_requests": true,
      "vacations": true,
      "reports": true,
      "user_overview": true,
      "zones": true,
      "users": true,
      "clients": true,
      "quadrants": true,
      "schedules": true,
      "employee_schedules": true,
      "services": true,
      "calendars": true,
      "zone_holidays": true,
      "tolerance": true,
      "bolsa_anotaciones": true
    },
    "resource_access": {
      "users": {
        "visible": true,
        "manage": true,
        "zone_scope": null
      }
    },
    "permissions": {
      "can_view_reports": {
        "label": "Puede acceder a reportes fuera de su ambito minimo",
        "allowed": true,
        "scoped_zone_ids": [],
        "effective_zone_scope": null
      }
    }
  }
}
```

## Semantica

- `navigation`: booleans listos para mostrar u ocultar secciones principales de UI.
- `resource_access.{recurso}.visible`: el usuario puede entrar en esa seccion o consultar su version propia/escopada.
- `resource_access.{recurso}.manage`: el usuario puede ejecutar operaciones de administracion dentro de ese recurso.
- `resource_access.{recurso}.zone_scope`:
  - `null`: sin limite de zonas efectivo.
  - `[]`: sin acceso transversal; normalmente solo ambito propio.
  - `[1,2,...]`: acceso limitado a esas zonas.
- `permissions`: refleja los flags legacy efectivos y sus scopes para casos donde el frontend necesite guards mas finos.

## Recomendacion para React

- Usar `navigation` para menus, tabs y rutas visibles.
- Usar `resource_access` para guards de pagina y acciones CRUD.
- Usar `permissions` solo cuando una pantalla necesite una decision mas especifica que no encaje en los dos bloques anteriores.
- Cachear esta respuesta tras login y refrescarla junto con `GET /auth/me` al restaurar sesion.