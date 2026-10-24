---
description: Cria o LocGestor (SaaS multi-tenant de locação de equipamentos) em PHP + PostgreSQL, por fases
argument-hint: "[fase 1-8 | vazio = começar da fase 1]"
---

# Missão

Você vai construir o **LocGestor**: um **SaaS multi-tenant** para controle e administração de **locação de produtos, equipamentos e acessórios** (construção civil, eventos e outros). Várias locadoras (os *tenants*) usam o mesmo sistema e o mesmo banco, e **cada locadora só enxerga os próprios dados**. O sistema tem cadastro de clientes e produtos, aluguel, devolução (inclusive parcial), reserva, fluxo de caixa, contrato e fatura de locação, relatórios e gráficos, além de um **painel da plataforma** para cadastrar e suspender locadoras.

Fase solicitada: **$ARGUMENTS** (se vazio, comece pela Fase 1).

Trabalhe **uma fase por vez**. Ao fim de cada fase: rode as verificações da fase, mostre um resumo curto do que foi feito e **pare e espere eu aprovar** antes de seguir para a próxima.

---

# Ambiente e restrições (obrigatório)

- **Windows + Laragon** (Apache). O projeto fica em `C:\laragon\www\locgestor` e é acessado por `http://locgestor.test`.
- **PHP 8.1+ puro, sem framework e sem Composer** nesta versão. Extensões: `pdo_pgsql`, `pgsql`.
- **PostgreSQL 18**, banco `locgestor`, encoding UTF8, timezone `America/Fortaleza`. Use `uuidv7()` nativo para as chaves primárias. Antes de começar, rode `psql --version` e `SELECT version();`. Se for anterior ao 18, **me avise** e proponha `gen_random_uuid()` no lugar.
- **Front-end:** HTML + CSS próprio + **JavaScript puro**. A única biblioteca é o **Chart.js 4.4.1**, salvo **localmente** em `assets/vendor/chart.umd.js` para funcionar offline.
- Toda a interface, mensagens, nomes de tabelas e colunas em **português do Brasil**. Formato brasileiro: `R$ 1.234,56` e `dd/mm/aaaa`.
- Código simples e legível, comentado nos pontos de regra de negócio e de isolamento. Nada de abstrações desnecessárias.
- **Nunca** apague ou sobrescreva arquivos existentes sem me avisar.

# Git e GitHub (obrigatório)

- O projeto é versionado com **Git** e publicado no **GitHub**. Se a pasta ainda não for um repositório, rode `git init -b main` na Fase 1.
- **Senhas nunca vão para o GitHub:** crie `config.example.php` (versionado, senhas vazias) e faça o sistema carregar `config.php`. Se `config.php` não existir, mostre: "Copie config.example.php para config.php e preencha as senhas do banco". O `config.php` real fica no `.gitignore`.
- `.gitignore`: `config.php`, `*.log`, `.env`, `/vendor/`, `/node_modules/`, `.vscode/`, `Thumbs.db`, `desktop.ini`, `.DS_Store`, `*.zip`, `/backups/`, `*.sql.gz`. **Não** ignore a pasta `.claude/`.
- `.gitattributes`: `* text=auto eol=lf` e `*.bat text eol=crlf`.
- **Ao fim de cada fase, depois que eu aprovar**, faça um commit em português no formato `Fase N: <resumo curto>`. Antes, rode `git status` e confirme que nenhum `config.php`, senha ou arquivo grande está indo junto.
- **Não** faça `git push` sem eu pedir. Quando eu pedir, use `git push -u origin main`.

---

# Multi-tenant: isolamento por RLS (a parte mais importante)

O isolamento **não pode depender de o programador lembrar do `WHERE tenant_id = ...`**. Quem garante é o próprio PostgreSQL, com **Row Level Security (RLS)**. Mesmo que uma consulta PHP esqueça o filtro, o banco não devolve dados de outra locadora.

## Papéis (roles) do banco

