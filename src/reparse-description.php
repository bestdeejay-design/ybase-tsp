<?php
/**
 * Перепарсинг описания для конкретного заведения
 * Usage: php src/reparse-description.php <company_id>
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use YellParser\Database;
use YellParser\Repository;
use YellParser\DescriptionNormalizer;

class DescriptionReparser
{
    private PDO $db;
    private Repository $repo;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->repo = new Repository($this->db);
    }
    
    public function reparse(int $companyId): void
    {
        // Получаем данные заведения
        $stmt = $this->db->prepare("SELECT * FROM companies WHERE id = ?");
        $stmt->execute([$companyId]);
        $company = $stmt->fetch();
        
        if (!$company) {
            echo "❌ Заведение с ID {$companyId} не найдено\n";
            exit(1);
        }
        
        echo "=== Перепарсинг описания ===\n";
        echo "Заведение: {$company['name']} (ID: {$companyId})\n";
        echo "Yell URL: {$company['yell_url']}\n\n";
        
        if (empty($company['yell_url'])) {
            echo "❌ Нет yell_url для парсинга\n";
            exit(1);
        }
        
        // Парсим свежие данные
        $parser = new YellParser\YellParser();
        $freshData = $parser->parseDetail($company['yell_url'], (string)$company['yell_id']);
        
        if (!$freshData) {
            echo "❌ Не удалось спарсить данные\n";
            exit(1);
        }
        
        echo "✅ Данные получены\n\n";
        
        // Показываем старое и новое описание
        echo "=== СТАРОЕ ОПИСАНИЕ ===\n";
        echo $company['description'] ?: "(пусто)\n";
        echo "\n\n=== НОВОЕ ОПИСАНИЕ (сырое) ===\n";
        echo $freshData['description'] ?: "(пусто)\n";
        
        // Применяем нормализацию
        if (!empty($freshData['description'])) {
            $normalizer = new DescriptionNormalizer();
            $normalized = $normalizer->normalize($freshData['description'], $freshData);
            
            echo "\n\n=== НОВОЕ ОПИСАНИЕ (после нормализации) ===\n";
            echo $normalized['description'] ?: "(пусто)\n";
            
            // Обновляем в базе
            $updateStmt = $this->db->prepare("UPDATE companies SET description = ? WHERE id = ?");
            $updateStmt->execute([$normalized['description'], $companyId]);
            
            echo "\n\n✅ Описание обновлено в базе данных\n";
            
            // Обновляем website если нашли в описании
            if (!empty($normalized['website']) && empty($company['website'])) {
                $updateWebStmt = $this->db->prepare("UPDATE companies SET website = ? WHERE id = ?");
                $updateWebStmt->execute([$normalized['website'], $companyId]);
                echo "✅ Website обновлён: {$normalized['website']}\n";
            }
        } else {
            echo "\n\n⚠️ Новое описание пустое\n";
        }
    }
}

// Запуск
$companyId = (int) ($argv[1] ?? 0);

if ($companyId <= 0) {
    echo "Usage: php src/reparse-description.php <company_id>\n";
    echo "Example: php src/reparse-description.php 4643\n";
    exit(1);
}

$reparser = new DescriptionReparser();
$reparser->reparse($companyId);
