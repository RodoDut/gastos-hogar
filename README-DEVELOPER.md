# README — Developer

Documentación técnica interna del proyecto **Gastos del Hogar**.  
Última actualización: julio 2026.

---

## Qué es este proyecto

App web PHP para registrar y balancear gastos compartidos entre los miembros de un hogar (hasta 5 personas). Cada usuario carga sus propios gastos; puede asignarlos a cualquier miembro. La app calcula quién aportó más y cuánto debe compensar el resto para quedar equitativos.

**URL de producción:** https://gastos.rdtecno.net  
**Repositorio:** https://github.com/RodoDut/gastos-hogar

---

## Arquitectura

El proyecto sigue principios **SOLID** y una arquitectura en capas inspirada en **Clean Architecture**.

```
GastosHogar/
├── public/                          ← Entry point de la app (document root real)
│   ├── index.php                    ← Front controller único
│   └── assets/css, js
├── src/
│   ├── Config.php                   ← Configuración centralizada desde .env
│   ├── helpers.php                  ← e(), money(), asset()
│   ├── Auth/
│   │   ├── Auth.php                 ← Login, logout, CSRF, timeout de sesión
│   │   ├── AuthorizationService.php ← PUNTO ÚNICO DE PERMISOS (ver sección Autorización)
│   │   └── UnauthorizedActionException.php
│   ├── User/
│   │   ├── User.php                 ← Entidad: id, name, username, passwordHash, role, active
│   │   ├── UserRole.php             ← Enum: Admin | Member
│   │   ├── UserRepositoryInterface.php
│   │   ├── JsonUserRepository.php   ← CRUD sobre data/people.json
│   │   └── UserService.php          ← createUser, deactivateUser, reactivateUser
│   ├── Admin/
│   │   └── UserController.php       ← Panel admin: listar, crear, desactivar usuarios
│   ├── Expense/
│   │   ├── Expense.php              ← Entidad: id, who, ownerId, desc, amt, cat, date
│   │   ├── ExpenseRepositoryInterface.php
│   │   └── JsonExpenseRepository.php
│   ├── Person/
│   │   ├── Person.php
│   │   ├── PersonRepositoryInterface.php
│   │   └── JsonPersonRepository.php ← Coexiste con JsonUserRepository (ver nota)
│   └── View/
│       └── View.php
├── templates/
│   ├── app.html.php
│   ├── login.html.php
│   ├── settings.html.php
│   └── admin_users.html.php         ← Solo accesible para rol admin
├── data/                            ← EXCLUIDO del rsync. Nunca se pisa en deploy.
│   ├── gastos.json
│   ├── people.json
│   └── tokens.json                  ← Para feature remember-me (pendiente)
├── scripts/                         ← EXCLUIDO del rsync. Solo uso local/SSH manual.
│   ├── migrate-users.php            ← Agrega username/passwordHash/role/active a people.json
│   └── migrate-add-owner.php        ← Agrega owner_id y normaliza who a UUID en gastos.json
├── docker-compose.yml
├── Dockerfile
├── .env                             ← NUNCA commitear
└── .env.example
```

### Nota: JsonPersonRepository vs JsonUserRepository

Ambos operan sobre `data/people.json`. Coexisten de forma segura porque:
- `JsonPersonRepository` lee y escribe el JSON raw completo, preservando campos extra.
- `JsonUserRepository.save()` usa `array_merge()` preservando campos existentes.
- Ambos usan `flock()` en escritura.

`JsonPersonRepository` gestiona quién puede ser asignado a un gasto (miembros del hogar).
`JsonUserRepository` gestiona quién puede iniciar sesión (credenciales y roles).
Un miembro puede existir en `people.json` sin credenciales (sin acceso a la app).

---

## Modelo de dominio

### Roles

| Rol | Puede |
|-----|-------|
| `admin` | Todo lo de member + acceder a `/admin_users`, crear/desactivar usuarios |
| `member` | CRUD sobre sus propios gastos, leer gastos y totales del resto |

### Propiedad de gastos

Cada gasto tiene dos campos de identidad distintos:

- **`owner_id`** → quién *cargó* el gasto (el usuario logueado al momento de agregar). Controla permisos de edición/borrado.
- **`who`** → a quién se *atribuye* el gasto en los balances. Puede ser cualquier miembro.

Ejemplo: Rodo paga el supermercado pero lo carga a nombre de Vanina.
→ `owner_id = rodo_uuid`, `who = vanina_uuid`.
→ Solo Rodo puede borrar ese gasto, pero suma al balance de Vanina.

### AuthorizationService

