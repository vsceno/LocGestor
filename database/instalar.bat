@echo off
rem =====================================================================
rem LocGestor - instalação do banco de dados
rem Roda 00_roles.sql, 01_schema.sql, 02_seed.sql e (opcional) 03_demo.sql.
rem Use no Terminal do Laragon (psql e php no PATH).
rem
rem Pede: senha do postgres, senhas dos papéis locgestor_app e
rem locgestor_plataforma e a senha do usuário "suporte" da plataforma.
rem Use senhas só com letras, números, - e _ (sem & ^ % ! " espaço).
rem As mesmas senhas dos papéis vão no config.php.
rem
rem Para automatizar, defina antes as variáveis PGPASSWORD, SENHA_APP,
rem SENHA_PLATAFORMA, SENHA_SUPORTE e DEMO (S/N).
rem =====================================================================
setlocal
chcp 65001 >nul
cd /d "%~dp0"
set PGCLIENTENCODING=UTF8
if not defined PGHOST set PGHOST=localhost
if not defined PGPORT set PGPORT=5432

rem --- localiza psql e php (PATH ou pastas do Laragon) ---
where psql >nul 2>nul
if errorlevel 1 for /d %%d in (C:\laragon\bin\postgresql\*) do if exist "%%d\bin\psql.exe" set "PATH=%%d\bin;%PATH%"
where psql >nul 2>nul
if errorlevel 1 ( echo psql nao encontrado. Abra o Terminal do Laragon ou ponha o PostgreSQL no PATH. & goto erro )
where php >nul 2>nul
if errorlevel 1 for /d %%d in (C:\laragon\bin\php\*) do if exist "%%d\php.exe" set "PATH=%%d;%PATH%"
where php >nul 2>nul
if errorlevel 1 ( echo php nao encontrado. Abra o Terminal do Laragon ou ponha o PHP no PATH. & goto erro )

rem --- senhas (digitadas sem aparecer na tela) ---
if not defined PGPASSWORD call :ler_senha "Senha do usuario postgres (Enter se nao tiver)" PGPASSWORD
if not defined SENHA_APP call :ler_senha "Nova senha do papel locgestor_app" SENHA_APP
if not defined SENHA_PLATAFORMA call :ler_senha "Nova senha do papel locgestor_plataforma" SENHA_PLATAFORMA
if not defined SENHA_SUPORTE call :ler_senha "Senha do usuario suporte do painel da plataforma" SENHA_SUPORTE
if not defined SENHA_APP ( echo A senha do locgestor_app e obrigatoria. & goto erro )
if not defined SENHA_PLATAFORMA ( echo A senha do locgestor_plataforma e obrigatoria. & goto erro )
if not defined SENHA_SUPORTE ( echo A senha do suporte e obrigatoria. & goto erro )

set PSQL=psql -h %PGHOST% -p %PGPORT% -U postgres -v ON_ERROR_STOP=1 -q

rem --- não reinstala por cima de um banco existente ---
set EXISTE=
for /f %%r in ('%PSQL% -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = 'locgestor'"') do set EXISTE=%%r
if "%EXISTE%"=="1" (
    echo O banco locgestor ja existe. Para reinstalar do zero, apague-o antes:
    echo     psql -U postgres -c "DROP DATABASE locgestor"
    goto erro
)

rem --- hash da senha do suporte (só o hash vai para o banco) ---
set HASH_SUPORTE=
for /f "delims=" %%h in ('php -r "echo password_hash($argv[1], PASSWORD_DEFAULT);" "%SENHA_SUPORTE%"') do set "HASH_SUPORTE=%%h"
if not defined HASH_SUPORTE ( echo Falha ao gerar o hash da senha do suporte. & goto erro )

echo.
echo [1/4] Papeis e banco de dados...
%PSQL% -d postgres -v senha_app=%SENHA_APP% -v senha_plataforma=%SENHA_PLATAFORMA% -f 00_roles.sql || goto erro
echo [2/4] Estrutura (tabelas, RLS, views, permissoes)...
%PSQL% -d locgestor -f 01_schema.sql || goto erro
echo [3/4] Usuario da plataforma...
%PSQL% -d locgestor -v hash_suporte=%HASH_SUPORTE% -f 02_seed.sql || goto erro

if not defined DEMO set /p "DEMO=[4/4] Carregar as locadoras de demonstracao (alfa-locacoes e beta-eventos)? (S/N) "
if /i "%DEMO%"=="S" (
    %PSQL% -d locgestor -f 03_demo.sql || goto erro
    echo Demonstracao carregada. Empresas: alfa-locacoes e beta-eventos, usuario admin, senha admin123.
)

echo.
echo Instalacao concluida.
echo Agora copie config.example.php para config.php e preencha as senhas dos papeis.
echo Painel da plataforma: usuario suporte com a senha que voce digitou.
endlocal
exit /b 0

:erro
echo.
echo *** Instalacao interrompida. ***
endlocal
exit /b 1

rem Lê uma senha sem mostrar na tela: call :ler_senha "Pergunta" VARIAVEL
:ler_senha
set "%~2="
for /f "usebackq delims=" %%p in (`powershell -NoProfile -Command "$s = Read-Host '%~1' -AsSecureString; [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($s))"`) do set "%~2=%%p"
exit /b 0
