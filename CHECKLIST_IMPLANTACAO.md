# 📋 CHECKLIST DE IMPLANTAÇÃO — LocGestor
**Branch:** `plataforma-recibos`
**Último commit:** `0955ef9`
**Atualizado:** 03/11/2026
**Status:** ✅ CI verde em PHP 8.1 e 8.4 — aguardando aprovação para mesclar o PR #2

---

## 🔴 BLOCO 1 — Instalação do zero ✅ Concluído
| Item | Detalhe | Status |
|---|---|---|
| `instalar.bat` aplica migrações 028–031+ | Roda todas as migrações em ordem (passo 5/5) | ✅ |
| Mensagem final do instalador corrigida | Aponta para `laravel/.env` | ✅ |
| Simulação de instalação limpa | Banco separado testado — tabelas e colunas presentes | ✅ |
| Suíte de testes | 265 testes passando (inclui 3 do middleware HTTPS) | ✅ |
| CI em PHP 8.1 e 8.4 | Verde no commit `0955ef9` (push e pull request) | ✅ |

---

## 🟡 BLOCO 2 — Código e limpeza ✅ Concluído
| Item | Ação | Resp. | Status |
|---|---|---|---|
| Comentários obsoletos atualizados | `routes/web.php` e `CadastroController.php` corrigidos | Claude | ✅ |
| `config.example.php` limpo | Apenas campos efetivamente usados | Claude | ✅ |
| `.env.example` atualizado | Asaas, pasta de arquivos, comentários corrigidos | Claude | ✅ |
| Middleware Forçar HTTPS criado | `LOCGESTOR_FORCAR_HTTPS` + HSTS + 3 testes | Claude | ✅ |
| `cadastro.php` mantido | Redirecionamento 301 — links antigos funcionam | Claude | ✅ |
| Remover 4 arquivos sobrando | `PROMPT-copiar-e-colar.md`, `laravel/README.md`, `welcome.blade.php`, `.claude/commands/criar-locgestor.md` | Claude | ✅ |
| Locadora `gama-teste` (banco local) | Manter no banco local, não afeta nada (a exclusão foi bloqueada pelo gatilho de auditoria) | — | ⏳ |
| Cor `#2563EB` na Alfa | Mantida — sem alteração visual | — | ✅ |

---

## 🟡 BLOCO 3 — Documentação ✅ Concluído
| Item | Detalhe | Status |
|---|---|---|
| README atualizado | Apresentação, 13 relatórios, instalação reescrita | ✅ |
| Migrações 028–031 documentadas | Seção 10.1 com lista e ordem correta | ✅ |
| Variáveis de ambiente | `LOCGESTOR_PROXIES`, `LOCGESTOR_FORCAR_HTTPS`, Asaas explicadas | ✅ |
| Passo Sandbox + Asaas | Seção 10.6 completa | ✅ |
| Checklist de produção integrado | Seção 10.7 com 11 itens | ✅ |
| Limitações registradas | Roboto, permissões, marca, métricas — seção 11 | ✅ |
| Busca por referências antigas | Nenhuma restante | ✅ |

---

## 🟢 BLOCO 4 — Confirmações ✅ Definido
| Item | Decisão | Status |
|---|---|---|
| Fonte Roboto | Pendência — aguarda aprovação de pacote | ✅ |
| Perfis e DRE | Apenas Administrador acessa DRE; demais relatórios abertos | ✅ |
| Marca na entrada/PWA | Limitação documentada | ✅ |
| Restringir faturamento/rentabilidade | Não — manter acesso atual por enquanto | ✅ |

---

## 🚀 BLOCO 5 — Pré-produção (AÇÃO SUA)
| Item | O que fazer | Status |
|---|---|---|
| Commit + push para `plataforma-recibos` | Feito no commit `0955ef9` | ✅ |
| Acompanhar CI | PHP 8.1 e 8.4 verdes | ✅ |
| Mesclar PR #2 para `main` | Após aprovação | ⬜ |
| Chave Sandbox Asaas | Criar conta → copiar chave → inserir no `.env` | ⬜ |
| Configurar `.env` | `LOCGESTOR_ASAAS_CHAVE`, `LOCGESTOR_FORCAR_HTTPS=true`, `LOCGESTOR_PROXIES` | ⬜ |
| Teste ponta a ponta | Criar cobrança → confirmar no Asaas → verificar status na LocGestor | ⬜ |
| Webhook no Asaas | Apontar para endereço da instalação | ⬜ |
| Agendar `schedule:run` | Tarefa do Windows a cada 1 minuto | ⬜ |
| Migrações em produção | Aplicar 028–031 pelo `psql` (seção 10.1). Não usar `php artisan migrate` neste banco | ⬜ |
| Sandbox → Produção | Trocar chave quando tudo validado | ⬜ |

---

## ✅ CONCLUSÃO
| Pergunta | Resposta |
|---|---|
| Código pronto para envio? | ✅ Sim — commit `0955ef9` enviado |
| CI verificada? | ✅ Verde em PHP 8.1 e 8.4 |
| Pode mesclar PR #2? | ✅ Sim, após aprovação |
| Produção liberada? | ⬜ Depende do Bloco 5 |
