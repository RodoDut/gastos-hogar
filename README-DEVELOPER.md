# README — Developer

Documentación técnica interna del proyecto **Gastos del Hogar**.  
Última actualización: junio 2026.

---

## Qué es este proyecto

App web PHP para registrar y balancear gastos compartidos entre dos o más personas del hogar. Cada persona carga sus gastos, la app calcula quién aportó más y cuánto debe compensar el otro para quedar 50/50 (o proporcional al número de personas).

**URL de producción:** https://gastos.rdtecno.net  
**Repositorio:** https://github.com/RodoDut/gastos-hogar

---

## Arquitectura

El proyecto sigue principios **SOLID** y una arquitectura en capas inspirada en **Clean Architecture**:

```
GastosHogar/
├── public/              ← Document root real de la app
│   ├── index.php        ← Entry point único (front controller)
│   └── assets/
│       ├── css/app.css
│       └── js/app.js
├── src/                 ← Lógica de negocio (fuera del document root)
│   ├── Config.php       ← Configuración centralizada desde .env
│   ├── helpers.php      ← Funciones globales: e(), money(), asset()
│   ├── Auth/
│   │   └── Auth.php     ← Login, logout, CSRF, timeout de sesión
│   ├── Expense/
│   │   ├── Expense.php                  ← Entidad (Value Object)
│   │   ├── ExpenseRepositoryInterface.php ← Contrato (Dependency Inversion)
│   │   └── JsonExpenseRepository.php    ← Implementación con archivo JSON
│   ├── Person/
│   │   ├── Person.php
│   │   ├── PersonRepositoryInterface.php
│   │   └── JsonPersonRepository.php
│   └── View/
│       └── View.php                     ← Renderizador de templates
├── templates/           ← Vistas PHP (fuera del document root)
│   ├── app.html.php
│   ├── login.html.php
│   └── settings.html.php
├── data/                ← Archivos JSON de datos (fuera del document root)
│   ├── gastos.json      ← Gastos registrados
│   └── people.json      ← Personas configuradas
├── vendor/              ← Dependencias Composer (fuera del document root)
├── .env                 ← Variables de entorno (NUNCA commitear)
├── .env.example         ← Plantilla de .env para nuevos entornos
└── composer.json
```

### Por qué esta separación

Solo `public/` es accesible desde el navegador. Todo lo demás (src, templates, data, vendor, .env) está físicamente fuera del document root. Esto garantiza que:
- Nadie puede acceder a `gastos.json` o `.env` desde la web
- La seguridad no depende solo de `.htaccess` sino de la estructura de directorios

---

## Dependencias

| Paquete | Versión | Uso |
|---------|---------|-----|
| `vlucas/phpdotenv` | ^5.6 | Carga variables de entorno desde `.env` |
| PHP | >=8.1 | Lenguaje base |

Instalación:
```bash
composer install
```

---

## Configuración local

### 1. Requisitos
- PHP 8.1+
- Composer
- Docker (opcional, para entorno local idéntico a producción)

### 2. Clonar y configurar
```bash
git clone https://github.com/RodoDut/gastos-hogar.git
cd gastos-hogar
composer install
cp .env.example .env
```

Editar `.env`:
```env
APP_PASS=tu_contraseña_aqui
DATA_FILE=data/gastos.json
SESSION_TTL=3600
MAX_ATTEMPTS=3
LOCKOUT_SEC=60
```

### 3. Levantar con Docker
```bash
docker-compose up
```
La app queda disponible en `http://localhost:8080`.

### 4. Sin Docker (PHP built-in server)
```bash
php -S localhost:8080 -t public/
```

---

## Contraseña de acceso

`APP_PASS` en `.env` puede ser texto plano o un hash bcrypt. Para mayor seguridad en producción usar hash:

```bash
php -r "echo password_hash('mi_contraseña', PASSWORD_BCRYPT);"
```

Pegar el hash resultante (empieza con `$2y$`) como valor de `APP_PASS`. La app detecta automáticamente si es texto plano o hash y usa `password_verify()` en consecuencia.

---

## Personas

Las personas se gestionan desde la pantalla **Configuración** dentro de la app (no desde el código ni el .env). Se guardan en `data/people.json`. No se puede eliminar una persona si tiene gastos registrados.

---

## Seguridad implementada

