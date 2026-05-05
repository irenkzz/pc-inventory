@echo off
setlocal
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install_runner.ps1" %*
endlocal
