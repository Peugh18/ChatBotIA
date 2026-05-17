@echo off
REM Ejecuta una pasada del scheduler de Laravel.
REM Este BAT lo invoca la Tarea Programada de Windows cada minuto.
cd /d "%~dp0\.."
php artisan schedule:run >> storage\logs\scheduler.log 2>&1