| Papel | Uso | Regras |
|---|---|---|
| `locgestor_owner` | Dono das tabelas. Só para rodar os scripts SQL (migrações) | `NOLOGIN` (os scripts rodam como `postgres` com `SET ROLE locgestor_owner`) |
| `locgestor_app` | **Único usuário que o PHP usa** | `LOGIN`, **`NOBYPASSRLS`**, **não** é dono de nenhuma tabela, sem `CREATE` no schema. Só `SELECT/INSERT/UPDATE/DELETE` nas tabelas e `USAGE` nas sequências |
| `locgestor_plataforma` | Painel da plataforma (dono do SaaS) | `LOGIN`, `NOBYPASSRLS`. Acesso a `tenants` e `plataforma_usuarios`. Nas tabelas dos tenants, vale o mesmo RLS do app |

Gere `database/00_roles.sql` (roda uma vez, como `postgres`) com as senhas como **placeholders** (`'TROQUE_AQUI'`) e me diga para trocá-las. As senhas reais vão só no `config.php`.

## Mecanismo

1. Função `app_tenant_id()`, que lê o tenant da sessão do banco:
   ```sql
   CREATE FUNCTION app_tenant_id() RETURNS uuid LANGUAGE sql STABLE AS
   $$ SELECT NULLIF(current_setting('app.tenant_id', true), '')::uuid $$;
   ```
2. **Toda** tabela de dados de locadora tem `tenant_id uuid NOT NULL DEFAULT app_tenant_id() REFERENCES tenants(id)`. Com esse DEFAULT, o PHP **nunca precisa enviar o `tenant_id`** nos INSERTs.
3. Em **toda** tabela de tenant: `ENABLE ROW LEVEL SECURITY` + **`FORCE ROW LEVEL SECURITY`** + política única:
   ```sql
   CREATE POLICY isolamento_tenant ON <tabela>
     USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
   ```
   Aplique com um bloco `DO $$ ... FOREACH ... $$` sobre a lista de tabelas, para nenhuma ficar de fora.
4. **Chaves estrangeiras compostas** impedem apontar para registro de outra locadora: cada tabela referenciada tem `UNIQUE (tenant_id, id)` e as FKs são `FOREIGN KEY (tenant_id, cliente_id) REFERENCES clientes (tenant_id, id)`, e assim por diante.
5. **Unicidade por locadora**, não global: `UNIQUE (tenant_id, cpf_cnpj)`, `UNIQUE (tenant_id, codigo)`, `UNIQUE (tenant_id, login)`, `UNIQUE (tenant_id, nome)` em categorias. Duas locadoras podem ter o mesmo cliente e o mesmo código de produto.
6. **⚠ Views:** toda view é criada com **`WITH (security_invoker = true)`**. Sem isso, a view roda com as permissões do dono e **ignora o RLS, vazando dados entre locadoras** (já confirmado em teste). Isso vale para `vw_produto_alugado`, `vw_locacao_valores` e qualquer outra.
7. **Funções `SECURITY DEFINER` são proibidas** nas tabelas de tenant.
8. **Numeração por locadora:** o PK é UUID, mas o usuário vê números sequenciais próprios de cada locadora (Locação nº 1, 2, 3... na Locadora A e também 1, 2, 3... na Locadora B). Tabela `tenant_sequencias (tenant_id, nome, ultimo)` com RLS e função `proximo_numero('locacao')` usando `INSERT ... ON CONFLICT DO UPDATE SET ultimo = ultimo + 1 RETURNING ultimo`. Use o mesmo para reservas. `UNIQUE (tenant_id, numero)`. Em URLs e telas, mostre o `numero`. Internamente use o `id`.

## No PHP

- `app/tenant.php` com a classe/funções do **contexto do tenant**:
  - `tenant_definir(string $tenantId)`: valida o UUID e executa `SELECT set_config('app.tenant_id', :t, false)` na conexão. Guarda também em `$GLOBALS`/sessão.
  - `tenant_id()` e `tenant()` (dados da locadora atual, com cache por requisição).
  - Em toda requisição autenticada, `bootstrap.php` chama `tenant_definir($_SESSION['tenant_id'])` **logo após conectar e antes de qualquer consulta**, e confere se a locadora está `ativa`. Se estiver suspensa, encerra a sessão com a mensagem "Acesso suspenso. Entre em contato com o suporte."
