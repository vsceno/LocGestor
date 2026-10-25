# LocGestor

[![Testes](https://github.com/vsceno/LocGestor/actions/workflows/testes.yml/badge.svg)](https://github.com/vsceno/LocGestor/actions/workflows/testes.yml)

SaaS **multi-tenant** para controle de **locação de equipamentos** (construção civil, eventos e outros).
Várias locadoras usam o mesmo sistema e o mesmo banco, e **cada uma só enxerga os próprios dados**, garantido pelo
próprio PostgreSQL (Row Level Security).

**Para a locadora:** clientes, produtos e categorias, locação com disponibilidade em tempo real, devolução total ou
parcial, reservas, recebimentos, fluxo de caixa, contrato e fatura de locação em A4, painel com gráficos, 8 relatórios
(impressão e CSV para o Excel), usuários com perfis e **WhatsApp**: botões que abrem o WhatsApp do atendente com a
mensagem pronta (resumo da locação, lembrete de devolução, cobrança do saldo, confirmação de reserva), inclusive
na lista de devoluções atrasadas e próximas do painel. Sem custo e sem cadastro: usa links `wa.me` com o celular do
cliente; o envio é feito pelo atendente (um toque). **PIX**: com a chave da locadora cadastrada em *Configurações*,
a locação e a fatura mostram um QR Code e o "copia e cola" com o valor do saldo (padrão BR Code do Banco Central),
e a cobrança pelo WhatsApp já leva o código. Sem API do banco, o recebimento é registrado pelo atendente quando o
dinheiro cai. **Antes de usar com clientes, pague um PIX de teste de R$ 0,01 lendo o QR Code com o app do seu banco.**

**Para o dono do SaaS:** painel da plataforma para cadastrar, suspender e reativar locadoras.

Tecnologia: PHP 8.1+ puro (sem framework, sem Composer), PostgreSQL 18, HTML/CSS/JavaScript puro e Chart.js 4.4.1
salvo localmente (funciona sem internet).

---

## Sumário

1. [Requisitos](#1-requisitos)
2. [Instalação no Laragon, passo a passo](#2-instalação-no-laragon-passo-a-passo)
3. [Primeiro acesso e primeira locadora](#3-primeiro-acesso-e-primeira-locadora)
4. [Estrutura do projeto](#4-estrutura-do-projeto)
5. [Como o isolamento entre locadoras funciona](#5-como-o-isolamento-entre-locadoras-funciona)
6. [Regra de cobrança](#6-regra-de-cobrança)
7. [Nota fiscal](#7-nota-fiscal)
8. [Segurança e LGPD](#8-segurança-e-lgpd)
9. [Problemas comuns](#9-problemas-comuns)
10. [Colocando em produção: login, HTTPS, backup e PostgreSQL](#10-colocando-em-produção-login-https-backup-e-postgresql)
11. [O que ficou de fora e próximos passos](#11-o-que-ficou-de-fora-e-próximos-passos)

---

## 1. Requisitos

| Item | Versão | Observação |
|---|---|---|
| Windows + [Laragon](https://laragon.org) | qualquer recente | Apache |
| PHP | 8.1 ou superior | extensões `pdo_pgsql`, `pgsql` e `gd` ativas (Laragon: *Menu › PHP › Extensions*); `exif` é opcional (endireita fotos de celular) |
| PostgreSQL | **18** | usa `uuidv7()` nativo. Em versões anteriores, troque `uuidv7()` por `gen_random_uuid()` em `01_schema.sql` |
| Git | qualquer | para clonar |

Confira no **Terminal do Laragon**:

```bat
php -v
php -m | findstr "pgsql gd exif"
psql --version
```

---

## 2. Instalação no Laragon, passo a passo

### 2.1 Clonar do GitHub

No Terminal do Laragon:

```bat
cd C:\laragon\www
git clone https://github.com/<seu-usuario>/locgestor.git
cd locgestor
```

A pasta precisa se chamar `locgestor` para o endereço ficar `http://locgestor.test`.

### 2.2 Criar os papéis, o banco e as tabelas

Com o PostgreSQL ligado no Laragon (*Start All*), rode:

```bat
database\instalar.bat
```

Ele pede, sem mostrar na tela:

| Pergunta | Para que serve |
|---|---|
| Senha do usuário `postgres` | administrador do PostgreSQL (Enter se não tiver) |
| Senha do papel `locgestor_app` | usuário que o sistema das locadoras usa |
| Senha do papel `locgestor_plataforma` | usuário que o painel da plataforma usa |
| Senha do usuário `suporte` | login do painel da plataforma |
| Carregar demonstração? (S/N) | cria as locadoras `alfa-locacoes` e `beta-eventos` |

Use senhas fortes com **letras, números, `-` e `_`** (o `.bat` não lida bem com `& ^ % ! "` e espaço).
**Anote as duas senhas dos papéis:** elas vão no `config.php` no próximo passo.

O instalador roda, nesta ordem:

| Arquivo | O que faz |
|---|---|
| `00_roles.sql` | cria os papéis `locgestor_owner`, `locgestor_app`, `locgestor_plataforma` e o banco `locgestor` |
| `01_schema.sql` | tabelas, RLS, views, funções e permissões. No fim, **aborta** se encontrar tabela sem RLS, view sem `security_invoker` ou função `SECURITY DEFINER` |
| `02_seed.sql` | usuário `suporte` do painel da plataforma |
| `03_demo.sql` | (opcional) duas locadoras de demonstração |

Ele se recusa a instalar por cima de um banco `locgestor` que já existe. Para reinstalar do zero:
`psql -U postgres -c "DROP DATABASE locgestor"` (apaga **todos** os dados).

<details>
<summary>Instalação manual, sem o .bat</summary>

```bat
cd database
psql -U postgres -d postgres  -v senha_app=SENHA_APP -v senha_plataforma=SENHA_PLAT -f 00_roles.sql
psql -U postgres -d locgestor -f 01_schema.sql
php -r "echo password_hash($argv[1], PASSWORD_DEFAULT);" "SenhaDoSuporte"
psql -U postgres -d locgestor -v hash_suporte="<hash gerado acima>" -f 02_seed.sql
psql -U postgres -d locgestor -f 03_demo.sql
```

Sem os `-v`, os papéis ficam com a senha `TROQUE_AQUI` e o `suporte` fica sem senha válida.
</details>

### 2.3 Trocar senhas depois (se precisar)

```sql
-- psql -U postgres
ALTER ROLE locgestor_app        PASSWORD 'nova-senha-do-app';
ALTER ROLE locgestor_plataforma PASSWORD 'nova-senha-da-plataforma';
```

Depois de trocar, atualize o `config.php`. Para a senha do `suporte`, gere um novo hash com o comando
`php -r ...` acima e rode `02_seed.sql` de novo com `-v hash_suporte=...`.

### 2.4 Criar o `config.php`

```bat
copy config.example.php config.php
```

Abra o `config.php` e preencha `password` em `db_app` e em `db_plataforma` com as senhas dos papéis.
**O `config.php` nunca vai para o GitHub** (está no `.gitignore`). Se ele não existir, o sistema mostra
"Copie config.example.php para config.php e preencha as senhas do banco".

### 2.5 Ativar o endereço `http://locgestor.test`

No Laragon, clique em **Recarregar** (*Reload*). Ele cria o virtual host e a entrada no arquivo `hosts`
(pode pedir permissão de administrador).

### 2.6 Conferir o isolamento

```bat
php tests\isolamento.php
```

Conecta como `locgestor_app` (com a senha do `config.php`) e roda os testes de isolamento entre locadoras.
Precisa terminar com **"todos os testes de isolamento passaram"**. Rode de novo sempre que mexer no banco.

### 2.7 Testes funcionais (pelas telas)

```bat
set LOCGESTOR_SUPORTE_SENHA=senha-do-suporte
php tests\funcional.php
```

Usa o sistema como um navegador: login, clientes, o roteiro de cobrança (R$ 845 previsto / R$ 520 final),
isolamento pelas URLs e pela API, reservas, caixa e perfis, relatórios e CSV, proteção do login e o painel da
plataforma. Tudo o que cria leva o prefixo `TESTE`/`teste` e é **apagado no fim**, mesmo se algum teste falhar.
Sem `LOCGESTOR_SUPORTE_SENHA`, a parte do painel da plataforma é pulada. Outro endereço: `LOCGESTOR_URL`.

**No GitHub** ([.github/workflows/testes.yml](.github/workflows/testes.yml)), a cada push e pull request, os
testes rodam sozinhos com **PHP 8.1 e 8.4** e **PostgreSQL 18**: instala o banco do zero, aplica as migrações,
roda os testes de isolamento e os funcionais e confere que o PHP não registrou nenhum aviso. O resultado aparece
na aba *Actions* e no selo no topo deste README.

---

## 3. Primeiro acesso e primeira locadora

### Painel da plataforma (dono do SaaS)

`http://locgestor.test/plataforma.php`: usuário **`suporte`**, com a senha digitada no instalador.

Em **+ Nova locadora**, informe nome, identificador de acesso (ex.: `alfa-locacoes`), plano e limite de usuários.
O sistema cria, numa única transação:

- a locadora e a ficha da empresa, já com o texto padrão do contrato e a observação fiscal da fatura;
- as 5 categorias padrão;
- o usuário **administrador** da locadora, com uma **senha temporária mostrada uma única vez**.

Envie ao cliente o identificador, o login e a senha temporária. No primeiro acesso, o sistema obriga a troca da senha.

### Planos e mensalidades

- **Planos** (menu *Planos*): nome, preço mensal e limite de usuários. Vêm prontos Básico (R$ 99, 3 usuários),
  Profissional (R$ 199, 5) e Empresarial (R$ 399, 15); edite à vontade.
- **Nova locadora:** escolha o plano, o **primeiro vencimento** (padrão: 30 dias, período de teste) e os **dias de
  carência** (padrão: 5). Vencimento vazio = sem cobrança (conta interna ou cortesia).
- **Gerenciar** (na lista de locadoras): troca de plano, limite especial de usuários, vencimento e carência;
  **registrar pagamento** de 1 a 24 meses (avança o vencimento; valor vazio = preço do plano × meses);
  **estornar o último pagamento** (o vencimento volta); **suspender/reativar** manualmente.
- **Aviso na locadora:** o admin vê uma faixa quando faltam 5 dias ou menos e, depois de vencida, até quando pode
  regularizar. Para mostrar seu contato nesse aviso, preencha `'suporte_contato'` no `config.php`.
- **Suspensão automática:** passada a carência sem pagamento, o sistema da locadora **bloqueia o acesso na hora**
  (não depende de tarefa agendada) e o painel passa a mostrar "suspensa por inadimplência". Registrar o pagamento
  **reativa** a locadora automaticamente.
- A lista mostra a **receita mensal recorrente** (locadoras ativas com cobrança) e quantas estão inadimplentes.

O recebimento das mensalidades em si (boleto, PIX, cartão) é feito fora do sistema; aqui você só registra.

### Sistema da locadora

`http://locgestor.test`: campos **Empresa** (o identificador), **Usuário** e **Senha**.
O navegador lembra a última empresa usada.

Com a demonstração carregada:

| Empresa | Usuário | Senha | Conteúdo |
|---|---|---|---|
| `alfa-locacoes` | `admin` | `admin123` | construção civil, ~12 meses de histórico, 3 locações abertas (1 atrasada), 1 reserva, preços semanais/mensais e 1 kit |
| `beta-eventos` | `admin` | `admin123` | eventos, poucos dados. Tem cliente com o **mesmo CPF** e produto com o **mesmo código** da Alfa |

**Troque essas senhas** (ou não carregue a demonstração) em qualquer ambiente acessível por outras pessoas.

### Vistorias (checklist, fotos e assinatura)

No detalhe da locação, **Vistoria de retirada** e **Vistoria de devolução** abrem um checklist com o estado de cada
item (ok, avariado, sujo, incompleto), observações, até **12 fotos** e a **assinatura do cliente na tela**
(dedo, caneta ou mouse). Cada locação tem uma vistoria de retirada; as de devolução podem ser várias (devolução parcial).

- A assinatura da retirada aparece no **contrato**, sobre a linha do locatário, com nome e data/hora.
- A vistoria vira uma página A4 para imprimir ou salvar em PDF.
- Vistoria registrada **não pode ser alterada nem apagada** (o banco não dá essa permissão ao sistema): é a prova.
- O celular reduz as fotos antes de enviar; o servidor regrava todas (máx. 1600 px, JPEG), o que também remove os
  metadados da câmera, como a localização GPS.
- Os arquivos ficam em `storage/` (ou na pasta de `pasta_arquivos` do `config.php`), separados por locadora, e só
  são entregues pelo sistema, para quem está logado na locadora dona. O acesso direto à pasta é bloqueado.

---

## 4. Estrutura do projeto

```
locgestor/
├── index.php              sistema das locadoras: index.php?p=<modulo>&a=<acao>
├── plataforma.php         painel do dono do SaaS (sessão e papel de banco próprios)
├── config.example.php     modelo de configuração (versionado, sem senhas)
├── config.php             configuração real (NÃO versionado)
├── .htaccess              sem listagem; bloqueia config*.php, .md, .sql, .bat, .log, .git
├── app/                   (bloqueado para a web)
│   ├── bootstrap.php      sessão, PDO, helpers, CSRF, formatação, validação, rotas e erros
│   ├── tenant.php         contexto da locadora (único lugar que define app.tenant_id)
│   ├── layout.php         menu, layout das telas e das páginas de impressão
│   ├── arquivos.php       gravação e leitura de fotos/assinaturas (regrava toda imagem)
│   └── negocio.php        todas as regras de negócio
├── modulos/               uma tela por arquivo: login, dashboard, clientes, produtos,
│                          locacoes, reservas, caixa, relatorios, config, api, arquivos
├── modulos_plataforma/    login, locadoras (tenants, mensalidades) e planos do painel da plataforma
├── assets/css/            app.css (telas) e print.css (A4)
├── assets/js/             app.js (máscaras, ViaCEP, itens, devolução), graficos.js e vistoria.js (assinatura e fotos)
├── assets/vendor/         chart.umd.js (Chart.js 4.4.1) e qrcode.js (QR Code do PIX), locais
├── database/              00_roles.sql, 01_schema.sql, 02_seed.sql, 03_demo.sql, instalar.bat,
│                          backup.bat, seguranca_postgres.sql e migrations/ (atualizações do banco)
├── tests/                 isolamento.php (isolamento entre locadoras) e funcional.php (pelas telas)
├── .github/workflows/     testes.yml: roda os testes no GitHub a cada push
├── storage/               fotos e assinaturas das vistorias (não versionadas; acesso direto bloqueado)
└── logs/                  app.log e php-erros.log (não versionados)
```

Cada rota chama a função `<modulo>_<acao>()` do arquivo `modulos/<modulo>.php`.
Ações de gravação seguem POST → Redirect → GET.

---

## 5. Como o isolamento entre locadoras funciona

O isolamento **não depende de o programador lembrar do `WHERE tenant_id`**. Quem garante é o PostgreSQL:

1. **Papéis.** O PHP conecta só como `locgestor_app` (ou `locgestor_plataforma` no painel). Nenhum dos dois é
   superusuário, dono de tabela ou `BYPASSRLS`. As tabelas pertencem ao `locgestor_owner`, que nem faz login.
2. **Contexto.** Depois do login, `tenant_definir()` executa `set_config('app.tenant_id', ...)` na conexão.
   A única fonte é a sessão: o sistema nunca aceita `tenant_id` de formulário, URL ou cookie.
3. **RLS forçado.** Toda tabela de locadora tem `tenant_id DEFAULT app_tenant_id()`, RLS `ENABLE` + `FORCE` e a
   política `tenant_id = app_tenant_id()`. Sem contexto, nada aparece e nada é gravado.
4. **FKs compostas** `(tenant_id, x_id)`: impossível apontar para cliente, produto ou locação de outra locadora.
5. **Unicidade por locadora**: CPF/CNPJ, código de produto, login e categoria se repetem entre locadoras.
6. **Views com `security_invoker = true`**. Sem isso, uma view criada pelo `postgres` ignora o RLS e
   **vaza dados** (confirmado em teste: mostrou os clientes de todas as locadoras).
7. **Numeração própria**: cada locadora tem locações nº 1, 2, 3...; as URLs usam o número, e o RLS faz
   uma locação de outra locadora simplesmente "não ser encontrada".

**Regras para quem for mexer no código:**

- toda tabela nova de locadora: `tenant_id ... DEFAULT app_tenant_id()`, `UNIQUE (tenant_id, id)`, FKs compostas,
  e incluí-la na lista do bloco de RLS do `01_schema.sql` **e** na lista `TABELAS` de `tests/isolamento.php`;
- toda view nova: `WITH (security_invoker = true)`;
- **nunca** crie função `SECURITY DEFINER` (o schema e o teste recusam);
- depois de qualquer mudança no banco, rode `php tests\isolamento.php`.

> ⚠ **PgBouncer:** se um dia for usado, configure **`pool_mode = session`**. Em modo `transaction`, o
> `app.tenant_id` definido por uma requisição fica na conexão do servidor e **vaza para o próximo cliente**,
> que passaria a ver os dados de outra locadora. A conexão PDO do sistema já é não persistente pelo mesmo motivo.

---

## 6. Regra de cobrança

- Cada produto tem **diária** e, se quiser, **preço da semana** (7 dias) e **do mês** (30 dias). Mínimo de 1 dia.
- **Dias** de um item = `max(1, data da devolução − data de saída)`. Saída e devolução no mesmo dia = 1 dia.
- **Preço de 1 unidade por N dias** (função `preco_periodo`, igual no banco, no PHP e no JavaScript):
  meses inteiros × mês + semanas inteiras do que sobra × semana + diárias do resto, com dois limites: as diárias
  que sobram **nunca passam do preço da semana**, e o que sobra do mês **nunca passa do preço do mês**.
  O total nunca é maior que só diárias. Sem semana/mês cadastrados, é a conta antiga: diária × dias.
  Ex.: diária R$ 10, semana R$ 50, mês R$ 150 → 4 dias R$ 40; 6 dias R$ 50; 10 dias R$ 80; 29 dias R$ 150; 35 dias R$ 200.
- **Valor do item** = quantidade × preço do período. Diária, semana e mês ficam **congelados** na locação: mudar o
  preço do produto não altera locações já feitas. Na tela da locação dá para ajustar só a diária daquele item.
- **Devolução parcial:** cada lote é cobrado pelos dias até a data em que voltou. Os itens que ficam na rua
  continuam correndo.
- **Valores da locação** (view `vw_locacao_valores`):
  - **previsto** = Σ(qtd × preço de (prevista − saída) dias) + acréscimo − desconto;
  - **até hoje / final** = Σ itens devolvidos + Σ(multa + avaria) + pendentes até hoje (se aberta)
    + acréscimo − desconto. Locação cancelada vale zero;
  - **pago** = entradas no caixa da locação − estornos.
- **Kits** (*Produtos › Kits*): pacote de produtos (ex.: andaime completo). Na locação e na reserva, **Adicionar kit**
  inclui os itens de uma vez (somando a quem já está na lista); estoque e preço continuam sendo os de cada produto.
- **Exemplo** (só diárias): 2 betoneiras (R$ 45) + 5 carrinhos (R$ 5), saída há 4 dias, previsão em 3 dias, acréscimo R$ 50,
  desconto R$ 10 → previsto (90 + 25) × 7 + 50 − 10 = **R$ 845,00**. Devolvendo tudo hoje com avaria de R$ 20:
  115 × 4 + 20 + 50 − 10 = **R$ 520,00**.
- **Disponibilidade** de um produto num período = estoque − itens na rua que ocupam o período (inclusive os
  atrasados) − reservas ativas que se cruzam com ele. Locação, reserva e prorrogação conferem o estoque dentro
  de uma transação com `SELECT ... FOR UPDATE`.
- **Saída no futuro não é locação:** para garantir equipamento para outra data, use a **reserva**.
- **Cancelamento:** só sem nenhuma devolução. O valor pago gera um lançamento de **estorno** (saída) no caixa.
- **Caixa:** só o administrador exclui lançamentos, e nunca os ligados a uma locação.

---

## 7. Nota fiscal

Esta versão **não emite NF-e**. O documento de cobrança é a **fatura de locação** (A4), com a observação
editável em *Configurações*:

> *"Locação de bens móveis - operação não sujeita ao ISS (LC 116/2003, item 3.01 vetado; Súmula Vinculante 31
> do STF). Confirme o enquadramento com seu contador."*

Preparação para o futuro:

- o produto já tem o campo **NCM** (8 dígitos);
- a integração prevista é com a [NFePHP](https://github.com/nfephp-org/sped-nfe) (`nfephp-org/sped-nfe`) para
  **NF-e de remessa e retorno** de bens em locação;
- cada locadora precisará do **próprio certificado digital A1**, guardado de forma isolada por tenant.

**Confirme sempre o enquadramento fiscal (ISS, ICMS, CFOP de remessa/retorno) com um contador.**

---

## 8. Segurança e LGPD

- Prepared statements em todas as consultas; `e()` em toda saída; token CSRF (`hash_equals`) em todo POST.
- Senhas com `password_hash`/`password_verify`; mínimo de 8 caracteres; login com atraso e mensagem genérica
  ("Dados de acesso inválidos") que não revela se a empresa existe.
- Cookie de sessão `HttpOnly` e `SameSite=Lax`; `session_regenerate_id` no login; sessões separadas para
  locadora e plataforma.
- IDs recebidos por GET/POST validados com `is_uuid()`; texto que não seja UTF-8 válido é recusado.
- **LGPD:** a busca de clientes (que aceita CPF) é enviada por POST, e o CPF nunca aparece na URL. O log da
  aplicação (`logs/app.log`) remove os detalhes das mensagens do banco e mascara números com cara de CPF/CNPJ.
- Violação de política RLS aparece ao usuário como "Operação não permitida" e é registrada como
  **ALERTA DE SEGURANÇA** no log.
- Pastas `app/`, `modulos/`, `modulos_plataforma/`, `database/`, `tests/` e `logs/` são bloqueadas para a web.

---

## 9. Problemas comuns

| Sintoma | Causa e solução |
|---|---|
| "Copie config.example.php para config.php..." | crie o `config.php` (passo 2.4) |
| "Não foi possível conectar ao banco de dados" | PostgreSQL desligado ou senha errada no `config.php` |
| `php tests\isolamento.php` acusa falha | **não use o sistema em produção** até corrigir: algo no banco deixou de isolar as locadoras |
| Gráficos vazios em `http://localhost/locgestor/` | um `.htaccess` em `C:\laragon\www` que bloqueia `/vendor/` impede o Chart.js de carregar. Acesse por `http://locgestor.test` |
| Arquivos "travados" (erro no `git commit`, log com linhas faltando) | programas de backup/sincronização (TeraBox, OneDrive etc.) prendem os arquivos enquanto copiam. Exclua a pasta do projeto da sincronização (ela também contém o `config.php` com as senhas) |
| PostgreSQL aceita qualquer senha | o Laragon vem com `trust` no `pg_hba.conf`. Siga a seção 10.5 antes de expor a máquina |
| "Muitas tentativas de acesso. Aguarde 15 minutos..." | limite de login atingido (seção 10.2). Espere 15 minutos ou, como `postgres`: `DELETE FROM login_tentativas;` |
| Usuário só consegue abrir "Minha senha" | ele entrou com senha temporária; ao definir uma senha nova, o sistema libera as outras telas |
| `backup.log` com "FALHOU" | PostgreSQL desligado, ou o `postgres` tem senha e falta o `pgpass.conf` (seção 10.4) |
| Erro "column trocar_senha does not exist" ou "relation login_tentativas does not exist" | banco instalado antes desta versão: rode a migração (seção 10.1) |

---

## 10. Colocando em produção: login, HTTPS, backup e PostgreSQL

### 10.1 Atualizar um banco já instalado (migrações)

Instalações novas já saem completas pelo `instalar.bat`. Um banco criado **antes** de uma atualização recebe as
mudanças pelos arquivos de `database/migrations/`, em ordem (cada um pode ser rodado mais de uma vez sem estragar nada):

```bat
psql -U postgres -d locgestor -f database\migrations\001_seguranca_login.sql
psql -U postgres -d locgestor -f database\migrations\002_pix.sql
psql -U postgres -d locgestor -f database\migrations\003_planos_assinatura.sql
psql -U postgres -d locgestor -f database\migrations\004_vistorias.sql
psql -U postgres -d locgestor -f database\migrations\005_precos_kits.sql
php tests\isolamento.php
```

| Migração | O que faz |
|---|---|
| `001_seguranca_login.sql` | tabela `login_tentativas` (limite de login) e coluna `usuarios.trocar_senha` (senha temporária) |
| `002_pix.sql` | colunas `pix_chave`, `pix_nome` e `pix_cidade` na `empresa` (cobrança por PIX) |
| `003_planos_assinatura.sql` | tabelas `planos` e `assinatura_pagamentos`; plano, vencimento e carência em `tenants` |
| `004_vistorias.sql` | tabelas `vistorias`, `vistoria_itens` e `vistoria_fotos` (checklist, fotos e assinatura) |
| `005_precos_kits.sql` | preço semanal/mensal (`produtos`, `locacao_itens`), função `preco_periodo`, view de valores e tabelas `kits`/`kit_itens` |

### 10.2 Proteções do login (já ativas)

- **Limite de tentativas:** 5 erros por conta ou 20 por IP em 15 minutos bloqueiam novas tentativas por 15 minutos,
  mesmo com a senha certa. Vale para as locadoras e para o painel da plataforma, e também para contas que não
  existem (o bloqueio não revela quais existem). O banco guarda só *hashes* do IP e da conta, nunca o texto.
  Cada bloqueio vai para o `logs/app.log` como **ALERTA DE SEGURANÇA**. Um acerto zera os erros daquela conta.
  Para liberar na hora: `DELETE FROM login_tentativas;` (como `postgres`).
- **Senha temporária:** o admin criado pelo painel da plataforma, os usuários criados pelo admin da locadora e quem
  teve a senha redefinida pelo admin entram e só conseguem usar a tela **Minha senha** até definirem uma nova.

### 10.3 HTTPS

1. No Laragon: *Menu › Apache › SSL › Enabled* (ou instale o certificado do seu domínio no servidor).
2. Confira que `https://locgestor.test` abre.
3. No `config.php`: `'forcar_https' => true,`

A partir daí, todo acesso por `http://` é redirecionado (301) para `https://`, o navegador passa a exigir HTTPS
(cabeçalho HSTS) e o cookie de sessão vira `Secure`. Só ative o item 3 depois que o item 2 funcionar.

### 10.4 Backup e restauração

`database\backup.bat` gera um `pg_dump` compactado do banco, confere se o arquivo pode ser lido e mantém os
**30** mais recentes em `C:\laragon\backups\locgestor` (fora da pasta do site, para nunca ficar acessível pelo
navegador). Cada execução é anotada em `backup.log`, na mesma pasta.

Fotos e assinaturas são arquivos, fora do banco: a cada execução a pasta `storage\` é **espelhada** em
`C:\laragon\backups\locgestor\arquivos` (só o que mudou é copiado). Para restaurar, copie essa pasta de volta
para `storage\` (ou para a pasta de `pasta_arquivos`).

**Agendar todo dia às 23h** (PowerShell; já feito nesta instalação):

```powershell
$acao    = New-ScheduledTaskAction -Execute 'C:\laragon\www\locgestor\database\backup.bat' -WorkingDirectory 'C:\laragon\www\locgestor\database'
$gatilho = New-ScheduledTaskTrigger -Daily -At '23:00'
# notebook: roda mesmo na bateria e, se estiver desligado às 23h, roda assim que ligar
$config  = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 30)
Register-ScheduledTask -TaskPath '\LocGestor\' -TaskName 'Backup diario' -Action $acao -Trigger $gatilho -Settings $config -Force
```

Variáveis opcionais: `BACKUP_DIR` (outra pasta), `MANTER` (quantos guardar) e `PASTA_ARQUIVOS` (se mudou `pasta_arquivos`). Se o `postgres` tiver senha
(seção 10.5), crie `%APPDATA%\postgresql\pgpass.conf` com a linha `localhost:5432:*:postgres:SENHA`.

**Copie os backups para fora do computador** (pendrive, nuvem, outro servidor). Backup que fica só na mesma
máquina não protege contra defeito no disco, roubo ou vírus.

**Restaurar** (apaga o banco atual; faça antes um backup dele):

```bat
psql -U postgres -c "DROP DATABASE locgestor"
psql -U postgres -d postgres -v senha_app=SENHA_APP -v senha_plataforma=SENHA_PLAT -f database\00_roles.sql
pg_restore -U postgres -d locgestor C:\laragon\backups\locgestor\locgestor_AAAAMMDD_HHMMSS.dump
php tests\isolamento.php
```

O `00_roles.sql` recria os papéis e o banco vazio; o `pg_restore` traz as tabelas, os dados, o RLS e as permissões.
Para só **testar** um backup sem mexer no banco em uso, restaure num banco com outro nome
(`CREATE DATABASE locgestor_teste OWNER locgestor_owner TEMPLATE template0`) e apague-o depois.

### 10.5 Exigir senha no PostgreSQL

O Laragon vem com `trust` no `pg_hba.conf`: qualquer pessoa ou programa no computador entra no banco como
qualquer usuário, inclusive `postgres`, sem senha. **Outros projetos** que usam o mesmo PostgreSQL também serão
afetados pela mudança, então siga na ordem:

1. **Diagnóstico** (não altera nada): mostra quem está sem senha e as regras atuais.
   ```bat
   psql -U postgres -d postgres -f database\seguranca_postgres.sql
   ```
2. **Defina uma senha** para cada papel marcado como `SEM SENHA` (o `postgres` pelo script; os demais com
   `ALTER ROLE nome PASSWORD '...'`):
   ```bat
   psql -U postgres -d postgres -v senha_postgres=SENHA_FORTE -f database\seguranca_postgres.sql
   ```
3. **Coloque as senhas nos outros projetos** (`.env` de cada um) e no `pgpass.conf` do backup (seção 10.4).
4. **Pare o PostgreSQL** no Laragon, abra o `pg_hba.conf` (o caminho aparece no diagnóstico) e troque `trust` por
   `scram-sha-256` nas linhas ativas, por exemplo:
   ```
   host    all    all    127.0.0.1/32    scram-sha-256
   host    all    all    ::1/128         scram-sha-256
   ```
5. **Inicie o PostgreSQL** e confira: `psql -U postgres` deve pedir senha; `php tests\isolamento.php` deve passar;
   abra o LocGestor e os outros projetos.

Se algo parar de funcionar, volte `scram-sha-256` para `trust` no `pg_hba.conf` e reinicie o PostgreSQL.

---

## 11. O que ficou de fora e próximos passos

Não implementado nesta versão:

- emissão de **NF-e** (remessa/retorno) e certificado A1 por locadora;
- **recuperação de senha** por e-mail (precisa de uma conta SMTP) e envio de contrato/fatura por e-mail;
- envio **automático** pelo WhatsApp (API paga: WhatsApp Business, Z-API, Twilio) e PDF anexado (hoje: links `wa.me` com a mensagem pronta e "Salvar como PDF" do navegador);
- **cobrança automática** das mensalidades das locadoras (boleto/PIX/cartão com baixa sozinha; hoje o pagamento é registrado à mão) e **baixa automática** do PIX (precisa da API do banco);
- **auditoria** detalhada (quem alterou o quê) e exclusão/anonimização de cliente a pedido (LGPD);
- **anexos** de documentos do cliente (fotos de avaria e assinatura já existem nas vistorias);

Sugestões de próximos passos, em ordem:

1. Exigir senha no PostgreSQL (seção 10.5) e copiar os backups para fora do computador.
2. Ampliar os testes funcionais a cada tela nova (o GitHub já roda os atuais a cada push).
3. Lembretes automáticos por API do WhatsApp e PDF gerado no servidor (exige liberar o Composer).
4. Baixa automática do PIX no caixa (exige a API PIX do banco da locadora; o QR Code e o copia e cola já existem).
5. Cobrança automática das mensalidades (gateway de pagamento) e e-mail de aviso antes do vencimento.
6. Integração NFePHP para remessa/retorno, com certificado A1 isolado por locadora.
