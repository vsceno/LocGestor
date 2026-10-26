-- =====================================================================
-- Migração 016 - auditoria das ações do painel da plataforma
--
--   psql -U postgres -d locgestor -f database\migrations\016_auditoria_plataforma.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- Registra, por gatilho, cada inclusão, alteração e exclusão nas tabelas do painel: locadoras (tenants),
-- planos, mensalidades, recados e usuários do painel. Quem fez: o usuário do painel (ou "rotina diária"
-- e "webhook asaas", quando é o sistema). Dados pessoais e senhas aparecem mascarados.
-- A tabela não tem tenant_id (é do painel, não de uma locadora), então não tem RLS. Ninguém altera nem
-- apaga o histórico: a permissão é só de leitura e inclusão.
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

CREATE TABLE IF NOT EXISTS auditoria_plataforma (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    quando      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    usuario     VARCHAR(80)  NOT NULL DEFAULT '',
    tabela      VARCHAR(40)  NOT NULL,
    registro_id uuid,
    rotulo      VARCHAR(160) NOT NULL DEFAULT '',
    acao        CHAR(1)      NOT NULL CHECK (acao IN ('I', 'U', 'D')),
    dados       JSONB        NOT NULL
);
CREATE INDEX IF NOT EXISTS auditoria_plataforma_quando_idx ON auditoria_plataforma (quando DESC);
CREATE INDEX IF NOT EXISTS auditoria_plataforma_registro_idx ON auditoria_plataforma (registro_id);

-- Mesma lógica de auditar() (migração 008), sem locadora: os argumentos são colunas mascaradas,
-- colunas ignoradas e a coluna usada como rótulo. Alteração só de colunas ignoradas não gera registro.
CREATE OR REPLACE FUNCTION auditar_plataforma() RETURNS trigger LANGUAGE plpgsql AS
$$
DECLARE
    v_mascarar TEXT[] := COALESCE(string_to_array(NULLIF(TG_ARGV[0], ''), ','), '{}');
    v_ignorar  TEXT[] := ARRAY['criado_em'] || COALESCE(string_to_array(NULLIF(TG_ARGV[1], ''), ','), '{}');
    v_antes    JSONB  := CASE WHEN TG_OP <> 'INSERT' THEN to_jsonb(OLD) END;
    v_depois   JSONB  := CASE WHEN TG_OP <> 'DELETE' THEN to_jsonb(NEW) END;
    v_reg      JSONB  := COALESCE(v_depois, v_antes);
    v_dados    JSONB;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        SELECT jsonb_object_agg(a.key, CASE WHEN a.key = ANY (v_mascarar) THEN '["***", "***"]'::jsonb
                                            ELSE jsonb_build_array(a.value, d.value) END)
          INTO v_dados
          FROM jsonb_each(v_antes) a JOIN jsonb_each(v_depois) d ON d.key = a.key
         WHERE a.value IS DISTINCT FROM d.value AND NOT a.key = ANY (v_ignorar);
        IF v_dados IS NULL THEN
            RETURN NULL;
        END IF;
    ELSE
        SELECT COALESCE(jsonb_object_agg(r.key, CASE WHEN r.key = ANY (v_mascarar) AND r.value <> '""'::jsonb
                                                     THEN '"***"'::jsonb ELSE r.value END), '{}'::jsonb)
          INTO v_dados
          FROM jsonb_each(v_reg) r
         WHERE NOT r.key = ANY (v_ignorar);
    END IF;
    INSERT INTO auditoria_plataforma (usuario, tabela, registro_id, rotulo, acao, dados)
    VALUES (COALESCE(NULLIF(current_setting('app.usuario', true), ''),
                     CASE current_user WHEN 'locgestor_plataforma' THEN 'plataforma' ELSE 'sistema' END),
            TG_TABLE_NAME,
            (v_reg->>'id')::uuid,
            left(COALESCE(v_reg->>TG_ARGV[2], ''), 160),
            left(TG_OP, 1),
            v_dados);
    RETURN NULL;
END
$$;
REVOKE EXECUTE ON FUNCTION auditar_plataforma() FROM PUBLIC;

DO $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN SELECT * FROM (VALUES
        ('tenants',              'cnpj,email_contato,telefone', 'asaas_cliente_id', 'slug'),
        ('planos',               '', '', 'nome'),
        ('assinatura_pagamentos', '', '', 'forma'),
        ('recados',              '', '', 'titulo'),
        ('plataforma_usuarios',  'senha_hash', 'ultimo_acesso', 'login')
    ) AS t(tabela, mascarar, ignorar, rotulo) LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS auditoria_plataforma ON %I', r.tabela);
        EXECUTE format('CREATE TRIGGER auditoria_plataforma AFTER INSERT OR UPDATE OR DELETE ON %I
                          FOR EACH ROW EXECUTE FUNCTION auditar_plataforma(%L, %L, %L)', r.tabela, r.mascarar, r.ignorar, r.rotulo);
    END LOOP;
END $$;

GRANT SELECT, INSERT ON auditoria_plataforma TO locgestor_plataforma;

RESET ROLE;