- Conexões PDO **não persistentes** (`PDO::ATTR_PERSISTENT => false`), para o `app.tenant_id` de uma requisição nunca sobrar para outra.
- **Login da locadora:** campos **Empresa** (o `slug`, por exemplo `alfa-locacoes`), **Usuário** e **Senha**. Fluxo: busca o tenant pelo slug na tabela `tenants` (que não tem RLS e o app só pode ler) → se não existe ou está suspenso, mostra a mensagem genérica "Dados de acesso inválidos" (não revela se a empresa existe) → `tenant_definir()` → busca o usuário (já filtrado pelo RLS) → `password_verify` → `session_regenerate_id(true)` → grava `tenant_id`, `tenant_slug` e o usuário na sessão. Lembre o último slug em um cookie para preencher o campo.
- **Nunca** aceite `tenant_id` vindo de formulário, URL ou cookie depois do login. A única fonte é a sessão.
- **PgBouncer:** se no futuro for usado, precisa ser em modo `session`. Em modo `transaction`, o `set_config(..., false)` vaza entre clientes. Deixe esse aviso comentado em `tenant.php` e no README.

## Painel da plataforma (dono do SaaS)

- Área separada `plataforma.php` (front controller próprio, sessão própria com `session_name('locgestor_plataforma')`), que conecta com o papel `locgestor_plataforma`.
- Tabela `plataforma_usuarios` (sem tenant) com login próprio. O seed cria `suporte` / senha gerada; mostre o comando para gerar o hash.
- Tabela `tenants`: `id uuid PK DEFAULT uuidv7()`, `slug` UNIQUE (só `a-z0-9-`, de 3 a 40 caracteres), `nome`, `cnpj`, `email_contato`, `telefone`, `status` (`ativa`/`suspensa`), `plano` (texto livre por enquanto), `limite_usuarios` (int), `criado_em`.
- Telas: lista de locadoras (com nº de usuários, clientes, locações abertas e último acesso), **Nova locadora** e suspender/reativar.
- **Nova locadora** (provisionamento), tudo numa transação: cria o tenant → `set_config('app.tenant_id', novo_id, true)` → cria a linha de `empresa`, as 5 categorias padrão, o texto padrão do contrato e o **usuário admin** da locadora com senha temporária → mostra na tela o slug, o login e a senha temporária **uma única vez**.
- Respeite `limite_usuarios` ao criar usuários dentro da locadora.

---

# Arquitetura

```
locgestor/
├── index.php            ← sistema da locadora: index.php?p=<modulo>&a=<acao>
├── plataforma.php       ← painel do dono do SaaS
├── config.example.php   ← modelo versionado (senhas vazias): db_app, db_plataforma, app_name, timezone, per_page
├── config.php           ← cópia local com as senhas reais (NÃO vai para o GitHub)
├── .gitignore, .gitattributes
├── .htaccess            ← Options -Indexes; bloqueia config*.php e README.md
├── app/
│   ├── bootstrap.php    ← sessão, PDO, helpers, CSRF, auth, formatação, validação CPF/CNPJ
│   ├── tenant.php       ← contexto do tenant (set_config, tenant atual, checagem de status)
│   ├── layout.php       ← layout_start($titulo, $menuAtivo), layout_end($scripts), print_start(), print_end()
│   └── negocio.php      ← TODAS as regras de negócio (disponibilidade, locação, devolução, recebimento)
├── modulos/             ← login, dashboard, clientes, produtos, locacoes, reservas, caixa, relatorios, config, api
├── modulos_plataforma/  ← login, tenants
├── assets/css/app.css, assets/css/print.css
├── assets/js/app.js, assets/js/graficos.js, assets/vendor/chart.umd.js
├── database/
│   ├── 00_roles.sql     ← papéis (roda 1 vez, como postgres)
│   ├── 01_schema.sql    ← tabelas, RLS, views, grants
│   ├── 02_seed.sql      ← usuário da plataforma
│   ├── 03_demo.sql      ← 2 locadoras de demonstração (opcional)
│   └── instalar.bat     ← roda os scripts na ordem, pedindo a senha do postgres
└── tests/
    └── isolamento.php   ← testes de isolamento entre locadoras (CLI)
```

