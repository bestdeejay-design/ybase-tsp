-- Добавляем поле для веса города (количество заведений на Yell.ru)
ALTER TABLE cities ADD COLUMN IF NOT EXISTS weight INTEGER DEFAULT 0;
ALTER TABLE cities ADD COLUMN IF NOT EXISTS total_establishments INTEGER DEFAULT 0;

-- Индекс для сортировки по весу
CREATE INDEX IF NOT EXISTS idx_cities_weight ON cities(weight DESC);
