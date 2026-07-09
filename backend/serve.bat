@echo off
REM Run the SKULOS backend locally with the correct PHP 8.3.
REM Double-click this file, or run it from a terminal in the backend folder.
set "PHP=%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
if not exist "%PHP%" (
  echo Could not find PHP 8.3 at "%PHP%".
  echo See CLAUDE.md "Local environment reality" to reinstall it.
  pause
  exit /b 1
)
echo Starting SKULOS at http://localhost:8000
echo Sign in at http://localhost:8000/login
echo Press Ctrl+C to stop.
"%PHP%" artisan serve --host=localhost --port=8000