- `app/`, `modulos/`, `modulos_plataforma/`, `database/` e `tests/` têm `.htaccess` com `Require all denied`.
- `index.php` valida `p` contra uma lista fixa de módulos, exige login (exceto em `login`), valida `a` com `/^[a-z_]+$/`, chama `csrf_check()` em todo POST e trata exceções:
  - `UserError` (exceção própria para erro de regra): grava `$_POST` em `$_SESSION['old']`, mostra a mensagem com `flash()` e redireciona para o `HTTP_REFERER`.
  - `PDOException`: registra no log e traduz para mensagem amigável (CPF/CNPJ duplicado, código duplicado, registro em uso, falha de conexão). Violação de política RLS vira "Operação não permitida" e é registrada no log como **alerta de segurança**.
- Padrão **POST → Redirect → GET** em todas as ações de gravação.

## Helpers obrigatórios em `bootstrap.php`
`db()`, `q($sql,$params)`, `all()`, `one()`, `val()`, `tx(callable)` (transação com rollback), `e()` (htmlspecialchars), `money()`, `num()`, `parse_money("1.234,56")`, `dt()`, `today()`, `valid_date()`, `days_between()`, `get()`, `post()`, `post_arr()`, `is_post()`, `url($pagina, $params)`, `redirect()`, `flash()`, `flashes()`, `old()`, `has_old()`, `csrf_token()`, `csrf_field()`, `csrf_check()`, `user()`, `uid()`, `is_admin()`, `require_admin()`, `empresa()`, `options()` (gera `<option>`), `paginate()`, `pager()`, `doc_valido()` (dígitos verificadores de CPF e CNPJ), `is_uuid()`, e as constantes `UFS` e `FORMAS_PGTO` (Dinheiro, PIX, Cartão de débito, Cartão de crédito, Boleto, Transferência, Cheque).

PDO com `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES = false` e `PERSISTENT = false`. Ao conectar: `SET TIME ZONE`.

---

# Banco de dados (`database/01_schema.sql`)

Todas as tabelas abaixo, **exceto `tenants` e `plataforma_usuarios`**, têm `id uuid PRIMARY KEY DEFAULT uuidv7()`, `tenant_id` (conforme a seção de multi-tenant), `UNIQUE (tenant_id, id)`, RLS forçado e FKs compostas.

| Tabela | Campos principais |
|---|---|
| `empresa` | uma linha por locadora (`PRIMARY KEY (tenant_id)`): razao_social, nome_fantasia, cnpj, ie, telefone, email, endereco, cidade, uf, cep, **termos_contrato**, **obs_fatura** |
| `usuarios` | nome, login (`UNIQUE (tenant_id, login)`), senha_hash, perfil (`admin`/`operador`), ativo, ultimo_acesso |
| `clientes` | tipo (PF/PJ), nome, cpf_cnpj (UNIQUE parcial por tenant quando não vazio), rg_ie, telefone, celular, email, cep, endereco, numero, bairro, cidade, uf, obs, **bloqueado**, ativo |
| `categorias` | nome (`UNIQUE (tenant_id, nome)`) |
| `produtos` | codigo (`UNIQUE (tenant_id, codigo)`), descricao, categoria_id, unidade, **qtd_total**, **valor_diaria**, **valor_reposicao**, ncm, obs, ativo |
| `locacoes` | **numero** (por tenant), cliente_id, data_saida, data_prev_devolucao (CHECK ≥ saída), status (`aberta`/`devolvida`/`cancelada`), local_uso, desconto, acrescimo, obs, reserva_id, usuario_id |
| `locacao_itens` | locacao_id, produto_id, quantidade > 0, qtd_devolvida (CHECK entre 0 e quantidade), **valor_diaria congelado** |
| `devolucoes` | locacao_id, data, multa, avaria, obs, usuario_id |
| `devolucao_itens` | devolucao_id, locacao_item_id, quantidade, dias, valor |
| `reservas` + `reserva_itens` | **numero** (por tenant), cliente_id, data_inicio, data_fim, status (`ativa`/`convertida`/`cancelada`), obs / produto_id, quantidade |
| `caixa` | data, tipo (`E`/`S`), categoria, descricao, valor > 0, forma, locacao_id (nullable), usuario_id |
| `tenant_sequencias` | nome, ultimo (PK `(tenant_id, nome)`) |

