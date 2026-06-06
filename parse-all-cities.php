<?php
/**
 * Parse all cities for all 12 categories
 * Usage: php parse-all-cities.php [city_limit] [category_limit]
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use YellParser\Database;

// Get all cities from DB - DESCENDING order (largest first)
$db = Database::getInstance();
$cities = $db->query("SELECT slug, name, weight FROM cities WHERE weight > 0 ORDER BY weight DESC")->fetchAll();

// Define 12 main categories with their Yell.ru slugs
$categories = [
    ['slug' => 'restorany', 'name' => 'Рестораны и кафе'],
    ['slug' => 'bary-i-kluby', 'name' => 'Бары и клубы'],
    ['slug' => 'magaziny', 'name' => 'Магазины'],
    ['slug' => 'salony-krasoty', 'name' => 'Красота'],
    ['slug' => 'medicina', 'name' => 'Медицина'],
    ['slug' => 'fitnes-kluby', 'name' => 'Спорт'],
    ['slug' => 'razvlecheniya-i-otdyh', 'name' => 'Развлечения'],
    ['slug' => 'obrazovanie', 'name' => 'Образование'],
    ['slug' => 'uslugi', 'name' => 'Услуги для бизнеса'],
    ['slug' => 'remont-bytovoy-tekhniki', 'name' => 'Услуги для дома'],
    ['slug' => 'avtoservisy-i-tyuning', 'name' => 'Транспорт'],
    ['slug' => 'agentstva-nedvizhimosti', 'name' => 'Недвижимость'],
];

echo "=== Mass Parsing: " . count($cities) . " cities x " . count($categories) . " categories ===\n\n";

$totalStats = [
    'cities_processed' => 0,
    'categories_processed' => 0,
    'establishments_saved' => 0,
    'establishments_skipped' => 0,
    'errors' => 0,
];

foreach ($cities as $cityIndex => $city) {
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "[" . ($cityIndex + 1) . "/" . count($cities) . "] City: {$city['name']}\n";
    echo "Weight: {$city['weight']}, Slug: {$city['slug']}\n";
    echo str_repeat("=", 60) . "\n";
    
    $cityStats = [
        'saved' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];
    
    foreach ($categories as $catIndex => $category) {
        echo "\n  [" . ($catIndex + 1) . "/" . count($categories) . "] Category: {$category['name']}\n";
        
        // Run parser
        $cmd = sprintf(
            'cd /var/www && php src/Parser.php parse-city %s %s 2>&1',
            escapeshellarg($city['slug']),
            escapeshellarg($category['slug'])
        );
        
        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);
        
        // Parse output for stats
        $outputStr = implode("\n", $output);
        
        if (preg_match('/✅ Saved: (\d+)/', $outputStr, $m)) {
            $saved = (int) $m[1];
            $cityStats['saved'] += $saved;
            $totalStats['establishments_saved'] += $saved;
        }
        
        if (preg_match('/⏭️ Skipped.*?:(\d+)/', $outputStr, $m)) {
            $skipped = (int) $m[1];
            $cityStats['skipped'] += $skipped;
            $totalStats['establishments_skipped'] += $skipped;
        }
        
        if ($returnCode !== 0 || str_contains($outputStr, '❌')) {
            $cityStats['errors']++;
            $totalStats['errors']++;
        }
        
        // Show brief result
        if (preg_match('/✅ Saved: (\d+).*?target was (\d+)/', $outputStr, $m)) {
            echo "    Result: {$m[1]}/{$m[2]} saved\n";
        } elseif (str_contains($outputStr, 'No restaurants found')) {
            echo "    Result: No establishments found\n";
        }
        
        $totalStats['categories_processed']++;
        
        // Small delay between categories
        sleep(1);
    }
    
    echo "\n  City summary for {$city['name']}:\n";
    echo "  ✅ Saved: {$cityStats['saved']}\n";
    echo "  ⏭️ Skipped: {$cityStats['skipped']}\n";
    echo "  ❌ Errors: {$cityStats['errors']}\n";
    
    $totalStats['cities_processed']++;
    
    // Delay between cities
    sleep(1);
}

echo "\n\n" . str_repeat("=", 60) . "\n";
echo "=== FINAL STATISTICS ===\n";
echo str_repeat("=", 60) . "\n";
echo "Cities processed: {$totalStats['cities_processed']}\n";
echo "Categories processed: {$totalStats['categories_processed']}\n";
echo "✅ Total establishments saved: {$totalStats['establishments_saved']}\n";
echo "⏭️ Total establishments skipped: {$totalStats['establishments_skipped']}\n";
echo "❌ Total errors: {$totalStats['errors']}\n";
echo str_repeat("=", 60) . "\n";
