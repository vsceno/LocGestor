-- =====================================================================
-- LocGestor - dados de DEMONSTRAÇÃO (opcional)
-- Roda como postgres, no banco locgestor, depois de 02_seed.sql, em banco novo.
--
-- Cria duas locadoras, cada uma com o usuário admin / admin123:
--   alfa-locacoes  construção civil, ~12 meses de histórico
--   beta-eventos   eventos, poucos dados; tem um cliente com o MESMO CPF e um
--                  produto com o MESMO código da Alfa (unicidade é por locadora)
-- As datas são relativas ao dia em que o script roda.
-- =====================================================================

\set ON_ERROR_STOP on
SET client_encoding = 'UTF8';
SET TIME ZONE 'America/Fortaleza';
SET ROLE locgestor_owner;

-- Alfa: mensalidade em dia (vence em 12 dias). Beta: vencida há 2 dias, ainda na carência de 5
-- (o admin da Beta vê o aviso de mensalidade vencida, mas continua usando o sistema).
INSERT INTO tenants (slug, nome, cnpj, email_contato, telefone, plano, limite_usuarios, plano_id, vencimento, carencia_dias)
SELECT x.slug, x.nome, x.cnpj, x.email, x.tel, p.nome, p.limite_usuarios, p.id, CURRENT_DATE + x.dias, 5
  FROM (VALUES
    ('alfa-locacoes', 'Alfa Locações de Equipamentos', '11444777000161', 'contato@alfalocacoes.com.br', '(85) 3222-1000', 'Profissional', 12),
    ('beta-eventos',  'Beta Eventos e Locações',       '',               'contato@betaeventos.com.br',  '(85) 3333-2000', 'Básico',       -2)
  ) AS x(slug, nome, cnpj, email, tel, plano, dias)
  JOIN planos p ON p.nome = x.plano;

-- =====================================================================
-- ALFA LOCAÇÕES
-- =====================================================================
DO $$
DECLARE
    v_tenant   uuid := (SELECT id FROM tenants WHERE slug = 'alfa-locacoes');
    v_admin    uuid;
    v_clientes uuid[];
    v_formas   TEXT[] := ARRAY['Dinheiro', 'PIX', 'PIX', 'PIX', 'Cartão de débito', 'Cartão de crédito', 'Boleto', 'Transferência'];
    v_total_loc CONSTANT INTEGER := 140;
    i          INTEGER;
    m          INTEGER;
    v_cli      uuid;
    v_saida    DATE;
    v_prev     DATE;
    v_dev      DATE;
    v_loc      uuid;
    v_devol    uuid;
    v_desc     NUMERIC(12,2);
    v_avaria   NUMERIC(12,2);
    v_valor    NUMERIC(12,2);
    v_mes      DATE;
    v_num      INTEGER;
    p          RECORD;
    li         RECORD;
