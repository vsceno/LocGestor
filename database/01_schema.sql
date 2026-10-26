-- =====================================================================
-- LocGestor - estrutura do banco (PostgreSQL 18), multi-tenant com RLS
-- Roda como postgres, no banco locgestor, DEPOIS de 00_roles.sql:
--
--   psql -U postgres -d locgestor -f 01_schema.sql
--
-- ISOLAMENTO ENTRE LOCADORAS
--   * Cada tabela de dados de locadora tem tenant_id preenchido sozinho por
--     DEFAULT app_tenant_id(), que lê a variável de sessão app.tenant_id.
--   * RLS habilitado E forçado (FORCE) em todas elas: nem o dono escapa.
--   * FKs compostas (tenant_id, x_id) impedem apontar para registro de outra locadora.
--   * Views com security_invoker = true (sem isso a view ignora o RLS e VAZA dados).
--   * Proibido criar funções SECURITY DEFINER que toquem tabelas de locadora.
-- =====================================================================

\set ON_ERROR_STOP on
SET client_encoding = 'UTF8';
SET TIME ZONE 'America/Fortaleza';

-- Tudo é criado como locgestor_owner (o PHP nunca usa este papel)
SET ROLE locgestor_owner;

-- Ninguém além do dono cria objetos no schema public
REVOKE CREATE ON SCHEMA public FROM PUBLIC;

-- ---------------------------------------------------------------------
-- Contexto da locadora: lê app.tenant_id da sessão (NULL se não definido)
-- ---------------------------------------------------------------------
CREATE FUNCTION app_tenant_id() RETURNS uuid LANGUAGE sql STABLE AS
$$ SELECT NULLIF(current_setting('app.tenant_id', true), '')::uuid $$;

-- =====================================================================
-- TABELAS DA PLATAFORMA (sem tenant_id e sem RLS)
-- =====================================================================

-- Planos de assinatura das locadoras
CREATE TABLE planos (
    id              uuid PRIMARY KEY DEFAULT uuidv7(),
    nome            VARCHAR(40)   NOT NULL,
    preco_mensal    NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (preco_mensal >= 0),
    limite_usuarios INTEGER       NOT NULL DEFAULT 3 CHECK (limite_usuarios >= 1),
    ativo           BOOLEAN       NOT NULL DEFAULT TRUE,
    criado_em       TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT planos_nome_uk UNIQUE (nome)
);

CREATE TABLE tenants (
    id              uuid PRIMARY KEY DEFAULT uuidv7(),
    slug            VARCHAR(40)  NOT NULL,
    nome            VARCHAR(150) NOT NULL,
    cnpj            VARCHAR(14)  NOT NULL DEFAULT '',
    email_contato   VARCHAR(120) NOT NULL DEFAULT '',
    telefone        VARCHAR(20)  NOT NULL DEFAULT '',
    status          VARCHAR(10)  NOT NULL DEFAULT 'ativa' CHECK (status IN ('ativa', 'suspensa', 'cancelada')),
    plano           VARCHAR(40)  NOT NULL DEFAULT 'Básico',
    limite_usuarios INTEGER      NOT NULL DEFAULT 3 CHECK (limite_usuarios >= 1),
    criado_em       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    -- Assinatura: vencimento da próxima mensalidade (NULL = sem cobrança/isenta).
    -- Passados carencia_dias do vencimento sem pagamento, o acesso é suspenso.
    plano_id         uuid        REFERENCES planos (id),
    vencimento       DATE,
    carencia_dias    INTEGER     NOT NULL DEFAULT 5,
    motivo_suspensao VARCHAR(20) NOT NULL DEFAULT '',
    cancelada_em    DATE,                       -- dia do cancelamento (status 'cancelada')
    -- Cobrança automática pelo Asaas (desligada por padrão) e o id do cliente lá
    cobranca_automatica BOOLEAN     NOT NULL DEFAULT FALSE,
    asaas_cliente_id    VARCHAR(40) NOT NULL DEFAULT '',
    CONSTRAINT tenants_slug_uk UNIQUE (slug),
    CONSTRAINT tenants_slug_ck CHECK (slug ~ '^[a-z0-9-]{3,40}$'),
    CONSTRAINT tenants_carencia_ck CHECK (carencia_dias BETWEEN 0 AND 60),
    CONSTRAINT tenants_motivo_ck CHECK (motivo_suspensao IN ('', 'manual', 'inadimplencia')),
    CONSTRAINT tenants_cancelada_ck CHECK ((status = 'cancelada') = (cancelada_em IS NOT NULL))
);

CREATE TABLE plataforma_usuarios (
    id            uuid PRIMARY KEY DEFAULT uuidv7(),
    nome          VARCHAR(100) NOT NULL,
    login         VARCHAR(40)  NOT NULL,
    senha_hash    VARCHAR(255) NOT NULL,
    ativo         BOOLEAN      NOT NULL DEFAULT TRUE,
    ultimo_acesso TIMESTAMPTZ,
    criado_em     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT plataforma_usuarios_login_uk UNIQUE (login)
);

-- Pagamentos de mensalidade das locadoras. Usa "locadora_id" (e não tenant_id) de propósito:
-- é dado da plataforma, sem RLS, e a regra "tabela com tenant_id tem RLS" continua sem exceção.
CREATE TABLE assinatura_pagamentos (
    id                  uuid PRIMARY KEY DEFAULT uuidv7(),
    locadora_id         uuid          NOT NULL REFERENCES tenants (id),
    data                DATE          NOT NULL,
    meses               INTEGER       NOT NULL CHECK (meses BETWEEN 1 AND 24),
    valor               NUMERIC(12,2) NOT NULL CHECK (valor >= 0),
    forma               VARCHAR(30)   NOT NULL DEFAULT 'PIX',
    vencimento_anterior DATE          NOT NULL,
    vencimento_novo     DATE          NOT NULL,
    obs                 TEXT          NOT NULL DEFAULT '',
    usuario_id          uuid          REFERENCES plataforma_usuarios (id),
    criado_em           TIMESTAMPTZ   NOT NULL DEFAULT now()
);
CREATE INDEX assinatura_pagamentos_locadora_idx ON assinatura_pagamentos (locadora_id, data);

-- Mensalidades geradas no Asaas (link de pagamento e situação). Tabela da plataforma com RLS:
-- o painel vê todas; o sistema da locadora só lê as próprias (botão "Pagar agora").
CREATE TABLE assinatura_cobrancas (
    id            uuid PRIMARY KEY DEFAULT uuidv7(),
    locadora_id   uuid          NOT NULL REFERENCES tenants (id),
    vencimento    DATE          NOT NULL,
    valor         NUMERIC(12,2) NOT NULL CHECK (valor > 0),
    status        VARCHAR(10)   NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente', 'paga', 'cancelada')),
    gateway_id    VARCHAR(40),                      -- id da cobrança no Asaas (pay_...); NULL enquanto é criada
    link          VARCHAR(300)  NOT NULL DEFAULT '', -- fatura do Asaas (PIX, boleto ou cartão)
    pagamento_id  uuid          REFERENCES assinatura_pagamentos (id),
    pago_em       DATE,
    criado_em     TIMESTAMPTZ   NOT NULL DEFAULT now(),
    atualizado_em TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT assinatura_cobrancas_gateway_uk UNIQUE (gateway_id)
);
-- Uma cobrança em aberto (ou paga) por vencimento: rodar a rotina duas vezes não cobra duas vezes
CREATE UNIQUE INDEX assinatura_cobrancas_vencimento_uk
    ON assinatura_cobrancas (locadora_id, vencimento) WHERE status <> 'cancelada';

CREATE TABLE gateway_eventos (
    id          VARCHAR(80) PRIMARY KEY,
    tipo        VARCHAR(60) NOT NULL,
    recebido_em TIMESTAMPTZ NOT NULL DEFAULT now()
);