**Regla crítica:** toda verificación de permisos pasa por `AuthorizationService`. Nunca verificar roles directamente desde `$_SESSION` ni desde los templates.

```php
$authz->canManageUsers(User $actor): bool
$authz->canEditExpense(User $actor, Expense $expense): bool
$authz->canDeleteExpense(User $actor, Expense $expense): bool
$authz->canViewAllExpenses(User $actor): bool
```

---

## Estructura de datos

### people.json

```json
{
  "people": [
    {
      "id": "902d14de5b019a10",
      "name": "Rodolfo",
      "username": "rodo",
      "passwordHash": "$2y$10$...",
      "role": "admin",
      "active": true
    }
  ]
}
```

Usuarios se desactivan (`"active": false`), **nunca se borran** (preserva integridad de `owner_id` en gastos históricos).

### gastos.json

```json
{
  "expenses": [
    {
      "id": "8f08159b26225c60",
      "who": "902d14de5b019a10",
      "owner_id": "902d14de5b019a10",
      "desc": "Luz",
      "amt": 38000,
      "cat": "Servicios",
      "date": "2026-06-05"
    }
  ]
}
```

`who` y `owner_id` son UUIDs (no nombres). Si un gasto antiguo tiene `who` como nombre literal, correr `migrate-add-owner.php`.

---

## Configuración local

### 1. Clonar y configurar

```bash
git clone https://github.com/RodoDut/gastos-hogar.git
cd gastos-hogar
composer install
cp .env.example .env
```

Editar `.env`:
```env
APP_PASS=cualquier_valor_requerido_por_dotenv
DATA_FILE=data/gastos.json
SESSION_TTL=3600
MAX_ATTEMPTS=3
LOCKOUT_SEC=60
```

> `APP_PASS` ya no se usa para el login (reemplazado por el sistema de usuarios), pero `dotenv` lo requiere para no fallar. Deuda técnica pendiente de limpiar en `Config.php`.

### 2. Levantar con Docker

```bash
docker-compose up -d
```

Disponible en `http://localhost:8080`.

Los volúmenes montan `src/`, `public/`, `templates/` y `data/` en modo hot-reload: los cambios en PHP y CSS se reflejan sin rebuild. Solo se necesita `docker-compose up -d --build` si se modifica `Dockerfile` o `composer.json`.

### 3. Crear primer usuario en local

Después de clonar, correr las migraciones para inicializar `people.json` con credenciales:

```bash
php scripts/migrate-users.php
php scripts/migrate-add-owner.php
```

---

## Migraciones de datos

### ⚠️ Regla crítica

**`data/` nunca se deploya automáticamente.** El rsync excluye esta carpeta para proteger los datos de producción. Si corrés una migración en local, los cambios en `people.json` o `gastos.json` **no llegan solos a producción** — hay que subirlos manualmente.

### Cuándo correr migraciones

| Script | Cuándo |
|--------|--------|
| `migrate-users.php` | Al crear el entorno por primera vez o al agregar campos nuevos a `people.json` |
| `migrate-add-owner.php` | Una sola vez, para normalizar gastos históricos sin `owner_id` |

### Cómo subir datos migrados a producción

**Opción 1 — File Manager de Hostinger (más simple):**
Subir desde hPanel → File Manager a:
```
/home/u938009968/domains/gastos.rdtecno.net/data/people.json
```

**Opción 2 — SCP desde Git Bash (no desde PowerShell/CMD):**
```bash
# Desde Git Bash, no PowerShell
scp -P 65002 -i /c/Users/rodod/AppData/Local/Temp/gastos_hogar_deploy_key \
  data/people.json \
  u938009968@145.223.77.254:/home/u938009968/domains/gastos.rdtecno.net/data/people.json
```

> La clave SSH tiene problemas de permisos en PowerShell/CMD de Windows. Usar siempre **Git Bash** para SSH/SCP manuales.

---

## CI/CD — GitHub Actions

### Flujo de ramas

```
feature/xxx  →  develop  →  main  →  deploy automático a producción
```

Nunca trabajar directo en `develop` ni en `main`. Siempre desde una rama de feature.

### Workflows

| Archivo | Trigger | Qué hace |
|---------|---------|----------|
| `validate.yml` | Push a `develop`, PR a `main` | Valida sintaxis PHP y `composer.json` |
| `deploy.yml` | Push a `main` | Deploy completo a Hostinger |

### Lo que hace deploy.yml paso a paso

1. Checkout del repo
2. Setup PHP 8.2
3. `composer install --no-dev --optimize-autoloader`
4. Carga clave SSH con `webfactory/ssh-agent` (evita problemas de encoding de Windows)
5. `ssh-keyscan` para poblar `known_hosts`
6. **rsync** con `--delete` y los siguientes excludes:

