-- =====================================================================
-- Migração 008 - auditoria (quem alterou o quê, e quando)
--
--   psql -U postgres -d locgestor -f database\migrations\008_auditoria.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- Gatilhos (triggers) nas tabelas principais gravam cada inclusão, alteração e exclusão em
-- auditoria, venha de qual tela vier. O PHP informa o usuário em app.usuario_id/app.usuario.
-- - O sistema só lê e inclui (não altera nem apaga o histórico).
-- - Dados pessoais (CPF, RG, contatos, endereço) e hash de senha ficam mascarados: aparece que
--   mudaram, não os valores.
-- - app.auditoria = 'off' desliga a gravação só para o dono do banco (carga da demonstração);
--   para os papéis do sistema o interruptor não vale.
-- A função do gatilho é SECURITY INVOKER (padrão): grava com as permissões e o RLS de quem alterou.
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

CREATE TABLE IF NOT EXISTS auditoria (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id   uuid         NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    quando      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    usuario_id  uuid,                                  -- sem FK: o histórico não depende do cadastro do usuário
    usuario     VARCHAR(80)  NOT NULL DEFAULT '',      -- login no momento ("plataforma: ...", "sistema")
    tabela      VARCHAR(40)  NOT NULL,
    registro_id uuid,
    rotulo      VARCHAR(160) NOT NULL DEFAULT '',      -- identificação legível: nº, código, nome
    acao        CHAR(1)      NOT NULL CHECK (acao IN ('I', 'U', 'D')),
    dados       JSONB        NOT NULL,                 -- I/D: o registro; U: {campo: [antes, depois]}
    CONSTRAINT auditoria_tenant_id_uk UNIQUE (tenant_id, id)
);
CREATE INDEX IF NOT EXISTS auditoria_quando_idx ON auditoria (tenant_id, quando DESC);
CREATE INDEX IF NOT EXISTS auditoria_registro_idx ON auditoria (tenant_id, registro_id);

ALTER TABLE auditoria ENABLE ROW LEVEL SECURITY;
ALTER TABLE auditoria FORCE ROW LEVEL SECURITY;
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'auditoria' AND policyname = 'isolamento_tenant') THEN
        CREATE POLICY isolamento_tenant ON auditoria
            USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
    END IF;
END $$;

-- Argumentos do gatilho: colunas mascaradas, colunas ignoradas (CSV) e a coluna do rótulo
CREATE OR REPLACE FUNCTION auditar() RETURNS trigger LANGUAGE plpgsql AS
$$
DECLARE
    v_mascarar TEXT[] := string_to_array(NULLIF(TG_ARGV[0], ''), ',');
    v_ignorar  TEXT[] := ARRAY['tenant_id', 'criado_em'] || COALESCE(string_to_array(NULLIF(TG_ARGV[1], ''), ','), '{}');
    v_antes    JSONB  := CASE WHEN TG_OP <> 'INSERT' THEN to_jsonb(OLD) END;
    v_depois   JSONB  := CASE WHEN TG_OP <> 'DELETE' THEN to_jsonb(NEW) END;
    v_reg      JSONB  := COALESCE(v_depois, v_antes);
    v_dados    JSONB;
BEGIN
    IF current_setting('app.auditoria', true) = 'off'
       AND current_user NOT IN ('locgestor_app', 'locgestor_plataforma') THEN
        RETURN NULL;
    END IF;
    v_mascarar := COALESCE(v_mascarar, '{}');
    IF TG_OP = 'UPDATE' THEN
        SELECT jsonb_object_agg(a.key, CASE WHEN a.key = ANY (v_mascarar) THEN '["***", "***"]'::jsonb
                                            ELSE jsonb_build_array(a.value, d.value) END)
          INTO v_dados
          FROM jsonb_each(v_antes) a JOIN jsonb_each(v_depois) d ON d.key = a.key
         WHERE a.value IS DISTINCT FROM d.value AND NOT a.key = ANY (v_ignorar);
        IF v_dados IS NULL THEN
            RETURN NULL;   -- nada relevante mudou (ex.: só o último acesso)
        END IF;
    ELSE
        SELECT COALESCE(jsonb_object_agg(r.key, CASE WHEN r.key = ANY (v_mascarar) AND r.value <> '""'::jsonb
                                                     THEN '"***"'::jsonb ELSE r.value END), '{}'::jsonb)
          INTO v_dados
          FROM jsonb_each(v_reg) r
         WHERE NOT r.key = ANY (v_ignorar);
    END IF;
    INSERT INTO auditoria (tenant_id, usuario_id, usuario, tabela, registro_id, rotulo, acao, dados)
    VALUES ((v_reg->>'tenant_id')::uuid,
            NULLIF(current_setting('app.usuario_id', true), '')::uuid,
            COALESCE(NULLIF(current_setting('app.usuario', true), ''),
                     CASE current_user WHEN 'locgestor_plataforma' THEN 'plataforma' ELSE 'sistema' END),
            TG_TABLE_NAME,
            (v_reg->>'id')::uuid,
            left(COALESCE(v_reg->>TG_ARGV[2], ''), 160),
            left(TG_OP, 1),
            v_dados);
    RETURN NULL;
END
$$;
REVOKE EXECUTE ON FUNCTION auditar() FROM PUBLIC;

DO $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN SELECT * FROM (VALUES
        ('clientes',    'cpf_cnpj,rg_ie,telefone,celular,email,cep,endereco,numero,bairro', '', 'nome'),
        ('usuarios',    'senha_hash', 'ultimo_acesso', 'login'),
        ('empresa',     'pix_chave', '', 'nome_fantasia'),
        ('categorias',  '', '', 'nome'),
        ('produtos',    '', '', 'codigo'),
        ('kits',        '', '', 'codigo'),
        ('reservas',    '', '', 'numero'),
        ('locacoes',    '', '', 'numero'),
        ('devolucoes',  '', '', 'data'),
        ('caixa',       '', '', 'descricao'),
        ('unidades',    '', '', 'patrimonio'),
        ('manutencoes', '', '', 'numero')
    ) AS t(tabela, mascarar, ignorar, rotulo) LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS auditoria ON %I', r.tabela);
        EXECUTE format('CREATE TRIGGER auditoria AFTER INSERT OR UPDATE OR DELETE ON %I
                          FOR EACH ROW EXECUTE FUNCTION auditar(%L, %L, %L)', r.tabela, r.mascarar, r.ignorar, r.rotulo);
    END LOOP;
END $$;

-- Histórico: só leitura e inclusão (a inclusão é feita pelo gatilho, com o papel de quem alterou)
GRANT SELECT, INSERT ON auditoria TO locgestor_app;
GRANT INSERT ON auditoria TO locgestor_plataforma;

RESET ROLE;
