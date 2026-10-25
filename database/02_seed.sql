-- =====================================================================
-- LocGestor - dados iniciais da plataforma
-- Roda como postgres, no banco locgestor, depois de 01_schema.sql.
--
-- Cria o usuário "suporte" do painel da plataforma. O hash da senha é
-- passado por variável, para nenhum hash real ficar no GitHub:
--
--   php -r "echo password_hash($argv[1], PASSWORD_DEFAULT);" "SuaSenhaForte"
--   psql -U postgres -d locgestor -v hash_suporte='<hash gerado>' -f 02_seed.sql
--
-- Sem o -v, o usuário é criado com um hash inválido e NÃO consegue entrar
-- até a senha ser definida.
-- =====================================================================

\set ON_ERROR_STOP on
SET client_encoding = 'UTF8';

\if :{?hash_suporte}
\else
    \set hash_suporte 'TROQUE_AQUI'
    \echo '*** ATENÇÃO: usuário suporte criado sem senha válida. Rode de novo com -v hash_suporte=... ***'
\endif

SET ROLE locgestor_owner;

-- Planos de assinatura (preço e limite podem ser mudados no painel da plataforma)
INSERT INTO planos (nome, preco_mensal, limite_usuarios) VALUES
    ('Básico', 99.00, 3), ('Profissional', 199.00, 5), ('Empresarial', 399.00, 15)
ON CONFLICT (nome) DO NOTHING;

INSERT INTO plataforma_usuarios (nome, login, senha_hash)
VALUES ('Suporte LocGestor', 'suporte', :'hash_suporte')
ON CONFLICT (login) DO UPDATE SET senha_hash = EXCLUDED.senha_hash;

RESET ROLE;
