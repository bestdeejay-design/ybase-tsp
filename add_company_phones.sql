-- Таблица для дополнительных телефонов заведений
CREATE TABLE IF NOT EXISTS company_phones (
    id SERIAL PRIMARY KEY,
    company_id INTEGER REFERENCES companies(id) ON DELETE CASCADE,
    phone VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(company_id, phone)
);

-- Индекс для быстрого поиска по телефону
CREATE INDEX IF NOT EXISTS idx_company_phones_phone ON company_phones(phone);
