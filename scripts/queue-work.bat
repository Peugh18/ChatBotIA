@echo off
REM Inicia el worker de colas de Laravel en primer plano.
REM Para producción, considera ejecutar este script bajo NSSM como servicio de Windows.
cd /d "%~dp0\.."
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
