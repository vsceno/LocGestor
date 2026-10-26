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
    status          VARCHAR(10)  NOT NULL DEFAULT 'ativa' CHECK (status IN ('ativa', 'suspensa')),
    plano           VARCHAR(40)  NOT NULL DEFAULT 'Básico',
    limite_usuarios INTEGER      NOT NULL DEFAULT 3 CHECK (limite_usuarios >= 1),
    criado_em       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    -- Assinatura: vencimento da próxima mensalidade (NULL = sem cobrança/isenta).
    -- Passados carencia_dias do vencimento sem pagamento, o acesso é suspenso.
    plano_id         uuid        REFERENCES planos (id),
    vencimento       DATE,
    carencia_dias    INTEGER     NOT NULL DEFAULT 5,
    motivo_suspensao VARCHAR(20) NOT NULL DEFAULT '',
    -- Cobrança automática pelo Asaas (desligada por padrão) e o id do cliente lá
    cobranca_automatica BOOLEAN     NOT NULL DEFAULT FALSE,
    asaas_cliente_id    VARCHAR(40) NOT NULL DEFAULT '',
    CONSTRAINT tenants_slug_uk UNIQUE (slug),
    CONSTRAINT tenants_slug_ck CHECK (slug ~ '^[a-z0-9-]{3,40}$'),
    CONSTRAINT tenants_carencia_ck CHECK (carencia_dias BETWEEN 0 AND 60),
    CONSTRAINT tenants_motivo_ck CHECK (motivo_suspensao IN ('', 'manual', 'inadimplencia'))
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
                             'vistorias', 'vistoria_itens', 'vistoria_fotos', 'kits', 'kit_itens']
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
       (COALESCE(it.previsto_itens, 0) + l.acrescimo - l.desconto)::NUMERIC(12,2) AS valor_previsto,
       CASE WHEN l.status = 'cancelada' THEN 0
            ELSE COALESCE(dv.valor_itens, 0)
               + COALESCE(dv.multa_avaria, 0)
               + CASE WHEN l.status = 'aberta' THEN COALESCE(it.pendente_hoje, 0) ELSE 0 END
               + l.acrescimo - l.desconto
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
GRANT SELECT ON vw_produto_alugado, vw_locacao_valores TO locgestor_app;
-- tenants não tem RLS: o app só lê as colunas necessárias ao login e ao limite de usuários
GRANT SELECT (id, slug, nome, status, plano, limite_usuarios, vencimento, carencia_dias) ON tenants TO locgestor_app;
GRANT EXECUTE ON FUNCTION app_tenant_id(), proximo_numero(TEXT), preco_periodo(INTEGER, NUMERIC, NUMERIC, NUMERIC) TO locgestor_app;

-- Painel da plataforma (plataforma.php). Nas tabelas das locadoras vale o mesmo RLS do app.
GRANT SELECT, INSERT, UPDATE ON tenants TO locgestor_plataforma;
GRANT SELECT, INSERT, UPDATE, DELETE ON planos TO locgestor_plataforma;
GRANT SELECT, INSERT, DELETE ON assinatura_pagamentos TO locgestor_plataforma;
GRANT SELECT, INSERT, UPDATE, DELETE ON assinatura_cobrancas TO locgestor_plataforma;
GRANT SELECT, INSERT ON gateway_eventos TO locgestor_plataforma;
GRANT SELECT (locadora_id, vencimento, valor, status, link) ON assinatura_cobrancas TO locgestor_app;
GRANT SELECT, UPDATE ON plataforma_usuarios TO locgestor_plataforma;
GRANT SELECT, INSERT ON empresa, categorias TO locgestor_plataforma;
GRANT SELECT, INSERT, UPDATE ON usuarios TO locgestor_plataforma;
GRANT SELECT ON clientes, locacoes TO locgestor_plataforma;
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

RESET ROLE;
