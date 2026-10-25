-- =====================================================================
-- LocGestor - diagnóstico e senha do PostgreSQL (antes de trocar 'trust' por senha)
--
-- Só diagnóstico (não altera nada):
--   psql -U postgres -d postgres -f database\seguranca_postgres.sql
--
-- Define a senha do postgres (e mostra o diagnóstico):
--   psql -U postgres -d postgres -v senha_postgres=SENHA_FORTE -f database\seguranca_postgres.sql
--
-- Este script NÃO mexe no pg_hba.conf. O passo a passo completo está no README,
-- seção "Exigir senha no PostgreSQL". Lembre que outros projetos do Laragon
-- podem usar este mesmo servidor e precisar de senha nos seus .env.
-- =====================================================================

\set ON_ERROR_STOP on

\if :{?senha_postgres}
    ALTER ROLE postgres PASSWORD :'senha_postgres';
    \echo '>>> Senha do postgres definida.'
\endif

\echo
\echo '== Papéis que fazem login e como a senha está guardada'
\echo '   (todos precisam de senha scram-sha-256 antes de trocar o pg_hba.conf)'
SELECT rolname AS papel,
       CASE WHEN rolpassword IS NULL                 THEN '*** SEM SENHA: definir antes de trocar o pg_hba.conf ***'
            WHEN rolpassword LIKE 'SCRAM-SHA-256$%'  THEN 'scram-sha-256 (ok)'
            WHEN rolpassword LIKE 'md5%'             THEN 'md5 (antigo: redefina a senha)'
            ELSE 'outro' END AS senha,
       rolsuper AS superusuario
  FROM pg_authid
 WHERE rolcanlogin
 ORDER BY rolname;

\echo '== Bancos (projetos que usam este servidor)'
SELECT datname AS banco, pg_get_userbyid(datdba) AS dono FROM pg_database WHERE NOT datistemplate ORDER BY 1;

\echo '== Regras de acesso atuais (pg_hba.conf)'
SELECT line_number AS linha, type AS tipo, database AS bancos, user_name AS usuarios, address AS endereco,
       auth_method AS metodo,
       CASE WHEN auth_method = 'trust' THEN '<- sem senha: trocar por scram-sha-256' ELSE '' END AS atencao
  FROM pg_hba_file_rules ORDER BY line_number;

SELECT current_setting('password_encryption') AS criptografia_de_senha_padrao,
       current_setting('hba_file') AS arquivo_pg_hba;
