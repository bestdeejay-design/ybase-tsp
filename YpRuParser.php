<?php

declare(strict_types=1);

namespace YellParser;

/**
 * Parser for YP.RU (Yellow Pages Russia)
 * Enriches catalog with data from yp.ru
 */
class YpRuParser
{
    private const BASE_URL = 'https://www.yp.ru';
    private const CITY_SUBDOMAINS = [
        'msk' => 'Москва',
        'spb' => 'Санкт-Петербург',
        'chelyabinsk' => 'Челябинск',
        'tyumen' => 'Тюмень',
        'yekaterinburg' => 'Екатеринбург',
        'novosibirsk' => 'Новосибирск',
        'kazan' => 'Казань',
        'nizhny-novgorod' => 'Нижний Новгород',
        'rostov-na-donu' => 'Ростов-на-Дону',
        'ufa' => 'Уфа',
        'krasnoyarsk' => 'Красноярск',
        'voronezh' => 'Воронеж',
        'perm' => 'Пермь',
        'volgograd' => 'Волгоград',
        'krasnodar' => 'Краснодар',
        'samara' => 'Самара',
        'saratov' => 'Саратов',
        'tolyatti' => 'Тольятти',
        'izhevsk' => 'Ижевск',
        'barnaul' => 'Барнаул',
        'irkutsk' => 'Иркутск',
        'habarovsk' => 'Хабаровск',
        'yaroslavl' => 'Ярославль',
        'vladivostok' => 'Владивосток',
        'tver' => 'Тверь',
        'tula' => 'Тула',
        'kaliningrad' => 'Калининград',
        'sochi' => 'Сочи',
    ];

    /**
     * Mapping of YP.RU categories to our categories
     */
    private const CATEGORY_MAPPING = [
        // Рестораны и кафе
        'restorany-i-kafe' => [
            'restorany',
            'kafe',
            'bary',
            'kofeyni',
            'stolovye',
            'fastfud',
            'sushi-bary',
            'pizzerii',
        ],
        // Бары и клубы
        'bary-i-kluby' => [
            'nochnye-kluby',
            'karaoke-kluby',
            'biljardnye-kluby',
            'paby',
        ],
        // Магазины
        'magaziny' => [
            'supermarkety',
            'produktovye-magaziny',
            'khoztovary',
            'stroitelnye-magaziny',
            'mebelnye-magaziny',
            'elektronika',
            'odezhda-obuv',
            'kosmetika-parfyumeriya',
            'detskie-tovary',
            'sportivnye-tovary',
            'knizhnye-magaziny',
            'tsvety',
            'alkogolnye-magaziny',
            'tabak',
        ],
        // Красота
        'krasota' => [
            'salony-krasoty',
            'parikmaherskie',
            'manikyurnye-salony',
            'kosmetologiya',
            'massazhnye-salony',
            'spasalony',
            'tatu-studii',
        ],
        // Медицина
        'medicina' => [
            'polikliniki',
            'stomatologiya',
            'gospitali',
            'apteki',
            'meditsinskie-tsentry',
            'diagnosticheskie-tsentry',
            'laboratorii',
            'fizioterapiya',
            'reabilitatsiya',
            'skoraya-pomoshch',
        ],
        // Спорт
        'sport' => [
            'fitnes-kluby',
            'sportivnye-kluby',
            'basseyny',
            'trenazhernye-zaly',
            'sportivnye-sektsii',
            'edy-v-sport',
            'velomagaziny',
        ],
        // Образование
        'obrazovanie' => [
            'shkoly',
            'detskie-sady',
            'kursy',
            'instituty',
            'repetitory',
            'yazykovye-kursy',
            'vozhdenie',
            'tantsy',
            'muzykalnye-shkoly',
        ],
        // Развлечения
        'razvlecheniya' => [
            'kinoteatry',
            'teatry',
            'muzei',
            'parki-razvlecheniy',
            ' bowling',
            'kvesty',
            'antikafe',
            'zoo',
            'akvaparki',
        ],
        // Услуги для бизнеса
        'uslugi-biznes' => [
            'bukhgalteriya',
            'yuridicheskie-uslugi',
            'reklama',
            'konsalting',
            'ofisnye-pomeshcheniya',
            'kurierskie-uslugi',
            'logistika',
        ],
        // Услуги для дома
        'uslugi-dom' => [
            'remont-kvartir',
            'santekhniki',
            'elektriki',
            'uborka',
            'klining',
            'khimchistka',
            'remont-techniki',
            'masterskie',
        ],
        // Транспорт
        'transport' => [
            'avtoservisy',
            'avtomoyki',
            'shinomonrazh',
            'avtozapchasti',
            'avtosalony',
            'avtoshkoly',
            'taksi',
            'gruzoperevozki',
            'avtostoyanki',
        ],
        // Недвижимость
        'nedvizhimost' => [
            'agentsva-nedvizhimosti',
            'arenda-kvartir',
            'arenda-ofisov',
            'stroitelstvo',
            'remont-zdaniy',
        ],
        // Отели
        'oteli-arenda' => [
            'oteli',
            'hostely',
            'gostinitsy',
            'sanatorii',
            'bazy-otdykha',
        ],
    ];

