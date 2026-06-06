<?php
/**
 * Скрипт для парсинга YP.RU и обогащения каталога
 * Usage: php public/parse-yp-ru.php [command] [options]
 * 
 * Commands:
 *   update-cities     - Обновить список городов
 *   parse-city        - Спарсить конкретный город
 *   parse-all         - Спарсить все города
 * 
 * Examples:
 *   php public/parse-yp-ru.php update-cities
 *   php public/parse-yp-ru.php parse-city --city=msk --category=restorany-i-kafe
 *   php public/parse-yp-ru.php parse-all
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/YpRuParser.php';

use YellParser\Database;
use YellParser\YpRuParser;

// Parse command line arguments
$command = $argv[1] ?? 'help';

// Manual parsing for long options with values
$options = [];
for ($i = 2; $i < $argc; $i++) {
    if (str_starts_with($argv[$i], '--')) {
        $arg = substr($argv[$i], 2);
        if (str_contains($arg, '=')) {
            [$key, $value] = explode('=', $arg, 2);
            $options[$key] = $value;
        } else {
            $options[$arg] = true;
        }
    }
}

echo "=== YP.RU Parser ===\n\n";

try {
    $db = Database::getInstance();
    $parser = new YpRuParser($db);
    
    switch ($command) {
        case 'update-cities':
            echo "Updating cities from YP.RU...\n";
            $stats = $parser->updateCities();
            echo "Added: {$stats['added']}\n";
            echo "Updated: {$stats['updated']}\n";
            if (!empty($stats['errors'])) {
                echo "Errors:\n";
                foreach ($stats['errors'] as $error) {
                    echo "  - $error\n";
                }
            }
            break;
            
        case 'parse-city':
            $citySlug = $options['city'] ?? null;
            $categorySlug = $options['category'] ?? null;
            $limit = (int) ($options['limit'] ?? 15);
            $dryRun = isset($options['dry-run']);
            
            if (!$citySlug) {
                echo "Error: --city parameter required\n";
                echo "Example: php parse-yp-ru.php parse-city --city=msk --category=restorany-i-kafe\n";
                exit(1);
            }
            
            // Get city by YP.RU slug (extract from yell_url)
            $stmt = $db->prepare("SELECT id, name, yell_url FROM cities WHERE yell_url LIKE ?");
            $stmt->execute(["%//{$citySlug}.yp.ru%"]);
            $city = $stmt->fetch();
            
            // Also try by our slug
            if (!$city) {
                $stmt = $db->prepare("SELECT id, name, yell_url FROM cities WHERE slug = ?");
                $stmt->execute([$citySlug]);
                $city = $stmt->fetch();
            }
            
            if (!$city) {
                echo "Error: City '$citySlug' not found in database\n";
                exit(1);
            }
            
            // Extract YP.RU slug from yell_url
            $ypSlug = $citySlug;
            if (preg_match('/https?:\/\/([^.]+)\.yp\.ru/', $city['yell_url'], $matches)) {
                $ypSlug = $matches[1];
            }
            
            echo "Parsing city: {$city['name']} (YP slug: $ypSlug)\n";
            
            if ($categorySlug) {
                // Parse specific category
                $stmt = $db->prepare("SELECT id, name FROM categories WHERE slug = ?");
                $stmt->execute([$categorySlug]);
                $category = $stmt->fetch();
                
                if (!$category) {
                    echo "Error: Category '$categorySlug' not found\n";
                    exit(1);
                }
                
                echo "Category: {$category['name']}\n";
                echo "Limit: $limit\n\n";
                
                $establishments = $parser->parseCityCategory($ypSlug, $categorySlug, $limit);
                
                echo "Found " . count($establishments) . " establishments\n\n";
                
                if (!$dryRun) {
                    $saved = 0;
                    foreach ($establishments as $est) {
                        if ($parser->saveEstablishment($est, $city['id'], $category['id'])) {
                            $saved++;
                            echo "✓ Saved: {$est['name']}\n";
                        } else {
                            echo "✗ Failed: {$est['name']}\n";
                        }
                    }
                    echo "\nTotal saved: $saved\n";
                } else {
                    echo "DRY RUN - not saving\n";
                    foreach ($establishments as $est) {
                        echo "- {$est['name']}\n";
                    }
                }
            } else {
                // Parse all categories
                $categories = $db->query("SELECT id, slug, name FROM categories ORDER BY id LIMIT 12")->fetchAll();
                
                foreach ($categories as $category) {
                    echo "\n--- Category: {$category['name']} ---\n";
                    $establishments = $parser->parseCityCategory($citySlug, $category['slug'], $limit);
                    echo "Found " . count($establishments) . " establishments\n";
                    
                    if (!$dryRun) {
                        $saved = 0;
                        foreach ($establishments as $est) {
                            if ($parser->saveEstablishment($est, $city['id'], $category['id'])) {
                                $saved++;
                            }
                        }
                        echo "Saved: $saved\n";
                    }
                    
                    // Small delay between categories
                    sleep(1);
                }
            }
            break;
            
        case 'parse-all':
            echo "Parsing all cities and categories...\n\n";
            
            $cities = $db->query("SELECT id, slug, name FROM cities ORDER BY weight DESC NULLS LAST")->fetchAll();
            $categories = $db->query("SELECT id, slug, name FROM categories ORDER BY id LIMIT 12")->fetchAll();
            
            $totalStats = ['cities' => 0, 'categories' => 0, 'saved' => 0];
            
            foreach ($cities as $city) {
                echo "\n========== City: {$city['name']} ==========\n";
                $totalStats['cities']++;
                
                foreach ($categories as $category) {
                    echo "\n[{$category['name']}] ";
                    $totalStats['categories']++;
                    
                    try {
                        $establishments = $parser->parseCityCategory($city['slug'], $category['slug'], 10);
                        $saved = 0;
                        
                        foreach ($establishments as $est) {
                            if ($parser->saveEstablishment($est, $city['id'], $category['id'])) {
                                $saved++;
                            }
                        }
                        
                        $totalStats['saved'] += $saved;
                        echo "Saved: $saved";
                    } catch (\Exception $e) {
                        echo "Error: " . $e->getMessage();
                    }
                    
                    // Delay between requests
                    usleep(500000); // 0.5 second
                }
            }
            
            echo "\n\n========== SUMMARY ==========\n";
            echo "Cities processed: {$totalStats['cities']}\n";
            echo "Categories processed: {$totalStats['categories']}\n";
            echo "Total establishments saved: {$totalStats['saved']}\n";
            break;
            
        case 'help':
        default:
            echo "Usage: php parse-yp-ru.php [command] [options]\n\n";
            echo "Commands:\n";
            echo "  update-cities     Update list of cities from YP.RU\n";
            echo "  parse-city        Parse specific city and category\n";
            echo "  parse-all         Parse all cities and categories\n";
            echo "  help              Show this help\n\n";
            echo "Options:\n";
            echo "  --city=SLUG       City slug (for parse-city)\n";
            echo "  --category=SLUG   Category slug (for parse-city)\n";
            echo "  --limit=N         Max establishments per category (default: 15)\n";
            echo "  --dry-run         Don't save to database\n\n";
            echo "Examples:\n";
            echo "  php parse-yp-ru.php update-cities\n";
            echo "  php parse-yp-ru.php parse-city --city=msk --category=restorany-i-kafe\n";
            echo "  php parse-yp-ru.php parse-city --city=spb --limit=5 --dry-run\n";
            echo "  php parse-yp-ru.php parse-all\n";
            break;
    }
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\nDone!\n";
