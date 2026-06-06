<?php

namespace YellParser;

use PDO;
use Symfony\Component\DomCrawler\Crawler;

class SmartNormalizer
{
    private \PDO $db;
    private array $verificationLog = [];
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    /**
     * Нормализует заведение с перепроверкой на Yell.ru
     */
    public function normalizeCompany(int $companyId): array
    {
        $company = $this->db->query("SELECT * FROM companies WHERE id = {$companyId}")->fetch();
        if (!$company) {
            return ['error' => 'Company not found'];
        }
        
        echo "🔍 Нормализация: {$company['name']} (ID: {$companyId})\n";
        
        // 1. Получаем свежие данные с Yell.ru
        $freshData = $this->fetchFromYell($company['yell_url'], $company['yell_id']);
        
        // 2. Сравниваем и обновляем
        $updates = [];
        
        // Проверяем меню
        $menuUpdates = $this->normalizeMenu($company, $freshData);
        if ($menuUpdates) $updates['menu'] = $menuUpdates;
        
        // Проверяем часы работы
        $hoursUpdates = $this->normalizeWorkingHours($company, $freshData);
        if ($hoursUpdates) $updates['working_hours'] = $hoursUpdates;
        
        // Проверяем описание и сайт
        $descUpdates = $this->normalizeDescription($company, $freshData);
        if ($descUpdates) $updates = array_merge($updates, $descUpdates);
        
        // Проверяем телефон
        if (!empty($freshData['phone']) && empty($company['phone'])) {
            $updates['phone'] = $freshData['phone'];
        }
        
        // Проверяем адрес
        if (!empty($freshData['address']) && empty($company['address'])) {
            $updates['address'] = $freshData['address'];
        }
        
        // 3. Применяем обновления
        if (!empty($updates)) {
            $this->applyUpdates($companyId, $updates);
            echo "  ✅ Обновлено: " . implode(', ', array_keys($updates)) . "\n";
        } else {
            echo "  ✓ Без изменений\n";
        }
        
        // 4. Верификация - проверяем что всё сохранилось
        $verification = $this->verifyUpdates($companyId, $updates);
        
        return [
            'company' => $company['name'],
            'updates' => $updates,
            'verification' => $verification,
        ];
    }
    
    /**
     * Получает свежие данные с Yell.ru
     */
    private function fetchFromYell(?string $url, string $yellId): array
    {
        if (!$url) {
            return [];
        }
        
        $html = $this->fetchContent($url);
        if (!$html) {
            return [];
        }
        
        $crawler = new Crawler($html);
        $data = [];
        
        // Меню - парсим полностью
        $data['menu'] = [];
        $crawler->filter('.price__list-group')->each(function (Crawler $group) use (&$data) {
            $category = trim($group->filter('.price__list-title')->text(''));
            if (!$category) return;
            
            $items = [];
            $group->filter('.price__list-row')->each(function (Crawler $row) use (&$items) {
                $name = trim($row->filter('.price__list-name')->text(''));
                $portion = trim($row->filter('.price__list-portion')->text(''));
                $price = trim($row->filter('.price__list-price')->text(''));
                
                if ($name) {
                    $items[] = [
                        'name' => $name,
                        'portion' => $portion,
                        'price' => $price,
                    ];
                }
            });
            
            if (!empty($items)) {
                $data['menu'][$category] = $items;
            }
        });
        
        // Часы работы - парсим полностью
        $data['working_hours'] = [];
        $crawler->filter('.company__worktime-item')->each(function (Crawler $item) use (&$data) {
            $day = trim($item->filter('.company__worktime-day')->text(''));
            $time = trim($item->filter('.company__worktime-time')->text(''));
            if ($day && $time) {
                $data['working_hours'][$day] = $time;
            }
        });
        
        // Описание
        $crawler->filter('.company__description')->each(function (Crawler $node) use (&$data) {
            $data['description'] = trim($node->text());
        });
        
        // Телефон
        $crawler->filter('[itemprop="telephone"]')->each(function (Crawler $node) use (&$data) {
            $data['phone'] = trim($node->text());
        });
        
        // Адрес
        $crawler->filter('[itemprop="streetAddress"]')->each(function (Crawler $node) use (&$data) {
            $data['address'] = trim($node->text());
        });
        
        // Website из контактов
        $crawler->filter('.company__contacts-item a[href^="http"]')->each(function (Crawler $node) use (&$data) {
            $href = $node->attr('href');
            if (!str_contains($href, 'yell.ru')) {
                $data['website'] = $href;
            }
        });
        
        return $data;
    }
    
    /**
     * Нормализует меню - объединяет старое и новое без дубликатов
     */
    private function normalizeMenu(array $company, array $freshData): ?array
    {
        $existingMenu = json_decode($company['menu'] ?? '[]', true);
        $freshMenu = $freshData['menu'] ?? [];
        
        if (empty($freshMenu)) {
            return null; // Нет новых данных
        }
        
        // Объединяем меню
        $merged = $existingMenu;
        foreach ($freshMenu as $category => $items) {
            if (!isset($merged[$category])) {
                $merged[$category] = [];
            }
            
            // Добавляем только уникальные позиции
            $existingNames = array_column($merged[$category], 'name');
            foreach ($items as $item) {
                if (!in_array($item['name'], $existingNames)) {
                    $merged[$category][] = $item;
                }
            }
        }
        
        // Проверяем изменения
        if ($merged != $existingMenu) {
            return $merged;
        }
        
        return null;
    }
    