| Medida | Dónde |
|--------|-------|
| CSRF tokens en todos los formularios POST | `Auth::csrfField()` / `Auth::validateCsrf()` |
| Session fixation: `session_regenerate_id(true)` post-login | `Auth::login()` |
| Rate limiting: bloqueo tras N intentos fallidos | `Auth::login()` / `Auth::getLockoutMessage()` |
| Contraseña con `hash_equals()` o `password_verify()` | `Auth::verifyPassword()` |
| Cookies con `HttpOnly`, `SameSite=Strict`, `Secure` | `public/index.php` bootstrap |
| Timeout de sesión configurable | `Auth::checkSessionTimeout()` |
| Datos guardados sin HTML encoding (e() solo al renderizar) | `JsonExpenseRepository` |
| Escritura de JSON con `flock()` para evitar race conditions | `JsonExpenseRepository::write()` |
| Directorio `data/` protegido con `.htaccess` `Deny from all` | Generado automáticamente al crear |
| Permisos `0700` en `data/`, `0600` en archivos JSON | `JsonExpenseRepository::write()` |
| HTTP headers: X-Frame-Options, CSP, nosniff, Referrer-Policy | `public/index.php` |
| Validación de formato para `month` (YYYY-MM) y `date` (Y-m-d) | `validMonth()` / `validDate()` |
| `display_errors` desactivado | `public/index.php` |

---

## Flujo de datos

```
Browser → public/index.php
           ├── Bootstrap: Dotenv, Config, Repos, Auth, View
           ├── Session hardening + CSRF
           ├── Auth check → login.html.php si no autenticado
           ├── Router por $_GET['page']:
           │   ├── 'settings' → gestión de personas
           │   └── default    → app principal
           └── View::render() → template + extract($data)
```

---

## CI/CD — GitHub Actions

### Workflows

| Archivo | Trigger | Qué hace |
|---------|---------|----------|
| `validate.yml` | Push a `develop`, PR a `main` | Valida `composer.json` y sintaxis PHP |
| `deploy.yml` | Push a `main` | Deploy completo a Hostinger |

### Flujo de deploy (`deploy.yml`)

1. **Checkout** del repositorio
2. **Setup PHP 8.2** con `shivammathur/setup-php`
3. **Composer install** sin dependencias de dev (`--no-dev --optimize-autoloader`)
4. **Setup SSH agent** con `webfactory/ssh-agent` — carga la clave privada en el agente sin escribirla en disco (evita problemas de encoding de claves en Windows)
5. **ssh-keyscan** con puerto correcto para poblar `known_hosts`
6. **rsync** de los archivos al servidor (excluye `.env`, `data/`, archivos Docker, `.git/`)
7. **Crear `.env`** en el servidor solo si no existe (usa `scp` para evitar problemas de quoting en SSH heredoc)
8. **Post-deploy**: crea `data/` con permisos `700`

### Secrets de GitHub requeridos

