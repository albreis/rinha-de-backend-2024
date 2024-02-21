-- Criar a tabela clientes
CREATE TABLE clientes (
    id SERIAL PRIMARY KEY,
    limite INTEGER NOT NULL,
    saldo_inicial INTEGER NOT NULL,
    saldo_atual INTEGER NOT NULL
);

-- Inserir os dados na tabela clientes
INSERT INTO clientes (limite, saldo_inicial, saldo_atual) VALUES
(100000, 0, 0),
(80000, 0, 0),
(1000000, 0, 0),
(10000000, 0, 0),
(500000, 0, 0);

-- Tabela extratos
CREATE TABLE extratos (
    id SERIAL PRIMARY KEY,
    cliente_id INTEGER NOT NULL,
    total INTEGER NOT NULL,
    data DATE NOT NULL,
    limite INTEGER NOT NULL,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id)
);

-- Tabela transacoes
CREATE TABLE transacoes (
    id SERIAL PRIMARY KEY,
    cliente_id INTEGER NOT NULL,
    valor INTEGER NOT NULL,
    tipo CHAR(1) CHECK (tipo IN ('c', 'd')) NOT NULL,
    descricao VARCHAR(255),
    realizada_em TIMESTAMP NOT NULL,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id)
);