| Excluido | Por qué |
|----------|---------|
| `.env` | Datos sensibles, se crea en primer deploy vía scp |
| `data/` | Datos runtime de producción, nunca se pisan |
| `public_html/` | Document root de Hostinger, gestionado por post-deploy |
| `scripts/` | Scripts de migración, no exponer en producción |
| `.git/`, `.github/` | Metadatos de Git |
| `docker/`, `Dockerfile`, `docker-compose.yml` | Solo desarrollo local |
| `gastos.php` | Monolito original deprecado |

7. Crea `.env` en el servidor **solo si no existe** (primer deploy)
8. **Post-deploy** (idempotente, corre en cada deploy):
   - Crea `data/` con permisos `700`
   - Crea `public_html/` si no existe
   - Crea `public_html/index.php` proxy si no existe
   - Recrea symlink `public_html/assets → public/assets` con `ln -sfn`
   - Crea `data/tokens.json` vacío si no existe

### Secrets de GitHub requeridos

| Secret | Valor |
|--------|-------|
| `HOSTINGER_SSH_KEY` | Clave privada SSH (sin passphrase) |
| `HOSTINGER_HOST` | `145.223.77.254` |
| `HOSTINGER_SSH_PORT` | `65002` |
| `HOSTINGER_USER` | `u938009968` |
| `HOSTINGER_REMOTE_PATH` | `/home/u938009968/domains/gastos.rdtecno.net` |
| `APP_PASS` | Valor legacy requerido por dotenv |

---

## Producción — Hostinger

### El problema del document root

Hostinger usa `public_html/` como document root para cada subdominio. La app tiene `public/` como entry point (estándar PHP moderno). No coinciden.

**Solución implementada (el post-deploy la mantiene automáticamente):**

```
public_html/
├── index.php   → proxy: require '../public/index.php'
└── assets      → SYMLINK a ../public/assets
```

Si por alguna razón se pierde (limpieza manual del servidor), el próximo push a `main` lo restaura automáticamente.

### Estructura completa en el servidor

```
/home/u938009968/domains/gastos.rdtecno.net/
├── src/
├── templates/
├── public/
│   ├── index.php        ← entry point real
│   └── assets/
├── vendor/
├── composer.json
├── .env                 ← NO está en el repo, creado en primer deploy
├── data/                ← NO viene del repo, gestionado manualmente
│   ├── gastos.json
│   ├── people.json
│   └── tokens.json
└── public_html/         ← NO viene del repo, gestionado por post-deploy
    ├── index.php        ← proxy a ../public/index.php
    └── assets           ← symlink a ../public/assets
```

### SSH manual al servidor

```bash
# Desde Git Bash (no PowerShell)
ssh -i /c/Users/rodod/AppData/Local/Temp/gastos_hogar_deploy_key \
    -p 65002 u938009968@145.223.77.254
```

Regenerar clave SSH (solo si es necesario):
```powershell
# Desde PowerShell, usando ssh-keygen de Git
& "C:\Program Files\Git\usr\bin\ssh-keygen.exe" `
  -t ed25519 -C "deploy@gastos-hogar" `
  -f "$env:TEMP\gastos_hogar_deploy_key" -N ""
```

Subir nueva clave pública: Hostinger hPanel → SSH Access → Manage SSH Keys.
Actualizar secret en GitHub:
```powershell
cmd /c "gh secret set HOSTINGER_SSH_KEY --repo RodoDut/gastos-hogar < ""%TEMP%\gastos_hogar_deploy_key"""
```

---

## Seguridad implementada

| Medida | Ubicación |
|--------|-----------|
| CSRF tokens en todos los POST | `Auth::csrfField()` / `Auth::validateCsrf()` |
| `session_regenerate_id(true)` post-login | `Auth::login()` |
| Rate limiting (N intentos → lockout) | `Auth::login()` |
| Passwords con `password_hash(PASSWORD_DEFAULT)` | `UserService::createUser()` |
| Verificación con `password_verify()` | `Auth::login()` |
| Cookies: `HttpOnly`, `SameSite=Lax`, `Secure` | bootstrap de `index.php` (Strict rompía la apertura del sitio desde apps externas como WhatsApp) |
| Timeout de sesión configurable | `Auth::checkSessionTimeout()` |
| `flock(LOCK_EX)` en escritura de JSON | todos los `JsonRepository` |
| `data/` con permisos `700`, archivos `600` | post-deploy + repos |
| `data/.htaccess` con `Deny from all` | generado por repos |
| HTTP headers: X-Frame-Options, CSP, nosniff, Referrer-Policy | `index.php` |
| `display_errors` desactivado | `index.php` |
| Autorización backend en cada acción (no solo en UI) | `AuthorizationService` |
| Usuario desactivado no puede loguearse | `Auth::login()` verifica `active === true` |

