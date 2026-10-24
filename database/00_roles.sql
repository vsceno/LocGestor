-- =====================================================================
-- LocGestor - papéis (roles) e banco de dados
-- Roda UMA vez, conectado como postgres no banco "postgres":
--
--   psql -U postgres -d postgres -v senha_app=XXX -v senha_plataforma=YYY -f 00_roles.sql
--
-- Sem os -v, as senhas ficam 'TROQUE_AQUI'. TROQUE-AS antes de usar
-- (ALTER ROLE locgestor_app PASSWORD '...'). As senhas reais ficam só no config.php.
-- Pode ser rodado de novo: não recria o que já existe e apenas atualiza as senhas.
-- =====================================================================

\set ON_ERROR_STOP on

\if :{?senha_app}
\else
    \set senha_app 'TROQUE_AQUI'
\endif
\if :{?senha_plataforma}
\else
    \set senha_plataforma 'TROQUE_AQUI'
\endif

-- Dono das tabelas: não faz login; os scripts usam SET ROLE locgestor_owner
SELECT 'CREATE ROLE locgestor_owner NOLOGIN'
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'locgestor_owner') \gexec

-- Usuário do sistema das locadoras (o único que o index.php usa)
SELECT 'CREATE ROLE locgestor_app LOGIN'
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'locgestor_app') \gexec

-- Usuário do painel da plataforma (plataforma.php)
SELECT 'CREATE ROLE locgestor_plataforma LOGIN'
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'locgestor_plataforma') \gexec

-- Atributos garantidos mesmo se o papel já existia.
-- NOBYPASSRLS é o que impede o PHP de enxergar dados de outra locadora.
ALTER ROLE locgestor_owner      NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
ALTER ROLE locgestor_app        LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION
                                PASSWORD :'senha_app';
ALTER ROLE locgestor_plataforma LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION
                                PASSWORD :'senha_plataforma';

-- Banco de dados, de propriedade do locgestor_owner
-- (assim o owner pode criar as tabelas no schema public, e o app não)
SELECT 'CREATE DATABASE locgestor OWNER locgestor_owner ENCODING ''UTF8'' TEMPLATE template0'
 WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'locgestor') \gexec

ALTER DATABASE locgestor SET timezone TO 'America/Fortaleza';

-- Só os dois papéis de aplicação conectam; ninguém mais cria objetos temporários
REVOKE ALL ON DATABASE locgestor FROM PUBLIC;
GRANT CONNECT ON DATABASE locgestor TO locgestor_app, locgestor_plataforma;

SELECT (:'senha_app' = 'TROQUE_AQUI' OR :'senha_plataforma' = 'TROQUE_AQUI') AS senha_padrao \gset
\if :senha_padrao
    \echo '*** ATENÇÃO: há papéis com a senha TROQUE_AQUI. Troque com ALTER ROLE ... PASSWORD antes de usar. ***'
\endif