| Secret | Descripción |
|--------|-------------|
| `HOSTINGER_SSH_KEY` | Clave privada SSH (sin passphrase, generada con Git's ssh-keygen) |
| `HOSTINGER_HOST` | IP o hostname del servidor |
| `HOSTINGER_SSH_PORT` | Puerto SSH (Hostinger usa <HOSTINGER_SSH_PORT>) |
| `HOSTINGER_USER` | Usuario SSH de Hostinger |
| `HOSTINGER_REMOTE_PATH` | Ruta absoluta en el servidor donde se despliega |
| `APP_PASS` | Contraseña de acceso a la app (para crear .env en primer deploy) |

### Flujo de trabajo recomendado

```
feature branch → develop (valida CI) → PR a main → merge → deploy automático
```

---

## Producción — Hostinger

### Estructura en el servidor

```
/home/<HOSTINGER_USER>/domains/gastos.rdtecno.net/   ← HOSTINGER_REMOTE_PATH
├── src/
├── templates/
├── vendor/
├── data/           ← excluido del rsync, creado por post-deploy
│   ├── .htaccess   ← "Deny from all" generado automáticamente
│   ├── gastos.json
│   └── people.json
├── .env            ← excluido del rsync, creado en primer deploy
├── .htaccess       ← redirige todo a public/ (para el root del proyecto)
├── composer.json
├── composer.lock
└── public_html/    ← document root del subdominio (Apache lo usa aquí)
    ├── index.php   ← proxy al entry point real: require '../public/index.php'
    ├── .htaccess   ← routing: todo a index.php excepto archivos estáticos
    └── assets      ← SYMLINK → ../public/assets (no es una copia)
```

### El problema del document root en Hostinger

Hostinger en shared hosting usa `public_html/` como document root para cada subdominio. La app fue diseñada con `public/` como document root (estándar de proyectos PHP modernos).

**Solución implementada:** en lugar de mover los archivos o duplicar assets, se usa:

1. `public_html/index.php` — proxy de una línea:
   ```php
   <?php require_once __DIR__ . '/../public/index.php';
   ```

2. `public_html/assets` — **symlink** (no carpeta) que apunta a `public/assets`:
   ```bash
   ln -s /home/<HOSTINGER_USER>/domains/gastos.rdtecno.net/public/assets \
         /home/<HOSTINGER_USER>/domains/gastos.rdtecno.net/public_html/assets
   ```

**Por qué symlink y no copia:** con una copia los assets se desincronizarían en cada deploy. El symlink es una entrada de directorio que apunta al mismo lugar físico — cuando el deploy actualiza `public/assets/`, `public_html/assets/` refleja el cambio instantáneamente sin ocupar espacio adicional.

**Si alguna vez se pierde el symlink** (ej: limpieza manual del servidor), recrearlo con:
```bash
ssh -i %TEMP%\gastos_hogar_deploy_key -p <HOSTINGER_SSH_PORT> <HOSTINGER_USER>@<HOSTINGER_IP>
rm -rf ~/domains/gastos.rdtecno.net/public_html/assets
ln -s ~/domains/gastos.rdtecno.net/public/assets \
      ~/domains/gastos.rdtecno.net/public_html/assets
```

---

## SSH al servidor

La clave SSH del proyecto se generó con Git's `ssh-keygen` (no el de Windows System32, que tiene problemas con passphrase vacía):

```powershell
# Generar nueva clave (solo si es necesario regenerar)
& "C:\Program Files\Git\usr\bin\ssh-keygen.exe" `
  -t ed25519 -C "deploy@gastos-hogar" `
  -f "$env:TEMP\gastos_hogar_deploy_key" -N ""
```

**Clave pública** (`%TEMP%\gastos_hogar_deploy_key.pub`): debe estar cargada en Hostinger hPanel → SSH Access → SSH Keys.

**Clave privada** (`%TEMP%\gastos_hogar_deploy_key`): debe estar cargada en GitHub Secrets como `HOSTINGER_SSH_KEY`. Usar `gh secret set` para evitar problemas de encoding del navegador:

```powershell
cmd /c "gh secret set HOSTINGER_SSH_KEY --repo RodoDut/gastos-hogar < ""%TEMP%\gastos_hogar_deploy_key"""
```

Conexión manual al servidor:
```powershell
& "C:\Program Files\Git\usr\bin\ssh.exe" `
  -i "$env:TEMP\gastos_hogar_deploy_key" `
  -p <HOSTINGER_SSH_PORT> <HOSTINGER_USER>@<HOSTINGER_IP>
```

---

## Almacenamiento de datos

Sin base de datos. Los datos se persisten en archivos JSON dentro de `data/`:

- `gastos.json` — gastos con campos: `id`, `who` (id de persona), `desc`, `amt`, `cat`, `date`
- `people.json` — personas con campos: `id`, `name`

La escritura usa `flock(LOCK_EX)` para evitar corrupción si dos usuarios guardan simultáneamente. Los archivos tienen permisos `0600` y el directorio `0700`.

---

## Decisiones técnicas relevantes

**¿Por qué JSON y no MySQL?**  
Es una app personal de dos personas con tráfico mínimo. JSON evita configurar y mantener una base de datos. Si la app escalara a múltiples hogares, migrar a MySQL sería el siguiente paso natural — los `Repository` están desacoplados detrás de interfaces, por lo que cambiar la implementación no requiere tocar el resto del código.

**¿Por qué no WordPress?**  
El proyecto nació como un ejercicio de PHP puro con buenas prácticas, independiente del ecosistema WordPress.

**¿Por qué `webfactory/ssh-agent` en el deploy?**  
Los métodos manuales (`echo "$KEY" > archivo`) fallan cuando la clave tiene saltos de línea que el navegador de Windows convierte a CRLF al pegar en GitHub Secrets. `webfactory/ssh-agent` carga la clave directamente en el agente SSH del runner sin escribirla en disco, evitando todos los problemas de encoding.