---

## Decisiones técnicas

**¿Por qué JSON y no base de datos?**
Escala mínima (2-5 usuarios, pocos miles de registros). Los `Repository` están detrás de interfaces — migrar a SQLite no requiere tocar servicios ni controllers.

**¿Por qué `webfactory/ssh-agent`?**
Las claves SSH pegadas en GitHub Secrets desde Windows tienen problemas de CRLF. `webfactory/ssh-agent` carga la clave en el agente SSH del runner sin escribirla en disco, evitando todos los problemas de encoding.

**¿Por qué symlink y no copia de assets?**
Una copia se desincronizaría en cada deploy. El symlink apunta al mismo lugar físico — cuando rsync actualiza `public/assets/`, `public_html/assets/` lo refleja instantáneamente sin espacio adicional.

**¿Por qué `<<'ENDSSH'` no funciona en el post-deploy?**
El heredoc con comillas desactiva la expansión de variables en el runner de GitHub Actions. `$DEPLOY_PATH` llega como texto literal al servidor y los paths quedan vacíos. Usar siempre el comando SSH con string entre comillas dobles para que las variables se expandan antes de enviarse.

---

## Remember-me ("Recordarme")

Implementado con el patrón selector/validator (Barry Jaspan) en `src/Auth/RememberMeService.php` + `data/tokens.json`. Cookie `remember_me`, 15 días, `HttpOnly`, `Secure`, `SameSite=Lax`. Cada uso rota el token (selector/validator nuevos, el viejo se borra) y detecta reuso de un token ya rotado como robo (`deleteAllForUser`).

### Flujo correcto (post-fix)

Tanto el login manual como el auto-login por cookie **deben** terminar en un `header('Location: ...'); exit;` (redirect 302) después de `Auth::loginAs()`, nunca renderizar la página directo en la misma respuesta que setea el `Set-Cookie`. Antes de este fix, el auto-login no redirigía y la rotación del token fallaba en el primer uso posterior (ver `attemptLogin()` en `RememberMeService.php` y el bloque correspondiente en `public/index.php`).

### Comportamiento esperado por el usuario — importante

Después de tildar "Recordarme" (o de que la cookie se rote automáticamente al reabrir el sitio), **hay que esperar unos 15-20 segundos antes de cerrar el navegador o quitarlo de la lista de apps recientes**. Chrome no escribe cada cookie a disco de inmediato: la mantiene en memoria y la vuelca a un archivo SQLite de forma diferida. Si el proceso del navegador se mata de forma abrupta (swipe desde recientes) muy poco después de que el servidor mandó el `Set-Cookie`, esa escritura puede perderse y la sesión no se recuerda — no es un bug de la app, es comportamiento del motor de cookies del navegador. Confirmado en Android (Motorola G82, Samsung A16) y desktop (Chrome Windows).

Recomendación práctica: minimizar con el botón Home en vez de cerrar/quitar la app de recientes inmediatamente después de loguearse.

Descartado durante el diagnóstico de este comportamiento (no repetir como hipótesis nueva): SameSite=Strict vs Lax (ya está en Lax), multi-selector por usuario, WebView embebido de WhatsApp, cookies de terceros bloqueadas, sync de configuración de Chrome entre dispositivos, cache de LiteSpeed/HCDN de Hostinger, permisos de archivo en `tokens.json`, y condición de carrera por rotación en pestañas múltiples (el `Set-Cookie` se confirmó correcto vía DevTools y `tokens.json` se confirmó que ni rota ni se corrompe — la cookie simplemente no llega al servidor tras un cierre abrupto).

### Pendiente de limpieza

Sacar el mensaje de debug de `templates/login.html.php` (agregado en PR #3 para diagnosticar este mismo bug) ahora que la causa está confirmada y resuelta.

## Próximas features planificadas

- `feature/notifications`: Alertas por email cuando un usuario agrega un gasto.
- `feature/tickets`: Permite cargar comprobantes de cada gasto (PDF, JPG, PNG) y verlos en la lista de gastos. Lo interesante sería que se pudiera tomar una imagen con la cámara del celular y subirla directamente desde el navegador.
 - `feature/smart-recognition`: OCR de comprobantes para autocompletar descripción, monto y fecha.
- `feature/webauthn`: login por huella digital/Face ID usando WebAuthn API