BEGIN
    -- A partir daqui, tudo grava e lê só dentro da Alfa (RLS)
    PERFORM set_config('app.tenant_id', v_tenant::TEXT, false);
    PERFORM provisionar_dados_padrao('Alfa Locações de Equipamentos Ltda');

    UPDATE empresa SET nome_fantasia = 'Alfa Locações', cnpj = '11444777000161', ie = '06.123456-7',
           telefone = '(85) 3222-1000', email = 'contato@alfalocacoes.com.br',
           endereco = 'Av. Bezerra de Menezes, 1500 - São Gerardo', cidade = 'Fortaleza', uf = 'CE', cep = '60325-001',
           pix_chave = '11444777000161', pix_nome = 'ALFA LOCACOES', pix_cidade = 'FORTALEZA';

    -- admin / admin123  (hash gerado com: php -r "echo password_hash('admin123', PASSWORD_DEFAULT);")
    INSERT INTO usuarios (nome, login, senha_hash, perfil)
    VALUES ('Administrador', 'admin', '$2y$12$UIs8S4Cb6Ad3l13vogp84elrC8NgCI/mLUEOyUz4Fmk/hj8ujgN/G', 'admin')
    RETURNING id INTO v_admin;

    -- Clientes do Ceará (CPF/CNPJ com dígitos verificadores válidos)
    INSERT INTO clientes (tipo, nome, cpf_cnpj, rg_ie, telefone, celular, email, cep, endereco, numero, bairro, cidade, uf, obs) VALUES
    ('PF', 'José Airton Holanda',           '52998224725',    '2001345678', '(85) 3274-1020', '(85) 98812-3344', 'airton.holanda@email.com', '60840-280', 'Rua Coronel Antônio Botelho', '215',  'Messejana',    'Fortaleza',         'CE', 'Pedreiro autônomo. Prefere PIX.'),
    ('PF', 'Maria Lúcia Bezerra',           '11144477735',    '2009876543', '',               '(85) 99701-5566', 'lucia.bezerra@email.com',  '61600-050', 'Rua Rui Barbosa',             '88',   'Centro',       'Caucaia',           'CE', ''),
    ('PF', 'Francisco das Chagas Pinheiro', '12345678909',    '9902345671', '(85) 3371-8890', '(85) 98620-7788', '',                         '61900-410', 'Avenida Carlos Jereissati',   '1450', 'Jereissati I', 'Maracanaú',         'CE', ''),
    ('PF', 'Antônia Rocha Sales',           '98765432100',    '2015566778', '(88) 3511-4321', '(88) 99645-1122', 'antonia.sales@email.com',  '63010-020', 'Rua São Pedro',               '602',  'Centro',       'Juazeiro do Norte', 'CE', ''),
    ('PJ', 'Construtora Jaguaribe Ltda',    '11222333000181', '061234567',  '(85) 3244-5000', '(85) 98100-2000', 'compras@jaguaribe.com.br', '60150-160', 'Avenida Santos Dumont',       '2828', 'Aldeota',      'Fortaleza',         'CE', 'Obras simultâneas. Faturamento quinzenal.'),
    ('PJ', 'Serralheria Cariri ME',         '45321876000178', '069876543',  '(88) 3512-7700', '(88) 99988-1100', 'serralheriacariri@email.com', '63040-000', 'Rua Padre Cícero',       '1200', 'Triângulo',    'Juazeiro do Norte', 'CE', '');
    v_clientes := ARRAY(SELECT id FROM clientes ORDER BY id);

    -- Produtos de obra
    INSERT INTO produtos (codigo, descricao, categoria_id, unidade, qtd_total, valor_diaria, valor_reposicao, ncm)
    SELECT x.cod, x.descr, c.id, x.un, x.qtd, x.diaria, x.repos, x.ncm
      FROM (VALUES
        ('AND-001', 'Andaime tubular 1,0 x 1,5 m (painel)', 'Andaimes e escoramento',    'PC', 80,  3.50,  280.00, '73084000'),
        ('ESC-300', 'Escora metálica regulável 3 m',        'Andaimes e escoramento',    'UN', 120, 1.20,  120.00, '73084000'),
        ('BET-400', 'Betoneira 400 L',                      'Compactação e concretagem', 'UN', 8,   45.00, 3800.00, '84743100'),
        ('MAR-10',  'Martelete rompedor 10 kg',             'Máquinas e ferramentas',    'UN', 6,   40.00, 2600.00, '84672999'),
        ('FUR-750', 'Furadeira de impacto 750 W',           'Máquinas e ferramentas',    'UN', 10,  12.00, 450.00,  '84672100'),
        ('SER-184', 'Serra circular 7 1/4" 1.800 W',        'Máquinas e ferramentas',    'UN', 6,   15.00, 650.00,  '84672200'),
        ('COM-SAP', 'Compactador de solo tipo sapo',        'Compactação e concretagem', 'UN', 4,   70.00, 9500.00, '84306990'),
        ('GER-6K',  'Gerador a gasolina 6 kVA',             'Energia e iluminação',      'UN', 4,   90.00, 5500.00, '85022011'),
        ('CAR-MAO', 'Carrinho de mão 60 L',                 'Compactação e concretagem', 'UN', 20,  5.00,  260.00,  '87168000'),
        ('ESD-7',   'Escada de alumínio 7 degraus',         'Máquinas e ferramentas',    'UN', 8,   10.00, 380.00,  '76169900')
      ) AS x(cod, descr, cat, un, qtd, diaria, repos, ncm)
      JOIN categorias c ON c.nome = x.cat;

    PERFORM setseed(0.42);

    -- ~140 locações devolvidas e pagas, em ordem cronológica (numeração crescente)
    FOR i IN 1..v_total_loc LOOP
        v_cli   := v_clientes[1 + floor(random() * array_length(v_clientes, 1))::INT];
        v_saida := CURRENT_DATE - 365 + floor((i - 1) * 350.0 / v_total_loc)::INT + floor(random() * 3)::INT;
        v_prev  := LEAST(v_saida + 1 + floor(random() * 14)::INT, CURRENT_DATE - 1);
        -- 15% das devoluções chegam com atraso de 1 a 4 dias (nunca depois de ontem)
        v_dev   := LEAST(v_prev + CASE WHEN random() < 0.15 THEN 1 + floor(random() * 4)::INT ELSE 0 END, CURRENT_DATE - 1);
        v_desc  := 0;
        v_avaria := CASE WHEN random() < 0.05 THEN round((30 + random() * 170)::NUMERIC, 0) ELSE 0 END;
        v_num   := proximo_numero('locacao');

        INSERT INTO locacoes (numero, cliente_id, data_saida, data_prev_devolucao, status, usuario_id, criado_em)
        VALUES (v_num, v_cli, v_saida, v_prev, 'devolvida', v_admin, v_saida + TIME '08:00' + random() * INTERVAL '9 hours')
        RETURNING id INTO v_loc;

        -- 1 a 3 produtos diferentes por locação
        FOR p IN SELECT id, qtd_total, valor_diaria FROM produtos
                  ORDER BY random() LIMIT 1 + floor(random() * 3)::INT LOOP
            INSERT INTO locacao_itens (locacao_id, produto_id, quantidade, valor_diaria)
            VALUES (v_loc, p.id, 1 + floor(random() * GREATEST(1, p.qtd_total / 4))::INT, p.valor_diaria);
        END LOOP;

        -- Desconto ocasional em locações maiores
        SELECT SUM(quantidade * valor_diaria * GREATEST(1, v_prev - v_saida)) INTO v_valor
          FROM locacao_itens WHERE locacao_id = v_loc;
        IF v_valor > 300 AND random() < 0.15 THEN
            v_desc := round((v_valor * 0.05)::NUMERIC, 0);
            UPDATE locacoes SET desconto = v_desc WHERE id = v_loc;
        END IF;

        -- Devolução total em uma única vez
        INSERT INTO devolucoes (locacao_id, data, avaria, obs, usuario_id, criado_em)
        VALUES (v_loc, v_dev, v_avaria,
                CASE WHEN v_avaria > 0 THEN 'Equipamento devolvido com avaria.' ELSE '' END,
                v_admin, v_dev + TIME '16:00')
        RETURNING id INTO v_devol;

        FOR li IN SELECT id, quantidade, valor_diaria FROM locacao_itens WHERE locacao_id = v_loc LOOP
            INSERT INTO devolucao_itens (devolucao_id, locacao_item_id, quantidade, dias, valor)
            VALUES (v_devol, li.id, li.quantidade, GREATEST(1, v_dev - v_saida),
                    li.quantidade * li.valor_diaria * GREATEST(1, v_dev - v_saida));
        END LOOP;
        UPDATE locacao_itens SET qtd_devolvida = quantidade WHERE locacao_id = v_loc;

        -- Pagamento integral no ato da devolução
        SELECT valor_atual INTO v_valor FROM vw_locacao_valores WHERE locacao_id = v_loc;
        INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma, locacao_id, usuario_id)
        SELECT v_dev, 'E', 'Locação', 'Recebimento locação nº ' || v_num || ' - ' || c.nome, v_valor,
               v_formas[1 + floor(random() * array_length(v_formas, 1))::INT], v_loc, v_admin
          FROM clientes c WHERE c.id = v_cli;
    END LOOP;

    -- Despesas mensais dos últimos 12 meses (só até hoje)
    FOR m IN 0..11 LOOP
        v_mes := (date_trunc('month', CURRENT_DATE) - m * INTERVAL '1 month')::DATE;
        INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma, usuario_id)
        SELECT d, 'S', cat, descr, val, forma, v_admin
          FROM (VALUES
                (v_mes + 4,  'Aluguel',     'Aluguel do galpão',                1500.00,                                   'Boleto'),
                (v_mes + 4,  'Salários',    'Folha de pagamento',               2200.00,                                   'Transferência'),
                (v_mes + 9,  'Energia',     'Conta de energia',                 round((220 + random() * 120)::NUMERIC, 2), 'Boleto'),
                (v_mes + 14, 'Manutenção',  'Manutenção de equipamentos',       round((150 + random() * 450)::NUMERIC, 2), 'PIX'),
                (v_mes + 19, 'Combustível', 'Combustível - entregas e coletas', round((180 + random() * 140)::NUMERIC, 2), 'Cartão de débito')
          ) AS x(d, cat, descr, val, forma)
         WHERE d <= CURRENT_DATE;
    END LOOP;

    -- Locação em aberto 1 (ATRASADA): Construtora Jaguaribe, saiu há 10 dias, previsão há 3 dias
    v_cli := (SELECT id FROM clientes WHERE cpf_cnpj = '11222333000181');
    v_num := proximo_numero('locacao');
    INSERT INTO locacoes (numero, cliente_id, data_saida, data_prev_devolucao, local_uso, obs, usuario_id)
    VALUES (v_num, v_cli, CURRENT_DATE - 10, CURRENT_DATE - 3, 'Obra Residencial Parque das Dunas - Rua Ana Bilhar, 500',
            'Entrega feita pelo caminhão da locadora.', v_admin)
    RETURNING id INTO v_loc;
    INSERT INTO locacao_itens (locacao_id, produto_id, quantidade, valor_diaria)
    SELECT v_loc, id, x.q, valor_diaria FROM produtos
      JOIN (VALUES ('AND-001', 40), ('ESC-300', 30), ('BET-400', 1)) AS x(cod, q) ON x.cod = produtos.codigo;
    INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma, locacao_id, usuario_id)
    VALUES (CURRENT_DATE - 10, 'E', 'Locação', 'Adiantamento locação nº ' || v_num || ' - Construtora Jaguaribe Ltda',
            300.00, 'PIX', v_loc, v_admin);

    -- Locação em aberto 2 (no prazo, sem pagamento): José Airton, saiu há 2 dias, volta em 5
    v_cli := (SELECT id FROM clientes WHERE cpf_cnpj = '52998224725');
    v_num := proximo_numero('locacao');
    INSERT INTO locacoes (numero, cliente_id, data_saida, data_prev_devolucao, local_uso, usuario_id)
    VALUES (v_num, v_cli, CURRENT_DATE - 2, CURRENT_DATE + 5, 'Reforma - Rua Coronel Antônio Botelho, 215', v_admin)
    RETURNING id INTO v_loc;
    INSERT INTO locacao_itens (locacao_id, produto_id, quantidade, valor_diaria)
    SELECT v_loc, id, x.q, valor_diaria FROM produtos
      JOIN (VALUES ('MAR-10', 1), ('CAR-MAO', 2)) AS x(cod, q) ON x.cod = produtos.codigo;

    -- Locação em aberto 3 (vence amanhã, com acréscimo de frete e adiantamento): Serralheria Cariri
    v_cli := (SELECT id FROM clientes WHERE cpf_cnpj = '45321876000178');
    v_num := proximo_numero('locacao');
    INSERT INTO locacoes (numero, cliente_id, data_saida, data_prev_devolucao, local_uso, acrescimo, usuario_id)
    VALUES (v_num, v_cli, CURRENT_DATE - 1, CURRENT_DATE + 1, 'Galpão novo - Rua Padre Cícero, 1200', 80.00, v_admin)
    RETURNING id INTO v_loc;
    INSERT INTO locacao_itens (locacao_id, produto_id, quantidade, valor_diaria)
    SELECT v_loc, id, x.q, valor_diaria FROM produtos
      JOIN (VALUES ('GER-6K', 1), ('COM-SAP', 1)) AS x(cod, q) ON x.cod = produtos.codigo;
    INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma, locacao_id, usuario_id)
    VALUES (CURRENT_DATE - 1, 'E', 'Locação', 'Adiantamento locação nº ' || v_num || ' - Serralheria Cariri ME',
            200.00, 'Cartão de crédito', v_loc, v_admin);

    -- Preço semanal/mensal (vale para locações novas; as anteriores ficaram com o preço da época)
    UPDATE produtos pr SET valor_semanal = x.sem, valor_mensal = x.mes
      FROM (VALUES ('AND-001', 17.50, 52.00), ('ESC-300', 6.00, 18.00),
                   ('GER-6K', 450.00, 1400.00), ('COM-SAP', 350.00, 1100.00)) AS x(cod, sem, mes)
     WHERE pr.codigo = x.cod;

    -- Kit: andaime completo para 1 andar
    INSERT INTO kits (codigo, nome, obs) VALUES ('KIT-AND', 'Andaime completo (1 andar, 4 painéis)', 'Painéis e escoras para montar uma torre.')
    RETURNING id INTO v_devol;
    INSERT INTO kit_itens (kit_id, produto_id, quantidade)
    SELECT v_devol, id, x.q FROM produtos
      JOIN (VALUES ('AND-001', 4), ('ESC-300', 4)) AS x(cod, q) ON x.cod = produtos.codigo;

    -- Reserva futura: Francisco das Chagas, daqui a 7 dias, por 3 dias
    v_cli := (SELECT id FROM clientes WHERE cpf_cnpj = '12345678909');
    INSERT INTO reservas (numero, cliente_id, data_inicio, data_fim, obs, usuario_id)
    VALUES (proximo_numero('reserva'), v_cli, CURRENT_DATE + 7, CURRENT_DATE + 9, 'Concretagem da laje', v_admin)
    RETURNING id INTO v_loc;
    INSERT INTO reserva_itens (reserva_id, produto_id, quantidade)
    SELECT v_loc, id, x.q FROM produtos
      JOIN (VALUES ('BET-400', 2), ('CAR-MAO', 4), ('AND-001', 20)) AS x(cod, q) ON x.cod = produtos.codigo;

    PERFORM set_config('app.tenant_id', '', false);