ALTER TABLE assinatura_cobrancas ENABLE ROW LEVEL SECURITY;
ALTER TABLE assinatura_cobrancas FORCE ROW LEVEL SECURITY;
CREATE POLICY plataforma_todas ON assinatura_cobrancas TO locgestor_plataforma USING (true) WITH CHECK (true);
CREATE POLICY locadora_propria ON assinatura_cobrancas FOR SELECT TO locgestor_app USING (locadora_id = app_tenant_id());

-- Acesso de suporte a uma locadora (ver migrations/013_acesso_suporte.sql)
CREATE TABLE acessos_suporte (
    id            uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id     uuid         NOT NULL REFERENCES tenants (id),
    login_suporte VARCHAR(40)  NOT NULL,
    motivo        VARCHAR(300) NOT NULL CHECK (char_length(motivo) >= 10),
    token_hash    CHAR(64)     NOT NULL,
    criado_em     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    expira_em     TIMESTAMPTZ  NOT NULL,
    usado_em      TIMESTAMPTZ,
    CONSTRAINT acessos_suporte_token_uk UNIQUE (token_hash)
);
CREATE INDEX acessos_suporte_tenant_idx ON acessos_suporte (tenant_id, criado_em DESC);
ALTER TABLE acessos_suporte ENABLE ROW LEVEL SECURITY;
ALTER TABLE acessos_suporte FORCE ROW LEVEL SECURITY;
CREATE POLICY isolamento_tenant ON acessos_suporte
    USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
CREATE POLICY plataforma_todas ON acessos_suporte TO locgestor_plataforma USING (true) WITH CHECK (true);

