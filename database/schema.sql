CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS members (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL UNIQUE COLLATE NOCASE,
    alias      TEXT,
    active     INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

-- Each row = one package purchase. quota is stored per row so changing
-- "kuota per paket" later does not rewrite history.
CREATE TABLE IF NOT EXISTS payments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    paid_at    TEXT,
    member_id  INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    packages   INTEGER NOT NULL DEFAULT 1,
    quota      INTEGER NOT NULL,
    amount     INTEGER NOT NULL DEFAULT 0,
    method     TEXT,
    note       TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

-- Each row = one quota deduction. type: play (main pakai kuota) | refund (refund/cashback)
CREATE TABLE IF NOT EXISTS usages (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    played_at  TEXT,
    member_id  INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    type       TEXT NOT NULL DEFAULT 'play' CHECK (type IN ('play', 'refund')),
    source     TEXT,
    note       TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

CREATE INDEX IF NOT EXISTS idx_payments_member ON payments(member_id);
CREATE INDEX IF NOT EXISTS idx_usages_member ON usages(member_id);
CREATE INDEX IF NOT EXISTS idx_usages_date ON usages(played_at);

-- Manual income & expenses (member package payments come from `payments`).
CREATE TABLE IF NOT EXISTS transactions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    tx_date     TEXT NOT NULL,
    type        TEXT NOT NULL CHECK (type IN ('income', 'expense')),
    category    TEXT NOT NULL,
    description TEXT,
    amount      INTEGER NOT NULL CHECK (amount >= 0),
    note        TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

CREATE INDEX IF NOT EXISTS idx_transactions_date ON transactions(tx_date);
