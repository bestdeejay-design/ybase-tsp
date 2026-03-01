-- Добавляем колонку is_normalized для отметки о нормализации данных
ALTER TABLE companies ADD COLUMN IF NOT EXISTS is_normalized BOOLEAN DEFAULT FALSE;

-- Индекс для быстрого поиска ненормализованных заведений
CREATE INDEX IF NOT EXISTS idx_companies_is_normalized ON companies(is_normalized) WHERE is_normalized = FALSE;

-- Индекс для быстрого поиска нормализованных
CREATE INDEX IF NOT EXISTS idx_companies_normalized ON companies(is_normalized) WHERE is_normalized = TRUE;
