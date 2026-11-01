-- Recibos e estornos da mensalidade (documentos da plataforma para as locadoras).
-- 1) Recibo com número próprio, sequencial e sem buracos: o contador anda na mesma transação do pagamento.
-- 2) Estorno sem apagar: o pagamento continua no histórico, marcado como estornado, e o recibo não some.
-- Idempotente: pode rodar de novo sem efeito.

CREATE TABLE IF NOT EXISTS plataforma_sequencias (
    nome   VARCHAR(40) PRIMARY KEY,
    ultimo INTEGER     NOT NULL DEFAULT 0 CHECK (ultimo >= 0)
);
GRANT SELECT, INSERT, UPDATE ON plataforma_sequencias TO locgestor_plataforma;

ALTER TABLE assinatura_pagamentos ADD COLUMN IF NOT EXISTS recibo_numero   INTEGER UNIQUE;
ALTER TABLE assinatura_pagamentos ADD COLUMN IF NOT EXISTS estornado_em    TIMESTAMPTZ;
ALTER TABLE assinatura_pagamentos ADD COLUMN IF NOT EXISTS estorno_motivo  TEXT NOT NULL DEFAULT '';

-- Pagamentos já existentes ganham número na ordem em que foram registrados (quem já tem número não muda)
WITH numerados AS (
    SELECT id, row_number() OVER (ORDER BY criado_em, id) AS n
      FROM assinatura_pagamentos
     WHERE recibo_numero IS NULL
)
UPDATE assinatura_pagamentos p SET recibo_numero = numerados.n FROM numerados WHERE p.id = numerados.id;

INSERT INTO plataforma_sequencias (nome, ultimo)
VALUES ('recibo', (SELECT COALESCE(max(recibo_numero), 0) FROM assinatura_pagamentos))
ON CONFLICT (nome) DO UPDATE SET ultimo = GREATEST(plataforma_sequencias.ultimo, EXCLUDED.ultimo);

-- Pagamento não se apaga: o estorno é marcado (a aplicação não precisa mais de DELETE aqui)
REVOKE DELETE ON assinatura_pagamentos FROM locgestor_plataforma;
-- Só as colunas do estorno podem ser alteradas (menor privilégio)
GRANT UPDATE (estornado_em, estorno_motivo) ON assinatura_pagamentos TO locgestor_plataforma;
