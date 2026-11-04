# LocGestor

[![Testes](https://github.com/vsceno/LocGestor/actions/workflows/testes.yml/badge.svg)](https://github.com/vsceno/LocGestor/actions/workflows/testes.yml)

SaaS **multi-tenant** para controle de **locação de equipamentos** (construção civil, eventos e outros).
Várias locadoras usam o mesmo sistema e o mesmo banco, e **cada uma só enxerga os próprios dados**, garantido pelo
próprio PostgreSQL (Row Level Security).

**Para a locadora:** clientes, produtos e categorias, locação com disponibilidade em tempo real, devolução total ou
parcial, reservas, recebimentos, fluxo de caixa, contrato e fatura de locação em A4 (com a logomarca da locadora, enviada em
*Configurações*), identidade visual própria (cores, fontes e logotipo quadrado), painel com gráficos e o resultado
do mês, 13 relatórios (a DRE, só para o administrador, a rentabilidade por equipamento e por unidade, a ocupação da
frota e a conversão de orçamentos e reservas)
(impressão e CSV para o Excel), usuários com perfis e **WhatsApp**: botões que abrem o WhatsApp do atendente com a
mensagem pronta (resumo da locação, lembrete de devolução, cobrança do saldo, confirmação de reserva), inclusive
na lista de devoluções atrasadas e próximas do painel. Sem custo e sem cadastro: usa links `wa.me` com o celular do
cliente; o envio é feito pelo atendente (um toque). **PIX**: com a chave da locadora cadastrada em *Configurações*,
a locação e a fatura mostram um QR Code e o "copia e cola" com o valor do saldo (padrão BR Code do Banco Central),
e a cobrança pelo WhatsApp já leva o código. Sem API do banco, o recebimento é registrado pelo atendente quando o
dinheiro cai. **Antes de usar com clientes, pague um PIX de teste de R$ 0,01 lendo o QR Code com o app do seu banco.**

**Para o dono do SaaS:** painel da plataforma para cadastrar, suspender e reativar locadoras, acompanhar os números da
assinatura (receita recorrente, inadimplência e teste grátis) e manter a tela *Configurações da LocGestor* (dados da
empresa, identidade, termos de uso e cadastro público).

Tecnologia: **Laravel 13** (pasta `laravel/`, PHP 8.1+ e Composer), PostgreSQL 18, Tailwind CSS 4 compilado pelo Vite
e Chart.js salvo localmente (funciona sem internet). O sistema antigo em PHP puro, na raiz, foi retirado: restam só os
endereços antigos, que levam ao Laravel.

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
10. [Colocando em produção: login, HTTPS, backup, PostgreSQL e Asaas](#10-colocando-em-produção-login-https-backup-postgresql-e-asaas)
11. [O que ficou de fora e próximos passos](#11-o-que-ficou-de-fora-e-próximos-passos)
12. [Migração para Laravel](#12-migração-para-laravel-pasta-laravel)

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
**Anote as duas senhas dos papéis:** elas vão no `laravel/.env` no passo 2.4.

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

Depois de trocar, atualize o `laravel/.env` (`DB_PASSWORD` ou `DB_PLATAFORMA_PASSWORD`). Para a senha do `suporte`, gere um novo hash com o comando
`php -r ...` acima e rode `02_seed.sql` de novo com `-v hash_suporte=...`.

### 2.4 Configurar o Laravel (`laravel/.env`)

As configurações do sistema ficam no arquivo `laravel/.env`. Ele **nunca vai para o GitHub** (está no `.gitignore`).

```bat
cd laravel
copy .env.example .env
php artisan key:generate
composer install
npm install
npm run build
cd ..
```

Abra `laravel\.env` e preencha:

- `DB_PASSWORD`: a senha do papel `locgestor_app` (a mesma digitada no `instalar.bat`);
- `DB_PLATAFORMA_PASSWORD`: a senha do papel `locgestor_plataforma`;
- as linhas `DB_*` do bloco do LocGestor vêm comentadas no modelo: tire o `#` delas;
- `APP_URL=http://locgestor.test/novo`, `ASSET_URL=/novo` e `SESSION_PATH=/novo` (ver a seção 12).

O `config.php` da raiz é **opcional**. Ele só guarda o endereço para onde `index.php` e `cadastro.php` levam
(`laravel_url`); sem ele, o destino é `/novo`. Se precisar, `copy config.example.php config.php`.

### 2.5 Ativar o endereço `http://locgestor.test`

No Laragon, clique em **Recarregar** (*Reload*). Ele cria o virtual host e a entrada no arquivo `hosts`
(pode pedir permissão de administrador).

### 2.6 Rodar os testes

Os testes do sistema estão na pasta `laravel/`. Eles usam o mesmo banco, com a conta `locgestor_app`, e cada
teste é desfeito no fim:

```bat
cd laravel
php artisan test
```

Entre eles estão o isolamento entre locadoras no próprio banco (RLS), as telas de cada módulo, o painel da
plataforma, o WhatsApp, o PWA e a cobrança automática do Asaas (com a API simulada, sem conta no Asaas).
Precisa terminar com **todos os testes passando**. Rode de novo sempre que mexer no banco.

Não há mais testes na raiz: o cadastro público, a DRE e as demais telas estão cobertos em `laravel/tests/`.

**No GitHub** ([.github/workflows/testes.yml](.github/workflows/testes.yml)), a cada push e pull request, os
testes rodam sozinhos com **PHP 8.1 e 8.4** e **PostgreSQL 18**: instala o banco do zero, aplica as migrações,
roda os testes de isolamento e os funcionais e confere que o PHP não registrou nenhum aviso. O resultado aparece
na aba *Actions* e no selo no topo deste README.

---

## 3. Primeiro acesso e primeira locadora

### Painel da plataforma (dono do SaaS)

`http://locgestor.test/novo/plataforma/login`: usuário **`suporte`**, com a senha digitada no instalador.
Para trocá-la, use o item **Minha senha** do menu (pede a senha atual; a antiga deixa de valer na hora).
Na lista de locadoras, a faixa **Precisam de atenção** aponta quem tem mensalidade vencida ou vencendo, está
suspensa ou está sem acesso há 7 dias, e os filtros da lista mostram cada grupo.

**Auditoria do painel:** menu **Auditoria**. Quem criou, alterou ou excluiu locadoras, planos, pagamentos,
recados e usuários do painel, e quando, com o antes e o depois. As ações automáticas aparecem como "rotina
diária" (cobranças e suspensões) e "webhook asaas". CNPJ, e-mail, telefone e senhas aparecem mascarados, e o
histórico não pode ser alterado nem apagado (migração 016).

**Senhas dos usuários:** na tela de uma locadora, **Redefinir senha** gera uma senha temporária para um usuário
(ela aparece uma única vez). O usuário é obrigado a trocá-la no próximo acesso, e as sessões que ele já tinha abertas
deixam de valer na hora (migração 015: `senha_redefinida_em`).

**Repetir locação:** o botão **Repetir locação** (no detalhe) abre uma nova locação com o cliente, os itens e as
quantidades, o local, os fretes e o endereço da anterior. As datas começam hoje, com o mesmo prazo. Acréscimo e
desconto não são copiados, porque são negociados de novo.

**Orçamentos:** menu **Orçamentos**: cotação com validade (até 90 dias) para um cliente, com os preços do cadastro
(podem ser negociados). Não reserva equipamento. Pode ser impresso e enviado pelo WhatsApp. Ao **transformar em
locação** (se a saída já foi) ou em **reserva** (se é futura), o orçamento fica convertido e não pode mais ser
alterado ou excluído. Orçamento vencido não converte. Migração 018.

**Fretes e endereço de entrega:** na nova locação há um campo só, **Frete**. O endereço de entrega é o próprio
**local de uso (obra/evento)**. Os fretes de entrega e de recolhimento aparecem no contrato e na fatura, e podem ser
ajustados enquanto a locação está em aberto (a mudança fica no histórico). Migração 017.

**Recados:** menu **Recados** do painel. Um aviso (aviso, novidade ou manutenção programada) com validade aparece no
topo do sistema de **todas** as locadoras até a data de validade ou até você desativar. A locadora pode fechar o
recado (fica fechado só naquele navegador). O texto aparece como texto, nunca como código.

**Acesso de suporte:** na tela de uma locadora, **Entrar como a locadora** (com o motivo, de 10 caracteres ou mais)
abre o sistema dela como ela vê, **somente leitura**, por 30 minutos. O link tem um código de uso único que vale
2 minutos (só o hash fica no banco). A locadora vê uma faixa no topo enquanto durar, e o acesso (quem, quando e o
motivo; nunca o código) fica na auditoria dela, como "Acesso de suporte". Nada se grava nessa sessão.

Menu **Uso**: por locadora, locações do mês, usuários que entraram nos últimos 30 dias (de quantos existem e do limite),
espaço das fotos de vistoria, quais funções usa (vistorias, roteiro, patrimônio, manutenção, kits, contas) e
sugestão de plano quando está perto do limite de usuários. Não mostra dados de clientes.

Menu **Números**: receita do mês e dos últimos 12 meses (mensalidades recebidas), locadoras novas e canceladas por
mês, taxa de cancelamento e ticket médio. **Cancelar contrato** (no detalhe da locadora) encerra o contrato: o acesso
fica bloqueado, as cobranças pendentes no Asaas são canceladas e a locadora não volta pelo painel. Os gráficos
precisam do arquivo `assets/vendor/chart.umd.js`; se o `.htaccess` do Laragon bloquear `/vendor/`, eles ficam vazios.

Em **+ Nova locadora**, informe nome, identificador de acesso (ex.: `alfa-locacoes`), plano e limite de usuários.
O sistema cria, numa única transação:

- a locadora e a ficha da empresa, já com o texto padrão do contrato e a observação fiscal da fatura;
- as 5 categorias padrão;
- o usuário **administrador** da locadora, com uma **senha temporária mostrada uma única vez**.

Envie ao cliente o identificador, o login e a senha temporária. No primeiro acesso, o sistema obriga a troca da senha.

### Cadastro público (teste grátis)

Com a chave **Abrir o cadastro público** ligada em *Configurações da LocGestor* (e com os termos de uso publicados),
a página `/novo/cadastro` (e o link **Teste grátis** no login) deixa uma locadora nova se cadastrar sozinha: nome,
identificador, e-mail, telefone, CNPJ opcional e o usuário e a senha do administrador. Ela começa **ativa**, no plano
escolhido em *Configurações* (vazio = o mais barato ativo), com **vencimento em X dias** (configurável de 1 a 90, padrão
14) e 5 dias de carência; depois segue as mesmas regras
de mensalidade (e, com o Asaas ligado para ela, cobrança automática).

- **Desligado por padrão:** sem a opção, a página responde 404.
- Contra robôs, sem serviço externo: campo-isca invisível, tempo mínimo de 3 segundos para enviar e no máximo
  **3 cadastros por IP por hora** (o IP fica guardado só como hash). Cada cadastro vai para o `logs/app.log`.
- Antes de ligar em produção, escreva os **termos de uso e a política de privacidade** (o formulário pede o aceite)
  e publique-os no seu site.

### Configurações da LocGestor

Painel da plataforma › **Configurações**. Cada seção salva só o que está nela, e toda alteração fica na auditoria:

- **Dados da empresa:** razão social e CNPJ (com os dígitos verificados), endereço e contato. Sem razão social e CNPJ,
  os recibos de mensalidade não são emitidos.
- **Identidade visual:** nome do sistema e logomarca, redesenhada em PNG (sem metadados).
- **Documentos:** termos de uso e política de privacidade (só endereços `https://`), observação padrão do recibo e o
  número do próximo recibo (automático e sem buracos).
- **Cadastro público:** abre ou fecha o "Teste grátis". Não abre sem os termos publicados. Dias de teste de 1 a 90 e
  plano do teste.
- **Suporte:** contato mostrado às locadoras no aviso de mensalidade.
- **Integrações (só leitura):** mostra se o Asaas e o token do webhook estão configurados. As chaves ficam no
  `laravel/.env`, nunca na tela.

### Números financeiros

Painel da plataforma › **Números**, na parte de assinaturas:

- **Receita recorrente (MRR)** e **ARR**: mensalidades das locadoras não canceladas com plano pago, com o gráfico dos
  últimos 12 meses.
- **Cancelado no mês**, em valor e em quantidade.
- **Inadimplência:** cobranças do Asaas vencidas e não pagas, por faixa (1 a 30, 31 a 60 e mais de 60 dias), com a
  lista das 15 maiores e link para a ficha da locadora.
- **Teste grátis que virou pagamento:** das locadoras que já acabaram o teste, quantas pagaram.
- **Cobranças do mês** por situação e **receita do mês por plano**.

### Planos e mensalidades

- **Planos** (menu *Planos*): nome, preço mensal e limite de usuários. Vêm prontos Básico (R$ 99, 3 usuários),
  Profissional (R$ 199, 5) e Empresarial (R$ 399, 15); edite à vontade.
- **Nova locadora:** escolha o plano, o **primeiro vencimento** (padrão: 30 dias, período de teste) e os **dias de
  carência** (padrão: 5). Vencimento vazio = sem cobrança (conta interna ou cortesia).
- **Gerenciar** (na lista de locadoras): troca de plano, limite especial de usuários, vencimento e carência;
  **registrar pagamento** de 1 a 24 meses (avança o vencimento; valor vazio = preço do plano × meses);
  **estornar o último pagamento** (o vencimento volta); **suspender/reativar** manualmente.
- **Aviso na locadora:** o admin vê uma faixa quando faltam 5 dias ou menos e, depois de vencida, até quando pode
  regularizar. Para mostrar seu contato nesse aviso, preencha o contato de suporte em *Configurações da LocGestor*.
- **Suspensão automática:** passada a carência sem pagamento, o sistema da locadora **bloqueia o acesso na hora**
  (não depende de tarefa agendada) e o painel passa a mostrar "suspensa por inadimplência". Registrar o pagamento
  **reativa** a locadora automaticamente.
- A lista mostra a **receita mensal recorrente** (locadoras ativas com cobrança) e quantas estão inadimplentes.

- **Cobrança automática pelo Asaas** (opcional, por locadora): a rotina diária gera a cobrança no Asaas alguns dias
  antes do vencimento; a locadora paga por PIX, boleto ou cartão na fatura do Asaas (o admin dela vê o botão
  **Pagar agora** no sistema) e a baixa é automática: avança o vencimento e reativa o acesso. Configuração na
  seção 10.6. Sem o Asaas configurado, registre os pagamentos à mão como acima.

### Sistema da locadora

`http://locgestor.test`: campos **Empresa** (o identificador), **Usuário** e **Senha**.
O navegador lembra a última empresa usada.

Com a demonstração carregada:

| Empresa | Usuário | Senha | Conteúdo |
|---|---|---|---|
| `alfa-locacoes` | `admin` | `admin123` | construção civil, ~12 meses de histórico, 3 locações abertas (1 atrasada), 1 reserva, preços semanais/mensais, 1 kit e patrimônio de betoneiras e geradores |
| `beta-eventos` | `admin` | `admin123` | eventos, poucos dados. Tem cliente com o **mesmo CPF** e produto com o **mesmo código** da Alfa |

**Troque essas senhas** (ou não carregue a demonstração) em qualquer ambiente acessível por outras pessoas.

### Códigos de barras

Em *Produtos › Editar*, o campo **Códigos de barras** aceita um código por linha (ou separados por vírgula ou espaço).
O **primeiro é o principal**; os outros são similares. Cada código tem de 5 a 20 dígitos, não se repete no mesmo
produto e não pode estar em outro produto **da mesma locadora** (outra locadora pode usar o mesmo número).
Ao salvar, a lista inteira é substituída. Na lista de produtos e na busca geral, um leitor USB (que digita o código
e Enter) acha o produto pelo código de barras exato.

### Recibos e romaneio

- **Recibo** (*Recibos*, ou pelo botão **Recibo** na locação): comprovante com número da própria locadora, que nunca
  se repete e não se altera nem se apaga. Pode ser avulso (informa quem pagou, CPF/CNPJ opcional, valor, forma e
  referência) ou ligado a uma locação (já preenche cliente e referência). Não lança nada no caixa: o pagamento
  continua sendo lançado no caixa, como antes.
- **Romaneio de retirada** (botão **Romaneio** na locação): lista os itens, a quantidade e os patrimônios para
  conferir na hora de entregar, com a coluna *Conferido* e a assinatura do cliente.
- **Romaneio de troca** (botão **Romaneio de troca**): mesma lista, com colunas *Saiu* e *Entrou* e linhas em branco
  para itens que entram no lugar. Só imprime: não muda o estoque nem a locação.

### Desconto e acréscimo com permissão

Dar desconto ou acréscimo numa locação ou num orçamento exige permissão. Quem é **administrador** lança direto. Quem é
**operador** precisa digitar a **senha de um administrador** no mesmo formulário; sem ela (ou com senha errada) a
locação não é gravada. O sistema guarda quem autorizou (*desconto_autorizado_por*), e o log de auditoria registra
quem lançou e quem autorizou. Orçamento autorizado que vira locação leva a autorização junto, sem pedir senha de novo.

### Perda ou extravio na devolução

Na devolução, a coluna **Perdido** marca quantas unidades **não voltaram**. Cada unidade perdida é cobrada pelo
**valor de reposição** do produto (cadastro), entra na avaria da devolução (e portanto no saldo da locação) e sai do
estoque. A perda fica registrada na tabela de perdas e aparece na observação da devolução. A perda fecha o item como
se tivesse voltado, então o pendente diminui. Não se cobra diária da unidade perdida: só a reposição.
Produto com patrimônio (unidades) ainda não aceita perda nessa tela: a baixa da unidade é feita em *Produtos*.

### Devolução prevista por item

Na nova locação, a tabela de itens tem a coluna **Devolução**: cada produto pode voltar numa data diferente. Trocar a
devolução geral (no topo da tabela) põe a mesma data em todos os itens. Cada item é cobrado pelos dias da data dele;
a locação fica com a devolução mais tarde. O mesmo produto não pode aparecer em duas linhas com datas diferentes (use
uma linha por produto). Migração 023. O atraso e a devolução por item ainda usam a data geral da locação.

### Período de cada item (diária, semanal, quinzenal ou mensal)

Cada item da locação tem um **Período**. A diária é a unidade base: se o cliente não escolhe plano, cobra-se dia a dia.
Os planos são ofertas. Semanal = 7 dias, quinzenal = 15, mensal = 30: cada bloco completo cobra o preço do plano, e
os dias que sobram voltam a ser diárias. Ex.: 20 dias no semanal = 2 semanas + 6 diárias. O valor digitado na coluna
Valor é o do período escolhido. A coluna **Dias** diz quantos dias o item fica; a devolução é calculada a partir dela
(saída + dias − 1, porque o dia da saída conta). Trocar a data da devolução atualiza os dias, e vice-versa. Os dias
cobrados também contam o dia da saída: 27/10 a 05/11 são 10 dias, cobrados como 10 diárias.
O período também vale para o **orçamento** e a **reserva** (ao virar locação, cada item leva o período). O detalhe, o
contrato e a fatura mostram o período de cada item. Produto sem preço no período escolhido não pode ser alugado nele (escolha outro).
O cadastro do produto tem o **Valor da quinzena**. Itens antigos (sem período) ficam como "automático" (melhor preço).

### Busca geral

O campo **Buscar** no topo de todas as telas acha clientes (nome, CPF/CNPJ ou trecho do telefone), locações e
reservas (`#123` procura só pelo número), produtos (código, descrição ou código de barras) e unidades de patrimônio (número ou
série). Um único resultado abre direto. A busca vai por POST: o termo (que pode ser um CPF) nunca aparece na URL.

### Aplicativo no celular (PWA)

O LocGestor pode ser **instalado** como aplicativo: no Chrome do Android, menu › *Instalar app* (ou *Adicionar à
tela inicial*); no iPhone, Safari › Compartilhar › *Adicionar à Tela de Início*. Abre em tela cheia, com o ícone
"LG" e atalhos para *Nova locação* e *Roteiro do dia*. Exige **HTTPS** (seção 10.3); em `localhost` funciona para
teste.

- O aplicativo guarda no aparelho **só** arquivos estáticos (estilos, scripts, ícones). As telas, com dados de
  clientes e locações, vêm sempre do servidor e nunca ficam salvas: num celular compartilhado, nada sobra depois
  de sair do sistema. Sem internet, aparece a página "Sem conexão".
- Arquivos (em `laravel/public/`): `manifest.webmanifest`, `sw.js` (service worker), `offline.html` e
  `assets/icons/`. Quem registra o aplicativo é a partial `laravel/resources/views/layouts/_pwa.blade.php`.

### Roteiro do motorista

Menu **Roteiro** (feito para o celular): as **entregas** do dia (locações com saída no dia) e as **coletas**
(devolução prevista no dia; hoje, também as atrasadas). Cada parada mostra cliente, endereço (o local de uso ou,
sem ele, o endereço do cliente), o que levar ou buscar e os botões **Mapa** (Google Maps), **Ligar**, **WhatsApp**
(mensagem "estamos a caminho" pronta) e **Entregue/Coletado**, que registra quem fez, a hora e uma observação.
A coleta não substitui a devolução: na loja, registre a devolução para conferir os itens.

### Vistorias (checklist, fotos e assinatura)

No detalhe da locação, **Vistoria de retirada** e **Vistoria de devolução** abrem um checklist com o estado de cada
item (ok, avariado, sujo, incompleto), observações, até **12 fotos** e a **assinatura do cliente na tela**
(dedo, caneta ou mouse). Cada locação tem uma vistoria de retirada; as de devolução podem ser várias (devolução parcial).

- A assinatura da retirada aparece no **contrato**, sobre a linha do locatário, com nome e data/hora.
- A vistoria vira uma página A4 para imprimir ou salvar em PDF.
- Vistoria registrada **não pode ser alterada nem apagada** (o banco não dá essa permissão ao sistema): é a prova.
- O celular reduz as fotos antes de enviar; o servidor regrava todas (máx. 1600 px, JPEG), o que também remove os
  metadados da câmera, como a localização GPS.
- Os arquivos ficam em `storage/` (ou na pasta de `LOCGESTOR_PASTA_ARQUIVOS` do `laravel/.env`), separados por locadora, e só
  são entregues pelo sistema, para quem está logado na locadora dona. O acesso direto à pasta é bloqueado.

### Patrimônio e manutenção

- **Unidades (patrimônio)**, opcional, por produto (*Produtos › Editar › Patrimônio*): número de patrimônio, série,
  data e valor de aquisição de cada peça, até a quantidade em estoque. O estoque continua sendo o número do produto;
  as unidades dizem **qual** peça está com **qual** cliente.
- **Na locação** (detalhe › *Patrimônio*): marque quais unidades saíram; os números aparecem no **contrato**. Na
  **devolução**, marque quais voltaram (devolvendo o item inteiro, todas voltam sozinhas). Uma unidade não sai em
  duas locações nem enquanto está em manutenção.
- **Manutenção** (menu *Manutenção*): ordem corretiva ou preventiva de uma unidade ou de N peças. Enquanto aberta,
  as peças **saem do estoque disponível** (locação, reserva, listas e relatório de estoque). Ao concluir, informe o
  custo e, se quiser, ele vira uma **saída no caixa** (categoria *Manutenção*).
- **Histórico da unidade**: todas as locações e manutenções dela. **Baixa** (venda, perda, sucata) tira a unidade
  do patrimônio e diminui 1 do estoque; só vale para peça que não está na rua nem em manutenção.

### Contas a pagar e previsão de caixa

*Fluxo de caixa* tem três abas:

- **Lançamentos:** o extrato de sempre.
- **Contas a pagar:** contas avulsas (com vencimento) e **recorrentes** (aluguel, salário, internet: valor e dia
  do mês). As recorrentes geram as parcelas sozinhas até 90 dias à frente, sem criar parcelas já vencidas; excluir
  uma parcela não a faz voltar; mudar o valor vale para as parcelas em aberto; desativar remove as futuras.
  **Pagar** lança a saída no caixa com a categoria da conta; só o administrador cria recorrentes, exclui contas e
  desfaz pagamentos (o lançamento de uma conta paga só sai do caixa por "Desfazer pagamento").
- **Previsão (30, 60 ou 90 dias):** saldo de hoje + o que deve entrar − o que deve sair, semana a semana, com
  gráfico e o menor saldo do período (avisa se o caixa fica negativo). Entram: o saldo de cada locação aberta na
  data prevista de devolução, locações atrasadas e devolvidas com saldo (na primeira semana) e reservas ativas
  estimadas pelos preços atuais. Saem: as contas em aberto (as vencidas, na primeira semana).

### Exportação para o contador

*Relatórios › Exportação para o contador* (só administrador): escolha o mês e baixe, em CSV (abre no Excel) ou
tudo num **.zip** com um LEIAME: lançamentos do caixa, recebimentos de locação com **CPF/CNPJ** do cliente,
contas pagas e o resumo por categoria com saldo inicial e final. É regime de caixa (o que entrou e saiu no fluxo
de caixa). Como traz CPF/CNPJ, envie só ao contador; cada exportação fica anotada no `logs/app.log` (sem CPF).

### Identidade visual

*Configurações › Identidade visual* (só administrador): cores principal, de destaque, do texto escuro, do texto claro
e do fundo; fontes dos títulos e dos textos; e o logotipo quadrado (usado no menu recolhido). Sem escolha, o sistema
usa o padrão neutro azul. O contraste é conferido ao salvar: texto sobre o fundo (4,5:1), texto dos botões (4,5:1) e
cor principal sobre o fundo (3:1). A prévia mostra o efeito antes de salvar, e *Voltar ao padrão neutro* desfaz tudo.
A logomarca horizontal fica em *Dados da locadora*. As impressões (contrato, fatura, recibo) usam a cor escolhida.

### Resultado do mês e análises

- **Painel:** *Resultado do mês* mostra locações iniciadas, faturado, recebido, ticket médio e prazo até o primeiro
  pagamento, comparados com o mês anterior. Há o gráfico de faturado e recebido dos últimos 12 meses e a idade do
  saldo a receber (0 a 30, 31 a 60 e mais de 60 dias desde a saída da locação).
- **Relatórios:** *DRE simples por mês* (só administrador), *Ocupação da frota por mês* e *Conversão de orçamentos e
  reservas*. A DRE segue o regime de caixa: resultado = entradas − saídas. "Outras saídas" são as que não vêm de
  manutenção nem de conta a pagar.

### Auditoria

*Configurações › Auditoria* (só administrador): toda inclusão, alteração e exclusão em locações, devoluções,
reservas, clientes, produtos, kits, categorias, patrimônio, manutenções, caixa, usuários e dados da empresa, com
**quem**, **quando** e **o que mudou** (antes → depois). Filtros por período, tipo, ação e usuário; o link
*histórico* mostra a vida inteira de um registro.

- É gravada por **gatilhos no banco**: vale para qualquer tela e não depende de lembrar de registrar no código.
- O sistema **só inclui e lê**: o histórico não pode ser alterado nem apagado (o banco não dá essa permissão).
- **LGPD:** CPF/CNPJ, RG, telefones, e-mail e endereço dos clientes, a chave PIX e o hash das senhas aparecem como
  *(oculto)*: a auditoria mostra que mudaram, não os valores.
- O login só atualiza o último acesso e não entra na auditoria. Alterações feitas pelo painel da plataforma
  aparecem como `plataforma: <login>`.

---

## 4. Estrutura do projeto

```
locgestor/
├── laravel/               o sistema das locadoras e o painel da plataforma (Laravel 13), em /novo
│   ├── app/               Controllers, Middleware, Support (regras: Locadora, Cobrancas, Assinaturas, Identidade,
│   │                      PainelFinanceiro, NumerosFinanceiros, Relatorios...) e Console (rotina diária)
│   ├── routes/            web.php (locadoras), plataforma.php (painel do dono do SaaS), console.php (agendamento)
│   ├── resources/views/   telas em Blade (layouts/app.blade.php: locadora; layouts/plataforma.blade.php: painel)
│   ├── public/            entrada do Laravel, compilados (build/), PWA (manifest, sw.js) e assets
│   ├── tests/             testes do sistema (php artisan test)
│   ├── .env.example       modelo das configurações (copie para .env; o .env não vai para o GitHub)
│   └── composer.json      dependências do PHP (composer install)
├── database/              00_roles.sql, 01_schema.sql, 02_seed.sql, 03_demo.sql, instalar.bat, backup.bat,
│                          seguranca_postgres.sql e migrations/ (atualizações do banco, de 001 a 031)
├── index.php              endereço antigo: só redireciona para o Laravel (/novo)
├── cadastro.php           endereço antigo do cadastro público: redireciona para /novo/cadastro
├── webhook_asaas.php      avisos de pagamento do Asaas: entrega ao Laravel (POST /webhook/asaas)
├── config.example.php     modelo opcional da raiz (só o endereço laravel_url)
├── .htaccess              sem listagem; bloqueia config*.php, README.md, .sql, .bat, .log, .git, .claude, laravel/ e .env
├── .github/workflows/     testes.yml: roda os testes no GitHub a cada push (PHP 8.1 e 8.4)
├── storage/               arquivos enviados (fotos, assinaturas, logomarcas): não versionados, acesso direto bloqueado
└── logs/                  registros do sistema (não versionados)
```

As telas das locadoras estão em `laravel/` (rotas em `laravel/routes/web.php`). As telas antigas do sistema das
locadoras (`modulos/`) foram retiradas depois de cada uma ter teste no Laravel.

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
  e incluí-la na lista do bloco de RLS do `01_schema.sql` **e** na lista `TABELAS` de `laravel/tests/Feature/IsolamentoBancoTest.php`;
- toda view nova: `WITH (security_invoker = true)`;
- **nunca** crie função `SECURITY DEFINER` (o schema e o teste recusam);
- depois de qualquer mudança no banco, rode `php artisan test` na pasta `laravel/`.

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
  atrasados) − reservas ativas que se cruzam com ele − peças em manutenção aberta. Locação, reserva e prorrogação conferem o estoque dentro
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
- **Auditoria** de toda alteração nos cadastros e movimentos (seção 3), sem dados pessoais nem senhas.
- Pilhas de erro do PHP sem os argumentos das funções (`zend.exception_ignore_args`): a senha do banco nunca
  chega ao `php-erros.log`.
- A pasta `laravel/` (com o `.env`, o código e o `vendor`), `database/`, `logs/` e `storage/` não são servidas pela web;
  só `laravel/public` responde, pelo endereço `/novo`. Qualquer arquivo `.env` é recusado.

---

## 9. Problemas comuns

| Sintoma | Causa e solução |
|---|---|
| "SQLSTATE ... password authentication failed" ou "connection refused" | PostgreSQL desligado, ou senha errada em `DB_PASSWORD` / `DB_PLATAFORMA_PASSWORD` no `laravel/.env` (passo 2.4) |
| Tela em branco ou erro "Table ... does not exist" / coluna inexistente | o banco é de uma versão anterior: aplique as migrações que faltam (seção 10.1, de 028 a 031) |
| `php artisan test` (em `laravel/`) acusa falha no isolamento | **não use o sistema em produção** até corrigir: algo no banco deixou de isolar as locadoras |
| Gráficos vazios em `http://localhost/locgestor/` | um `.htaccess` em `C:\laragon\www` que bloqueia `/vendor/` impede o Chart.js de carregar. Acesse por `http://locgestor.test` |
| Arquivos "travados" (erro no `git commit`, log com linhas faltando) | programas de backup/sincronização (TeraBox, OneDrive etc.) prendem os arquivos enquanto copiam. Exclua a pasta do projeto da sincronização (ela também contém o `laravel/.env` com as senhas) |
| PostgreSQL aceita qualquer senha | o Laragon vem com `trust` no `pg_hba.conf`. Siga a seção 10.5 antes de expor a máquina |
| "Muitas tentativas de acesso. Aguarde 15 minutos..." | limite de login atingido (seção 10.2). Espere 15 minutos ou, como `postgres`: `DELETE FROM login_tentativas;` |
| Usuário só consegue abrir "Minha senha" | ele entrou com senha temporária; ao definir uma senha nova, o sistema libera as outras telas |
| `backup.log` com "FALHOU" | PostgreSQL desligado, ou o `postgres` tem senha e falta o `pgpass.conf` (seção 10.4) |
| Erro "column trocar_senha does not exist" ou "relation login_tentativas does not exist" | banco instalado antes desta versão: rode a migração (seção 10.1) |

---

## 10. Colocando em produção: login, HTTPS, backup, PostgreSQL e Asaas

### 10.1 Atualizar um banco já instalado (migrações)

Instalações novas já saem completas pelo `instalar.bat`. Um banco criado **antes** de uma atualização recebe as
mudanças pelos arquivos de `database/migrations/`, em ordem (cada um pode ser rodado mais de uma vez sem estragar nada):

```bat
psql -U postgres -d locgestor -f database\migrations\001_seguranca_login.sql
psql -U postgres -d locgestor -f database\migrations\002_pix.sql
psql -U postgres -d locgestor -f database\migrations\003_planos_assinatura.sql
psql -U postgres -d locgestor -f database\migrations\004_vistorias.sql
psql -U postgres -d locgestor -f database\migrations\005_precos_kits.sql
psql -U postgres -d locgestor -f database\migrations\006_cobranca_asaas.sql
psql -U postgres -d locgestor -f database\migrations\007_patrimonio_manutencao.sql
psql -U postgres -d locgestor -f database\migrations\008_auditoria.sql
psql -U postgres -d locgestor -f database\migrations\009_contas_pagar.sql
psql -U postgres -d locgestor -f database\migrations\010_entregas.sql
psql -U postgres -d locgestor -f database\migrations\011_cancelamento_locadora.sql
psql -U postgres -d locgestor -f database\migrations\012_uso_plataforma.sql
psql -U postgres -d locgestor -f database\migrations\013_acesso_suporte.sql
psql -U postgres -d locgestor -f database\migrations\014_recados.sql
psql -U postgres -d locgestor -f database\migrations\015_redefinir_senha.sql
psql -U postgres -d locgestor -f database\migrations\016_auditoria_plataforma.sql
psql -U postgres -d locgestor -f database\migrations\017_frete_endereco.sql
psql -U postgres -d locgestor -f database\migrations\018_orcamentos.sql
psql -U postgres -d locgestor -f database\migrations\019_codigos_barras.sql
psql -U postgres -d locgestor -f database\migrations\020_recibos.sql
psql -U postgres -d locgestor -f database\migrations\021_desconto_autorizacao.sql
psql -U postgres -d locgestor -f database\migrations\022_devolucao_perdas.sql
psql -U postgres -d locgestor -f database\migrations\023_devolucao_por_item.sql
psql -U postgres -d locgestor -f database\migrations\024_periodo_do_item.sql
psql -U postgres -d locgestor -f database\migrations\025_dias_cobranca.sql
psql -U postgres -d locgestor -f database\migrations\026_periodo_orcamento_reserva.sql
psql -U postgres -d locgestor -f database\migrations\027_logomarca.sql
psql -U postgres -d locgestor -f database\migrations\028_recibo_e_estorno_plataforma.sql
psql -U postgres -d locgestor -f database\migrations\029_configuracoes_plataforma.sql
psql -U postgres -d locgestor -f database\migrations\030_identidade_visual.sql
psql -U postgres -d locgestor -f database\migrations\031_numeros_financeiros.sql
cd laravel
php artisan test
```

| Migração | O que faz |
|---|---|
| `001_seguranca_login.sql` | tabela `login_tentativas` (limite de login) e coluna `usuarios.trocar_senha` (senha temporária) |
| `002_pix.sql` | colunas `pix_chave`, `pix_nome` e `pix_cidade` na `empresa` (cobrança por PIX) |
| `003_planos_assinatura.sql` | tabelas `planos` e `assinatura_pagamentos`; plano, vencimento e carência em `tenants` |
| `004_vistorias.sql` | tabelas `vistorias`, `vistoria_itens` e `vistoria_fotos` (checklist, fotos e assinatura) |
| `005_precos_kits.sql` | preço semanal/mensal (`produtos`, `locacao_itens`), função `preco_periodo`, view de valores e tabelas `kits`/`kit_itens` |
| `006_cobranca_asaas.sql` | cobrança automática: colunas em `tenants`, tabelas `assinatura_cobrancas` (com RLS) e `gateway_eventos` |
| `007_patrimonio_manutencao.sql` | tabelas `unidades`, `manutencoes` e `locacao_unidades`, view `vw_produto_manutencao` |
| `008_auditoria.sql` | tabela `auditoria` (com RLS, só inclusão e leitura), função `auditar()` e gatilhos nas tabelas principais |
| `009_contas_pagar.sql` | tabelas `contas_recorrentes` e `contas_pagar` (com RLS e auditoria) |
| `010_entregas.sql` | tabela `entregas` (paradas do roteiro do motorista marcadas como feitas) |
| `011_cancelamento_locadora.sql` | cancelamento de locadora (colunas em `tenants`) e números do painel da plataforma |
| `012_uso_plataforma.sql` | tela de uso por locadora (painel da plataforma) |
| `013_acesso_suporte.sql` | tabela `acessos_suporte` (acesso de suporte somente leitura, registrado na auditoria) |
| `014_recados.sql` | tabela `recados` (avisos do painel para todas as locadoras) |
| `015_redefinir_senha.sql` | coluna `usuarios.senha_redefinida_em` (senha temporária pelo painel) |
| `016_auditoria_plataforma.sql` | tabela `auditoria_plataforma` (ações do painel da plataforma, só inclusão e leitura) |
| `017_frete_endereco.sql` | colunas `frete_entrega`, `frete_recolhimento` e `endereco_entrega` em `locacoes`; view `vw_locacao_valores` soma os fretes |
| `018_orcamentos.sql` | tabelas `orcamentos` e `orcamento_itens` (cotação com validade, convertível em locação ou reserva) |
| `019_codigos_barras.sql` | tabela `produto_barras` (códigos de barras do produto; um principal e os similares) |
| `020_recibos.sql` | tabela `recibos` (recibos numerados, sem alteração nem exclusão; CPF/CNPJ mascarado na auditoria) |
| `021_desconto_autorizacao.sql` | coluna `desconto_autorizado_por` em `locacoes` e `orcamentos` (quem autorizou o desconto ou acréscimo) |
| `022_devolucao_perdas.sql` | tabela `devolucao_perdas` (equipamentos perdidos ou extraviados na devolução, com o valor de reposição) |
| `023_devolucao_por_item.sql` | coluna `data_prev_devolucao` em `locacao_itens` (devolução de cada item; o valor previsto usa a data do item) |
| `024_periodo_do_item.sql` | período por item (`periodo`, `valor_quinzenal`) e função `preco_plano()`; itens antigos ficam como "automatico" |
| `025_dias_cobranca.sql` | a view de valores conta o dia da saída (27/10 a 05/11 = 10 dias) |
| `026_periodo_orcamento_reserva.sql` | período por item no orçamento (`periodo`, `valor_quinzenal`) e na reserva (`periodo`) |
| `027_logomarca.sql` | coluna `logo_arquivo` na `empresa` (logomarca da locadora nos documentos impressos) |
| `028_recibo_e_estorno_plataforma.sql` | recibos de mensalidade numerados sem buracos (`plataforma_sequencias`); colunas `recibo_numero`, `estornado_em` e `estorno_motivo` em `assinatura_pagamentos`. Estorno marca o pagamento e não apaga |
| `029_configuracoes_plataforma.sql` | tabela `plataforma_configuracoes` (uma linha): dados da LocGestor, nome, termos de uso, cadastro público, suporte; com auditoria |
| `030_identidade_visual.sql` | identidade visual da locadora: cores, fontes e `logo_quadrada_arquivo` na `empresa` (vazio = padrão neutro) |
| `031_numeros_financeiros.sql` | `tenants.teste_ate` (fim do teste grátis, gravado pelo cadastro público) e índice de cobranças por situação e vencimento |

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
3. No `laravel/.env`: `APP_URL=https://SEU-DOMINIO`, `SESSION_SECURE_COOKIE=true` e `LOCGESTOR_FORCAR_HTTPS=true`.
   Depois, na pasta `laravel`: `php artisan config:clear`.

A partir daí, todo acesso por `http://` é redirecionado (301) para `https://`, as respostas levam o cabeçalho HSTS
(o navegador passa a usar só HTTPS por um ano) e o cookie de sessão só viaja por HTTPS. Só ative o item 3 depois que o
item 2 funcionar. Atrás de Cloudflare ou balanceador, o proxy precisa estar em `LOCGESTOR_PROXIES` para o sistema
reconhecer o HTTPS vindo dele.

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
cd laravel
php artisan test
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
5. **Inicie o PostgreSQL** e confira: `psql -U postgres` deve pedir senha; `php artisan test` (em `laravel/`) deve passar;
   abra o LocGestor e os outros projetos.

Se algo parar de funcionar, volte `scram-sha-256` para `trust` no `pg_hba.conf` e reinicie o PostgreSQL.

### 10.6 Cobrança automática das mensalidades (Asaas)

1. **Conta de testes:** crie uma conta em [sandbox.asaas.com](https://sandbox.asaas.com) e gere a chave da API
   (*Integrações › Chaves de API*). Na conta de verdade (asaas.com) o passo é o mesmo.
2. **`laravel/.env`** (nunca vai para o Git). Depois, na pasta `laravel`: `php artisan config:clear`.
   ```bat
   LOCGESTOR_ASAAS_AMBIENTE=sandbox
   LOCGESTOR_ASAAS_CHAVE=$aact_...
   LOCGESTOR_ASAAS_WEBHOOK_TOKEN=um-texto-longo-aleatorio
   LOCGESTOR_ASAAS_DIAS_ANTES=10
   ```
   `sandbox` para testes e `producao` para cobrar de verdade. A chave de produção nunca vai junto com `sandbox`.
3. **Rotina diária e agendador.** O Laravel gera as cobranças, confere os pagamentos e suspende os inadimplentes
   todo dia às 06:00 (`laravel/routes/console.php`), mas só se o servidor chamar o agendador **a cada minuto**.
   Teste à mão com `php artisan locgestor:rotina-diaria` (na pasta `laravel`) e agende o agendador (PowerShell; use o
   `php.exe` do Laragon):
   ```powershell
   $acao    = New-ScheduledTaskAction -Execute 'C:\laragon\bin\php\php-8.x\php.exe' -Argument 'artisan schedule:run' -WorkingDirectory 'C:\laragon\www\locgestor\laravel'
   $gatilho = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
   $config  = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
   Register-ScheduledTask -TaskPath '\LocGestor\' -TaskName 'Agendador do Laravel' -Action $acao -Trigger $gatilho -Settings $config -Force
   ```
4. **Webhook** (baixa na hora; só quando o sistema estiver num endereço público com HTTPS): no Asaas,
   *Integrações › Webhooks*, URL `https://SEU-DOMINIO/webhook_asaas.php`, eventos de **cobrança** e o mesmo
   **token de autenticação** de `LOCGESTOR_ASAAS_WEBHOOK_TOKEN`. Sem webhook (ex.: no Laragon local) tudo funciona igual, só que a
   baixa acontece na rotina diária ou no botão **Conferir pagamentos**.
5. **Em cada locadora** (painel › Gerenciar): preencha o **CNPJ** (o Asaas exige) e marque **Gerar a cobrança no
   Asaas**. O botão **Gerar cobrança do vencimento** cria a cobrança na hora, sem esperar a rotina.
6. **Validar antes de cobrar de verdade:** no sandbox, gere a cobrança de uma locadora de teste, pague pela fatura
   de teste e confira a baixa no painel. No Asaas, envie um aviso de teste do webhook e confira que um token errado
   é recusado (401). Só depois troque para `producao`, com a chave de produção.

Como funciona e o que protege:

- Cada cobrança leva a nossa referência; se a resposta do Asaas se perder, a rotina seguinte acha a cobrança por
  essa referência em vez de criar outra. Um vencimento nunca tem duas cobranças em aberto (índice único no banco).
- O aviso do webhook só diz **qual** cobrança mudou: a situação é sempre conferida na API antes da baixa (um aviso
  falso não registra pagamento). Token errado = 401 e alerta no log; aviso repetido é ignorado.
- A baixa usa o mesmo registro do pagamento manual (avança 1 mês e reativa). Pagamento recebido pelo Asaas não é
  estornado pelo painel: se o dinheiro voltar ao cliente no Asaas, ajuste o vencimento à mão.
- O sistema da locadora só lê o **link** das cobranças **dela** (RLS na tabela `assinatura_cobrancas`).
- Mensalidade já vencida é cobrada com vencimento para hoje (o Asaas não aceita data passada).
- Os testes da cobrança (`laravel/tests/Feature/Plataforma/CobrancaAsaasTest.php`) usam a API simulada; antes de cobrar de verdade, faça um ciclo
  completo no **sandbox** (gerar, pagar pela fatura de teste e conferir a baixa).

### 10.7 Checklist de produção

Faça nesta ordem. Cada item vale para uma instalação nova ou para uma atualização.

1. **Servidor:** PHP 8.1 ou superior com as extensões exigidas pelo `laravel/composer.json` (inclui `gd`, usado nas
   logomarcas e nas fotos) e PostgreSQL 18.
2. **`laravel/.env`:** `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` gerada, `APP_URL` com `https://`, as senhas
   dos papéis em `DB_PASSWORD` e `DB_PLATAFORMA_PASSWORD`, e `LOCGESTOR_PASTA_ARQUIVOS` apontando para uma pasta fora
   da pasta do site. Se houver Cloudflare ou balanceador na frente, `LOCGESTOR_PROXIES` com os IPs (seção abaixo).
3. **Banco:** numa instalação nova, `database\instalar.bat` aplica tudo (responda `N` à demonstração). Num banco
   existente, aplique as migrações que faltam (seção 10.1), de 028 a 031 e as seguintes.
4. **Painel da plataforma › Configurações da LocGestor:** dados da empresa (razão social e CNPJ, para os recibos),
   nome do sistema, termos de uso e política de privacidade em `https://`. O cadastro público só deve ser aberto
   depois que os termos estiverem publicados.
5. **Trocar a senha do usuário `suporte`** (painel da plataforma › Minha senha) e a senha do administrador de cada
   locadora real.
6. **Agendador:** `php artisan schedule:run` a cada minuto (seção 10.6, passo 3).
7. **Asaas:** chave de produção, webhook público com HTTPS e validação no sandbox antes (seção 10.6).
8. **HTTPS** (seção 10.3) e **backup** com cópia para fora do computador (seção 10.4).
9. **Senha do PostgreSQL** (seção 10.5).
10. **Teste depois de publicar:** entrar como administrador de uma locadora, abrir o painel, imprimir uma fatura e um
    recibo, conferir a auditoria, e, se o cadastro estiver aberto, criar uma locadora de teste e apagá-la pelo painel.
11. **Dados de demonstração:** não instale com a demonstração (`03_demo.sql`) em produção.

### Atualizações e variáveis de ambiente (`laravel/.env`)

Variáveis que o sistema lê, além das de banco e do Asaas (seção 10.6):

- `LOCGESTOR_PROXIES`: IPs dos proxies confiáveis, separados por vírgula (Cloudflare, balanceador). **Vazio = nenhum
  proxy confiável**, que é o padrão seguro. Com um proxy de verdade na frente, sem essa lista o endereço do visitante
  fica errado nos registros e no limite de tentativas de login. Não ponha IPs que não são do seu proxy.
- `LOCGESTOR_PASTA_ARQUIVOS`: pasta das fotos, assinaturas e logomarcas (padrão: `storage/` na raiz do projeto).

---

## 11. O que ficou de fora e próximos passos

Não implementado nesta versão:

- emissão de **NF-e** (remessa/retorno) e certificado A1 por locadora;
- **recuperação de senha** por e-mail (precisa de uma conta SMTP) e envio de contrato/fatura por e-mail;
- envio **automático** pelo WhatsApp (API paga: WhatsApp Business, Z-API, Twilio) e PDF anexado (hoje: links `wa.me` com a mensagem pronta e "Salvar como PDF" do navegador);
- **baixa automática** do PIX das locações (precisa da API do banco da locadora; as mensalidades já têm cobrança automática pelo Asaas);
- exclusão/anonimização de cliente a pedido (LGPD) e prazo de guarda da auditoria (hoje ela não expira);
- **anexos** de documentos do cliente (fotos de avaria e assinatura já existem nas vistorias);

Decisões em aberto e limitações conhecidas (registradas para não serem esquecidas):

- **Fonte Roboto:** não está disponível. Hoje há a Inter (já no pacote `@fontsource-variable/inter`) e a fonte do sistema.
  Adicioná-la exige um pacote novo (`@fontsource-variable/roboto`), por isso depende de aprovação.
- **Permissões:** só existem os perfis *administrador* e *operador*. A DRE é restrita ao administrador. Liberar a
  DRE a uma pessoa específica exige um campo de permissão por usuário e uma opção na tela *Usuários*; ainda não existe.
- **Marca na entrada:** a tela de login e o aplicativo instalado (PWA, com manifesto e ícones fixos) mostram a marca
  LocGestor. A identidade de cada locadora aparece depois do login, porque antes não se sabe qual locadora está entrando.
- **Números da plataforma:** a receita recorrente e o cancelamento em valor usam o plano atual de cada locadora (o banco
  não guarda a troca de plano). A ocupação da frota usa o estoque de hoje em todos os meses. A DRE é de regime de caixa.
- **Menor privilégio:** o perfil do painel da plataforma ainda consegue ler `clientes` e `locacoes`. Essa leitura deve
  ser revisada.
- **Asaas em produção:** a cobrança e o webhook só foram testados com a API simulada. Falta o ciclo completo no sandbox
  com uma chave real (seção 10.6, passo 6).

Sugestões de próximos passos, em ordem:

1. Exigir senha no PostgreSQL (seção 10.5) e copiar os backups para fora do computador.
2. Ampliar os testes funcionais a cada tela nova (o GitHub já roda os atuais a cada push).
3. Lembretes automáticos por API do WhatsApp e PDF gerado no servidor (exige liberar o Composer).
4. Baixa automática do PIX no caixa (exige a API PIX do banco da locadora; o QR Code e o copia e cola já existem).
5. Ligar o Asaas: ciclo completo no sandbox, depois produção com o webhook num endereço público (seção 10.6).
6. Integração NFePHP para remessa/retorno, com certificado A1 isolado por locadora.

## 12. Migração para Laravel (pasta laravel/)

O sistema está sendo levado aos poucos para o **Laravel 13**, na pasta `laravel/`, sem desligar o sistema atual.
Os dois usam o **mesmo banco** e o **mesmo papel** `locgestor_app`: o isolamento entre locadoras continua no RLS do
PostgreSQL. No Laravel, `App\Support\Locadora` é o único lugar que define `app.tenant_id`, e o middleware
`SessaoLocadora` confere em toda requisição se a locadora está ativa e em dia, se o usuário está ativo e se a senha não
foi redefinida.

Já no Laravel: login, painel, busca, clientes (lista, ficha, cadastro, edição, bloqueio e exclusão) e produtos (lista
com disponibilidade, cadastro, edição e exclusão, categorias, kits e patrimônio) e locações (lista, ficha, nova locação e devolução, com recebimento, prorrogação, cancelamento,
entrega, patrimônio, WhatsApp e PIX), reservas (com kits), orçamentos (com conversão em locação ou reserva), o fluxo de
caixa (lançamentos, contas a pagar com recorrentes e previsão), os recibos, a manutenção, os relatórios (com CSV,
impressão e a exportação para o contador), as configurações (empresa e logomarca, usuários, senha e auditoria) e o
roteiro do motorista. Contrato, fatura, romaneio, orçamento impresso e vistorias (com fotos e assinatura) também são
do Laravel. A plataforma também: o painel do operador (`/novo/plataforma/login`, com sessão própria) tem locadoras
(cadastro, plano, mensalidade, pagamentos e estorno, suspensão, senha temporária, acesso de suporte e cancelamento),
planos, números, uso por locadora, recados, auditoria e a troca da senha do operador. O cadastro público ("Teste
grátis") fica no Laravel, desligado por padrão: abre pela chave em *Configurações da LocGestor*, só com os termos de uso
publicados. A cobrança pelo Asaas
(cobrança automática, conferência, webhook em `/webhook/asaas` e a rotina diária `php artisan locgestor:rotina-diaria`,
agendada às 06:00 pelo `schedule:run`) também é do Laravel. O endereço antigo do webhook (`webhook_asaas.php`) só
encaminha a requisição para ele. As telas antigas continuam acessíveis pelo endereço, mas o menu já leva ao Laravel.

**Subir o Laravel (desenvolvimento, Laragon):** o Laravel responde em `http://locgestor.test/novo`, pelo mesmo Apache.

```bat
cd laravel
composer install
copy .env.example .env
php artisan key:generate
npm install
npm run build
cd ..
mklink /J novo laravel\public
```

A junção `novo` (ignorada pelo git) aponta só para `laravel/public`. O `.htaccess` da raiz devolve 404 para qualquer
endereço `/laravel/...` e nega qualquer arquivo `.env`: o código, o `vendor` e os segredos do Laravel nunca são servidos.
No `.env`, use `APP_URL=http://locgestor.test/novo`, `ASSET_URL=/novo` (os pedaços de JavaScript carregados sob demanda
usam esse caminho; rode `npm run build` depois de mudar) e `SESSION_PATH=/novo` (o cookie de sessão fica só nessa pasta).

As telas do Laravel usam **Tailwind CSS 4** compilado pelo Vite (`npm run build` gera `public/build`). A fonte Inter vem
do pacote `@fontsource-variable/inter`, junto com o build: nada é baixado da internet quando o sistema está em uso.
Atalhos: `Ctrl+K` (ou `/`) abre a paleta de busca e ações; o botão de lua/sol troca o tema claro/escuro.

No `.env`, preencha `DB_*` com o papel `locgestor_app` e a senha digitada no instalador (passo 2.4). `SESSION_DRIVER=file`
e `CACHE_STORE=file` também valem para o ambiente local.
**Nunca rode `php artisan migrate`** contra esse banco: a estrutura continua nos arquivos de `database/`.

**Testes do Laravel:** `cd laravel` e `php artisan test`. Eles usam o mesmo banco e o mesmo papel do `.env` (o RLS vale) e
precisam dos dados de demonstração (`03_demo.sql`). Cada teste roda numa transação desfeita no fim, então nada fica
gravado. Para enxergar dados de outra locadora (e provar que a tela não mostra), os testes usam uma conexão de
superusuário só para leitura (`DB_ADMIN_USERNAME`/`DB_ADMIN_PASSWORD`, ou `PGUSER`/`PGPASSWORD`, padrão `postgres`).
Cobrem login e bloqueio por tentativas, isolamento entre locadoras, cadastro de clientes e de produtos, o painel e a busca.
No GitHub, rodam junto com os testes do sistema atual (linha do PHP 8.4).

**Endereço antigo:** `http://locgestor.test/` e `index.php` levam para o sistema novo (`/novo`). Quem chega por
ele entra de novo com a senha: não há mais passagem entre os dois sistemas. Se o endereço antigo precisar de outro
destino, o `config.php` da raiz (opcional) recebe `'laravel_url' => 'https://SEU-DOMINIO/novo'`.
