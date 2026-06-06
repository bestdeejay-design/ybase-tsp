<?php
/**
 * Обновление описаний для всех заведений в базе
 * Usage: php src/update-all-descriptions.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Parser.php';

use YellParser\Database;
use YellParser\Repository;

class DescriptionUpdater
{
    private PDO $db;
    private Repository $repo;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->repo = new Repository($this->db);
    }
    
    public function updateAll(): void
    {
        // Получаем все заведения с yell_url
        $stmt = $this->db->query('SELECT id, name, yell_url, yell_id, city_id, description FROM companies WHERE yell_url IS NOT NULL ORDER BY id');
        $companies = $stmt->fetchAll();
        
        echo "=== Обновление описаний ===\n";
        echo "Всего заведений: " . count($companies) . "\n\n";
        
        $updated = 0;
        $errors = 0;
        $skipped = 0;
        
        foreach ($companies as $index => $company) {
            echo "[" . ($index + 1) . "/" . count($companies) . "] ID {$company['id']} - {$company['name']}\n";
            
            try {
                // Парсим свежие данные
                $parser = new YellParser();
                $freshData = $parser->parseDetail($company['yell_url'], (string)$company['yell_id']);
                
                if (!$freshData) {
                    echo "  ⚠️ Не удалось спарсить\n";
                    $errors++;
                    continue;
                }
                
                // Обновляем только если описание изменилось
                if (!empty($freshData['description'])) {
                    $oldDesc = $company['description'] ?? '';
                    $newDesc = $freshData['description'];
                    
                    if ($oldDesc !== $newDesc) {
                        $updateStmt = $this->db->prepare('UPDATE companies SET description = ? WHERE id = ?');
                        $updateStmt->execute([$newDesc, $company['id']]);
                        echo "  ✅ Описание обновлено (" . strlen($newDesc) . " chars)\n";
                        $updated++;
                    } else {
                        echo "  ⏭️ Без изменений\n";
                        $skipped++;
                    }
                } else {
                    echo "  ⚠️ Новое описание пустое\n";
                    $skipped++;
                }
                
                // Пауза между запросами
                sleep(2);
                
            } catch (Exception $e) {
                echo "  ❌ Ошибка: " . $e->getMessage() . "\n";
                $errors++;
            }
        }
        
        echo "\n=== ИТОГО ===\n";
        echo "Обновлено: {$updated}\n";
        echo "Без изменений: {$skipped}\n";
        echo "Ошибок: {$errors}\n";
    }
}

$updater = new DescriptionUpdater();
$updater->updateAll();
