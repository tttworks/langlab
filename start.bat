@echo off
setlocal
title LangLab Launcher

REM Generic launcher -- requires php and npm to be on your PATH.
REM Windows: double-click this file.  Other OS: see README.

set "BACKEND_PORT=8001"
set "FRONTEND_PORT=5174"
set "ROOT=%~dp0"
set "BACKEND_DIR=%ROOT%backend\public"
set "FRONTEND_DIR=%ROOT%frontend"

where php >nul 2>&1 || (echo [ERROR] php not found on PATH. & pause & exit /b 1)
where npm >nul 2>&1 || (echo [ERROR] npm not found. Install Node.js first. & pause & exit /b 1)

if not exist "%FRONTEND_DIR%\node_modules" (
    echo [i] Installing frontend dependencies for the first run ...
    pushd "%FRONTEND_DIR%" && call npm install && popd
)

echo [1/2] Starting backend  on :%BACKEND_PORT% ...
start "LangLab Backend" /min cmd /c "php -d upload_max_filesize=128M -d post_max_size=128M -d memory_limit=512M -d max_execution_time=600 -S 127.0.0.1:%BACKEND_PORT% -t "%BACKEND_DIR%""

echo [2/2] Starting frontend on :%FRONTEND_PORT% ...
start "LangLab Frontend" /min cmd /c "cd /d "%FRONTEND_DIR%" && npm run dev"

echo Waiting for frontend ...
for /l %%I in (1,1,30) do (
    netstat -ano | findstr ":%FRONTEND_PORT%" | findstr "LISTENING" >nul 2>&1
    if not errorlevel 1 goto ready
    ping -n 2 127.0.0.1 >nul
)
echo [WARN] Frontend did not answer within 30s -- check the minimized window.
pause
exit /b 1

:ready
start "" "http://127.0.0.1:%FRONTEND_PORT%"
