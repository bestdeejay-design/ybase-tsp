<?php
/**
 * Parse big cities (Moscow, SPb) with higher limits
 */

declare(strict_types=1);

require_once __DIR__ . '/Parser.php';

use YellParser\Database;

$db = Database::getInstance();
$parser = new YellParser();

// Big cities with their target limits per category
$cities = [
    ['slug' => 'moscow', 'name' => 'Москва', 'weight' => 699875, 'limit_per_category' => 30], // 30 per category for million+
    ['slug' => 'spb', 'name' => 'Санкт-Петербург', 'weight' => 309282, 'limit_per_category' => 20], // 20 per category for 300k+
];

// All 13 categories with Yell.ru mappings
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

echo "=== Parser: Big Cities (Moscow, SPb) ===\n";

foreach ($cities as $cityInfo) {
    echo "\n🏙️  {$cityInfo['name']} ({$cityInfo['slug']}) - {$cityInfo['limit_per_category']} на категорию\n";
    
    // Get city ID
    $stmt = $db->prepare("SELECT id FROM cities WHERE slug = ?");
    $stmt->execute([$cityInfo['slug']]);
    $cityData = $stmt->fetch();
    
    if (!$cityData) {
        echo "❌ Город {$cityInfo['slug']} не найден\n";
        continue;
    }
    
    $cityId = $cityData['id'];
    
    // Check progress
    $progress = $db->prepare("SELECT * FROM parsing_progress_one WHERE city_id = ?");
    $progress->execute([$cityId]);
    $progData = $progress->fetch();
    
    $parsedCats = [];
    if ($progData) {
        $parsedCats = json_decode($progData['categories_parsed'] ?? '[]', true);
        echo "   ⏳ Уже обработано: " . count($parsedCats) . " категорий\n";
    }
    
    $cityCount = 0;
    $cityErrors = 0;
    
    foreach ($cats as $cat) {
        // Skip already processed categories
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
        
        // Parse establishments with target limit
        $list = [];
        $attempts = 0;
        $maxAttempts = 3;
        
        while (empty($list) && $attempts < $maxAttempts) {
            $list = $parser->parseList($cityInfo['slug'], $cat['yell'], $cityInfo['limit_per_category']);
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
            // Check duplicate
            $exists = $db->prepare('SELECT 1 FROM companies WHERE yell_id = ? AND city_id = ?');
            $exists->execute([$item['yell_id'], $cityId]);
            if ($exists->fetch()) continue;
            
            // Parse details with retries
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
                $companyId = $parser->saveRestaurant($detail, $cityId, $cat['slug']);
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
        
        // Update progress
        $parsedCats[] = $cat['slug'];
        $db->prepare("
            INSERT INTO parsing_progress_one (city_id, city_slug, categories_parsed, total_parsed, last_attempt, status)
            VALUES (?, ?, ?, ?, NOW(), 'parsing')
            ON CONFLICT (city_id) 
            DO UPDATE SET 
                categories_parsed = ?, 
                total_parsed = parsing_progress_one.total_parsed + ?,
                last_attempt = NOW(),
                status = 'parsing'
        ")->execute([
            $cityId, $cityInfo['slug'], json_encode($parsedCats), $saved,
            json_encode($parsedCats), $saved
        ]);
    }
    
    // Update city status
    $status = ($cityErrors >= 3) ? 'failed' : 'completed';
    $db->prepare("
        UPDATE parsing_progress_one 
        SET status = ?, last_attempt = NOW() 
        WHERE city_id = ?
    ")->execute([$status, $cityId]);
    
    echo "   📊 Всего: {$cityCount} | Статус: {$status}\n";
}

echo "\n=== Завершено ===\n";