    private HttpClient $httpClient;
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->httpClient = new HttpClient();
        $this->db = $db;
    }

    /**
     * Get list of available cities from YP.RU
     */
    public function getAvailableCities(): array
    {
        $cities = [];
        
        foreach (self::CITY_SUBDOMAINS as $slug => $name) {
            $cities[] = [
                'slug' => $slug,
                'name' => $name,
                'url' => "https://{$slug}.yp.ru",
            ];
        }
        
        return $cities;
    }

    /**
     * Update cities in database with YP.RU data
     */
    public function updateCities(): array
    {
        $stats = ['added' => 0, 'updated' => 0, 'errors' => []];
        $cities = $this->getAvailableCities();
        
        foreach ($cities as $cityData) {
            try {
                // Check if city exists
                $stmt = $this->db->prepare("SELECT id FROM cities WHERE name = ?");
                $stmt->execute([$cityData['name']]);
                $existing = $stmt->fetch();
                
                if ($existing) {
                    // Update yell_url if not set
                    $stmt = $this->db->prepare("UPDATE cities SET yell_url = ? WHERE id = ?");
                    $stmt->execute([$cityData['url'], $existing['id']]);
                    $stats['updated']++;
                } else {
                    // Add new city
                    $stmt = $this->db->prepare("INSERT INTO cities (name, slug, yell_url) VALUES (?, ?, ?)");
                    $stmt->execute([
                        $cityData['name'],
                        $cityData['slug'],
                        $cityData['url']
                    ]);
                    $stats['added']++;
                }
            } catch (\Exception $e) {
                $stats['errors'][] = $cityData['name'] . ': ' . $e->getMessage();
            }
        }
        
        return $stats;
    }

    /**
     * Parse establishments from YP.RU for a specific city and category
     */
    public function parseCityCategory(string $citySlug, string $categorySlug, int $limit = 15): array
    {
        $results = [];
        $ypCategories = $this->getYpCategoriesForOurCategory($categorySlug);
        
        foreach ($ypCategories as $ypCategory) {
            $url = "https://{$citySlug}.yp.ru/list/{$ypCategory}/";
            $page = $this->httpClient->get($url);
            
            if (!$page) {
                continue;
            }
            
            $establishments = $this->parseListPage($page, $citySlug);
            $results = array_merge($results, $establishments);
            
            if (count($results) >= $limit) {
                break;
            }
        }
        
        return array_slice($results, 0, $limit);
    }

    /**
     * Parse list page and extract establishment data
     */
    private function parseListPage(string $html, string $citySlug): array
    {
        $establishments = [];
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        
        // YP.RU uses different structure - look for company cards
        $nodes = $xpath->query('//div[contains(@class, "company-card")]');
        
        foreach ($nodes as $node) {
            $data = $this->extractCompanyData($node, $xpath, $citySlug);
            if ($data) {
                $establishments[] = $data;
            }
        }
        
        return $establishments;
    }

    /**
     * Extract company data from DOM node
     */
    private function extractCompanyData(\DOMNode $node, \DOMXPath $xpath, string $citySlug): ?array
    {
        try {
            $nameNode = $xpath->query('.//h2[contains(@class, "company-name")]', $node)->item(0)
                ?? $xpath->query('.//a[contains(@class, "company-title")]', $node)->item(0);
            
            if (!$nameNode) {
                return null;
            }
            
            $name = trim($nameNode->textContent);
            
            // Get detail URL
            $detailPath = null;
            if ($nameNode->nodeName === 'a' && $nameNode instanceof \DOMElement) {
                $detailPath = $nameNode->getAttribute('href');
            } else {
                $linkNode = $xpath->query('.//a', $nameNode)->item(0);
                if ($linkNode && $linkNode instanceof \DOMElement) {
                    $detailPath = $linkNode->getAttribute('href');
                }
            }
            
            // Build full URL
            $detailUrl = null;
            if ($detailPath) {
                $detailUrl = str_starts_with($detailPath, 'http') 
                    ? $detailPath 
                    : "https://{$citySlug}.yp.ru" . $detailPath;
            }
            
            // Get address
            $addressNode = $xpath->query('.//div[contains(@class, "address")]', $node)->item(0);
            $address = $addressNode ? trim($addressNode->textContent) : null;
            
            // Get phone
            $phoneNode = $xpath->query('.//div[contains(@class, "phone")]', $node)->item(0);
            $phone = $phoneNode ? trim($phoneNode->textContent) : null;
            
            // Get rating
            $ratingNode = $xpath->query('.//span[contains(@class, "rating")]', $node)->item(0);
            $rating = $ratingNode ? (float) trim($ratingNode->textContent) : null;
            
            return [
                'name' => $name,
                'address' => $address,
                'phone' => $phone,
                'rating' => $rating,
                'detail_url' => $detailUrl,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Parse detail page for full establishment information
     */
    public function parseDetailPage(string $url): ?array
    {
        $html = $this->httpClient->get($url);
        if (!$html) {
            return null;
        }
        
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        
        $data = [
            'name' => null,
            'description' => null,
            'address' => null,
            'phone' => [],
            'website' => null,
            'email' => null,
            'schedule' => null,
            'photos' => [],
            'rating' => null,
            'reviews_count' => 0,
        ];
        
        // Name
        $nameNode = $xpath->query('//h1')->item(0);
        if ($nameNode) {
            $data['name'] = trim($nameNode->textContent);
        }
        
        // Description
        $descNode = $xpath->query('//div[contains(@class, "description")]')->item(0);
        if ($descNode) {
            $data['description'] = trim($descNode->textContent);
        }
        
        // Address
        $addressNode = $xpath->query('//div[contains(@class, "address")]')->item(0);
        if ($addressNode) {
            $data['address'] = trim($addressNode->textContent);
        }
        
        // Phones
        $phoneNodes = $xpath->query('//div[contains(@class, "phone")]');
        foreach ($phoneNodes as $phoneNode) {
            $phone = trim($phoneNode->textContent);
            if ($phone) {
                $data['phone'][] = $phone;
            }
        }
        
        // Website
        $websiteNode = $xpath->query('//a[contains(@class, "website")]')->item(0);
        if ($websiteNode && $websiteNode instanceof \DOMElement) {
            $data['website'] = $websiteNode->getAttribute('href');
        }
        
        // Email
        $emailNode = $xpath->query('//a[contains(@href, "mailto:")]')->item(0);
        if ($emailNode && $emailNode instanceof \DOMElement) {
            $data['email'] = str_replace('mailto:', '', $emailNode->getAttribute('href'));
        }
        
        // Schedule
        $scheduleNode = $xpath->query('//div[contains(@class, "schedule")]')->item(0);
        if ($scheduleNode) {
            $data['schedule'] = trim($scheduleNode->textContent);
        }
        
        // Rating
        $ratingNode = $xpath->query('//span[contains(@class, "rating")]')->item(0);
        if ($ratingNode) {
            $data['rating'] = (float) trim($ratingNode->textContent);
        }
        
        // Reviews count
        $reviewsNode = $xpath->query('//span[contains(@class, "reviews-count")]')->item(0);
        if ($reviewsNode) {
            $data['reviews_count'] = (int) preg_replace('/\D/', '', $reviewsNode->textContent);
        }
        
        return $data;
    }

    /**
     * Get YP.RU category slugs for our category
     */
    private function getYpCategoriesForOurCategory(string $ourCategorySlug): array
    {
        return self::CATEGORY_MAPPING[$ourCategorySlug] ?? [];
    }

    /**
     * Save establishment to database
     */
    public function saveEstablishment(array $data, int $cityId, int $categoryId): bool
    {
        try {
            // Check for duplicates
            $stmt = $this->db->prepare("SELECT id FROM companies WHERE name = ? AND city_id = ?");
            $stmt->execute([$data['name'], $cityId]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Update existing
                $stmt = $this->db->prepare("UPDATE companies SET 
                    address = ?, 
                    phone = ?, 
                    website = ?, 
                    description = ?,
                    rating = ?,
                    updated_at = NOW()
                WHERE id = ?");
                $stmt->execute([
                    $data['address'] ?? null,
                    json_encode($data['phone'] ?? []),
                    $data['website'] ?? null,
                    $data['description'] ?? null,
                    $data['rating'] ?? null,
                    $existing['id']
                ]);
                return true;
            }
            
            // Insert new
            $stmt = $this->db->prepare("INSERT INTO companies 
                (name, address, phone, website, description, rating, city_id, source, source_url, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'yp.ru', ?, NOW(), NOW())");
            $stmt->execute([
                $data['name'],
                $data['address'] ?? null,
                json_encode($data['phone'] ?? []),
                $data['website'] ?? null,
                $data['description'] ?? null,
                $data['rating'] ?? null,
                $cityId,
                $data['detail_url'] ?? null
            ]);
            
            $companyId = $this->db->lastInsertId();
            
            // Link to category
            $stmt = $this->db->prepare("INSERT INTO company_categories (company_id, category_id) VALUES (?, ?)");
            $stmt->execute([$companyId, $categoryId]);
            
            return true;
        } catch (\Exception $e) {
            error_log("Error saving establishment: " . $e->getMessage());
            return false;
        }
    }
}
