# GastosHogar

Aplicación web para registrar y balancear gastos compartidos entre los miembros de un hogar. Cada persona carga sus propios gastos, puede asignarlos a cualquier integrante, y la app calcula automáticamente quién aportó más y cuánto debe compensar el resto para quedar equitativos.

**Demo en producción:** https://gastos.rdtecno.net

---

## Funcionalidades

- **Registro de gastos** con descripción, monto, categoría y fecha, asignables a cualquier miembro del hogar (hasta 5 personas).
- **Cálculo de balances**: la app determina automáticamente cuánto le corresponde compensar a cada uno para equilibrar los gastos comunes.
- **Comprobantes adjuntos**: cada gasto puede tener una foto o PDF del ticket/factura asociado, con reemplazo y borrado seguros.
- **PWA instalable** con soporte de Web Share Target: en Android, se puede compartir una foto directo desde la cámara o galería hacia la app, sin pasar por el selector de archivos.
- **Sistema de usuarios con roles** (admin / member), con permisos diferenciados sobre quién puede editar o borrar cada gasto según quién lo cargó.
- **Sesión "Recordarme"** persistente y segura (rotación de tokens, detección de robo de cookies).
- **Panel de administración** para gestión de usuarios (alta, baja, reactivación).

## Arquitectura y stack

- **PHP 8.2**, sin framework, con arquitectura en capas inspirada en **Clean Architecture** y principios **SOLID**.
- Separación estricta por capas: `Auth`, `User`, `Expense`, `Person`, `View`, cada una con sus interfaces de repositorio.
- Persistencia en **JSON** (sin base de datos) mediante repositorios detrás de interfaces — preparado para migrar a SQLite sin tocar la lógica de negocio.
- **Punto único de autorización** (`AuthorizationService`): ninguna vista ni controller verifica permisos por su cuenta.
- **CI/CD con GitHub Actions**: validación automática de sintaxis PHP en cada PR, deploy automático a producción (Hostinger) en cada push a `main`.

## Seguridad

- CSRF tokens en todas las acciones de escritura.
- Contraseñas con `password_hash` / `password_verify` (bcrypt).
- Rate limiting con bloqueo temporal ante intentos fallidos de login.
- Cookies `HttpOnly`, `Secure`, `SameSite=Lax`.
- Verificación de tipo MIME real de archivos subidos (nunca se confía en la extensión declarada por el cliente).
- Comprobantes servidos por ruta protegida, nunca por acceso directo al archivo.
- Permisos de archivos restrictivos (`700`/`600`) y bloqueo de acceso directo al directorio de datos.

## Stack técnico

| Área | Tecnología |
|---|---|
| Backend | PHP 8.2 |
| Dependencias | Composer, `vlucas/phpdotenv` |
| Frontend | HTML, CSS, JavaScript (vanilla) |
| Datos | JSON (repositorios con interfaz, migrable a SQLite) |
| Contenedores | Docker / Docker Compose (desarrollo local) |
| CI/CD | GitHub Actions |
| Hosting | Hostinger (deploy automático vía SSH + rsync) |

## Licencia

MIT. Ver [`LICENSE`](./LICENSE).

---

Desarrollado por [RD-Tecno by Rodolfo M. Duttweiler](https://github.com/RodoDut).
