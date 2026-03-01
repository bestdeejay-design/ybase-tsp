<?php
/**
 * Menu Update Script
 * Checks all establishments with menu, compares with Yell.ru, updates if needed
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\DomCrawler\Crawler;
use YellParser\Database;

class MenuUpdater
{
    private PDO $db;
    private int $updated = 0;
    private int $skipped = 0;
    private int $errors = 0;
    private array $log = [];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function run(): void
    {
        echo "=== Menu Update Script ===\n\n";

        // Get all establishments with menu
        $companies = $this->getCompaniesWithMenu();
        echo "Found " . count($companies) . " establishments with menu\n\n";

        foreach ($companies as $company) {
            $this->processCompany($company);
            // Small delay to avoid overwhelming Yell.ru
            usleep(500000); // 0.5 seconds
        }

        $this->printSummary();
    }

    private function getCompaniesWithMenu(): array
    {
        $stmt = $this->db->query("
            SELECT id, name, yell_url, menu
            FROM companies
            WHERE menu IS NOT NULL 
              AND menu != '[]' 
              AND menu != '{}'
              AND yell_url IS NOT NULL
            ORDER BY id
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function processCompany(array $company): void
    {
        $id = $company['id'];
        $name = $company['name'];
        $url = $company['yell_url'];

        echo "Processing: {$name} (ID: {$id})\n";
        echo "  URL: {$url}\n";

        // Parse current menu from Yell
        $freshMenu = $this->parseMenuFromYell($url);

        if ($freshMenu === null) {
            echo "  ❌ Failed to fetch menu from Yell.ru\n\n";
            $this->errors++;
            $this->log[] = ['id' => $id, 'name' => $name, 'status' => 'error', 'reason' => 'fetch_failed'];
            return;
        }

        if (empty($freshMenu)) {
            echo "  ⚠️ No menu found on Yell.ru\n\n";
            $this->skipped++;
            $this->log[] = ['id' => $id, 'name' => $name, 'status' => 'skipped', 'reason' => 'no_menu_on_yell'];
            return;
        }

        // Get current menu from DB
        $currentMenu = json_decode($company['menu'], true) ?? [];

        // Compare menus
        $comparison = $this->compareMenus($currentMenu, $freshMenu);

        echo "  Current DB: {$comparison['current_count']} items\n";
        echo "  Fresh Yell: {$comparison['fresh_count']} items\n";

        if ($comparison['should_update']) {
            echo "  🔄 Updating... ({$comparison['reason']})\n";
            $this->updateMenu($id, $freshMenu);
            $this->updated++;
            $this->log[] = [
                'id' => $id,
                'name' => $name,
                'status' => 'updated',
                'reason' => $comparison['reason'],
                'diff' => $comparison['diff']
            ];
        } else {
            echo "  ✓ No update needed\n";
            $this->skipped++;
            $this->log[] = ['id' => $id, 'name' => $name, 'status' => 'skipped', 'reason' => 'current_is_better'];
        }

        echo "\n";
    }

    private function parseMenuFromYell(string $url): ?array
    {
        $html = $this->fetchContent($url);
        if (!$html) {
            return null;
        }

        $crawler = new Crawler($html);
        $menu = [];

        // Source 1: Direct HTML rows (visible menu)
        $crawler->filter('.price__list-group')->each(function (Crawler $group) use (&$menu) {
            $category = trim($group->filter('.price__list-title span')->text(''));
            if (!$category) $category = trim($group->filter('.price__list-title')->text(''));
            if (!$category) return;

            $items = [];
            $group->filter('.price__list-row')->each(function (Crawler $row) use (&$items) {
                $name = trim($row->filter('.price__list-left, .price__val')->text(''));
                $priceText = trim($row->filter('.price__list-right')->text(''));

                if ($name && $priceText) {
                    $price = null;
                    $portion = null;

                    if (preg_match('/([\d\s]+)\s*руб\./u', $priceText, $m)) {
                        $price = str_replace(' ', '', $m[1]) . ' руб.';
                    }
                    if (preg_match('/за\s*([\d\s]+(?:г|мл|шт))/ui', $priceText, $m)) {
                        $portion = trim($m[1]);
                    }

                    $items[] = ['name' => $name, 'price' => $price, 'portion' => $portion];
                }
            });

            if (!empty($items)) $menu[$category] = $items;
        });

        // Source 2: JSON data embedded in page
        if (empty($menu)) {
            $html = $crawler->html();

            // Look for menu JSON
            if (preg_match('/"menu":\s*(\{.*?\}),\s*"/', $html, $m)) {
                $menuJson = json_decode($m[1], true);
                if ($menuJson && is_array($menuJson)) {
                    foreach ($menuJson as $category => $items) {
                        if (is_array($items)) {
                            $menu[$category] = array_map(function ($item) {
                                return [
                                    'name' => $item['name'] ?? $item['title'] ?? '',
                                    'price' => isset($item['price']) ? $item['price'] . ' руб.' : null,
                                    'portion' => $item['portion'] ?? $item['weight'] ?? null,
                                ];
                            }, $items);
                        }
                    }
                }
            }

            // Look for priceList JSON
            if (preg_match('/"priceList":\s*(\[.*?\])/s', $html, $m)) {
                $priceList = json_decode($m[1], true);
                if ($priceList && is_array($priceList)) {
                    foreach ($priceList as $categoryData) {
                        $category = $categoryData['category'] ?? $categoryData['title'] ?? '';
                        $items = $categoryData['items'] ?? $categoryData['dishes'] ?? [];
                        if ($category && !empty($items)) {
                            $menu[$category] = array_map(function ($item) {
                                return [
                                    'name' => $item['name'] ?? $item['title'] ?? '',
                                    'price' => isset($item['price']) ? $item['price'] . ' руб.' : null,
                                    'portion' => $item['portion'] ?? $item['weight'] ?? null,
                                ];
                            }, $items);
                        }
                    }
                }
            }
        }

        return $menu;
    }

    private function fetchContent(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            ],
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$html) {
            return null;
        }

        return $html;
    }

    private function compareMenus(array $current, array $fresh): array
    {
        $currentCount = $this->countMenuItems($current);
        $freshCount = $this->countMenuItems($fresh);

        $result = [
            'current_count' => $currentCount,
            'fresh_count' => $freshCount,
            'should_update' => false,
            'reason' => '',
            'diff' => []
        ];

        // Check if fresh menu has more items
        if ($freshCount > $currentCount) {
            $result['should_update'] = true;
            $result['reason'] = "more_items (+" . ($freshCount - $currentCount) . ")";
            $result['diff'] = $this->findMenuDiff($current, $fresh);
            return $result;
        }

        // Check if fresh menu has same count but different structure
        if ($freshCount === $currentCount && $freshCount > 0) {
            // Check if categories are different
            $currentCats = array_keys($current);
            $freshCats = array_keys($fresh);

            if ($currentCats !== $freshCats) {
                $result['should_update'] = true;
                $result['reason'] = "different_categories";
                return $result;
            }

            // Check if items are different
            if ($this->areItemsDifferent($current, $fresh)) {
                $result['should_update'] = true;
                $result['reason'] = "items_changed";
                return $result;
            }
        }

        // Fresh menu is smaller or same - keep current
        $result['reason'] = "current_has_equal_or_more";
        return $result;
    }

    private function countMenuItems(array $menu): int
    {
        $count = 0;
        foreach ($menu as $category => $items) {
            if (is_array($items)) {
                $count += count($items);
            }
        }
        return $count;
    }

    private function areItemsDifferent(array $current, array $fresh): bool
    {
        foreach ($fresh as $category => $freshItems) {
            if (!isset($current[$category])) {
                return true;
            }

            $currentItems = $current[$category];
            if (count($freshItems) !== count($currentItems)) {
                return true;
            }

            // Compare item names
            $currentNames = array_map(fn($i) => $i['name'] ?? '', $currentItems);
            $freshNames = array_map(fn($i) => $i['name'] ?? '', $freshItems);

            if ($currentNames !== $freshNames) {
                return true;
            }
        }

        return false;
    }

    private function findMenuDiff(array $current, array $fresh): array
    {
        $diff = [];

        foreach ($fresh as $category => $items) {
            $freshCount = count($items);
            $currentCount = isset($current[$category]) ? count($current[$category]) : 0;

            if ($freshCount > $currentCount) {
                $diff[$category] = '+' . ($freshCount - $currentCount);
            } elseif (!isset($current[$category])) {
                $diff[$category] = 'new (' . $freshCount . ')';
            }
        }

        return $diff;
    }

    private function updateMenu(int $companyId, array $menu): void
    {
        $stmt = $this->db->prepare("
            UPDATE companies 
            SET menu = :menu, updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            ':id' => $companyId,
            ':menu' => json_encode($menu, JSON_UNESCAPED_UNICODE)
        ]);
    }

    private function printSummary(): void
    {
        echo "\n=== Summary ===\n";
        echo "Updated: {$this->updated}\n";
        echo "Skipped: {$this->skipped}\n";
        echo "Errors: {$this->errors}\n";
        echo "Total: " . ($this->updated + $this->skipped + $this->errors) . "\n\n";

        if ($this->updated > 0) {
            echo "=== Updated Establishments ===\n";
            foreach ($this->log as $entry) {
                if ($entry['status'] === 'updated') {
                    echo "- {$entry['name']} (ID: {$entry['id']}) - {$entry['reason']}\n";
                    if (!empty($entry['diff'])) {
                        foreach ($entry['diff'] as $cat => $change) {
                            echo "    {$cat}: {$change}\n";
                        }
                    }
                }
            }
        }
    }
}

// Run the updater
$updater = new MenuUpdater();
$updater->run();