-- Tentativas de login que falharam (limite contra tentativa e erro).
-- Guarda só hashes do IP e da conta, nunca o IP ou o login em texto.
CREATE TABLE login_tentativas (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    chave       CHAR(64)    NOT NULL,
    criado_em   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX login_tentativas_chave_idx ON login_tentativas (chave, criado_em);

-- =====================================================================
-- TABELAS DAS LOCADORAS (todas com tenant_id + RLS)
-- =====================================================================

-- Dados da locadora: uma linha por tenant.
-- Os textos padrão de contrato e fatura ficam no DEFAULT, assim toda locadora nova já nasce com eles.
CREATE TABLE empresa (
    tenant_id       uuid         NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    razao_social    VARCHAR(150) NOT NULL,
    nome_fantasia   VARCHAR(150) NOT NULL DEFAULT '',
    cnpj            VARCHAR(18)  NOT NULL DEFAULT '',
    ie              VARCHAR(20)  NOT NULL DEFAULT '',
    telefone        VARCHAR(20)  NOT NULL DEFAULT '',
    email           VARCHAR(120) NOT NULL DEFAULT '',
    endereco        VARCHAR(200) NOT NULL DEFAULT '',
    cidade          VARCHAR(80)  NOT NULL DEFAULT '',
    uf              CHAR(2)      NOT NULL DEFAULT 'CE',
    cep             VARCHAR(9)   NOT NULL DEFAULT '',
    termos_contrato TEXT         NOT NULL DEFAULT
'CLÁUSULA 1ª - DO ESTADO DO EQUIPAMENTO: O LOCATÁRIO declara receber os bens descritos neste contrato em perfeito estado de conservação e funcionamento, obrigando-se a devolvê-los nas mesmas condições, ressalvado o desgaste natural pelo uso adequado.

CLÁUSULA 2ª - DA COBRANÇA: A locação é cobrada por diária, contada da data de saída até a data da efetiva devolução de cada item, sendo cobrada no mínimo 1 (uma) diária.

CLÁUSULA 3ª - DAS DIÁRIAS EXCEDENTES: Os bens não devolvidos até a data prevista continuarão sendo cobrados pelo mesmo valor de diária até a efetiva devolução, sem prejuízo de multa eventualmente acordada.

CLÁUSULA 4ª - DAS AVARIAS E EXTRAVIOS: Em caso de dano, perda, furto ou roubo, o LOCATÁRIO ressarcirá a LOCADORA pelo custo do reparo ou, quando irrecuperável, pelo valor de reposição do bem constante do cadastro da LOCADORA.

CLÁUSULA 5ª - DA SUBLOCAÇÃO: É vedado ao LOCATÁRIO sublocar, ceder, emprestar ou transferir a terceiros os bens locados, bem como retirá-los do local de uso informado sem autorização por escrito da LOCADORA.',
    obs_fatura      TEXT         NOT NULL DEFAULT
'Locação de bens móveis - operação não sujeita ao ISS (LC 116/2003, item 3.01 vetado; Súmula Vinculante 31 do STF). Confirme o enquadramento com seu contador.',
    -- PIX para cobrança (BR Code): chave, nome (até 25) e cidade (até 15) do recebedor. Vazia = desligado.
    pix_chave       VARCHAR(77)  NOT NULL DEFAULT '',
    pix_nome        VARCHAR(25)  NOT NULL DEFAULT '',
    pix_cidade      VARCHAR(15)  NOT NULL DEFAULT '',
    PRIMARY KEY (tenant_id)
);

CREATE TABLE usuarios (
    id            uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id     uuid         NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    nome          VARCHAR(100) NOT NULL,
    login         VARCHAR(40)  NOT NULL,
    senha_hash    VARCHAR(255) NOT NULL,
    perfil        VARCHAR(10)  NOT NULL DEFAULT 'operador' CHECK (perfil IN ('admin', 'operador')),
    ativo         BOOLEAN      NOT NULL DEFAULT TRUE,
    trocar_senha  BOOLEAN      NOT NULL DEFAULT FALSE,   -- senha temporária: precisa trocar no próximo acesso
    senha_redefinida_em TIMESTAMPTZ,        -- redefinida pelo painel: sessões abertas antes disso deixam de valer
    ultimo_acesso TIMESTAMPTZ,
    criado_em     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT usuarios_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT usuarios_login_uk     UNIQUE (tenant_id, login)
);

-- cpf_cnpj guarda só os dígitos
CREATE TABLE clientes (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id   uuid         NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    tipo        CHAR(2)      NOT NULL DEFAULT 'PF' CHECK (tipo IN ('PF', 'PJ')),
    nome        VARCHAR(150) NOT NULL,
    cpf_cnpj    VARCHAR(14)  NOT NULL DEFAULT '',
    rg_ie       VARCHAR(20)  NOT NULL DEFAULT '',
    telefone    VARCHAR(20)  NOT NULL DEFAULT '',
    celular     VARCHAR(20)  NOT NULL DEFAULT '',
    email       VARCHAR(120) NOT NULL DEFAULT '',
    cep         VARCHAR(9)   NOT NULL DEFAULT '',
    endereco    VARCHAR(150) NOT NULL DEFAULT '',
    numero      VARCHAR(10)  NOT NULL DEFAULT '',
    bairro      VARCHAR(80)  NOT NULL DEFAULT '',
    cidade      VARCHAR(80)  NOT NULL DEFAULT '',
    uf          CHAR(2)      NOT NULL DEFAULT 'CE',
    obs         TEXT         NOT NULL DEFAULT '',
    bloqueado   BOOLEAN      NOT NULL DEFAULT FALSE,
    ativo       BOOLEAN      NOT NULL DEFAULT TRUE,
    criado_em   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT clientes_tenant_id_uk UNIQUE (tenant_id, id)
);
-- Documento único DENTRO da locadora (outra locadora pode ter o mesmo cliente); vazio é permitido
CREATE UNIQUE INDEX clientes_cpf_cnpj_uk ON clientes (tenant_id, cpf_cnpj) WHERE cpf_cnpj <> '';
CREATE INDEX clientes_nome_idx ON clientes (tenant_id, lower(nome));

CREATE TABLE categorias (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id   uuid        NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    nome        VARCHAR(60) NOT NULL,
    CONSTRAINT categorias_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT categorias_nome_uk      UNIQUE (tenant_id, nome)
);

CREATE TABLE produtos (
    id              uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id       uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    codigo          VARCHAR(30)   NOT NULL,
    descricao       VARCHAR(150)  NOT NULL,
    categoria_id    uuid,
    unidade         VARCHAR(10)   NOT NULL DEFAULT 'UN',
    qtd_total       INTEGER       NOT NULL DEFAULT 0 CHECK (qtd_total >= 0),
    valor_diaria    NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (valor_diaria >= 0),
    valor_semanal   NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (valor_semanal >= 0),   -- 0 = não oferece
    valor_mensal    NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (valor_mensal >= 0),    -- 0 = não oferece
    valor_reposicao NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (valor_reposicao >= 0),
    ncm             VARCHAR(8)    NOT NULL DEFAULT '',   -- preparado para futura NF-e de remessa/retorno (NFePHP)
    obs             TEXT          NOT NULL DEFAULT '',
    ativo           BOOLEAN       NOT NULL DEFAULT TRUE,
    criado_em       TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT produtos_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT produtos_codigo_uk    UNIQUE (tenant_id, codigo),
    CONSTRAINT produtos_categoria_fk FOREIGN KEY (tenant_id, categoria_id) REFERENCES categorias (tenant_id, id)
);
CREATE INDEX produtos_categoria_idx ON produtos (tenant_id, categoria_id);

-- Reservas (antes de locacoes, que aponta para elas). numero é sequencial por locadora.
CREATE TABLE reservas (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id   uuid        NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    numero      INTEGER     NOT NULL,
    cliente_id  uuid        NOT NULL,
    data_inicio DATE        NOT NULL,
    data_fim    DATE        NOT NULL,
    status      VARCHAR(10) NOT NULL DEFAULT 'ativa' CHECK (status IN ('ativa', 'convertida', 'cancelada')),
    obs         TEXT        NOT NULL DEFAULT '',
    usuario_id  uuid,
    criado_em   TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT reservas_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT reservas_numero_uk    UNIQUE (tenant_id, numero),
    CONSTRAINT reservas_datas_ck     CHECK (data_fim >= data_inicio),
    CONSTRAINT reservas_cliente_fk   FOREIGN KEY (tenant_id, cliente_id) REFERENCES clientes (tenant_id, id),
    CONSTRAINT reservas_usuario_fk   FOREIGN KEY (tenant_id, usuario_id) REFERENCES usuarios (tenant_id, id)
);
CREATE INDEX reservas_status_datas_idx ON reservas (tenant_id, status, data_inicio, data_fim);
CREATE INDEX reservas_cliente_idx      ON reservas (tenant_id, cliente_id);

CREATE TABLE reserva_itens (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id   uuid    NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    reserva_id  uuid    NOT NULL,
    produto_id  uuid    NOT NULL,
    quantidade  INTEGER NOT NULL CHECK (quantidade > 0),
    CONSTRAINT reserva_itens_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT reserva_itens_reserva_fk   FOREIGN KEY (tenant_id, reserva_id) REFERENCES reservas (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT reserva_itens_produto_fk   FOREIGN KEY (tenant_id, produto_id) REFERENCES produtos (tenant_id, id)
);
CREATE INDEX reserva_itens_reserva_idx ON reserva_itens (tenant_id, reserva_id);
CREATE INDEX reserva_itens_produto_idx ON reserva_itens (tenant_id, produto_id);

-- Locações. numero é sequencial por locadora (Locação nº 1, 2, 3...).
CREATE TABLE locacoes (
    id                  uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id           uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    numero              INTEGER       NOT NULL,
    cliente_id          uuid          NOT NULL,
    data_saida          DATE          NOT NULL,
    data_prev_devolucao DATE          NOT NULL,
    status              VARCHAR(10)   NOT NULL DEFAULT 'aberta' CHECK (status IN ('aberta', 'devolvida', 'cancelada')),
    local_uso           VARCHAR(200)  NOT NULL DEFAULT '',
    desconto            NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (desconto >= 0),
    acrescimo           NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (acrescimo >= 0),
    frete_entrega       NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (frete_entrega >= 0),
    frete_recolhimento  NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (frete_recolhimento >= 0),
    endereco_entrega    VARCHAR(200)  NOT NULL DEFAULT '',
    obs                 TEXT          NOT NULL DEFAULT '',
    reserva_id          uuid,
    usuario_id          uuid,
    criado_em           TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT locacoes_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT locacoes_numero_uk    UNIQUE (tenant_id, numero),
    CONSTRAINT locacoes_datas_ck     CHECK (data_prev_devolucao >= data_saida),
    CONSTRAINT locacoes_cliente_fk   FOREIGN KEY (tenant_id, cliente_id) REFERENCES clientes (tenant_id, id),
    CONSTRAINT locacoes_reserva_fk   FOREIGN KEY (tenant_id, reserva_id) REFERENCES reservas (tenant_id, id),
    CONSTRAINT locacoes_usuario_fk   FOREIGN KEY (tenant_id, usuario_id) REFERENCES usuarios (tenant_id, id)
);
CREATE INDEX locacoes_status_idx  ON locacoes (tenant_id, status, data_prev_devolucao);
CREATE INDEX locacoes_saida_idx   ON locacoes (tenant_id, data_saida);
CREATE INDEX locacoes_cliente_idx ON locacoes (tenant_id, cliente_id);

-- valor_diaria/semanal/mensal ficam congelados no momento da locação (mudar o preço do produto não afeta locações já feitas)
CREATE TABLE locacao_itens (
    id              uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id       uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    locacao_id      uuid          NOT NULL,
    produto_id      uuid          NOT NULL,
    quantidade      INTEGER       NOT NULL CHECK (quantidade > 0),
    qtd_devolvida   INTEGER       NOT NULL DEFAULT 0,
    valor_diaria    NUMERIC(12,2) NOT NULL CHECK (valor_diaria >= 0),
    valor_semanal   NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (valor_semanal >= 0),
    valor_mensal    NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (valor_mensal >= 0),
    CONSTRAINT locacao_itens_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT locacao_itens_devolvida_ck CHECK (qtd_devolvida BETWEEN 0 AND quantidade),
    CONSTRAINT locacao_itens_locacao_fk   FOREIGN KEY (tenant_id, locacao_id) REFERENCES locacoes (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT locacao_itens_produto_fk   FOREIGN KEY (tenant_id, produto_id) REFERENCES produtos (tenant_id, id)
);
CREATE INDEX locacao_itens_locacao_idx ON locacao_itens (tenant_id, locacao_id);
CREATE INDEX locacao_itens_produto_idx ON locacao_itens (tenant_id, produto_id);

-- Uma locação pode ter várias devoluções parciais
CREATE TABLE devolucoes (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id   uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    locacao_id  uuid          NOT NULL,
    data        DATE          NOT NULL,
    multa       NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (multa >= 0),
    avaria      NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (avaria >= 0),
    obs         TEXT          NOT NULL DEFAULT '',
    usuario_id  uuid,
    criado_em   TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT devolucoes_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT devolucoes_locacao_fk   FOREIGN KEY (tenant_id, locacao_id) REFERENCES locacoes (tenant_id, id),
    CONSTRAINT devolucoes_usuario_fk   FOREIGN KEY (tenant_id, usuario_id) REFERENCES usuarios (tenant_id, id)
);
CREATE INDEX devolucoes_locacao_idx ON devolucoes (tenant_id, locacao_id);
CREATE INDEX devolucoes_data_idx    ON devolucoes (tenant_id, data);

-- valor = quantidade x diária x dias, com dias = max(1, data da devolução - saída)
CREATE TABLE devolucao_itens (
    id              uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id       uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    devolucao_id    uuid          NOT NULL,
    locacao_item_id uuid          NOT NULL,
    quantidade      INTEGER       NOT NULL CHECK (quantidade > 0),
    dias            INTEGER       NOT NULL CHECK (dias >= 1),
    valor           NUMERIC(12,2) NOT NULL CHECK (valor >= 0),
    CONSTRAINT devolucao_itens_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT devolucao_itens_devolucao_fk FOREIGN KEY (tenant_id, devolucao_id) REFERENCES devolucoes (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT devolucao_itens_item_fk      FOREIGN KEY (tenant_id, locacao_item_id) REFERENCES locacao_itens (tenant_id, id)
);
CREATE INDEX devolucao_itens_devolucao_idx ON devolucao_itens (tenant_id, devolucao_id);
CREATE INDEX devolucao_itens_item_idx      ON devolucao_itens (tenant_id, locacao_item_id);

-- Fluxo de caixa: E = entrada, S = saída
CREATE TABLE caixa (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id   uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    data        DATE          NOT NULL,
    tipo        CHAR(1)       NOT NULL CHECK (tipo IN ('E', 'S')),
    categoria   VARCHAR(60)   NOT NULL DEFAULT '',
    descricao   VARCHAR(200)  NOT NULL,
    valor       NUMERIC(12,2) NOT NULL CHECK (valor > 0),
    forma       VARCHAR(30)   NOT NULL DEFAULT 'Dinheiro',
    locacao_id  uuid,
    usuario_id  uuid,
    criado_em   TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT caixa_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT caixa_locacao_fk   FOREIGN KEY (tenant_id, locacao_id) REFERENCES locacoes (tenant_id, id),
    CONSTRAINT caixa_usuario_fk   FOREIGN KEY (tenant_id, usuario_id) REFERENCES usuarios (tenant_id, id)
);
CREATE INDEX caixa_data_idx    ON caixa (tenant_id, data);
CREATE INDEX caixa_locacao_idx ON caixa (tenant_id, locacao_id);

-- Vistorias de retirada/devolução: estado de cada item, fotos e assinatura do cliente.
-- É prova do estado do equipamento: o app só lê e insere (não altera nem apaga).
CREATE TABLE vistorias (
    id                 uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id          uuid         NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    locacao_id         uuid         NOT NULL,
    tipo               VARCHAR(10)  NOT NULL CHECK (tipo IN ('retirada', 'devolucao')),
    obs                TEXT         NOT NULL DEFAULT '',
    assinante_nome     VARCHAR(100) NOT NULL DEFAULT '',
    assinatura_arquivo VARCHAR(120) NOT NULL DEFAULT '',   -- '' = não assinada
    assinado_em        TIMESTAMPTZ,
    usuario_id         uuid,
    criado_em          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT vistorias_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT vistorias_locacao_fk   FOREIGN KEY (tenant_id, locacao_id) REFERENCES locacoes (tenant_id, id),
    CONSTRAINT vistorias_usuario_fk   FOREIGN KEY (tenant_id, usuario_id) REFERENCES usuarios (tenant_id, id)
);
CREATE INDEX vistorias_locacao_idx ON vistorias (tenant_id, locacao_id);

CREATE TABLE vistoria_itens (
    id              uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id       uuid         NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    vistoria_id     uuid         NOT NULL,
    locacao_item_id uuid         NOT NULL,
    estado          VARCHAR(12)  NOT NULL CHECK (estado IN ('ok', 'avariado', 'sujo', 'incompleto')),
    obs             VARCHAR(200) NOT NULL DEFAULT '',
    CONSTRAINT vistoria_itens_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT vistoria_itens_vistoria_fk  FOREIGN KEY (tenant_id, vistoria_id) REFERENCES vistorias (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT vistoria_itens_item_fk      FOREIGN KEY (tenant_id, locacao_item_id) REFERENCES locacao_itens (tenant_id, id)
);
CREATE INDEX vistoria_itens_vistoria_idx ON vistoria_itens (tenant_id, vistoria_id);

-- arquivo: caminho relativo na pasta de arquivos (tenant/ano/nome-aleatório.jpg)
CREATE TABLE vistoria_fotos (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id   uuid         NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    vistoria_id uuid         NOT NULL,
    arquivo     VARCHAR(120) NOT NULL,
    tamanho     INTEGER      NOT NULL CHECK (tamanho > 0),
    largura     INTEGER      NOT NULL,
    altura      INTEGER      NOT NULL,
    criado_em   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT vistoria_fotos_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT vistoria_fotos_vistoria_fk  FOREIGN KEY (tenant_id, vistoria_id) REFERENCES vistorias (tenant_id, id) ON DELETE CASCADE
);
CREATE INDEX vistoria_fotos_vistoria_idx ON vistoria_fotos (tenant_id, vistoria_id);

-- Kits: atalho de cadastro. Na locação/reserva o kit vira os itens dele (estoque e preço continuam por produto).
CREATE TABLE kits (
    id        uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id uuid         NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    codigo    VARCHAR(30)  NOT NULL,
    nome      VARCHAR(150) NOT NULL,
    obs       TEXT         NOT NULL DEFAULT '',
    ativo     BOOLEAN      NOT NULL DEFAULT TRUE,
    criado_em TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT kits_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT kits_codigo_uk    UNIQUE (tenant_id, codigo)
);

CREATE TABLE kit_itens (
    id         uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id  uuid    NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    kit_id     uuid    NOT NULL,
    produto_id uuid    NOT NULL,
    quantidade INTEGER NOT NULL CHECK (quantidade > 0),
    CONSTRAINT kit_itens_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT kit_itens_produto_uk   UNIQUE (tenant_id, kit_id, produto_id),
    CONSTRAINT kit_itens_kit_fk       FOREIGN KEY (tenant_id, kit_id) REFERENCES kits (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT kit_itens_produto_fk   FOREIGN KEY (tenant_id, produto_id) REFERENCES produtos (tenant_id, id)
);
CREATE INDEX kit_itens_produto_idx ON kit_itens (tenant_id, produto_id);

-- Patrimônio: cada peça com número de patrimônio/série (opcional; o estoque continua em produtos.qtd_total).
-- Manutenção aberta (data_fim NULL) tira as peças do estoque disponível.
CREATE TABLE unidades (
    id              uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id       uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    produto_id      uuid          NOT NULL,
    patrimonio      VARCHAR(30)   NOT NULL,
    serie           VARCHAR(60)   NOT NULL DEFAULT '',
    aquisicao_data  DATE,
    aquisicao_valor NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (aquisicao_valor >= 0),
    obs             TEXT          NOT NULL DEFAULT '',
    ativa           BOOLEAN       NOT NULL DEFAULT TRUE,   -- FALSE = baixada (vendida, perdida, sucata)
    baixa_data      DATE,
    baixa_motivo    VARCHAR(200)  NOT NULL DEFAULT '',
    criado_em       TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT unidades_tenant_id_uk  UNIQUE (tenant_id, id),
    CONSTRAINT unidades_patrimonio_uk UNIQUE (tenant_id, patrimonio),
    CONSTRAINT unidades_produto_fk    FOREIGN KEY (tenant_id, produto_id) REFERENCES produtos (tenant_id, id),
    CONSTRAINT unidades_baixa_ck      CHECK (ativa OR baixa_data IS NOT NULL)
);
CREATE INDEX unidades_produto_idx ON unidades (tenant_id, produto_id);

CREATE TABLE manutencoes (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id   uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    numero      INTEGER       NOT NULL,
    produto_id  uuid          NOT NULL,
    unidade_id  uuid,
    quantidade  INTEGER       NOT NULL CHECK (quantidade > 0),
    tipo        VARCHAR(12)   NOT NULL CHECK (tipo IN ('preventiva', 'corretiva')),
    descricao   VARCHAR(500)  NOT NULL,
    fornecedor  VARCHAR(100)  NOT NULL DEFAULT '',
    data_inicio DATE          NOT NULL,
    data_fim    DATE,                                   -- NULL = em manutenção (fora do estoque disponível)
    custo       NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (custo >= 0),
    solucao     VARCHAR(500)  NOT NULL DEFAULT '',
    caixa_id    uuid,                                   -- saída lançada no caixa com o custo
    usuario_id  uuid,
    criado_em   TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT manutencoes_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT manutencoes_numero_uk    UNIQUE (tenant_id, numero),
    CONSTRAINT manutencoes_produto_fk   FOREIGN KEY (tenant_id, produto_id) REFERENCES produtos (tenant_id, id),
    CONSTRAINT manutencoes_unidade_fk   FOREIGN KEY (tenant_id, unidade_id) REFERENCES unidades (tenant_id, id),
    CONSTRAINT manutencoes_caixa_fk     FOREIGN KEY (tenant_id, caixa_id) REFERENCES caixa (tenant_id, id),
    CONSTRAINT manutencoes_usuario_fk   FOREIGN KEY (tenant_id, usuario_id) REFERENCES usuarios (tenant_id, id),
    CONSTRAINT manutencoes_unidade_ck   CHECK (unidade_id IS NULL OR quantidade = 1),
    CONSTRAINT manutencoes_datas_ck     CHECK (data_fim IS NULL OR data_fim >= data_inicio)
);
CREATE INDEX manutencoes_produto_idx ON manutencoes (tenant_id, produto_id, data_fim);
-- Uma unidade não fica em duas manutenções abertas ao mesmo tempo
CREATE UNIQUE INDEX manutencoes_unidade_aberta_uk
    ON manutencoes (tenant_id, unidade_id) WHERE data_fim IS NULL AND unidade_id IS NOT NULL;

CREATE TABLE locacao_unidades (
    id              uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id       uuid NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    locacao_item_id uuid NOT NULL,
    unidade_id      uuid NOT NULL,
    devolucao_id    uuid,                               -- devolução em que a unidade voltou (NULL = na rua)
    CONSTRAINT locacao_unidades_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT locacao_unidades_item_fk      FOREIGN KEY (tenant_id, locacao_item_id) REFERENCES locacao_itens (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT locacao_unidades_unidade_fk   FOREIGN KEY (tenant_id, unidade_id) REFERENCES unidades (tenant_id, id),
    CONSTRAINT locacao_unidades_devolucao_fk FOREIGN KEY (tenant_id, devolucao_id) REFERENCES devolucoes (tenant_id, id)
);
CREATE INDEX locacao_unidades_item_idx ON locacao_unidades (tenant_id, locacao_item_id);
-- Uma unidade não está na rua em duas locações ao mesmo tempo
CREATE UNIQUE INDEX locacao_unidades_na_rua_uk ON locacao_unidades (tenant_id, unidade_id) WHERE devolucao_id IS NULL;

-- Contas a pagar; as recorrentes (aluguel, salário...) geram as parcelas até ~90 dias à frente
CREATE TABLE contas_recorrentes (
    id         uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id  uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    descricao  VARCHAR(200)  NOT NULL,
    categoria  VARCHAR(60)   NOT NULL,
    valor      NUMERIC(12,2) NOT NULL CHECK (valor > 0),
    dia        SMALLINT      NOT NULL CHECK (dia BETWEEN 1 AND 28),
    inicio     DATE          NOT NULL,                  -- primeiro mês com vencimento
    ativa      BOOLEAN       NOT NULL DEFAULT TRUE,
    gerado_ate DATE,                                    -- último vencimento já gerado em contas_pagar
    criado_em  TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT contas_recorrentes_tenant_id_uk UNIQUE (tenant_id, id)
);

CREATE TABLE contas_pagar (
    id            uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id     uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    descricao     VARCHAR(200)  NOT NULL,
    categoria     VARCHAR(60)   NOT NULL,
    valor         NUMERIC(12,2) NOT NULL CHECK (valor > 0),
    vencimento    DATE          NOT NULL,
    pago_em       DATE,                                 -- NULL = em aberto
    caixa_id      uuid,                                 -- saída lançada no caixa ao pagar
    recorrente_id uuid,
    usuario_id    uuid,
    criado_em     TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT contas_pagar_tenant_id_uk  UNIQUE (tenant_id, id),
    CONSTRAINT contas_pagar_parcela_uk    UNIQUE (tenant_id, recorrente_id, vencimento),
    CONSTRAINT contas_pagar_caixa_fk      FOREIGN KEY (tenant_id, caixa_id) REFERENCES caixa (tenant_id, id),
    CONSTRAINT contas_pagar_recorrente_fk FOREIGN KEY (tenant_id, recorrente_id) REFERENCES contas_recorrentes (tenant_id, id),
    CONSTRAINT contas_pagar_usuario_fk    FOREIGN KEY (tenant_id, usuario_id) REFERENCES usuarios (tenant_id, id),
    CONSTRAINT contas_pagar_pago_ck       CHECK ((pago_em IS NULL) = (caixa_id IS NULL))
);
CREATE INDEX contas_pagar_vencimento_idx ON contas_pagar (tenant_id, pago_em, vencimento);

-- Roteiro do motorista: paradas (entrega/coleta) marcadas como feitas
CREATE TABLE entregas (
    id         uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id  uuid         NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    locacao_id uuid         NOT NULL,
    tipo       VARCHAR(7)   NOT NULL CHECK (tipo IN ('entrega', 'coleta')),
    data       DATE         NOT NULL,          -- dia do roteiro
    obs        VARCHAR(200) NOT NULL DEFAULT '',
    usuario_id uuid,
    feita_em   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT entregas_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT entregas_parada_uk    UNIQUE (tenant_id, locacao_id, tipo, data),
    CONSTRAINT entregas_locacao_fk   FOREIGN KEY (tenant_id, locacao_id) REFERENCES locacoes (tenant_id, id),
    CONSTRAINT entregas_usuario_fk   FOREIGN KEY (tenant_id, usuario_id) REFERENCES usuarios (tenant_id, id)
);
CREATE INDEX entregas_data_idx ON entregas (tenant_id, data);

-- Auditoria: cada inclusão, alteração e exclusão nas tabelas principais (gravada por gatilho, ver auditar())
CREATE TABLE auditoria (
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
CREATE INDEX auditoria_quando_idx ON auditoria (tenant_id, quando DESC);
CREATE INDEX auditoria_registro_idx ON auditoria (tenant_id, registro_id);

-- Numeração própria de cada locadora ('locacao', 'reserva', ...)
CREATE TABLE tenant_sequencias (
    tenant_id   uuid        NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    nome        VARCHAR(30) NOT NULL,
    ultimo      INTEGER     NOT NULL DEFAULT 0,
    PRIMARY KEY (tenant_id, nome)
);

-- =====================================================================
-- ROW LEVEL SECURITY: uma política única por tabela, aplicada em laço
-- para nenhuma tabela ficar de fora.
-- =====================================================================
DO $$
DECLARE
    t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY['empresa', 'usuarios', 'clientes', 'categorias', 'produtos',
                             'reservas', 'reserva_itens', 'locacoes', 'locacao_itens',
                             'devolucoes', 'devolucao_itens', 'caixa', 'tenant_sequencias',
                             'vistorias', 'vistoria_itens', 'vistoria_fotos', 'kits', 'kit_itens',
                             'unidades', 'manutencoes', 'locacao_unidades', 'auditoria',
                             'contas_recorrentes', 'contas_pagar', 'entregas']
    LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
        EXECUTE format('CREATE POLICY isolamento_tenant ON %I
                          USING (tenant_id = app_tenant_id())
                          WITH CHECK (tenant_id = app_tenant_id())', t);
    END LOOP;
END $$;

-- =====================================================================
-- FUNÇÕES (todas SECURITY INVOKER: rodam com as permissões e o RLS de quem chama)
-- =====================================================================

-- Próximo número da locadora atual para a sequência informada.
-- O ON CONFLICT trava a linha da sequência até o fim da transação: dois usuários
-- da mesma locadora nunca recebem o mesmo número.
CREATE FUNCTION proximo_numero(p_nome TEXT) RETURNS INTEGER LANGUAGE sql VOLATILE AS
$$
    INSERT INTO tenant_sequencias (nome, ultimo) VALUES (p_nome, 1)
    ON CONFLICT (tenant_id, nome) DO UPDATE SET ultimo = tenant_sequencias.ultimo + 1
    RETURNING ultimo
$$;

-- Dados iniciais de uma locadora recém-criada (usada pelo provisionamento e pela demo).
-- Exige app.tenant_id já definido para a nova locadora.
CREATE FUNCTION provisionar_dados_padrao(p_razao_social TEXT) RETURNS void LANGUAGE sql VOLATILE AS
$$
    INSERT INTO empresa (razao_social, nome_fantasia) VALUES (p_razao_social, p_razao_social);
    INSERT INTO categorias (nome) VALUES
        ('Andaimes e escoramento'),
        ('Máquinas e ferramentas'),
        ('Compactação e concretagem'),
        ('Energia e iluminação'),
        ('Eventos');
$$;

-- Preço de 1 unidade por p_dias (mínimo 1): meses de 30 dias, depois semanas de 7, depois diárias.
-- As diárias que sobram nunca passam do preço da semana, e o que sobra do mês nunca passa do preço do mês.
-- Nunca custa mais que só diárias. Semanal/mensal = 0: não oferecido.
CREATE FUNCTION preco_periodo(p_dias INTEGER, p_diaria NUMERIC, p_semanal NUMERIC, p_mensal NUMERIC)
RETURNS NUMERIC LANGUAGE sql IMMUTABLE PARALLEL SAFE AS
$$
    SELECT LEAST(d * p_diaria, m * p_mensal + CASE WHEN p_mensal > 0 THEN LEAST(s, p_mensal) ELSE s END)
      FROM (SELECT d, m,
                   CASE WHEN p_semanal > 0 THEN (r / 7) * p_semanal + LEAST((r % 7) * p_diaria, p_semanal)
                        ELSE r * p_diaria END AS s
              FROM (SELECT d,
                           CASE WHEN p_mensal > 0 THEN d / 30 ELSE 0 END AS m,
                           CASE WHEN p_mensal > 0 THEN d % 30 ELSE d END AS r
                      FROM (SELECT GREATEST(1, p_dias) AS d) a) b) c
$$;

-- Auditoria (a explicação completa está em migrations/008_auditoria.sql)
-- Argumentos do gatilho: colunas mascaradas, colunas ignoradas (CSV) e a coluna do rótulo
CREATE FUNCTION auditar() RETURNS trigger LANGUAGE plpgsql AS
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
        ('manutencoes', '', '', 'numero'),
        ('contas_pagar', '', '', 'descricao'),
        ('contas_recorrentes', '', 'gerado_ate', 'descricao'),
        ('entregas', '', '', 'tipo')
    ) AS t(tabela, mascarar, ignorar, rotulo) LOOP
        EXECUTE format('CREATE TRIGGER auditoria AFTER INSERT OR UPDATE OR DELETE ON %I
                          FOR EACH ROW EXECUTE FUNCTION auditar(%L, %L, %L)', r.tabela, r.mascarar, r.ignorar, r.rotulo);
    END LOOP;
END $$;

-- =====================================================================
-- VIEWS (security_invoker = true é OBRIGATÓRIO: sem isso o RLS é ignorado)
-- =====================================================================

-- Quantidade de cada produto na rua (locações em aberto, ainda não devolvida)
CREATE VIEW vw_produto_alugado WITH (security_invoker = true) AS
SELECT li.tenant_id,
       li.produto_id,
       SUM(li.quantidade - li.qtd_devolvida)::INTEGER AS qtd_alugada
  FROM locacao_itens li
  JOIN locacoes l ON l.tenant_id = li.tenant_id AND l.id = li.locacao_id
 WHERE l.status = 'aberta'
 GROUP BY li.tenant_id, li.produto_id;

-- Peças em manutenção agora, por produto
CREATE VIEW vw_produto_manutencao WITH (security_invoker = true) AS
SELECT tenant_id, produto_id, SUM(quantidade)::INTEGER AS qtd_manutencao
  FROM manutencoes
 WHERE data_fim IS NULL
 GROUP BY tenant_id, produto_id;

-- Valores de cada locação.
--   valor_previsto: período combinado (mínimo 1 diária, preco_periodo) + acréscimo - desconto
--   valor_atual:    já apurado nas devoluções (itens + multa + avaria)
--                   + se aberta, itens pendentes cobrados até hoje (mínimo 1 diária)
--                   + acréscimo - desconto.  Locação cancelada vale 0.
--   valor_pago:     entradas no caixa da locação menos saídas (estornos)
CREATE VIEW vw_locacao_valores WITH (security_invoker = true) AS
SELECT l.tenant_id,
       l.id AS locacao_id,
       l.numero,
       l.status,
       (COALESCE(it.previsto_itens, 0) + l.acrescimo + l.frete_entrega + l.frete_recolhimento - l.desconto)::NUMERIC(12,2) AS valor_previsto,
       CASE WHEN l.status = 'cancelada' THEN 0
            ELSE COALESCE(dv.valor_itens, 0)
               + COALESCE(dv.multa_avaria, 0)
               + CASE WHEN l.status = 'aberta' THEN COALESCE(it.pendente_hoje, 0) ELSE 0 END
               + l.acrescimo + l.frete_entrega + l.frete_recolhimento - l.desconto
       END::NUMERIC(12,2) AS valor_atual,
       COALESCE(cx.pago, 0)::NUMERIC(12,2) AS valor_pago
  FROM locacoes l
  LEFT JOIN LATERAL (
        SELECT SUM(li.quantidade * preco_periodo(l.data_prev_devolucao - l.data_saida,
                                                 li.valor_diaria, li.valor_semanal, li.valor_mensal)) AS previsto_itens,
               SUM((li.quantidade - li.qtd_devolvida) * preco_periodo(CURRENT_DATE - l.data_saida,
                                                 li.valor_diaria, li.valor_semanal, li.valor_mensal)) AS pendente_hoje
          FROM locacao_itens li
         WHERE li.tenant_id = l.tenant_id AND li.locacao_id = l.id
       ) it ON TRUE
  LEFT JOIN LATERAL (
        SELECT SUM(d.multa + d.avaria) AS multa_avaria,
               SUM((SELECT COALESCE(SUM(di.valor), 0)
                      FROM devolucao_itens di
                     WHERE di.tenant_id = d.tenant_id AND di.devolucao_id = d.id)) AS valor_itens
          FROM devolucoes d
         WHERE d.tenant_id = l.tenant_id AND d.locacao_id = l.id
       ) dv ON TRUE
  LEFT JOIN LATERAL (
        SELECT SUM(CASE WHEN c.tipo = 'E' THEN c.valor ELSE -c.valor END) AS pago
          FROM caixa c
         WHERE c.tenant_id = l.tenant_id AND c.locacao_id = l.id
       ) cx ON TRUE;

-- =====================================================================
-- GRANTS mínimos
-- =====================================================================
GRANT USAGE ON SCHEMA public TO locgestor_app, locgestor_plataforma;

REVOKE EXECUTE ON FUNCTION app_tenant_id(), proximo_numero(TEXT), provisionar_dados_padrao(TEXT),
                           preco_periodo(INTEGER, NUMERIC, NUMERIC, NUMERIC) FROM PUBLIC;

-- Sistema das locadoras (index.php)
GRANT SELECT, INSERT, UPDATE, DELETE ON clientes, categorias, produtos, reservas, reserva_itens, caixa, kits, kit_itens
      TO locgestor_app;
GRANT SELECT, INSERT, UPDATE ON locacoes, locacao_itens, devolucoes, devolucao_itens, tenant_sequencias, usuarios
      TO locgestor_app;
GRANT SELECT, UPDATE ON empresa TO locgestor_app;
-- Vistorias são prova: só leitura e inclusão
GRANT SELECT, INSERT ON vistorias, vistoria_itens, vistoria_fotos TO locgestor_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON unidades, manutencoes, locacao_unidades TO locgestor_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON contas_recorrentes, contas_pagar TO locgestor_app;
GRANT SELECT, INSERT, DELETE ON entregas TO locgestor_app;
-- Auditoria: só leitura e inclusão (a inclusão é do gatilho, com o papel de quem alterou)
GRANT SELECT, INSERT ON auditoria TO locgestor_app;
GRANT SELECT ON vw_produto_alugado, vw_locacao_valores, vw_produto_manutencao TO locgestor_app;
-- tenants não tem RLS: o app só lê as colunas necessárias ao login e ao limite de usuários
GRANT SELECT (id, slug, nome, status, plano, limite_usuarios, vencimento, carencia_dias) ON tenants TO locgestor_app;
GRANT EXECUTE ON FUNCTION app_tenant_id(), proximo_numero(TEXT), preco_periodo(INTEGER, NUMERIC, NUMERIC, NUMERIC) TO locgestor_app;

-- Painel da plataforma (plataforma.php). Nas tabelas das locadoras vale o mesmo RLS do app.
GRANT SELECT, INSERT, UPDATE ON tenants TO locgestor_plataforma;
GRANT SELECT, INSERT, UPDATE, DELETE ON planos TO locgestor_plataforma;
GRANT SELECT, INSERT, DELETE ON assinatura_pagamentos TO locgestor_plataforma;
GRANT SELECT, INSERT, UPDATE, DELETE ON assinatura_cobrancas TO locgestor_plataforma;
GRANT SELECT, INSERT ON gateway_eventos TO locgestor_plataforma;
GRANT INSERT ON auditoria TO locgestor_plataforma;
GRANT SELECT (locadora_id, vencimento, valor, status, link) ON assinatura_cobrancas TO locgestor_app;
GRANT SELECT, UPDATE ON plataforma_usuarios TO locgestor_plataforma;
GRANT SELECT, INSERT ON empresa, categorias TO locgestor_plataforma;
GRANT SELECT, INSERT, UPDATE ON usuarios TO locgestor_plataforma;
GRANT SELECT ON clientes, locacoes TO locgestor_plataforma;
-- Tela de uso por locadora: só contagens (RLS da locadora continua valendo)
GRANT SELECT ON vistorias, vistoria_fotos, entregas, unidades, manutencoes, kits, contas_pagar TO locgestor_plataforma;
-- Acesso de suporte (painel "Entrar como a locadora"): registro do painel e troca do código pela locadora
GRANT SELECT, INSERT ON acessos_suporte TO locgestor_plataforma;
-- Recados do painel para todas as locadoras (sem tenant_id: são avisos gerais)
CREATE TABLE recados (
    id         uuid PRIMARY KEY DEFAULT uuidv7(),
    tipo       VARCHAR(12)  NOT NULL CHECK (tipo IN ('aviso', 'novidade', 'manutencao')),
    titulo     VARCHAR(120) NOT NULL CHECK (char_length(titulo) >= 3),
    texto      VARCHAR(600) NOT NULL CHECK (char_length(texto) >= 5),
    expira_em  DATE         NOT NULL,
    ativo      BOOLEAN      NOT NULL DEFAULT TRUE,
    usuario    VARCHAR(40)  NOT NULL DEFAULT '',
    criado_em  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX recados_ativos_idx ON recados (ativo, expira_em);
GRANT SELECT, INSERT, UPDATE ON recados TO locgestor_plataforma;
GRANT SELECT ON recados TO locgestor_app;
GRANT SELECT (id, tenant_id, login_suporte, motivo, token_hash, expira_em, usado_em), UPDATE (usado_em) ON acessos_suporte TO locgestor_app;
GRANT EXECUTE ON FUNCTION app_tenant_id(), provisionar_dados_padrao(TEXT) TO locgestor_plataforma;

-- Proteção do login (as duas áreas)
GRANT SELECT, INSERT, DELETE ON login_tentativas TO locgestor_app, locgestor_plataforma;

-- Não há sequências (as chaves são uuidv7), mas se alguma for criada o app poderá usá-la
GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO locgestor_app, locgestor_plataforma;

-- =====================================================================
-- AUTOCHECAGEM: aborta a instalação se alguma tabela com tenant_id ficou sem
-- RLS forçado ou sem política, se alguma view não tem security_invoker,
-- ou se existe função SECURITY DEFINER no schema.
-- =====================================================================
DO $$
DECLARE
    v_falhas TEXT;
BEGIN
    SELECT string_agg(c.relname, ', ') INTO v_falhas
      FROM pg_class c
      JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = 'public'
     WHERE c.relkind IN ('r', 'p')
       AND EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped)
       AND (NOT c.relrowsecurity OR NOT c.relforcerowsecurity
            OR NOT EXISTS (SELECT 1 FROM pg_policy p WHERE p.polrelid = c.oid));
    IF v_falhas IS NOT NULL THEN
        RAISE EXCEPTION 'Tabelas sem RLS completo: %', v_falhas;
    END IF;

    SELECT string_agg(c.relname, ', ') INTO v_falhas
      FROM pg_class c
      JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = 'public'
     WHERE c.relkind IN ('v', 'm')
       AND NOT COALESCE('security_invoker=true' = ANY (c.reloptions), false);
    IF v_falhas IS NOT NULL THEN
        RAISE EXCEPTION 'Views sem security_invoker=true: %', v_falhas;
    END IF;

    SELECT string_agg(p.proname, ', ') INTO v_falhas
      FROM pg_proc p
      JOIN pg_namespace n ON n.oid = p.pronamespace AND n.nspname = 'public'
     WHERE p.prosecdef;
    IF v_falhas IS NOT NULL THEN
        RAISE EXCEPTION 'Funções SECURITY DEFINER não são permitidas: %', v_falhas;
    END IF;
END $$;

-- Auditoria do painel da plataforma (ver migrations/016_auditoria_plataforma.sql)
CREATE TABLE auditoria_plataforma (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    quando      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    usuario     VARCHAR(80)  NOT NULL DEFAULT '',
    tabela      VARCHAR(40)  NOT NULL,
    registro_id uuid,
    rotulo      VARCHAR(160) NOT NULL DEFAULT '',
    acao        CHAR(1)      NOT NULL CHECK (acao IN ('I', 'U', 'D')),
    dados       JSONB        NOT NULL
);
CREATE INDEX auditoria_plataforma_quando_idx ON auditoria_plataforma (quando DESC);
CREATE INDEX auditoria_plataforma_registro_idx ON auditoria_plataforma (registro_id);

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

-- Orçamentos (ver migrations/018_orcamentos.sql)
CREATE TABLE orcamentos (
    id                uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id         uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    numero            INTEGER       NOT NULL,
    cliente_id        uuid          NOT NULL,
    emissao           DATE          NOT NULL DEFAULT CURRENT_DATE,
    validade          DATE          NOT NULL,
    data_saida        DATE          NOT NULL,
    data_prev_devolucao DATE        NOT NULL,
    local_uso         VARCHAR(200)  NOT NULL DEFAULT '',
    acrescimo         NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (acrescimo >= 0),
    desconto          NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (desconto >= 0),
    frete_entrega     NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (frete_entrega >= 0),
    frete_recolhimento NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (frete_recolhimento >= 0),
    obs               TEXT          NOT NULL DEFAULT '',
    status            VARCHAR(10)   NOT NULL DEFAULT 'aberto' CHECK (status IN ('aberto', 'convertido')),
    locacao_id        uuid,
    reserva_id        uuid,
    usuario_id        uuid,
    criado_em         TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT orcamentos_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT orcamentos_numero_uk    UNIQUE (tenant_id, numero),
    CONSTRAINT orcamentos_datas_ck     CHECK (data_prev_devolucao >= data_saida),
    CONSTRAINT orcamentos_cliente_fk   FOREIGN KEY (tenant_id, cliente_id) REFERENCES clientes (tenant_id, id),
    CONSTRAINT orcamentos_locacao_fk   FOREIGN KEY (tenant_id, locacao_id) REFERENCES locacoes (tenant_id, id),
    CONSTRAINT orcamentos_reserva_fk   FOREIGN KEY (tenant_id, reserva_id) REFERENCES reservas (tenant_id, id),
    CONSTRAINT orcamentos_usuario_fk   FOREIGN KEY (tenant_id, usuario_id) REFERENCES usuarios (tenant_id, id),
    CONSTRAINT orcamentos_convertido_ck CHECK (status = 'aberto' OR locacao_id IS NOT NULL OR reserva_id IS NOT NULL)
);
CREATE INDEX orcamentos_cliente_idx ON orcamentos (tenant_id, cliente_id);

CREATE TABLE orcamento_itens (
    id            uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id     uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    orcamento_id  uuid          NOT NULL,
    produto_id    uuid          NOT NULL,
    quantidade    INTEGER       NOT NULL CHECK (quantidade > 0),
    valor_diaria  NUMERIC(12,2) NOT NULL CHECK (valor_diaria >= 0),
    valor_semanal NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (valor_semanal >= 0),
    valor_mensal  NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (valor_mensal >= 0),
    CONSTRAINT orcamento_itens_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT orcamento_itens_orc_fk  FOREIGN KEY (tenant_id, orcamento_id) REFERENCES orcamentos (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT orcamento_itens_prod_fk FOREIGN KEY (tenant_id, produto_id) REFERENCES produtos (tenant_id, id)
);
CREATE INDEX orcamento_itens_orc_idx ON orcamento_itens (tenant_id, orcamento_id);

DO $$
DECLARE
    t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY['orcamentos', 'orcamento_itens'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
        IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = t AND policyname = 'isolamento_tenant') THEN
            EXECUTE format('CREATE POLICY isolamento_tenant ON %I
                              USING (tenant_id = app_tenant_id())
                              WITH CHECK (tenant_id = app_tenant_id())', t);
        END IF;
    END LOOP;
END $$;

CREATE TRIGGER auditoria AFTER INSERT OR UPDATE OR DELETE ON orcamentos
    FOR EACH ROW EXECUTE FUNCTION auditar('', 'criado_em', 'numero');

GRANT SELECT, INSERT, UPDATE, DELETE ON orcamentos, orcamento_itens TO locgestor_app;

RESET ROLE;

-- Códigos de barras do produto (migração 019)
SET ROLE locgestor_owner;

CREATE TABLE IF NOT EXISTS produto_barras (
    id         uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id  uuid        NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    produto_id uuid        NOT NULL,
    codigo     VARCHAR(20) NOT NULL CHECK (codigo ~ '^[0-9]{5,20}$'),
    principal  BOOLEAN     NOT NULL DEFAULT FALSE,
    CONSTRAINT produto_barras_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT produto_barras_codigo_uk    UNIQUE (tenant_id, codigo),
    CONSTRAINT produto_barras_produto_fk   FOREIGN KEY (tenant_id, produto_id) REFERENCES produtos (tenant_id, id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS produto_barras_produto_idx ON produto_barras (tenant_id, produto_id);
-- Um principal por produto
CREATE UNIQUE INDEX IF NOT EXISTS produto_barras_principal_uk ON produto_barras (tenant_id, produto_id) WHERE principal;

ALTER TABLE produto_barras ENABLE ROW LEVEL SECURITY;
ALTER TABLE produto_barras FORCE ROW LEVEL SECURITY;
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'produto_barras' AND policyname = 'isolamento_tenant') THEN
        CREATE POLICY isolamento_tenant ON produto_barras
            USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
    END IF;
END $$;

DROP TRIGGER IF EXISTS auditoria ON produto_barras;
CREATE TRIGGER auditoria AFTER INSERT OR UPDATE OR DELETE ON produto_barras
    FOR EACH ROW EXECUTE FUNCTION auditar('', '', 'codigo');

GRANT SELECT, INSERT, UPDATE, DELETE ON produto_barras TO locgestor_app;

RESET ROLE;

-- Recibos numerados (migração 020)
SET ROLE locgestor_owner;

CREATE TABLE IF NOT EXISTS recibos (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id   uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    numero      INTEGER       NOT NULL,
    emissao     DATE          NOT NULL DEFAULT CURRENT_DATE,
    recebido_de VARCHAR(150)  NOT NULL,
    documento   VARCHAR(14)   NOT NULL DEFAULT '',
    valor       NUMERIC(12,2) NOT NULL CHECK (valor > 0),
    referente   VARCHAR(200)  NOT NULL,
    forma       VARCHAR(30)   NOT NULL DEFAULT 'Dinheiro',
    cliente_id  uuid,
    locacao_id  uuid,
    usuario_id  uuid,
    criado_em   TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT recibos_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT recibos_numero_uk    UNIQUE (tenant_id, numero),
    CONSTRAINT recibos_cliente_fk   FOREIGN KEY (tenant_id, cliente_id) REFERENCES clientes (tenant_id, id),
    CONSTRAINT recibos_locacao_fk   FOREIGN KEY (tenant_id, locacao_id) REFERENCES locacoes (tenant_id, id),
    CONSTRAINT recibos_usuario_fk   FOREIGN KEY (tenant_id, usuario_id) REFERENCES usuarios (tenant_id, id)
);
CREATE INDEX IF NOT EXISTS recibos_locacao_idx ON recibos (tenant_id, locacao_id);

ALTER TABLE recibos ENABLE ROW LEVEL SECURITY;
ALTER TABLE recibos FORCE ROW LEVEL SECURITY;
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'recibos' AND policyname = 'isolamento_tenant') THEN
        CREATE POLICY isolamento_tenant ON recibos
            USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
    END IF;
END $$;

DROP TRIGGER IF EXISTS auditoria ON recibos;
CREATE TRIGGER auditoria AFTER INSERT ON recibos
    FOR EACH ROW EXECUTE FUNCTION auditar('documento', 'criado_em', 'numero');

-- Sem UPDATE nem DELETE para o sistema: o recibo emitido fica como está
GRANT SELECT, INSERT ON recibos TO locgestor_app;

RESET ROLE;

-- Autorização de desconto e acréscimo (migração 021)
SET ROLE locgestor_owner;

ALTER TABLE locacoes ADD COLUMN IF NOT EXISTS desconto_autorizado_por uuid;
ALTER TABLE orcamentos ADD COLUMN IF NOT EXISTS desconto_autorizado_por uuid;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'locacoes_desconto_aut_fk') THEN
        ALTER TABLE locacoes ADD CONSTRAINT locacoes_desconto_aut_fk
            FOREIGN KEY (tenant_id, desconto_autorizado_por) REFERENCES usuarios (tenant_id, id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'orcamentos_desconto_aut_fk') THEN
        ALTER TABLE orcamentos ADD CONSTRAINT orcamentos_desconto_aut_fk
            FOREIGN KEY (tenant_id, desconto_autorizado_por) REFERENCES usuarios (tenant_id, id);
    END IF;
END $$;

RESET ROLE;

-- Perdas na devolução (migração 022)
SET ROLE locgestor_owner;

CREATE TABLE devolucao_perdas (
    id               uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id        uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    devolucao_id     uuid          NOT NULL,
    locacao_item_id  uuid          NOT NULL,
    quantidade       INTEGER       NOT NULL CHECK (quantidade > 0),
    valor_reposicao  NUMERIC(12,2) NOT NULL CHECK (valor_reposicao >= 0),
    valor            NUMERIC(12,2) NOT NULL CHECK (valor >= 0),
    CONSTRAINT devolucao_perdas_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT devolucao_perdas_devolucao_fk FOREIGN KEY (tenant_id, devolucao_id) REFERENCES devolucoes (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT devolucao_perdas_item_fk      FOREIGN KEY (tenant_id, locacao_item_id) REFERENCES locacao_itens (tenant_id, id) ON DELETE CASCADE
);
CREATE INDEX devolucao_perdas_devolucao_idx ON devolucao_perdas (tenant_id, devolucao_id);

ALTER TABLE devolucao_perdas ENABLE ROW LEVEL SECURITY;
ALTER TABLE devolucao_perdas FORCE ROW LEVEL SECURITY;
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'devolucao_perdas' AND policyname = 'isolamento_tenant') THEN
        CREATE POLICY isolamento_tenant ON devolucao_perdas
            USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
    END IF;
END $$;

DROP TRIGGER IF EXISTS auditoria ON devolucao_perdas;
CREATE TRIGGER auditoria AFTER INSERT ON devolucao_perdas
    FOR EACH ROW EXECUTE FUNCTION auditar('', 'criado_em', '');

GRANT SELECT, INSERT ON devolucao_perdas TO locgestor_app;

RESET ROLE;