    /**
     * Нормализует часы работы
     */
    private function normalizeWorkingHours(array $company, array $freshData): ?array
    {
        $existing = json_decode($company['working_hours'] ?? '[]', true);
        $fresh = $freshData['working_hours'] ?? [];
        
        if (empty($fresh)) {
            return null;
        }
        
        // Если свежих данных больше или они отличаются
        if (count($fresh) > count($existing) || $fresh != $existing) {
            return $fresh;
        }
        
        return null;
    }
    
    /**
     * Нормализует описание и извлекает website
     */
    private function normalizeDescription(array $company, array $freshData): ?array
    {
        $updates = [];
        
        // Используем свежее описание если оно есть
        $description = $freshData['description'] ?? $company['description'];
        
        if (!$description) {
            return null;
        }
        
        // 1. Сначала извлекаем website ДО удаления служебных строк
        $website = $this->extractWebsite($description);
        
        // 2. Если нашли website в описании, а в базе нет
        if ($website && empty($company['website'])) {
            $updates['website'] = $website;
        }
        
        // 3. Если website есть в свежих данных (из контактов), а в базе нет
        if (!empty($freshData['website']) && empty($company['website'])) {
            $updates['website'] = $freshData['website'];
        }
        
        // 4. Удаляем служебные строки
        $description = $this->removeBoilerplate($description);
        
        // 5. Форматируем описание
        $formatted = $this->formatText($description);
        
        if ($formatted != $company['description']) {
            $updates['description'] = $formatted;
        }
        
        return !empty($updates) ? $updates : null;
    }
    
    /**
     * Удаляет служебные строки
     */
    private function removeBoilerplate(string $text): string
    {
        $patterns = [
            '/Ищите нас в соцсетях:.*$/isu' => '',
            '/Информация скопирована с Yell\.ru.*$/isu' => '',
            '/Описание скопировано с Yell\.ru.*$/isu' => '',
            '/Текст скопирован с Yell\.ru.*$/isu' => '',
        ];
        
        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }
        
        return trim($text);
    }
    
    /**
     * Извлекает website из текста
     */
    private function extractWebsite(string &$text): ?string
    {
        // Ищем URL с протоколом
        if (preg_match('/(https?:\/\/[^\s,]+)/i', $text, $matches)) {
            $url = rtrim($matches[1], '.,;:!?)\'"');
            if (!str_contains($url, 'yell.ru')) {
                $text = str_replace($matches[0], '', $text);
                return $url;
            }
        }
        
        // Ищем домен без протокола
        if (preg_match('/\b([a-z0-9][a-z0-9\-]*(?:\.[a-z0-9][a-z0-9\-]*)+\.[a-z]{2,})/i', $text, $matches)) {
            $domain = $matches[1];
            $commonDomains = ['yandex.ru', 'google.com', 'vk.com', 'facebook.com', 'instagram.com', 'youtube.com'];
            
            if (!in_array(strtolower($domain), $commonDomains)) {
                $text = str_replace($matches[0], '', $text);
                return 'https://' . $domain;
            }
        }
        
        return null;
    }
    
    /**
     * Форматирует текст
     */
    private function formatText(string $text): string
    {
        // Убираем лишние пробелы
        $text = preg_replace('/[ \t]+/', ' ', $text);
        
        // Добавляем переносы после длинных предложений
        $text = preg_replace('/(.{150,}[.!?])(\s+)(?=[А-ЯA-Z])/u', "$1\n\n$2", $text);
        
        // Перенос перед ключевыми фразами
        $phrases = ['Организация располагается', 'Узнать подробности', 'Двери заведения', 'Адрес'];
        foreach ($phrases as $phrase) {
            $text = preg_replace('/([.!?])\s+(' . preg_quote($phrase) . ')/u', "$1\n\n$2", $text);
        }
        
        // Убираем множественные переносы
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        
        return trim($text);
    }
    
    /**
     * Применяет обновления к базе
     */
    private function applyUpdates(int $companyId, array $updates): void
    {
        $fields = [];
        $params = [];
        
        foreach ($updates as $field => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $fields[] = "{$field} = ?";
            $params[] = $value;
        }
        
        $params[] = $companyId;
        $sql = "UPDATE companies SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->db->prepare($sql)->execute($params);
    }
    
    /**
     * Проверяет что обновления сохранились
     */
    private function verifyUpdates(int $companyId, array $expectedUpdates): array
    {
        $company = $this->db->query("SELECT * FROM companies WHERE id = {$companyId}")->fetch();
        $errors = [];
        
        foreach ($expectedUpdates as $field => $expected) {
            $actual = $company[$field] ?? null;
            
            if (is_array($expected)) {
                $actual = json_decode($actual ?? '[]', true);
                if ($actual != $expected) {
                    $errors[] = "{$field}: mismatch";
                }
            } else {
                if ($actual != $expected) {
                    $errors[] = "{$field}: expected '{$expected}', got '{$actual}'";
                }
            }
        }
        
        return $errors;
    }
    
    /**
     * Загружает контент по URL
     */
    private function fetchContent(string $url): ?string
    {
        $context = stream_context_create([
            'http' => ['header' => 'User-Agent: Mozilla/5.0', 'timeout' => 30],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        
        $html = @file_get_contents($url, false, $context);
        return $html !== false ? $html : null;
    }
}