Valores em `NUMERIC(12,2)`. **Todo índice começa por `tenant_id`** (por exemplo `(tenant_id, status, data_prev_devolucao)` em locações), porque toda consulta filtra por ele.

**Views** (com `security_invoker = true`):
- `vw_produto_alugado`: soma de `quantidade - qtd_devolvida` por produto em locações `aberta`.
- `vw_locacao_valores`: por locação, devolve
  - `valor_previsto` = Σ(qtd × diária × max(1, prev − saída)) + acréscimo − desconto
  - `valor_atual` = Σ valores devolvidos + Σ(multa + avaria) + (se aberta: pendentes × diária × max(1, hoje − saída)) + acréscimo − desconto
  - `valor_pago` = Σ caixa da locação (E soma, S subtrai)

No final do schema, faça os `GRANT`s mínimos para `locgestor_app` e `locgestor_plataforma` e `REVOKE CREATE ON SCHEMA public FROM PUBLIC`.

`03_demo.sql` (opcional): **duas locadoras**, `alfa-locacoes` (construção civil) e `beta-eventos` (eventos), cada uma com admin `admin`/`admin123`.
- **Alfa:** 6 clientes do Ceará com CPF/CNPJ válidos, produtos de obra (andaime, escora, betoneira, martelete, furadeira, serra, compactador, gerador, carrinho de mão, escada), cerca de 140 locações devolvidas e pagas espalhadas pelos últimos 12 meses (bloco `DO $$` com `setseed`), despesas mensais, 3 locações abertas (uma atrasada) e 1 reserva futura.
- **Beta:** poucos dados de eventos (mesas com cadeiras, tendas, gerador), incluindo **um cliente com o mesmo CPF de um cliente da Alfa** e **um produto com o mesmo código**, para provar que a unicidade é por locadora.

---

# Regras de negócio (`app/negocio.php`)

Todas as consultas rodam com o contexto do tenant já definido. **Não** coloque `WHERE tenant_id` manual: o RLS faz isso. Escreva as consultas como se houvesse uma única locadora.

