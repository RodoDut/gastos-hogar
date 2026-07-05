# README — Developer

Documentación técnica interna del proyecto **Gastos del Hogar**.  
Última actualización: julio 2026 (comprobantes/tickets en producción; PWA + Web Share Target en curso en `develop`, ver sección correspondiente).

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
│   ├── manifest.json                  ← Web App Manifest (PWA + share_target, ver sección PWA)
│   └── assets/
│       ├── css, js
│       ├── js/sw.js                   ← Service worker minimo (solo requisito de instalabilidad, sin cache offline)
│       └── icons/ (icon-192.png, icon-512.png)
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
│   │   ├── Expense.php              ← Entidad: id, who, ownerId, desc, amt, cat, date, ticketFilename
│   │   ├── ExpenseRepositoryInterface.php
│   │   ├── JsonExpenseRepository.php
│   │   ├── TicketService.php        ← Validación/storage/serving de comprobantes (ver sección Comprobantes)
│   │   └── InvalidTicketException.php
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
│   ├── tokens.json                  ← Para feature remember-me
│   └── tickets/                     ← Comprobantes. IGNORADO por git (.gitignore)
│       └── pending/                 ← Comprobantes compartidos vía PWA sin gasto asociado todavía
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

## Comprobantes (tickets)

`feature/tickets` (mergeada a `main`, en producción). Cada gasto puede tener **un** comprobante (JPG/PNG/PDF) via el campo nullable `Expense::$ticketFilename`.

### Modelo y storage

- El nombre de archivo real nunca sale del cliente: `TicketService::store()` detecta el MIME real con `finfo_file()` (nunca `$_FILES['type']` ni la extensión declarada) y genera `{expenseId}_{random8hex}.{ext}`.
- Se guarda en `data/tickets/` (chmod 700, `.htaccess Deny from all`, igual patrón que el resto de `data/`).
- Tamaño máx configurable via `Config::$ticketMaxBytes` (default 4MB, override opcional `TICKET_MAX_BYTES` en `.env`).
- Se sirve por ruta protegida `?page=ticket&eid={expenseId}` — el lookup es siempre por `eid` via `$expRepo->findById()`, nunca por filename crudo del cliente. Elimina cualquier path traversal/IDOR de raíz.

### Autorización — asimétrica a propósito

- **Ver** el comprobante: cualquier miembro logueado (mismo criterio que ya se aplica a ver gastos ajenos).
- **Subir/reemplazar/quitar**: solo el owner del gasto, via `AuthorizationService::canEditExpense()`.

### Acciones (`public/index.php`)

| Acción | Qué hace |
|---|---|
| `add` (existente, extendida) | Si viene `$_FILES['ticket']`, lo valida/guarda antes de crear el `Expense` |
| `attach_ticket` | Reemplaza el comprobante de un gasto existente. Guarda el nuevo ANTES de borrar el viejo (si el nuevo es inválido, no se pierde el anterior) |
| `remove_ticket` | Quita el comprobante y la key `ticket` del JSON |
| `del` (existente) | Además de borrar el gasto, borra el archivo de ticket asociado si existe (sin huérfanos) |

Spinner indeterminado (`#ticketOverlay` + `.spinner` en `app.css`/`app.js`) durante las 3 acciones — feedback visual entre el submit y la navegación al redirect, no un progreso real en % (el patrón POST-Redirect-GET actual no se tocó).

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
/home/<HOSTINGER_USER>/domains/gastos.rdtecno.net/data/people.json
```

**Opción 2 — SCP desde Git Bash (no desde PowerShell/CMD):**
```bash
# Desde Git Bash, no PowerShell
scp -P <HOSTINGER_SSH_PORT> -i <PATH_TO_DEPLOY_KEY> \
  data/people.json \
  <HOSTINGER_USER>@<HOSTINGER_IP>:/home/<HOSTINGER_USER>/domains/gastos.rdtecno.net/data/people.json
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
| `HOSTINGER_HOST` | `<HOSTINGER_IP>` |
| `HOSTINGER_SSH_PORT` | `<HOSTINGER_SSH_PORT>` |
| `HOSTINGER_USER` | `<HOSTINGER_USER>` |
| `HOSTINGER_REMOTE_PATH` | `/home/<HOSTINGER_USER>/domains/gastos.rdtecno.net` |
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
/home/<HOSTINGER_USER>/domains/gastos.rdtecno.net/
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
ssh -i <PATH_TO_DEPLOY_KEY> \
    -p <HOSTINGER_SSH_PORT> <HOSTINGER_USER>@<HOSTINGER_IP>
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

---

## PWA + Web Share Target — EN CURSO

**Rama:** `feature/pwa-manifest`, mergeada a `develop` (commit local `f5b7ff1`, merge `503b570`) y luego a `main` (merge `026e91f`). **Ya está en producción.** El 404 de `manifest.json` detectado tras ese deploy se corrigió en `fix/pwa-manifest-symlink` (ver sección "`public_html` no espeja 1:1 la carpeta `public/`" más abajo).

### Objetivo

Convertir el sitio en PWA instalable para que Android la ofrezca como destino en el menú nativo de "Compartir" (Web Share Target API) — compartir una foto/PDF desde la cámara/galería directo hacia el form de alta de gasto, sin pasar por el selector de archivos.

### Implementado

