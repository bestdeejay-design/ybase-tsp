<?php
/**
 * Move existing adult establishments to raznoe category
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use YellParser\Database;

$db = Database::getInstance();

// Get raznoe category ID
$stmt = $db->query("SELECT id FROM categories WHERE slug = 'raznoe'");
$raznoeId = $stmt->fetchColumn();

if (!$raznoeId) {
    echo "❌ Category 'raznoe' not found!\n";
    exit(1);
}

echo "=== Moderating existing establishments ===\n\n";

// Find establishments with adult content
$keywords = [
    'стриптиз', 'strip', 'стрип', 'эротик', 'erotic', 'эротич',
    'pole dance', 'пол дэнс', 'полденс', 'bdsm', 'бдсм', 'фетиш', 'fetish',
    'интим', 'intim', 'секс', 'sex', 'порно', 'porno',
    'проститут', 'шлюх', 'путан', 'индивидуал',
    'салон эротического', 'эротический массаж',
    'мужской клуб', 'gentlemen',
    'кальян', 'hookah', 'хука', 'шиша', 'shisha',
    'казино', 'casino', 'букмекер', 'тотализатор'
];

$sql = "SELECT id, name FROM companies WHERE ";
$conditions = [];
foreach ($keywords as $kw) {
    $conditions[] = "LOWER(name) LIKE '%" . addslashes($kw) . "%'";
}
$sql .= implode(' OR ', $conditions);

$stmt = $db->query($sql);
$companies = $stmt->fetchAll();

echo "Found " . count($companies) . " establishments with adult content\n\n";

$updated = 0;
foreach ($companies as $company) {
    // Delete existing categories
    $db->prepare('DELETE FROM company_categories WHERE company_id = ?')->execute([$company['id']]);
    
    // Add to raznoe
    $db->prepare('INSERT INTO company_categories (company_id, category_id) VALUES (?, ?)')
        ->execute([$company['id'], $raznoeId]);
    
    // Update name if not already marked
    if (!str_starts_with($company['name'], '[МОДЕРАЦИЯ]')) {
        $newName = '[МОДЕРАЦИЯ] ' . $company['name'];
        $db->prepare('UPDATE companies SET name = ? WHERE id = ?')
            ->execute([$newName, $company['id']]);
        echo "✅ [МОДЕРАЦИЯ] {$company['name']}\n";
    } else {
        echo "✓ Already marked: {$company['name']}\n";
    }
    
    $updated++;
}

echo "\n=== Done! Updated {$updated} establishments ===\n";
