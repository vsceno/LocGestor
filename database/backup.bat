@echo off
rem =====================================================================
rem LocGestor - backup do banco de dados
rem
rem Gera um pg_dump compactado (formato custom) do banco locgestor, confere se
rem o arquivo é legível e mantém só os %MANTER% backups mais recentes.
rem Agendado pelo Agendador de Tarefas do Windows (veja o README).
rem
rem Variáveis opcionais (defina antes de chamar, ou no agendamento):
rem   BACKUP_DIR  pasta dos backups  (padrão: C:\laragon\backups\locgestor)
rem   MANTER      quantos guardar     (padrão: 30)
rem   PGHOST, PGPORT
rem Se o usuário postgres tiver senha, coloque-a no arquivo
rem %APPDATA%\postgresql\pgpass.conf  (linha: localhost:5432:*:postgres:SENHA)
rem
rem Restaurar: veja "Backup e restauração" no README.
rem =====================================================================
setlocal
chcp 65001 >nul
if not defined PGHOST set PGHOST=localhost
if not defined PGPORT set PGPORT=5432
rem Fora da pasta do site: backup nunca pode ser baixado pelo navegador
if not defined BACKUP_DIR set "BACKUP_DIR=C:\laragon\backups\locgestor"
if not defined MANTER set MANTER=30

rem --- pg_dump do Laragon (a versão mais nova encontrada, igual à do servidor) ---
for /d %%d in (C:\laragon\bin\postgresql\*) do if exist "%%d\bin\pg_dump.exe" set "PATH=%%d\bin;%PATH%"
where pg_dump >nul 2>nul
if errorlevel 1 ( echo pg_dump nao encontrado. & exit /b 1 )

if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"
for /f %%t in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd_HHmmss"') do set TS=%%t
set "ARQ=%BACKUP_DIR%\locgestor_%TS%.dump"

pg_dump -h %PGHOST% -p %PGPORT% -U postgres -w -Fc -f "%ARQ%" locgestor
if errorlevel 1 goto erro

rem Um backup só vale se puder ser lido: lista o conteúdo do arquivo
pg_restore --list "%ARQ%" >nul
if errorlevel 1 goto erro

rem Mantém só os %MANTER% mais recentes
powershell -NoProfile -Command "Get-ChildItem -LiteralPath '%BACKUP_DIR%' -Filter 'locgestor_*.dump' | Sort-Object LastWriteTime -Descending | Select-Object -Skip %MANTER% | Remove-Item -Force"

for %%f in ("%ARQ%") do echo [%date% %time%] OK %%~nxf (%%~zf bytes)>> "%BACKUP_DIR%\backup.log"
echo Backup gravado em %ARQ%
endlocal
exit /b 0

:erro
echo [%date% %time%] FALHOU - confira se o PostgreSQL esta ligado e a senha do postgres>> "%BACKUP_DIR%\backup.log"
echo *** Backup FALHOU. Veja %BACKUP_DIR%\backup.log ***
if exist "%ARQ%" del "%ARQ%"
endlocal
exit /b 1