1. **Disponibilidade** `disponivel($produtoId, $inicio, $fim, $ignorarReserva = null)` = `qtd_total` − itens na rua que ocupam o período (devolução prevista ≥ início, **ou** período começando hoje, porque item atrasado continua fora) − reservas `ativa` que se cruzam com o período (exceto a reserva ignorada).
2. **Criar locação:** cliente existe e não está bloqueado; datas válidas; **saída não pode ser no futuro** (para isso existe a reserva); devolução prevista ≥ saída; pelo menos 1 item. Dentro de `tx()`: `SELECT ... FOR UPDATE` nos produtos, depois `validar_estoque()` listando **todos** os itens em falta ("Betoneira (pedido 8, disponível 7)"), depois `proximo_numero('locacao')`, depois grava. Linhas repetidas do mesmo produto são somadas. A diária vem do formulário ou, se vazia, do produto. Se veio de reserva, marca a reserva como `convertida`. Se houver adiantamento, lança no caixa.
3. **Devolução** (total ou parcial): só em locação `aberta`; data entre a saída e hoje; quantidade de cada item ≤ pendente; pelo menos um item. Dias = max(1, data − saída). Cada item vale qtd × diária × dias. Atualiza `qtd_devolvida`. Se nada ficar pendente, status `devolvida`. Recebimento opcional no ato.
4. **Recebimento:** lança `E` no caixa, categoria "Locação", descrição "Recebimento locação nº N - Cliente".
5. **Prorrogar:** só se `aberta`. Altera a data prevista e acrescenta uma linha de histórico na observação.
6. **Cancelar:** só se `aberta` e sem nenhuma devolução. Se houver valor pago, gera lançamento `S` de estorno.
7. **Produto:** o estoque total não pode ficar menor que a quantidade alugada.
8. **Reserva:** começa hoje ou depois e valida o estoque no período. Botão "Retirar → locação" pré-preenche a nova locação (saída = hoje).
9. **Caixa:** só o **admin** da locadora exclui lançamentos, e **nunca** os ligados a uma locação.
10. **Usuários:** respeitar `tenants.limite_usuarios` (contando só os ativos).

---

# Telas da locadora

- **Login:** Empresa + Usuário + Senha (veja a seção multi-tenant), `session_regenerate_id` e pequeno atraso em caso de erro. Atualiza `usuarios.ultimo_acesso`.
- **Layout:** menu lateral escuro (Painel, Locações, Reservas, Clientes, Produtos, Fluxo de caixa, Relatórios, Configurações) com ícones SVG inline, **nome da locadora no topo do menu**, usuário logado e botão Sair. Cor de destaque laranja `#e8710a`. Cores em variáveis CSS no `:root`. **Responsivo:** abaixo de 760px o menu vira gaveta (checkbox hack), sem rolagem horizontal da página; tabelas largas rolam dentro do card.
- **Painel:** 4 indicadores (locações abertas + itens na rua; atrasadas + vencem hoje; recebido no mês vs mês anterior; total a receber), gráficos de entradas × saídas por mês e de locações por mês (12 meses), ranking dos 8 produtos que mais faturaram, lista de devoluções atrasadas e próximas, reservas dos próximos 7 dias.
- **Clientes:** lista com busca (nome, documento, telefone), filtro ativos/todos/bloqueados e paginação; formulário com máscaras (CPF/CNPJ, telefone, CEP) e **busca de CEP no ViaCEP** ao sair do campo; ficha com dados, resumo (nº de locações, total movimentado, saldo devedor) e histórico.
- **Produtos:** lista com estoque, alugado, reservado hoje e disponível (selo verde/amarelo/vermelho); formulário; tela de categorias.
- **Locações:** abas Em aberto / Atrasadas / Vencem hoje / Devolvidas / Canceladas / Todas; busca por nº ou cliente.
  - **Nova locação:** cliente por `<datalist>` com o nome (o PHP resolve para o UUID; não exponha UUID no texto); tabela dinâmica de itens (adicionar/remover linha, diária preenchida ao escolher o produto); **disponibilidade em tempo real** via `index.php?p=api&a=disponivel` (aviso em vermelho se a quantidade passar do disponível); total previsto recalculado ao vivo; acréscimo, desconto, adiantamento e forma de pagamento.
  - **Detalhe:** 4 caixas (previsto, até hoje/final, pago, saldo), dados, receber pagamento, prorrogar, cancelar, itens com pendentes, histórico de devoluções e pagamentos.
  - **Devolução:** mudar a data recalcula as diárias e mostra os dias de atraso; quantidade a devolver por item; multa, avaria e observação; resumo ao vivo (desta devolução, já apurado, já pago, saldo); campo "receber agora" preenchido com o saldo.
  - **Contrato** e **Fatura de locação** em layout de impressão A4 (`print.css`) com dados **da locadora logada**, locatário, itens, totais, cláusulas, assinaturas e a observação fiscal da fatura.