- `public/manifest.json`: manifest instalable + bloque `share_target` (`action: /index.php?page=share_ticket`, acepta `image/jpeg`, `image/png`, `application/pdf`).
- `public/assets/js/sw.js`: service worker mínimo (`skipWaiting`/`clients.claim`/pass-through de `fetch`), sin cache offline — solo cumple el requisito de instalabilidad de Chrome.
- `public/assets/icons/icon-192.png` e `icon-512.png`: íconos reales (casita sobre el gradiente `#4f46e5`→`#7c3aed` del header).
- `TicketService::storePending()` / `promotePending()` / `cleanPending()`: comprobantes compartidos sin gasto asociado aún van a `data/tickets/pending/`, se promueven a `data/tickets/` recién al completar el alta del gasto.
- Ruta `?page=share_ticket` en `index.php`, resuelta **antes** del gate de login global (bug real encontrado y corregido durante la implementación: si no, un POST sin sesión activa caía en el formulario de login completo en vez del mensaje mínimo esperado).
- Sin CSRF en `share_ticket` a propósito: el POST lo arma el share sheet nativo de Android, no hay forma de inyectar el token, y no es explotable desde una página web de terceros (el share sheet es mediado por el SO).
- UI: `templates/app.html.php` muestra "🧾 Comprobante listo para adjuntar" + `pending_ticket` hidden cuando llega `?pending_ticket=` en la URL.

### Verificado

- Service worker: `#activated and is running`, sin errores (confirmado en `chrome://inspect` remoto sobre el Moto G82 vía `adb reverse tcp:8080 tcp:8080`).
- Manifest: Identity, Icons (192 y 512 cargan bien) sin errores de instalabilidad reales — los únicos warnings son opcionales (screenshots para Richer Install UI, `display-override`, `protocol_handlers`), ninguno bloquea instalación.
- `php -l` limpio en todos los `.php` tocados.
- Flujo `share_ticket` probado manualmente con POST simulado (curl/Postman con cookie de sesión): guarda en `pending/`, redirige con `pending_ticket`, `promotePending()` mueve el archivo al completar el alta.

### Pendiente / bloqueado

**El problema activo:** en el Moto G82 (Android, Chrome), el menú ⋮ solo ofrece "Agregar a la pantalla principal" (shortcut simple, sin manifest/SW real), nunca "Instalar app" (instalación PWA real, única que habilita el share target). Con manifest e íconos verificados sin errores, la hipótesis más probable es el **heurístico de "engagement"** que usa Chrome antes de ofrecer la instalación completa (visitas repetidas en días distintos), no un bug del código.

**Próximo paso a probar:** desde `chrome://inspect#devices` en la laptop, con la pestaña del Moto G82 abierta, en el panel **Application → Manifest** hay un ícono ("Add to homescreen") que dispara el flujo de instalación real salteándose el heurístico — todavía no se probó. Si con eso instala completo, confirma que el manifest está 100% correcto y solo faltaba tiempo/uso repetido. Si aun así no ofrece instalar, hay que revisar de nuevo Network tab (íconos con 200 real, no cacheados con error) y la consola del `chrome://inspect` por errores silenciosos de JS al registrar el SW.

**Herramienta nueva disponible:** `chrome-devtools-mcp` ya está instalado y conectado en Claude Code (`claude mcp add chrome-devtools --scope user npx chrome-devtools-mcp@latest`, scope user). Permite que el propio Claude Code inspeccione consola/red/manifest de una instancia de Chrome sin que haya que sacar capturas de pantalla manualmente. No probado aún contra Chrome de Android via remote debugging (`--browserUrl`), solo confirmado funcionando contra Chrome desktop.

**Una vez resuelta la instalación:** falta la prueba end-to-end real de compartir una foto desde Android hacia la PWA instalada y confirmar que aparece "GastosHogar"/"Gastos" en el share sheet, y luego mergear `develop` → `main` siguiendo el flujo normal (push develop, validate.yml en verde, merge a main, push main, deploy.yml).

### Detalle no incluido en el manifest

Hay un `public/assets/icons/icon.png` de 1254×1254 (1.3MB) sin optimizar, dejado como posible fuente para versiones futuras de mayor densidad — no está declarado en `manifest.json` y no debería servirse tal cual (sin comprimir) si se llega a usar.

### `public_html` no espeja 1:1 la carpeta `public/`

`public_html/` en el servidor solo contiene `index.php` (proxy) y symlinks explícitos creados en el paso "Post-deploy setup" de `deploy.yml` — no es una copia ni un symlink de toda la carpeta `public/`. Cualquier archivo estático nuevo que se agregue en la **raíz** de `public/` (no dentro de `public/assets/`) necesita su propio `ln -sfn` explícito en ese paso, o quedará inaccesible (404) aunque el rsync lo haya copiado correctamente al servidor. Así se detectó y corrigió el 404 de `manifest.json` en producción: el archivo se desplegaba bien vía rsync pero no había symlink hacia él en `public_html/`.

---

## Próximas features planificadas

- `feature/notifications`: Alertas por email cuando un usuario agrega un gasto.
- `feature/smart-recognition`: OCR de comprobantes para autocompletar descripción, monto y fecha.
- `feature/webauthn`: login por huella digital/Face ID usando WebAuthn API
- Google Drive Picker API para adjuntar comprobantes directo desde Drive (evaluado, descartado por ahora por complejidad: requiere proyecto en Google Cloud Console, OAuth, Picker API + Drive API. Workaround actual: descargar el archivo al dispositivo antes de subirlo, ya que subir directo desde Drive en modo streaming falla con `ERR_UPLOAD_FILE_CHANGED`).
