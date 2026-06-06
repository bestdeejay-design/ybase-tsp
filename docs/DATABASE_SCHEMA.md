# Схема базы данных

## Таблицы

### cities
Города для парсинга

| Поле | Тип | Описание |
|------|-----|----------|
| id | SERIAL PK | ID города |
| name | VARCHAR(100) | Название города |
| slug | VARCHAR(50) UNIQUE | URL-slug города |
| yell_url | TEXT | URL на yell.ru |
| weight | INTEGER | Вес для сортировки |
| created_at | TIMESTAMP | Дата создания |

**Индексы**: slug (unique)

### categories
Основные категории каталога

| Поле | Тип | Описание |
|------|-----|----------|
| id | SERIAL PK | ID категории |
| name | VARCHAR(100) | Название категории |
| slug | VARCHAR(50) UNIQUE | URL-slug категории |
| icon | VARCHAR(50) | Иконка категории |
| created_at | TIMESTAMP | Дата создания |

**Категории**:
1. restorany-i-kafe (Рестораны и кафе)
2. bary-i-kluby (Бары и клубы)
3. magaziny (Магазины)
4. krasota (Красота)
5. medicina (Медицина)
6. sport (Спорт)
7. obrazovanie (Образование)
8. razvlecheniya (Развлечения)
9. uslugi-dlya-biznesa (Услуги для бизнеса)
10. uslugi-dlya-doma (Услуги для дома)
11. nedvizhimost (Недвижимость)
12. transport (Транспорт)

### companies
Заведения (компании)

| Поле | Тип | Описание |
|------|-----|----------|
| id | SERIAL PK | ID заведения |
| name | VARCHAR(255) | Название |
| address | TEXT | Адрес |
| phone | VARCHAR(255) | Телефон (JSON array) |
| website | VARCHAR(255) | Сайт |
| description | TEXT | Описание |
| rating | DECIMAL(3,2) | Рейтинг (0-5) |
| reviews_count | INTEGER | Количество отзывов |
| city_id | INTEGER FK | ID города |
| source | VARCHAR(50) | Источник (yell.ru, 2gis, etc) |
| source_url | TEXT | URL источника |
| menu | TEXT | Меню |
| social_links | TEXT | Социальные ссылки (JSON) |
| created_at | TIMESTAMP | Дата создания |
| updated_at | TIMESTAMP | Дата обновления |

**Индексы**: city_id, name, source

### company_categories
Связь many-to-many между компаниями и категориями

| Поле | Тип | Описание |
|------|-----|----------|
| company_id | INTEGER FK | ID компании |
| category_id | INTEGER FK | ID категории |

**PK**: (company_id, category_id)

### company_phones
Телефоны компаний (нормализованные)

| Поле | Тип | Описание |
|------|-----|----------|
| id | SERIAL PK | ID |
| company_id | INTEGER FK | ID компании |
| phone | VARCHAR(20) | Номер телефона |
| is_main | BOOLEAN | Основной ли |

### company_photos
Фотографии заведений

| Поле | Тип | Описание |
|------|-----|----------|
| id | SERIAL PK | ID |
| company_id | INTEGER FK | ID компании |
| url | TEXT | URL фото |
| caption | VARCHAR(255) | Подпись |

### parsing_progress
Прогресс парсинга по городам и категориям

| Поле | Тип | Описание |
|------|-----|----------|
| id | SERIAL PK | ID |
| city_id | INTEGER FK | ID города |
| category_id | INTEGER FK | ID категории |
| page | INTEGER | Текущая страница |
| processed | INTEGER | Обработано записей |
| saved | INTEGER | Сохранено записей |
| last_updated | TIMESTAMP | Последнее обновление |

### description_normalization_log
Лог нормализации описаний

| Поле | Тип | Описание |
|------|-----|----------|
| id | SERIAL PK | ID |
| company_id | INTEGER FK | ID компании |
| original_length | INTEGER | Длина оригинала |
| normalized_length | INTEGER | Длина после нормализации |
| normalized_at | TIMESTAMP | Дата нормализации |

## ER-диаграмма

```
cities ||--o{ companies : has
categories ||--o{ company_categories : has
companies ||--o{ company_categories : belongs_to
companies ||--o{ company_phones : has
companies ||--o{ company_photos : has
companies ||--o{ parsing_progress : tracks
```

## SQL для создания

См. `database/init.sql` для полной схемы.

## Примеры запросов

### Список заведений по городу и категории
```sql
SELECT c.*, cat.name as category_name
FROM companies c
JOIN company_categories cc ON cc.company_id = c.id
JOIN categories cat ON cat.id = cc.category_id
JOIN cities city ON city.id = c.city_id
WHERE city.slug = 'moscow' 
  AND cat.slug = 'restorany-i-kafe'
ORDER BY c.rating DESC;
```

### Статистика по городам
```sql
SELECT 
  city.name,
  COUNT(DISTINCT c.id) as total_companies,
  COUNT(DISTINCT CASE WHEN cat.slug = 'restorany-i-kafe' THEN c.id END) as restaurants
FROM cities city
LEFT JOIN companies c ON c.city_id = city.id
LEFT JOIN company_categories cc ON cc.company_id = c.id
LEFT JOIN categories cat ON cat.id = cc.category_id
GROUP BY city.id, city.name
ORDER BY total_companies DESC;
```

### Поиск по телефону
```sql
SELECT c.name, c.phone, city.name as city
FROM companies c
JOIN cities city ON city.id = c.city_id
WHERE c.phone LIKE '%7911%';
```
