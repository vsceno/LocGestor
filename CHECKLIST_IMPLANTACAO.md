# 📋 CHECKLIST DE IMPLANTAÇÃO — LocGestor
**Branch:** `plataforma-recibos`
**Atualizado:** 03/11/2026
**Status:** ⏳ Aguardando CI & Merge

---

## 🔴 BLOCO 1 — Instalação do zero ✅ Concluído
| Item | Detalhe | Status |
|---|---|---|
| `instalar.bat` aplica migrações 028–031+ | Atualizado para rodar todas as migrações automaticamente | ✅ |
| Mensagem final do instalador corrigida | Aponta para `laravel/.env` | ✅ |
| Simulação de instalação limpa | Banco separado testado — tabelas e colunas presentes | ✅ |
| Suíte de testes | 265 testes passando (inclui 3 do middleware HTTPS) | ✅ |
| CI em `plataforma-recibos` | Aguardando push | ⬜ |

---

## 🟡 BLOCO 2 — Código e limpeza ⏳ Parcial
| Item | Ação | Resp. | Status |
|---|---|---|---|
| Comentários obsoletos atualizados | `routes/web.php` e `CadastroController.php` corrigidos | Claude | ✅ |
| `config.example.php` limpo | Apenas campos efetivamente usados | Claude | ✅ |
| `.env.example` atualizado | Asaas, pasta de arquivos, comentários corrigidos | Claude | ✅ |
| Middleware Forçar HTTPS criado | `LOCGESTOR_FORCAR_HTTPS` + HSTS + 3 testes | Claude | ✅ |
| `cadastro.php` mantido | Redirecionamento 301 — links antigos funcionam | Claude | ✅ |
| Apagar locadora `gama-teste` do banco | Decisão: pode apagar | Claude | ⬜ |
| Remover 4 arquivos sobrando | `PROMPT-copiar-e-colar.md`, `laravel/README.md`, `welcome.blade.php`, `.claude/commands/criar-locgestor.md` | Claude | ⬜ |
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
| Commit + push para `plataforma-recibos` | Usar mensagem padronizada abaixo | ⬜ |
| Acompanhar CI | Confirmar PHP 8.1 e 8.4 verdes | ⬜ |
| Mesclar PR #2 para `main` | Após CI ok | ⬜ |
| Chave Sandbox Asaas | Criar conta → copiar chave → inserir no `.env` | ⬜ |
| Configurar `.env` | `LOCGESTOR_ASAAS_CHAVE`, `LOCGESTOR_FORCAR_HTTPS=true`, `LOCGESTOR_PROXIES` | ⬜ |
| Teste ponta a ponta | Criar cobrança → confirmar no Asaas → verificar status na LocGestor | ⬜ |
| Webhook no Asaas | Apontar para endereço da instalação | ⬜ |
| Agendar `schedule:run` | Tarefa do Windows a cada 1 minuto | ⬜ |
| Migrações em produção | `php artisan migrate` confirmar 028–031 aplicadas | ⬜ |
| Sandbox → Produção | Trocar chave quando tudo validado | ⬜ |

---

## ✅ CONCLUSÃO
| Pergunta | Resposta |
|---|---|
| Código pronto para envio? | ✅ Sim — após limpeza do Bloco 2 |
| CI verificada? | ⬜ Aguardando push |
| Pode mesclar PR #2? | ⏳ Apenas com CI verde |
| Produção liberada? | ⬜ Depende do Bloco 5 |
