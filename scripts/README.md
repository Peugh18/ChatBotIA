# Roma CRM — Scripts de operación (Windows)

## Archivos

- `scheduler-run.bat` — invoca `php artisan schedule:run` una vez.
- `queue-work.bat` — corre el worker de colas en primer plano.
- `install-windows-tasks.ps1` — registra ambos como tareas programadas.

## Instalación (una sola vez)

Abrir **PowerShell como Administrador** en la raíz del proyecto y ejecutar:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\install-windows-tasks.ps1
```

Esto crea:

| Tarea                       | Trigger          | Qué hace |
|-----------------------------|------------------|----------|
| `RomaCRM-LaravelScheduler`  | cada minuto      | `schedule:run` (dispara los Jobs definidos en `routes/console.php`) |
| `RomaCRM-QueueWorker`       | al iniciar sesión | `queue:work` para procesar Jobs en cola |

## Verificar

```powershell
schtasks /Query /TN RomaCRM-LaravelScheduler
schtasks /Query /TN RomaCRM-QueueWorker
```

Logs:

- Scheduler: `storage/logs/scheduler.log`
- Queue worker: salida estándar (si lo querés persistir, redirigir en `queue-work.bat`).

## Iniciar el worker ahora sin reiniciar sesión

```powershell
schtasks /Run /TN RomaCRM-QueueWorker
```

## Desinstalar

```powershell
schtasks /Delete /TN RomaCRM-LaravelScheduler /F
schtasks /Delete /TN RomaCRM-QueueWorker /F
```

## Producción robusta (recomendado)

Para que el queue worker se reinicie automáticamente si crashea, instalá **NSSM**
(https://nssm.cc/) y registralo como servicio:

```powershell
nssm install RomaCRMQueue "D:\TiomiguelonGgs\Desktop\Whatsaap\scripts\queue-work.bat"
nssm set RomaCRMQueue AppDirectory "D:\TiomiguelonGgs\Desktop\Whatsaap"
nssm start RomaCRMQueue
```