- **Reservas:** abas Ativas / Convertidas / Canceladas, com selos "vencida" e "retirar hoje".
- **Fluxo de caixa:** filtro por período e tipo; saldo anterior, entradas, saídas e saldo final; novo lançamento com categoria (datalist das já usadas); extrato com saldo acumulado; totais por forma de pagamento e por categoria.
- **Relatórios** (cada um definido como array com título, descrição, uso de período, SQL e colunas tipadas `txt|num|money|pct|date|link`; todos com **Imprimir** e **Exportar CSV** com BOM UTF-8 e separador `;` para o Excel):
  locações em atraso · clientes com débito · disponibilidade de estoque (com patrimônio a preço de reposição) · produtos mais alugados (com % de ocupação) · produtos sem movimento · faturamento por mês · clientes que mais alugam · locações no período. Gráfico de barras quando fizer sentido.
- **Configurações:** dados da empresa e textos do contrato/fatura (admin), usuários (admin; ninguém pode desativar ou rebaixar a si mesmo; respeita o limite do plano) e troca da própria senha (mínimo de 8 caracteres).

---

# Segurança (não negociável)

Prepared statements em **todas** as consultas (nunca concatene valor do usuário em SQL); `e()` em **toda** saída; token CSRF em **todo** formulário POST, comparado com `hash_equals`; senhas só com `password_hash`/`password_verify`; cookie de sessão `httponly` e `samesite=Lax`; transações com `FOR UPDATE` em estoque; validação no servidor, mesmo quando também existe no JS; **IDs recebidos por GET/POST validados com `is_uuid()`** antes de ir para o banco; o PHP **nunca** conecta como `postgres` nem como `locgestor_owner`.

**LGPD:** o sistema guarda dados pessoais dos clientes das locadoras. Não exponha CPF em URLs nem em logs.

---

# Fiscal

**Não implemente NF-e nesta versão.** O documento de cobrança é a **fatura de locação**, com observação padrão: *"Locação de bens móveis - operação não sujeita ao ISS (LC 116/2003, item 3.01 vetado; Súmula Vinculante 31 do STF). Confirme o enquadramento com seu contador."* Deixe o campo `ncm` nos produtos preparado para uma futura integração com a NFePHP (`nfephp-org/sped-nfe`) para NF-e de remessa/retorno. Cada locadora terá o próprio certificado digital A1 no futuro (não implementar agora).

---

# Fases e verificação

| Fase | Entrega | Como verificar antes de parar |
|---|---|---|
| **1** | `00_roles.sql`, `01_schema.sql` (com RLS, FKs compostas, views `security_invoker`), `02_seed.sql`, `03_demo.sql`, `instalar.bat` | Rodar tudo em banco novo, sem erro. Consulta de auditoria provando que **toda** tabela com coluna `tenant_id` tem `relrowsecurity` e `relforcerowsecurity` = true e pelo menos 1 política, e que **toda** view tem `security_invoker=true` (via `pg_class.reloptions`). Mostrar o resultado. |
| **2** | `tests/isolamento.php` (roda no terminal com `php tests/isolamento.php`, conectando como `locgestor_app`) | Testes abaixo, **todos passando**. Esta fase vem antes das telas de propósito: se o isolamento falhar, nada mais importa. |
| **3** | `config.example.php`, `bootstrap.php`, `tenant.php`, `layout.php`, `index.php`, login com Empresa, CSS, `.htaccess` | `php -l` em todos; login em `alfa-locacoes` e em `beta-eventos`; cada uma vê o próprio nome no menu; locadora suspensa não entra; menu responsivo |
| **4** | `plataforma.php` + login + lista + nova locadora + suspender/reativar | Criar a locadora `gama-teste` pelo painel, entrar com o admin gerado e ver o sistema vazio, com as categorias padrão |
| **5** | `negocio.php` + Clientes + Produtos | Rejeita CPF inválido e duplicado **na mesma locadora**; aceita o mesmo CPF em outra; não reduz estoque abaixo do alugado |
| **6** | Locações (nova, detalhe, devolução, receber, prorrogar, cancelar, contrato, fatura) + `api.php` + `app.js` | Roteiro de teste abaixo, mais: a numeração da Alfa e da Beta é independente; abrir pela URL o id de uma locação da Alfa estando logado na Beta mostra "não encontrada" |
| **7** | Reservas + Fluxo de caixa + Painel + Relatórios + Chart.js local + `graficos.js` | Todas as telas retornam 200 e nenhum warning/notice no log; os totais do painel da Beta não incluem nada da Alfa; CSV abre no Excel com acentos |
| **8** | `README.md` (como clonar do GitHub, criar os papéis, trocar as senhas, copiar `config.example.php`, instalar no Laragon passo a passo, criar a primeira locadora pelo painel da plataforma, aviso do PgBouncer, regra de cobrança, nota fiscal) | Rodar `tests/isolamento.php` de novo; `git log --oneline` com um commit por fase; `git ls-files` não lista `config.php`; lista do que ficou de fora e próximos passos |

