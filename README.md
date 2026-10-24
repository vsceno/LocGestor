# LocGestor

SaaS **multi-tenant** para controle de **locação de equipamentos** (construção civil, eventos e outros).
Várias locadoras usam o mesmo sistema e o mesmo banco, e **cada uma só enxerga os próprios dados**, garantido pelo
próprio PostgreSQL (Row Level Security).

**Para a locadora:** clientes, produtos e categorias, locação com disponibilidade em tempo real, devolução total ou
parcial, reservas, recebimentos, fluxo de caixa, contrato e fatura de locação em A4, painel com gráficos, 8 relatórios
(impressão e CSV para o Excel) e usuários com perfis.

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
10. [O que ficou de fora e próximos passos](#10-o-que-ficou-de-fora-e-próximos-passos)

---

## 1. Requisitos

| Item | Versão | Observação |
|---|---|---|
| Windows + [Laragon](https://laragon.org) | qualquer recente | Apache |
| PHP | 8.1 ou superior | extensões `pdo_pgsql` e `pgsql` ativas (Laragon: *Menu › PHP › Extensions*) |
| PostgreSQL | **18** | usa `uuidv7()` nativo. Em versões anteriores, troque `uuidv7()` por `gen_random_uuid()` em `01_schema.sql` |
| Git | qualquer | para clonar |

Confira no **Terminal do Laragon**:

```bat
php -v
php -m | findstr pgsql
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

---

## 3. Primeiro acesso e primeira locadora

### Painel da plataforma (dono do SaaS)

`http://locgestor.test/plataforma.php`: usuário **`suporte`**, com a senha digitada no instalador.

Em **+ Nova locadora**, informe nome, identificador de acesso (ex.: `alfa-locacoes`), plano e limite de usuários.
O sistema cria, numa única transação:

- a locadora e a ficha da empresa, já com o texto padrão do contrato e a observação fiscal da fatura;
- as 5 categorias padrão;
- o usuário **administrador** da locadora, com uma **senha temporária mostrada uma única vez**.

Envie ao cliente o identificador, o login e a senha temporária, e oriente a troca em *Configurações › Minha senha*.

Na mesma tela você **suspende** uma locadora (os usuários dela perdem o acesso na hora) ou a reativa.

### Sistema da locadora

`http://locgestor.test`: campos **Empresa** (o identificador), **Usuário** e **Senha**.
O navegador lembra a última empresa usada.

Com a demonstração carregada:

| Empresa | Usuário | Senha | Conteúdo |
|---|---|---|---|
| `alfa-locacoes` | `admin` | `admin123` | construção civil, ~12 meses de histórico, 3 locações abertas (1 atrasada) e 1 reserva |
| `beta-eventos` | `admin` | `admin123` | eventos, poucos dados. Tem cliente com o **mesmo CPF** e produto com o **mesmo código** da Alfa |

**Troque essas senhas** (ou não carregue a demonstração) em qualquer ambiente acessível por outras pessoas.

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
│   └── negocio.php        todas as regras de negócio
├── modulos/               uma tela por arquivo: login, dashboard, clientes, produtos,
│                          locacoes, reservas, caixa, relatorios, config, api
├── modulos_plataforma/    login e locadoras (tenants) do painel da plataforma
├── assets/css/            app.css (telas) e print.css (A4)
├── assets/js/             app.js (máscaras, ViaCEP, itens, devolução) e graficos.js
├── assets/vendor/         chart.umd.js (Chart.js 4.4.1 local)
├── database/              00_roles.sql, 01_schema.sql, 02_seed.sql, 03_demo.sql, instalar.bat
├── tests/isolamento.php   testes de isolamento entre locadoras (terminal)
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

- A locação é cobrada **por diária**, com **mínimo de 1 diária**.
- **Dias** de um item = `max(1, data da devolução − data de saída)`. Saída e devolução no mesmo dia = 1 diária.
- **Valor do item** = quantidade × diária × dias. A diária fica **congelada** na locação: mudar o preço do produto
  não altera locações já feitas.
- **Devolução parcial:** cada lote é cobrado pelos dias até a data em que voltou. Os itens que ficam na rua
  continuam correndo.
- **Valores da locação** (view `vw_locacao_valores`):
  - **previsto** = Σ(qtd × diária × max(1, prevista − saída)) + acréscimo − desconto;
  - **até hoje / final** = Σ itens devolvidos + Σ(multa + avaria) + pendentes até hoje (se aberta)
    + acréscimo − desconto. Locação cancelada vale zero;
  - **pago** = entradas no caixa da locação − estornos.
- **Exemplo:** 2 betoneiras (R$ 45) + 5 carrinhos (R$ 5), saída há 4 dias, previsão em 3 dias, acréscimo R$ 50,
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
| PostgreSQL aceita qualquer senha | o Laragon vem com `trust` no `pg_hba.conf`. Defina uma senha para o `postgres` e troque para `scram-sha-256` antes de expor a máquina |

---

## 10. O que ficou de fora e próximos passos

Não implementado nesta versão:

- emissão de **NF-e** (remessa/retorno) e certificado A1 por locadora;
- **edição de plano e limite** de uma locadora já criada no painel da plataforma (hoje: pelo banco);
- **troca obrigatória** da senha temporária no primeiro acesso;
- **recuperação de senha** por e-mail e envio de contrato/fatura por e-mail ou WhatsApp;
- **cobrança** da assinatura das locadoras (planos e faturamento do SaaS);
- **auditoria** detalhada (quem alterou o quê) e exclusão/anonimização de cliente a pedido (LGPD);
- **anexos** (fotos de avaria, documentos do cliente);
- testes automatizados das telas (hoje existem os testes de isolamento e roteiros manuais).

Sugestões de próximos passos, em ordem:

1. Ajustar o PostgreSQL para `scram-sha-256` e usar senhas fortes em todos os papéis.
2. Publicar com HTTPS (o cookie de sessão passa a ser `Secure` automaticamente).
3. Backups diários do banco (`pg_dump`), guardados fora do servidor.
4. Tela de planos no painel da plataforma (limite de usuários, suspensão automática por inadimplência).
5. Integração NFePHP para remessa/retorno, com certificado A1 isolado por locadora.
