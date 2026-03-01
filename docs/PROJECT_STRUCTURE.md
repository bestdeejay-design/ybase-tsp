# Структура проекта

```
lovii/
├── docker-compose.yml          # Конфигурация Docker
├── Dockerfile                  # Dockerfile для PHP
├── nginx.conf                  # Конфигурация Nginx
├── composer.json               # Зависимости PHP
│
├── src/                        # Исходный код PHP
│   ├── Parser.php             # Основной парсер Yell.ru
│   ├── YpRuParser.php         # Парсер YP.RU
│   ├── DescriptionNormalizer.php  # Нормализатор описаний
│   ├── PhoneExtractor.php     # Извлечение телефонов
│   ├── Database.php           # Класс для работы с БД
│   ├── HttpClient.php         # HTTP клиент
│   ├── category-mapping.php   # Маппинг категорий
│   └── parser-cities.php      # Парсер городов
│
├── public/                     # Web-файлы и скрипты
│   ├── index.php              # Главная страница
│   ├── catalog.php            # Каталог заведений
│   ├── catalog-filtered.php   # Фильтрованный каталог
│   ├── parse-all-cities.php   # Массовый парсинг
│   ├── parse-one-per-city.php # Парсинг одного города
│   ├── parse-yp-ru.php        # Парсер YP.RU CLI
│   ├── review-moderated.php   # Перемодерация
│   └── longest-description.php # Поиск длинных описаний
│
├── templates/                  # HTML шаблоны
│   ├── base.php               # Базовый шаблон
│   ├── catalog.php            # Шаблон каталога
│   └── company_card.php       # Карточка заведения
│
├── puppeteer-parser/           # Node.js парсеры
│   ├── Dockerfile             # Dockerfile для Puppeteer
│   ├── package.json           # Зависимости Node.js
│   ├── parser.js              # Базовый парсер
│   └── parse-2gis-city.js     # Парсер 2GIS
│
├── database/                   # SQL скрипты
│   └── init.sql               # Инициализация БД
│
├── docs/                       # Документация
│   ├── PROJECT_OVERVIEW.md    # Обзор проекта
│   ├── DATABASE_SCHEMA.md     # Схема БД
│   ├── PARSERS.md             # Документация парсеров
│   ├── EXPORT_GUIDE.md        # Руководство по выгрузке
│   └── PROJECT_STRUCTURE.md   # Этот файл
│
├── logs/                       # Логи
│   └── parser.log
│
└── backups/                    # Резервные копии
    └── .gitkeep
```

## Описание директорий

### src/
Основной PHP код. Содержит классы для парсинга, нормализации и работы с БД.

**Ключевые файлы:**
- `Parser.php` - 2000+ строк, основная логика парсинга Yell.ru
- `DescriptionNormalizer.php` - очистка и форматирование текста
- `category-mapping.php` - маппинг 100+ тегов на 12 категорий

### public/
Скрипты для запуска парсеров и web-интерфейс.

**Ключевые файлы:**
- `parse-all-cities.php` - массовый парсинг 83 городов
- `catalog-filtered.php` - просмотр каталога с фильтрами

### puppeteer-parser/
Node.js скрипты для парсинга через headless browser.

**Статус:** ⚠️ Экспериментально, не используется в продакшене

### templates/
PHP шаблоны для отображения данных.

### database/
SQL скрипты для создания таблиц и начальных данных.

### docs/
Документация проекта.

## Ключевые файлы

### docker-compose.yml
Определяет 4 сервиса:
- `php` - PHP-FPM 8.2
- `web` - Nginx
- `postgres` - PostgreSQL 16
- `puppeteer` - Node.js + Puppeteer (опционально)

### src/Parser.php
Основной класс парсера. ~2000 строк.

**Основные методы:**
- `parseCityCategory()` - парсинг города по категории
- `parseListPage()` - парсинг списка
- `parseDetailPage()` - детальная страница
- `saveEstablishment()` - сохранение в БД
- `isAdultContent()` - фильтрация контента

### src/category-mapping.php
Маппинг категорий Yell.ru на 12 основных.

**Структура:**
```php
'restorany-i-kafe' => [
    'name' => 'Рестораны и кафе',
    'yell_tags' => [
        'restorany', 'kafe', 'bary', 
        'kofeyni', 'stolovye', ...
    ]
]
```

### public/parse-all-cities.php
Массовый парсинг всех городов.

**Логика:**
1. Получает список городов из БД
2. Для каждого города парсит 12 категорий
3. Сохраняет прогресс в БД
4. Логирует результаты

## База данных

### Таблицы
- `cities` - 83 города
- `categories` - 12 категорий
- `companies` - ~3000+ заведений
- `company_categories` - связи many-to-many

### Размер
- Ориентировочно: 100-200 MB
- SQL dump: ~50 MB

## Логи

### Файлы
- `logs/parser.log` - логи парсинга
- Docker logs - логи контейнеров

### Просмотр
```bash
# Логи парсера
tail -f logs/parser.log

# Логи Docker
docker compose logs -f php
```

## Зависимости

### PHP (composer.json)
- `guzzlehttp/guzzle` - HTTP клиент
- `symfony/dom-crawler` - HTML парсинг
- `symfony/css-selector` - CSS селекторы

### Node.js (package.json)
- `puppeteer` - Headless browser
- `pg` - PostgreSQL клиент

## Конфигурация

### Переменные окружения
```env
DB_HOST=postgres
DB_PORT=5432
DB_NAME=yell_parser
DB_USER=parser_user
DB_PASSWORD=parser_pass
```

### Настройки парсера
- Лимит: 15-100 заведений на категорию (зависит от размера города)
- Задержка: 2-3 сек между запросами
- Таймаут: 30 сек
- Повторы: 3 попытки при ошибке

## Разработка

### Добавление нового парсера
1. Создать класс в `src/`
2. Добавить скрипт запуска в `public/`
3. Обновить документацию

### Тестирование
```bash
# Парсинг одного города
docker compose exec php php public/parse-one-per-city.php --city=moscow --category=restorany-i-kafe --limit=5

# Проверка данных
docker compose exec postgres psql -U parser_user -d yell_parser -c "SELECT COUNT(*) FROM companies WHERE city_id = 1;"
```

## Деплой

### Требования
- Docker 20+
- Docker Compose 2+
- 4GB RAM
- 20GB диск

### Команды
```bash
# Запуск
docker compose up -d

# Остановка
docker compose down

# Пересборка
docker compose up -d --build
```