**Testes de isolamento da Fase 2** (cada um imprime `OK` ou `FALHOU` com o motivo; o script termina com código 1 se algum falhar):

- **T-ISO-01:** com o contexto da Beta, `SELECT` em **todas** as tabelas de tenant não retorna nenhuma linha da Alfa (comparar com a contagem conhecida da demo).
- **T-ISO-02:** sem contexto definido (`app.tenant_id` vazio), `SELECT` retorna 0 linhas e `INSERT` falha.
- **T-ISO-03:** com o contexto da Beta, `INSERT` informando o `tenant_id` da Alfa explicitamente falha (violação de política).
- **T-ISO-04:** com o contexto da Beta, `INSERT` em `locacoes` apontando `cliente_id` de um cliente da Alfa falha (FK composta).
- **T-ISO-05:** com o contexto da Beta, `UPDATE` e `DELETE` usando o `id` de um registro da Alfa afetam 0 linhas.
- **T-ISO-06:** as views `vw_locacao_valores` e `vw_produto_alugado`, no contexto da Beta, não somam nada da Alfa.
- **T-ISO-07:** `locgestor_app` não consegue contornar o RLS: após `SET row_security = off`, um `SELECT` em tabela de tenant **dá erro** ("query would be affected by row-level security policy"); `ALTER TABLE ... DISABLE ROW LEVEL SECURITY` falha ("must be owner"); `SET ROLE locgestor_owner` e `SET ROLE postgres` falham ("permission denied").

> **Importante:** o script de testes deve **conectar diretamente** como `locgestor_app`, com o usuário e a senha do `config.php`. **Não** teste conectando como `postgres` e fazendo `SET ROLE`: o superusuário continua sendo o usuário da sessão, e alguns testes passam ou falham de forma enganosa.
- **T-ISO-08:** o mesmo CPF e o mesmo código de produto existem na Alfa e na Beta sem conflito.

**Roteiro de teste da Fase 6** (logado na Alfa; use `curl` com cookie ou um script PHP; mostre os resultados):
1. Locação de 2 betoneiras (diária R$ 45) + 5 carrinhos (R$ 5), saída há 4 dias, previsão em +3 dias, acréscimo R$ 50, desconto R$ 10, adiantamento R$ 100 → **previsto R$ 845,00**.
2. Pedir mais do que o disponível → mensagem "Estoque insuficiente" listando o item.
3. Devolver 1 betoneira hoje → devolução parcial, locação continua aberta.
4. Tentar devolver mais que o pendente → erro.
5. Devolver o restante com avaria R$ 20 e receber R$ 300 → status `devolvida`, **valor final R$ 520,00**, pago R$ 400,00, saldo R$ 120,00.
6. Saída com data futura → erro sugerindo reserva.

Se qualquer verificação falhar, corrija antes de me reportar a fase como concluída. Não diga que algo funciona sem ter rodado o teste. **Se perceber qualquer caminho em que dados de uma locadora possam aparecer para outra, pare e me avise imediatamente**, mesmo que não seja da fase atual.
