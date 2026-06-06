<?php

require_once __DIR__ . '/parser.php';

use YellParser\Database;

$db = Database::getInstance();
$parser = new YellParser();

// Создаем таблицу для отслеживания прогресса парсинга (1 заведение на категорию)
$db->exec("
    CREATE TABLE IF NOT EXISTS parsing_progress_one (
        city_id INTEGER PRIMARY KEY,
        city_slug VARCHAR(100) NOT NULL,
        categories_parsed JSONB DEFAULT '[]',
        total_parsed INTEGER DEFAULT 0,
        last_attempt TIMESTAMP,
        status VARCHAR(20) DEFAULT 'pending',
        error_message TEXT
    )
");

// Все 13 категорий с маппингом Yell.ru
// ПРИМЕЧАНИЕ: категория 'raznoe' (Разное) НЕ включается в парсинг — она только для ручной сортировки
$cats = [
    ['yell' => 'restorany', 'slug' => 'restorany-i-kafe'],
    ['yell' => 'bary-i-kluby', 'slug' => 'bary-i-kluby'],
    ['yell' => 'supermarkety', 'slug' => 'magaziny'],
    ['yell' => 'salony-krasoty', 'slug' => 'krasota'],
    ['yell' => 'medicina', 'slug' => 'medicina'],
    ['yell' => 'sport-i-fitnes', 'slug' => 'sport'],
    ['yell' => 'razvlecheniya-i-otdyh', 'slug' => 'razvlecheniya'],
    ['yell' => 'obrazovanie', 'slug' => 'obrazovanie'],
    ['yell' => 'biznes-finansy-strahovanie', 'slug' => 'uslugi-biznes'],
    ['yell' => 'remontnye-servisy', 'slug' => 'uslugi-dom'],
    ['yell' => 'avto-moto', 'slug' => 'transport'],
    ['yell' => 'agentstva-nedvizhimosti', 'slug' => 'nedvizhimost'],
    ['yell' => 'gostinicy', 'slug' => 'oteli-arenda'],
];

// Берем следующий город (не Москву и не СПб)
$stmt = $db->query("
    SELECT c.id, c.slug, c.name 
    FROM cities c 
    WHERE c.slug NOT IN ('moscow', 'spb')
      AND NOT EXISTS (
          SELECT 1 FROM parsing_progress_one p 
          WHERE p.city_id = c.id AND p.status = 'completed'
      )
    ORDER BY c.id
    LIMIT 1
");

$cities = $stmt->fetchAll();

echo "=== Parser: 10 заведений на категорию ===\n";
echo "Городов к обработке: " . count($cities) . "\n\n";

$totalAdded = 0;
$failedCities = [];

foreach ($cities as $city) {
    echo "🏙️  {$city['name']} ({$city['slug']})\n";
    
    // Проверяем прогресс
    $progress = $db->prepare("SELECT * FROM parsing_progress_one WHERE city_id = ?");
    $progress->execute([$city['id']]);
    $progData = $progress->fetch();
    
    // Формируем URL города
    $cityUrl = "https://www.yell.ru/{$city['slug']}/";
    
    // Сохраняем URL города в таблицу cities
    $db->prepare("UPDATE cities SET yell_url = ? WHERE id = ? AND (yell_url IS NULL OR yell_url = '')")
       ->execute([$cityUrl, $city['id']]);
    
    if (!$progData) {
        $db->prepare("INSERT INTO parsing_progress_one (city_id, city_slug, status) VALUES (?, ?, 'parsing')")
           ->execute([$city['id'], $city['slug']]);
        $parsedCats = [];
        $categoryUrls = [];
    } else {
        $parsedCats = json_decode($progData['categories_parsed'] ?? '[]', true);
        $categoryUrls = json_decode($progData['category_urls'] ?? '{}', true);
        echo "   ⏳ Уже обработано: " . count($parsedCats) . " категорий\n";
    }
    
    $cityCount = 0;
    $cityErrors = 0;
    
    foreach ($cats as $cat) {
        // Пропускаем уже обработанные категории
        if (in_array($cat['slug'], $parsedCats)) {
            echo "   ✓ {$cat['slug']} (пропущено)\n";
            continue;
        }
        
        echo "   📁 {$cat['slug']}: ";
        
        $catId = $db->query("SELECT id FROM categories WHERE slug = '{$cat['slug']}'")->fetchColumn();
        if (!$catId) {
            echo "❌ нет категории\n";
            continue;
        }
        
        // Парсим 10 заведений
        $list = [];
        $attempts = 0;
        $maxAttempts = 3;
        
        while (empty($list) && $attempts < $maxAttempts) {
            $list = $parser->parseList($city['slug'], $cat['yell'], 10);
            if (empty($list)) {
                $attempts++;
                echo "(попытка {$attempts}/{$maxAttempts}) ";
                sleep(5 * $attempts);
            }
        }
        
        echo count($list) . " найдено\n";
        
        if (empty($list)) {
            $cityErrors++;
            continue;
        }
        
        $saved = 0;
        foreach ($list as $item) {
            // Проверяем дубликат
            $exists = $db->prepare('SELECT 1 FROM companies WHERE yell_id = ? AND city_id = ?');
            $exists->execute([$item['yell_id'], $city['id']]);
            if ($exists->fetch()) continue;
            
            // Парсим детали с повторными попытками
            $detail = null;
            $detailAttempts = 0;
            while (!$detail && $detailAttempts < 3) {
                $detail = $parser->parseDetail($item['url'], $item['yell_id']);
                if (!$detail) {
                    $detailAttempts++;
                    sleep(2);
                }
            }
            
            if (!$detail) {
                echo "      ⚠️  Не удалось спарсить {$item['yell_id']}\n";
                continue;
            }
            
            try {
                $companyId = $parser->saveRestaurant($detail, $city['id'], $cat['slug']);
                $db->prepare('INSERT INTO company_categories (company_id, category_id) VALUES (?, ?) ON CONFLICT DO NOTHING')
                   ->execute([$companyId, $catId]);
                $saved++;
                usleep(500000);
            } catch (Exception $e) {
                echo "      ⚠️  Ошибка сохранения: " . $e->getMessage() . "\n";
            }
        }
        
        echo "      ✅ Сохранено: {$saved}\n";
        $cityCount += $saved;
        
        // Сохраняем URL категории
        $categoryUrl = "https://www.yell.ru/{$city['slug']}/top/{$cat['yell']}/";
        $categoryUrls[$cat['slug']] = $categoryUrl;
        
        // Обновляем прогресс
        $parsedCats[] = $cat['slug'];
        $db->prepare("
            UPDATE parsing_progress_one 
            SET categories_parsed = ?, total_parsed = total_parsed + ?, last_attempt = NOW()
            WHERE city_id = ?
        ")->execute([json_encode($parsedCats), $saved, $city['id']]);
    }
    
    // Обновляем статус города
    $status = ($cityErrors >= 3) ? 'failed' : 'completed';
    $db->prepare("
        UPDATE parsing_progress_one 
        SET status = ?, last_attempt = NOW() 
        WHERE city_id = ?
    ")->execute([$status, $city['id']]);
    
    echo "   📊 Всего: {$cityCount} | Статус: {$status}\n\n";
    $totalAdded += $cityCount;
    
    if ($status === 'failed') {
        $failedCities[] = $city['name'];
    }
    
    // Пауза между городами
    sleep(3);
}

echo "\n=== ИТОГО ===\n";
echo "Добавлено заведений: {$totalAdded}\n";
echo "Неудачных городов: " . count($failedCities) . "\n";
if (!empty($failedCities)) {
    echo "Список: " . implode(', ', $failedCities) . "\n";
}

// Показываем статистику
$stats = $db->query("
    SELECT 
        status,
        COUNT(*) as count
    FROM parsing_progress
    GROUP BY status
")->fetchAll();

echo "\nСтатус парсинга:\n";
foreach ($stats as $stat) {
    echo "  {$stat['status']}: {$stat['count']}\n";
}
