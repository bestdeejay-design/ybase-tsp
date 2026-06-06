<?php

declare(strict_types=1);

namespace YellParser;

use PDO;

class Repository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Сохраняет или обновляет компанию
     */
    public function saveCompany(array $data): int
    {
        // Проверяем существование компании
        $stmt = $this->db->prepare("SELECT id FROM companies WHERE yell_id = ? OR yell_url = ?");
        $stmt->execute([$data['yell_id'] ?? null, $data['yell_url'] ?? null]);
        $existing = $stmt->fetch();

        // Подготавливаем JSON поля
        $workingHours = isset($data['working_hours']) ? json_encode($data['working_hours']) : null;
        $features = isset($data['features']) ? json_encode($data['features']) : null;
        $featureGroups = isset($data['feature_groups']) ? json_encode($data['feature_groups']) : null;
        $menu = isset($data['menu']) && !empty($data['menu']) ? json_encode($data['menu']) : null;
        $socialLinks = isset($data['social_links']) && !empty($data['social_links']) ? json_encode($data['social_links']) : null;
        $rawData = isset($data['raw_data']) ? json_encode($data['raw_data']) : null;
        $images = isset($data['images']) ? json_encode($data['images']) : '[]';

        if ($existing) {
            // Обновляем существующую
            $sql = "UPDATE companies SET 
                name = ?, description = ?, address = ?, city_id = ?, 
                phone = ?, email = ?, website = ?, rating = ?, 
                review_count = ?, yell_url = ?, latitude = ?, longitude = ?, 
                working_hours = ?, metro_station = ?, metro_distance = ?,
                features = ?, feature_groups = ?, menu = ?, social_links = ?, images = ?, raw_data = ?, yell_category = ?, parsed_at = CURRENT_TIMESTAMP
                WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $data['name'],
                $data['description'] ?? null,
                $data['address'] ?? null,
                $data['city_id'] ?? null,
                $data['phone'] ?? null,
                $data['email'] ?? null,
                $data['website'] ?? null,
                $data['rating'] ?? null,
                $data['review_count'] ?? 0,
                $data['yell_url'] ?? null,
                $data['latitude'] ?? null,
                $data['longitude'] ?? null,
                $workingHours,
                $data['metro_station'] ?? null,
                $data['metro_distance'] ?? null,
                $features,
                $featureGroups,
                $menu,
                $socialLinks,
                $images,
                $rawData,
                $data['yell_category'] ?? null,
                $existing['id'],
            ]);
            
            return (int) $existing['id'];
        }

        // Создаем новую
        $sql = "INSERT INTO companies 
            (yell_id, name, description, address, city_id, phone, email, website, 
             rating, review_count, yell_url, latitude, longitude, working_hours,
             metro_station, metro_distance, features, feature_groups, menu, social_links, images, raw_data, yell_category, parsed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            RETURNING id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['yell_id'] ?? null,
            $data['name'],
            $data['description'] ?? null,
            $data['address'] ?? null,
            $data['city_id'] ?? null,
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['website'] ?? null,
            $data['rating'] ?? null,
            $data['review_count'] ?? 0,
            $data['yell_url'] ?? null,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $workingHours,
            $data['metro_station'] ?? null,
            $data['metro_distance'] ?? null,
            $features,
            $featureGroups,
            $menu,
            $socialLinks,
            $images,
            $rawData,
            $data['yell_category'] ?? null,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Сохраняет отзыв
     */
    public function saveReview(int $companyId, array $review): int
    {
        // Проверяем дубликат
        $stmt = $this->db->prepare(
            "SELECT id FROM reviews 
             WHERE company_id = ? AND author_name = ? AND text = ?"
        );
        $stmt->execute([
            $companyId,
            $review['author'] ?? 'Anonymous',
            $review['text'] ?? '',
        ]);

        if ($stmt->fetch()) {
            return 0; // Дубликат
        }

        $stmt = $this->db->prepare(
            "INSERT INTO reviews (company_id, author_name, rating, text, date)
             VALUES (?, ?, ?, ?, ?)
             RETURNING id"
        );
        
        $stmt->execute([
            $companyId,
            $review['author'] ?? 'Anonymous',
            $review['rating'] ?? null,
            $review['text'] ?? '',
            $review['date'] ?? null,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Сохраняет или получает город
     */
    public function saveOrGetCity(string $name, string $slug, ?string $region = null, ?string $yellUrl = null): int
    {
        $stmt = $this->db->prepare("SELECT id FROM cities WHERE slug = ?");
        $stmt->execute([$slug]);
        $existing = $stmt->fetch();

        if ($existing) {
            return (int) $existing['id'];
        }

        $stmt = $this->db->prepare(
            "INSERT INTO cities (name, slug, region, yell_url) VALUES (?, ?, ?, ?) RETURNING id"
        );
        $stmt->execute([$name, $slug, $region, $yellUrl]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Сохраняет или получает категорию
     */
    public function saveOrGetCategory(string $name, string $slug, ?int $parentId = null, ?string $yellUrl = null): int
    {
        $stmt = $this->db->prepare("SELECT id FROM categories WHERE slug = ?");
        $stmt->execute([$slug]);
        $existing = $stmt->fetch();

        if ($existing) {
            return (int) $existing['id'];
        }

        $stmt = $this->db->prepare(
            "INSERT INTO categories (name, slug, parent_id, yell_url) VALUES (?, ?, ?, ?) RETURNING id"
        );
        $stmt->execute([$name, $slug, $parentId, $yellUrl]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Связывает компанию с категорией
     */
    public function linkCompanyCategory(int $companyId, int $categoryId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO company_categories (company_id, category_id) VALUES (?, ?)
             ON CONFLICT DO NOTHING"
        );
        $stmt->execute([$companyId, $categoryId]);
    }

    /**
     * Логирует задачу парсинга
     */
    public function logParseTask(string $taskType, string $status, ?string $message = null, int $itemsParsed = 0): int
    {
        if ($status === 'started') {
            $stmt = $this->db->prepare(
                "INSERT INTO parse_logs (task_type, status, message, items_parsed)
                 VALUES (?, ?, ?, ?) RETURNING id"
            );
            $stmt->execute([$taskType, $status, $message, $itemsParsed]);
            return (int) $stmt->fetchColumn();
        }

        // Обновляем существующую запись
        $stmt = $this->db->prepare(
            "UPDATE parse_logs SET status = ?, message = ?, items_parsed = ?, completed_at = CURRENT_TIMESTAMP
             WHERE task_type = ? AND status = 'started'
             ORDER BY started_at DESC LIMIT 1 RETURNING id"
        );
        $stmt->execute([$status, $message, $itemsParsed, $taskType]);
        $result = $stmt->fetch();

        return $result ? (int) $result['id'] : 0;
    }

    /**
     * Получает компании без полных данных
     */
    public function getCompaniesForDetailParsing(int $limit = 100): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM companies 
             WHERE parsed_at IS NULL OR parsed_at < NOW() - INTERVAL '7 days'
             ORDER BY id
             LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * Получает одно ненормализованное заведение
     */
    public function getUnnormalizedCompany(): ?array
    {
        $stmt = $this->db->query(
            "SELECT * FROM companies 
             WHERE is_normalized = FALSE OR is_normalized IS NULL
             ORDER BY id
             LIMIT 1"
        );
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Получает количество ненормализованных заведений
     */
    public function getUnnormalizedCount(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM companies 
             WHERE is_normalized = FALSE OR is_normalized IS NULL"
        )->fetchColumn();
    }

    /**
     * Отмечает заведение как нормализованное
     */
    public function markAsNormalized(int $companyId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE companies SET is_normalized = TRUE WHERE id = ?"
        );
        $stmt->execute([$companyId]);
    }

    /**
     * Обновляет описание и website заведения после нормализации
     */
    public function updateNormalizedDescription(int $companyId, string $description, ?string $website, array $phones = []): void
    {
        $stmt = $this->db->prepare(
            "UPDATE companies SET description = ?, website = ?, is_normalized = TRUE WHERE id = ?"
        );
        $stmt->execute([$description, $website, $companyId]);
        
        // Сохраняем дополнительные телефоны
        if (!empty($phones)) {
            foreach ($phones as $phone) {
                $this->saveCompanyPhone($companyId, $phone);
            }
        }
    }
    
    /**
     * Сохраняет дополнительный телефон заведения
     */
    public function saveCompanyPhone(int $companyId, string $phone): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO company_phones (company_id, phone) VALUES (?, ?) ON CONFLICT DO NOTHING"
        );
        $stmt->execute([$companyId, $phone]);
    }

    /**
     * Сохраняет или получает тег
     */
    public function saveOrGetTag(string $name, ?string $category = null): int
    {
        $slug = $this->transliterate($name);
        
        $stmt = $this->db->prepare("SELECT id FROM tags WHERE slug = ?");
        $stmt->execute([$slug]);
        $existing = $stmt->fetch();

        if ($existing) {
            return (int) $existing['id'];
        }

        $stmt = $this->db->prepare(
            "INSERT INTO tags (name, slug, category) VALUES (?, ?, ?) RETURNING id"
        );
        $stmt->execute([$name, $slug, $category]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Связывает компанию с тегом
     */
    public function linkCompanyTag(int $companyId, int $tagId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO company_tags (company_id, tag_id) VALUES (?, ?)
             ON CONFLICT DO NOTHING"
        );
        $stmt->execute([$companyId, $tagId]);
    }

    /**
     * Получает теги компании
     */
    public function getCompanyTags(int $companyId): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.* FROM tags t
             JOIN company_tags ct ON t.id = ct.tag_id
             WHERE ct.company_id = ?
             ORDER BY t.category, t.name"
        );
        $stmt->execute([$companyId]);
        return $stmt->fetchAll();
    }

    /**
     * Получает все теги по категориям
     */
    public function getTagsByCategory(): array
    {
        $stmt = $this->db->query(
            "SELECT category, json_agg(json_build_object('id', id, 'name', name, 'slug', slug)) as tags
             FROM tags
             WHERE category IS NOT NULL
             GROUP BY category
             ORDER BY category"
        );
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Ищет компании по запросу
     */
    public function searchCompanies(string $query, ?int $cityId = null, array $tagIds = [], int $limit = 20): array
    {
        $sql = "SELECT c.*, ci.name as city_name FROM companies c
                LEFT JOIN cities ci ON c.city_id = ci.id
                WHERE (c.name ILIKE ? OR c.description ILIKE ? OR c.address ILIKE ?)";
        $params = ["%$query%", "%$query%", "%$query%"];

        if ($cityId) {
            $sql .= " AND c.city_id = ?";
            $params[] = $cityId;
        }

        if (!empty($tagIds)) {
            $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
            $sql .= " AND c.id IN (
                SELECT company_id FROM company_tags 
                WHERE tag_id IN ($placeholders)
                GROUP BY company_id 
                HAVING COUNT(DISTINCT tag_id) = ?
            )";
            $params = array_merge($params, $tagIds, [count($tagIds)]);
        }

        $sql .= " ORDER BY c.rating DESC NULLS LAST, c.review_count DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Получает компании по тегу
     */
    public function getCompaniesByTag(string $tagSlug, ?int $cityId = null, int $limit = 20): array
    {
        $sql = "SELECT c.*, ci.name as city_name FROM companies c
                JOIN company_tags ct ON c.id = ct.company_id
                JOIN tags t ON ct.tag_id = t.id
                LEFT JOIN cities ci ON c.city_id = ci.id
                WHERE t.slug = ?";
        $params = [$tagSlug];

        if ($cityId) {
            $sql .= " AND c.city_id = ?";
            $params[] = $cityId;
        }

        $sql .= " ORDER BY c.rating DESC NULLS LAST, c.review_count DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Транслитерация для slug
     */
    private function transliterate(string $text): string
    {
        $translit = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
            'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
            ' ' => '-', ',' => '', '&' => 'and',
        ];
        
        $text = mb_strtolower($text);
        $result = '';
        for ($i = 0; $i < mb_strlen($text); $i++) {
            $char = mb_substr($text, $i, 1);
            $result .= $translit[$char] ?? $char;
        }
        return preg_replace('/-+/', '-', trim($result, '-'));
    }

    /**
     * Получает статистику
     */
    public function getStats(): array
    {
        return [
            'companies' => $this->db->query("SELECT COUNT(*) FROM companies")->fetchColumn(),
            'reviews' => $this->db->query("SELECT COUNT(*) FROM reviews")->fetchColumn(),
            'categories' => $this->db->query("SELECT COUNT(*) FROM categories")->fetchColumn(),
            'cities' => $this->db->query("SELECT COUNT(*) FROM cities")->fetchColumn(),
            'tags' => $this->db->query("SELECT COUNT(*) FROM tags")->fetchColumn(),
        ];
    }
}
