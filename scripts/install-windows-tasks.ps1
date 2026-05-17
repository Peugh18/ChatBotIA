# Instala las tareas programadas de Windows para Roma CRM.
# EJECUTAR COMO ADMINISTRADOR.
#
# Uso:
#   powershell -ExecutionPolicy Bypass -File scripts\install-windows-tasks.ps1

$ErrorActionPreference = 'Stop'

$projectRoot = Resolve-Path "$PSScriptRoot\.."
$schedulerBat = Join-Path $projectRoot 'scripts\scheduler-run.bat'
$queueBat     = Join-Path $projectRoot 'scripts\queue-work.bat'

if (-not (Test-Path $schedulerBat)) { throw "No se encuentra $schedulerBat" }
if (-not (Test-Path $queueBat))     { throw "No se encuentra $queueBat" }

Write-Host "Project root: $projectRoot" -ForegroundColor Cyan

# 1) Scheduler: cada minuto
$schedTaskName = 'RomaCRM-LaravelScheduler'
schtasks /Delete /TN $schedTaskName /F 2>$null | Out-Null
schtasks /Create `
  /TN $schedTaskName `
  /SC MINUTE /MO 1 `
  /TR "`"$schedulerBat`"" `
  /RL HIGHEST /F | Out-Null
Write-Host "OK  Tarea creada: $schedTaskName (cada minuto)" -ForegroundColor Green

# 2) Queue worker: al iniciar sesión + reinicia si muere
$queueTaskName = 'RomaCRM-QueueWorker'
schtasks /Delete /TN $queueTaskName /F 2>$null | Out-Null
schtasks /Create `
  /TN $queueTaskName `
  /SC ONLOGON `
  /TR "`"$queueBat`"" `
  /RL HIGHEST /F | Out-Null
Write-Host "OK  Tarea creada: $queueTaskName (al iniciar sesión)" -ForegroundColor Green

Write-Host ""
Write-Host "Para ejecutar el queue worker AHORA sin reiniciar sesión:" -ForegroundColor Yellow
Write-Host "    schtasks /Run /TN $queueTaskName" -ForegroundColor Yellow
Write-Host ""
Write-Host "Para desinstalar:" -ForegroundColor Yellow
Write-Host "    schtasks /Delete /TN $schedTaskName /F" -ForegroundColor Yellow
Write-Host "    schtasks /Delete /TN $queueTaskName /F" -ForegroundColor Yellow
