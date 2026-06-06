# Руководство по выгрузке данных

## Подготовка к выгрузке

### 1. Проверка данных

```bash
# Подключение к БД
docker compose exec postgres psql -U parser_user -d yell_parser

# Статистика по источникам
SELECT source, COUNT(*) as count 
FROM companies 
GROUP BY source 
ORDER BY count DESC;

# Статистика по городам
SELECT c.name, COUNT(comp.id) as companies
FROM cities c
LEFT JOIN companies comp ON comp.city_id = c.id
GROUP BY c.id, c.name
ORDER BY companies DESC
LIMIT 20;

# Статистика по категориям
SELECT cat.name, COUNT(cc.company_id) as count
FROM categories cat
LEFT JOIN company_categories cc ON cc.category_id = cat.id
GROUP BY cat.id, cat.name
ORDER BY count DESC;
```

### 2. Очистка тестовых данных

```sql
-- Удалить заведения без категорий
DELETE FROM companies 
WHERE id NOT IN (SELECT company_id FROM company_categories);

-- Удалить дубликаты (оставить только первое)
DELETE FROM companies a
USING companies b
WHERE a.id > b.id 
  AND a.name = b.name 
  AND a.city_id = b.city_id;
```

## Форматы выгрузки

### SQL Dump (рекомендуется)

```bash
# Полный дамп
docker compose exec postgres pg_dump -U parser_user -d yell_parser > backup_$(date +%Y%m%d).sql

# Только данные (без структуры)
docker compose exec postgres pg_dump -U parser_user -d yell_parser --data-only > data_only_$(date +%Y%m%d).sql

# Только companies и связи
docker compose exec postgres pg_dump -U parser_user -d yell_parser \
  --table=companies \
  --table=company_categories \
  --table=cities \
  --table=categories > catalog_data_$(date +%Y%m%d).sql
```

### JSON

```bash
# Создать скрипт выгрузки
cat > /tmp/export_json.sql << 'EOF'
COPY (
  SELECT json_agg(
    json_build_object(
      'id', c.id,
      'name', c.name,
      'address', c.address,
      'phone', c.phone,
      'website', c.website,
      'description', c.description,
      'rating', c.rating,
      'reviews_count', c.reviews_count,
      'city', city.name,
      'categories', (
        SELECT json_agg(cat.name)
        FROM company_categories cc
        JOIN categories cat ON cat.id = cc.category_id
        WHERE cc.company_id = c.id
      ),
      'source', c.source,
      'source_url', c.source_url,
      'created_at', c.created_at
    )
  )
  FROM companies c
  JOIN cities city ON city.id = c.city_id
  WHERE c.source = 'yell.ru'
) TO STDOUT;
EOF

# Выполнить выгрузку
docker compose exec -T postgres psql -U parser_user -d yell_parser -f /tmp/export_json.sql > catalog_$(date +%Y%m%d).json
```

### CSV

```bash
# Создать скрипт выгрузки
cat > /tmp/export_csv.sql << 'EOF'
COPY (
  SELECT 
    c.id,
    c.name,
    c.address,
    c.phone,
    c.website,
    c.rating,
    c.reviews_count,
    city.name as city,
    string_agg(cat.name, ', ') as categories,
    c.source,
    c.source_url
  FROM companies c
  JOIN cities city ON city.id = c.city_id
  LEFT JOIN company_categories cc ON cc.company_id = c.id
  LEFT JOIN categories cat ON cat.id = cc.category_id
  WHERE c.source = 'yell.ru'
  GROUP BY c.id, city.name
  ORDER BY city.name, c.name
) TO STDOUT WITH CSV HEADER;
EOF

# Выполнить выгрузку
docker compose exec -T postgres psql -U parser_user -d yell_parser -f /tmp/export_csv.sql > catalog_$(date +%Y%m%d).csv
```

## Структура выгружаемых данных

### Поля

| Поле | Тип | Описание |
|------|-----|----------|
| id | integer | Уникальный ID |
| name | string | Название заведения |
| address | string | Адрес |
| phone | string | Телефон(ы) |
| website | string | Сайт |
| description | string | Описание |
| rating | decimal | Рейтинг (0-5) |
| reviews_count | integer | Количество отзывов |
| city | string | Город |
| categories | array/string | Категории |
| source | string | Источник данных |
| source_url | string | URL источника |

### Фильтрация

#### Только с телефонами
```sql
WHERE phone IS NOT NULL AND phone != ''
```

#### Только с рейтингом >= 4
```sql
WHERE rating >= 4.0
```

#### Только рестораны
```sql
WHERE cat.slug = 'restorany-i-kafe'
```

#### По городу
```sql
WHERE city.slug = 'moscow'
```

## Перенос на другой сервер

### 1. Экспорт
```bash
docker compose exec postgres pg_dump -U parser_user -d yell_parser > full_backup.sql
```

### 2. Импорт
```bash
# На новом сервере
docker compose up -d postgres
sleep 5
docker compose exec -T postgres psql -U parser_user -d yell_parser < full_backup.sql
```

## Резервное копирование

### Автоматический бэкап
```bash
# Добавить в crontab (ежедневно в 3:00)
0 3 * * * cd /path/to/project && docker compose exec postgres pg_dump -U parser_user -d yell_parser > backups/backup_$(date +\%Y\%m\%d).sql 2>&1
```

### Хранение бэкапов
```bash
# Хранить последние 7 дней
find backups/ -name "backup_*.sql" -mtime +7 -delete
```

## Проверка целостности

### После выгрузки
```sql
-- Количество записей
SELECT COUNT(*) FROM companies;

-- Проверка связей
SELECT COUNT(*) FROM company_categories 
WHERE company_id NOT IN (SELECT id FROM companies);

-- Дубликаты
SELECT name, city_id, COUNT(*) 
FROM companies 
GROUP BY name, city_id 
HAVING COUNT(*) > 1;
```

## Размеры данных

### Ориентировочные размеры
- SQL dump: ~50-100 MB
- JSON: ~100-200 MB
- CSV: ~30-50 MB

### Сжатие
```bash
gzip catalog_20240301.sql
# Размер уменьшится в 5-10 раз
```
