-- Создание схемы базы данных для парсера yell.ru

-- Таблица категорий
CREATE TABLE IF NOT EXISTS categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    parent_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
    yell_url TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Таблица городов
CREATE TABLE IF NOT EXISTS cities (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    region VARCHAR(255),
    yell_url TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Таблица компаний
CREATE TABLE IF NOT EXISTS companies (
    id SERIAL PRIMARY KEY,
    yell_id VARCHAR(100) UNIQUE,
    name VARCHAR(500) NOT NULL,
    description TEXT,
    address TEXT,
    city_id INTEGER REFERENCES cities(id) ON DELETE SET NULL,
    phone VARCHAR(50),
    email VARCHAR(255),
    website TEXT,
    rating DECIMAL(2,1),
    review_count INTEGER DEFAULT 0,
    yell_url TEXT UNIQUE,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    working_hours JSONB,
    -- Дополнительные поля для ресторанов
    metro_station VARCHAR(255),
    metro_distance VARCHAR(100),
    business_lunch BOOLEAN DEFAULT FALSE,
    delivery BOOLEAN DEFAULT FALSE,
    takeaway BOOLEAN DEFAULT FALSE,
    wifi BOOLEAN DEFAULT FALSE,
    parking BOOLEAN DEFAULT FALSE,
    payment_cards BOOLEAN DEFAULT FALSE,
    features JSONB,
    raw_data JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    parsed_at TIMESTAMP
);

-- Таблица связи компаний и категорий (многие-ко-многим)
CREATE TABLE IF NOT EXISTS company_categories (
    company_id INTEGER REFERENCES companies(id) ON DELETE CASCADE,
    category_id INTEGER REFERENCES categories(id) ON DELETE CASCADE,
    PRIMARY KEY (company_id, category_id)
);

-- Таблица отзывов
CREATE TABLE IF NOT EXISTS reviews (
    id SERIAL PRIMARY KEY,
    company_id INTEGER REFERENCES companies(id) ON DELETE CASCADE,
    author_name VARCHAR(255),
    rating INTEGER CHECK (rating >= 1 AND rating <= 5),
    text TEXT,
    date DATE,
    source VARCHAR(50) DEFAULT 'yell',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Таблица логов парсинга
CREATE TABLE IF NOT EXISTS parse_logs (
    id SERIAL PRIMARY KEY,
    task_type VARCHAR(50) NOT NULL,
    status VARCHAR(50) NOT NULL,
    message TEXT,
    items_parsed INTEGER DEFAULT 0,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP
);

-- Индексы для оптимизации
CREATE INDEX IF NOT EXISTS idx_companies_city ON companies(city_id);
CREATE INDEX IF NOT EXISTS idx_companies_rating ON companies(rating);
CREATE INDEX IF NOT EXISTS idx_companies_yell_id ON companies(yell_id);
CREATE INDEX IF NOT EXISTS idx_reviews_company ON reviews(company_id);
CREATE INDEX IF NOT EXISTS idx_company_categories_company ON company_categories(company_id);
CREATE INDEX IF NOT EXISTS idx_company_categories_category ON company_categories(category_id);

-- Функция для обновления updated_at
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Триггеры для автоматического обновления updated_at
CREATE TRIGGER update_companies_updated_at BEFORE UPDATE ON companies
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_categories_updated_at BEFORE UPDATE ON categories
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
