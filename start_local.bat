@echo off
echo ==========================================
echo   Servidor local BF10 Web
echo   http://localhost:8080
echo   API proxied a produccion
echo   Ctrl+C para parar
echo ==========================================
echo.
php -S localhost:8080 router.php