END $$;

-- =====================================================================
-- BETA EVENTOS
-- =====================================================================
DO $$
DECLARE
    v_tenant   uuid := (SELECT id FROM tenants WHERE slug = 'beta-eventos');
    v_admin    uuid;
    v_clientes uuid[];
    i          INTEGER;
    m          INTEGER;
    v_cli      uuid;
    v_saida    DATE;
    v_prev     DATE;
    v_loc      uuid;
    v_devol    uuid;
    v_valor    NUMERIC(12,2);
    v_num      INTEGER;
    p          RECORD;
    li         RECORD;
BEGIN
    PERFORM set_config('app.tenant_id', v_tenant::TEXT, false);
    PERFORM provisionar_dados_padrao('Beta Eventos e Locações Ltda');

    UPDATE empresa SET nome_fantasia = 'Beta Eventos', telefone = '(85) 3333-2000', email = 'contato@betaeventos.com.br',
           endereco = 'Rua Tibúrcio Cavalcante, 900 - Meireles', cidade = 'Fortaleza', uf = 'CE', cep = '60125-100';

    INSERT INTO usuarios (nome, login, senha_hash, perfil)
    VALUES ('Administrador', 'admin', '$2y$12$UIs8S4Cb6Ad3l13vogp84elrC8NgCI/mLUEOyUz4Fmk/hj8ujgN/G', 'admin')
    RETURNING id INTO v_admin;

    -- Maria Lúcia Bezerra tem o MESMO CPF de uma cliente da Alfa: permitido, pois são locadoras diferentes
    INSERT INTO clientes (tipo, nome, cpf_cnpj, celular, email, cidade, uf) VALUES
    ('PF', 'Maria Lúcia Bezerra',         '11144477735',    '(85) 99701-5566', 'lucia.bezerra@email.com', 'Caucaia',   'CE'),
    ('PF', 'Ana Clara Moreira',           '31840765208',    '(85) 98877-6655', 'anaclara@email.com',      'Fortaleza', 'CE'),
    ('PJ', 'Buffet Sabor do Sertão Ltda', '27451936000195', '(85) 99123-4567', 'buffet@sabordosertao.com.br', 'Eusébio', 'CE');
    v_clientes := ARRAY(SELECT id FROM clientes ORDER BY id);

    -- GER-6K é o MESMO código de um produto da Alfa (com outro preço)
    INSERT INTO produtos (codigo, descricao, categoria_id, unidade, qtd_total, valor_diaria, valor_reposicao)
    SELECT x.cod, x.descr, c.id, x.un, x.qtd, x.diaria, x.repos
      FROM (VALUES
        ('MES-4C',  'Mesa plástica com 4 cadeiras', 'Eventos',              'JG', 60,  8.00,   220.00),
        ('TEN-5X5', 'Tenda pirâmide 5 x 5 m',       'Eventos',              'UN', 10,  120.00, 3200.00),
        ('TOA-RED', 'Toalha de mesa redonda',       'Eventos',              'UN', 100, 2.50,   45.00),
        ('GER-6K',  'Gerador silenciado 6 kVA',     'Energia e iluminação', 'UN', 3,   95.00,  6200.00)
      ) AS x(cod, descr, cat, un, qtd, diaria, repos)
      JOIN categorias c ON c.nome = x.cat;

    PERFORM setseed(0.17);

    -- 8 eventos nos últimos 3 meses, devolvidos e pagos
    FOR i IN 1..8 LOOP
        v_cli   := v_clientes[1 + floor(random() * array_length(v_clientes, 1))::INT];
        v_saida := CURRENT_DATE - 90 + (i - 1) * 10 + floor(random() * 3)::INT;
        v_prev  := v_saida + 1 + floor(random() * 2)::INT;
        v_num   := proximo_numero('locacao');

        INSERT INTO locacoes (numero, cliente_id, data_saida, data_prev_devolucao, status, usuario_id)
        VALUES (v_num, v_cli, v_saida, v_prev, 'devolvida', v_admin)
        RETURNING id INTO v_loc;

        FOR p IN SELECT id, qtd_total, valor_diaria FROM produtos ORDER BY random() LIMIT 2 LOOP
            INSERT INTO locacao_itens (locacao_id, produto_id, quantidade, qtd_devolvida, valor_diaria)
            VALUES (v_loc, p.id, 1 + floor(random() * GREATEST(1, p.qtd_total / 3))::INT, 0, p.valor_diaria);
        END LOOP;

        INSERT INTO devolucoes (locacao_id, data, usuario_id) VALUES (v_loc, v_prev, v_admin)
        RETURNING id INTO v_devol;
        FOR li IN SELECT id, quantidade, valor_diaria FROM locacao_itens WHERE locacao_id = v_loc LOOP
            INSERT INTO devolucao_itens (devolucao_id, locacao_item_id, quantidade, dias, valor)
            VALUES (v_devol, li.id, li.quantidade, GREATEST(1, v_prev - v_saida),
                    li.quantidade * li.valor_diaria * GREATEST(1, v_prev - v_saida));
        END LOOP;
        UPDATE locacao_itens SET qtd_devolvida = quantidade WHERE locacao_id = v_loc;

        SELECT valor_atual INTO v_valor FROM vw_locacao_valores WHERE locacao_id = v_loc;
        INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma, locacao_id, usuario_id)
        SELECT v_prev, 'E', 'Locação', 'Recebimento locação nº ' || v_num || ' - ' || c.nome, v_valor, 'PIX', v_loc, v_admin
          FROM clientes c WHERE c.id = v_cli;
    END LOOP;

    -- Aluguel do depósito dos últimos 3 meses
    FOR m IN 0..2 LOOP
        INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma, usuario_id)
        SELECT d, 'S', 'Aluguel', 'Aluguel do depósito', 900.00, 'Boleto', v_admin
          FROM (SELECT (date_trunc('month', CURRENT_DATE) - m * INTERVAL '1 month')::DATE + 4 AS d) x
         WHERE d <= CURRENT_DATE;
    END LOOP;

    -- Locação em aberto: Buffet, montagem ontem, recolhe em 2 dias
    v_cli := (SELECT id FROM clientes WHERE cpf_cnpj = '27451936000195');
    v_num := proximo_numero('locacao');
    INSERT INTO locacoes (numero, cliente_id, data_saida, data_prev_devolucao, local_uso, usuario_id)
    VALUES (v_num, v_cli, CURRENT_DATE - 1, CURRENT_DATE + 2, 'Aniversário - Sítio Lagoa Redonda', v_admin)
    RETURNING id INTO v_loc;
    INSERT INTO locacao_itens (locacao_id, produto_id, quantidade, valor_diaria)
    SELECT v_loc, id, x.q, valor_diaria FROM produtos
      JOIN (VALUES ('TEN-5X5', 2), ('MES-4C', 15), ('TOA-RED', 15)) AS x(cod, q) ON x.cod = produtos.codigo;

    PERFORM set_config('app.tenant_id', '', false);
END $$;

RESET ROLE;
