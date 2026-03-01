# Документация по парсерам

## Yell.ru Parser (PHP)

### Файлы
- `src/Parser.php` - основной класс
- `public/parse-all-cities.php` - массовый парсинг
- `public/parse-one-per-city.php` - парсинг одного города

### Методы

#### parseListPage($html, $citySlug, $categorySlug)
Парсит страницу списка заведений.

**Параметры:**
- `$html` - HTML страницы
- `$citySlug` - slug города
- `$categorySlug` - slug категории

**Возвращает:** массив заведений с базовой информацией

#### parseDetailPage($url)
Парсит детальную страницу заведения.

**Параметры:**
- `$url` - URL страницы

**Возвращает:** массив с полной информацией
- name, description, address, phone
- website, email, schedule, photos
- rating, reviews_count, menu, social_links

#### saveEstablishment($data, $cityId, $categoryId)
Сохраняет заведение в базу данных.

**Параметры:**
- `$data` - данные заведения
- `$cityId` - ID города
- `$categoryId` - ID категории

**Возвращает:** boolean

#### isAdultContent($data)
Проверяет на нежелательный контент (стриптиз, кальяны, казино).

**Параметры:**
- `$data` - данные заведения

**Возвращает:** boolean

### Использование

#### Парсинг одного города
```bash
docker compose exec php php public/parse-one-per-city.php --city=moscow --category=restorany-i-kafe --limit=10
```

#### Массовый парсинг
```bash
docker compose exec php php public/parse-all-cities.php
```

### Особенности
- Поддерживает пагинацию
- Обрабатывает ошибки и таймауты
- Проверяет дубликаты по имени и городу
- Фильтрует по рейтингу (минимум 4.0) и отзывам (минимум 5)

## YP.RU Parser (PHP)

### Файлы
- `src/YpRuParser.php`
- `public/parse-yp-ru.php`

### Статус
⚠️ **Заблокирован DDoS-Guard** - требуется JavaScript challenge

### Проблемы
- HTTP-запросы блокируются (403 Forbidden)
- Требуется headless browser (Puppeteer/Playwright)
- Puppeteer также не проходит защиту

## 2GIS Parser (Node.js + Puppeteer)

### Файлы
- `puppeteer-parser/parser.js` - базовый скрипт
- `puppeteer-parser/parse-2gis-city.js` - парсинг города по категориям

### Методы

#### parse2GIS()
Парсит 2GIS для указанного города и категории.

**Переменные окружения:**
- `CITY` - slug города (по умолчанию: bologoe)
- `CATEGORY` - категория (по умолчанию: kafe)
- `LIMIT` - лимит заведений (по умолчанию: 10)

### Использование
```bash
docker compose run --rm -e CITY=vorkuta -e CATEGORY=kafe -e LIMIT=12 puppeteer node parse-2gis-city.js
```

### Проблемы
- ❌ Нет адресов (селекторы не работают)
- ❌ Website ведёт на redirect.2gis.com
- ✅ Телефоны есть (полные)
- ✅ Названия есть

### Рекомендация
Не использовать для полноценного парсинга - данные неполные.

## DescriptionNormalizer (PHP)

### Файл
- `src/DescriptionNormalizer.php`

### Методы

#### normalize($html)
Нормализует HTML-описание.

**Параметры:**
- `$html` - HTML текст

**Возвращает:** очищенный текст

### Что делает
1. Удаляет HTML-теги
2. Удаляет социальные ссылки и футеры
3. Разбивает на параграфы
4. Удаляет дублирующиеся пробелы
5. Форматирует текст

### Использование
```php
$normalizer = new DescriptionNormalizer();
$cleanText = $normalizer->normalize($rawHtml);
```

## PhoneExtractor

### Файл
- `src/PhoneExtractor.php`

### Методы

#### extract($text)
Извлекает телефоны из текста.

**Параметры:**
- `$text` - текст для анализа

**Возвращает:** массив телефонов

#### normalize($phone)
Нормализует телефонный номер.

**Параметры:**
- `$phone` - номер телефона

**Возвращает:** нормализованный номер (+7XXXXXXXXXX)

## Category Mapping

### Файл
- `src/category-mapping.php`

### Назначение
Маппинг тегов Yell.ru на 12 основных категорий.

### Структура
```php
'category-slug' => [
    'name' => 'Название',
    'yell_tags' => ['tag1', 'tag2', ...]
]
```

## Проблемы и решения

### Yell.ru блокирует
**Решение**: Использовать задержки между запросами (2-3 сек)

### Телефоны скрыты
**Решение**: Парсить детальные страницы

### Дубликаты
**Решение**: Проверка по имени + городу перед сохранением

### Adult content
**Решение**: Фильтрация по ключевым словам (strip, кальян, казино)

## Рекомендации

1. **Yell.ru** - основной источник, стабильный
2. **2GIS** - только для телефонов (дополнительно)
3. **YP.RU/Zoon** - не использовать (блокировки)

## Мониторинг

### Логи парсинга
```bash
docker compose logs -f php
```

### Прогресс
Смотреть таблицу `parsing_progress` в БД

### Статистика
```sql
SELECT source, COUNT(*) FROM companies GROUP BY source;
```
