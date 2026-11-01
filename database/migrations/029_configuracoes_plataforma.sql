-- Configurações da LocGestor (tela "Configurações" do painel da plataforma): dados da empresa, identidade visual,
-- documentos, cadastro público e suporte. Uma linha só, com identificador fixo. Alterações entram na auditoria
-- (gatilho auditoria_plataforma, igual às outras tabelas da plataforma).
-- Idempotente: pode rodar de novo sem efeito.

CREATE TABLE IF NOT EXISTS plataforma_configuracoes (
    id                 UUID PRIMARY KEY DEFAULT '00000000-0000-0000-0000-000000000001'
                       CHECK (id = '00000000-0000-0000-0000-000000000001'),
    emitente_razao     VARCHAR(150) NOT NULL DEFAULT '',
    emitente_cnpj      VARCHAR(14)  NOT NULL DEFAULT '' CHECK (emitente_cnpj ~ '^[0-9]{0,14}$'),
    emitente_endereco  VARCHAR(200) NOT NULL DEFAULT '',
    emitente_contato   VARCHAR(200) NOT NULL DEFAULT '',
    nome_sistema       VARCHAR(40)  NOT NULL DEFAULT 'LocGestor',
    logo_versao        VARCHAR(20)  NOT NULL DEFAULT '',
    termos_url         VARCHAR(300) NOT NULL DEFAULT '' CHECK (termos_url = '' OR termos_url LIKE 'https://%'),
    privacidade_url    VARCHAR(300) NOT NULL DEFAULT '' CHECK (privacidade_url = '' OR privacidade_url LIKE 'https://%'),
    observacao_recibo  VARCHAR(300) NOT NULL DEFAULT '',
    cadastro_publico   BOOLEAN      NOT NULL DEFAULT false,
    cadastro_dias_teste INTEGER     NOT NULL DEFAULT 14 CHECK (cadastro_dias_teste BETWEEN 0 AND 90),
    cadastro_plano_id  UUID         REFERENCES planos (id) ON DELETE SET NULL,
    suporte_contato    VARCHAR(200) NOT NULL DEFAULT '',
    atualizado_em      TIMESTAMPTZ  NOT NULL DEFAULT now()
);

INSERT INTO plataforma_configuracoes (id) VALUES ('00000000-0000-0000-0000-000000000001') ON CONFLICT (id) DO NOTHING;

-- A plataforma lê e altera; a locadora só lê (login, mensalidade e cadastro precisam saber o que mostrar).
-- Nenhum DELETE: a configuração sempre existe.
GRANT SELECT, UPDATE ON plataforma_configuracoes TO locgestor_plataforma;
GRANT SELECT ON plataforma_configuracoes TO locgestor_app;

DROP TRIGGER IF EXISTS auditoria_plataforma ON plataforma_configuracoes;
CREATE TRIGGER auditoria_plataforma AFTER INSERT OR UPDATE OR DELETE ON plataforma_configuracoes
    FOR EACH ROW EXECUTE FUNCTION auditar_plataforma('', 'atualizado_em', 'nome_sistema');
