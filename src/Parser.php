<?php
/**
 * Universal Yell.ru Parser
 * 
 * Usage:
 *   php parser.php list <city> <category> [limit]  - Parse list of restaurants
 *   php parser.php update [company_id]              - Update existing restaurants
 *   php parser.php parse <url>                      - Parse single restaurant
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\DomCrawler\Crawler;
use YellParser\Database;
use YellParser\Repository;
use YellParser\DescriptionNormalizer;

class YellParser
{
    private Repository $repo;
    private PDO $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->repo = new Repository($this->db);
    }
    
    /**
     * Parse list of restaurants from category page with quality filtering
     */
    public function parseList(string $citySlug, string $categorySlug, int $limit = 10, array $options = []): array
    {
        $minRating = $options['min_rating'] ?? 4.0;
        $minReviews = $options['min_reviews'] ?? 5;
        
        $url = "https://www.yell.ru/{$citySlug}/top/{$categorySlug}/";
        echo "📄 Fetching list: {$url}\n";
        
        $html = $this->fetchContent($url);
        if (!$html) {
            echo "❌ Failed to fetch\n";
            return [];
        }
        
        $crawler = new Crawler($html);
        $restaurants = [];
        
        // Parse list items - Yell list page doesn't have rating/review info
        // We'll filter after parsing details
        $crawler->filter('a.companies__item-title-text[href^="/' . $citySlug . '/com/"]')->each(function (Crawler $node) use (&$restaurants) {
            $href = $node->attr('href');
            $name = trim($node->text());
            
            if (empty($name)) return;
            
            preg_match('/_(\d+)\/$/', $href, $matches);
            $yellId = $matches[1] ?? null;
            
            if ($yellId) {
                $restaurants[$yellId] = [
                    'name' => $name,
                    'yell_id' => $yellId,
                    'url' => 'https://www.yell.ru' . $href,
                ];
            }
        });
        
        echo "✅ Found " . count($restaurants) . " restaurants\n";
        return array_slice($restaurants, 0, $limit, true);
    }
    
    /**
     * Calculate limit based on city weight
     */
    public function calculateLimitByCityWeight(int $cityWeight): int
    {
        // City size tiers
        if ($cityWeight >= 500000) {
            return 100; // Million+ cities (Moscow, SPb)
        } elseif ($cityWeight >= 100000) {
            return 50;  // Large cities (Yekaterinburg, Novosibirsk)
        } elseif ($cityWeight >= 50000) {
            return 30;  // Medium cities (Kazan, Nizhny Novgorod)
        } else {
            return 15;  // Small cities
        }
    }
    
    /**
     * Parse single restaurant detail
     */
    public function parseDetail(string $url, string $yellId): ?array
    {
        $html = $this->fetchContent($url);
        if (!$html) return null;
        
        $crawler = new Crawler($html);
        $data = [
            'yell_id' => $yellId,
            'yell_url' => $url,
            'social_links' => [],
        ];
        
        // Name
        $crawler->filter('h1')->each(function (Crawler $node) use (&$data) {
            $data['name'] = trim($node->text());
        });
        
        // Phone
        $crawler->filter('[itemprop="telephone"]')->each(function (Crawler $node) use (&$data) {
            $data['phone'] = trim($node->text());
        });
        
        // Address
        $crawler->filter('[itemprop="streetAddress"]')->each(function (Crawler $node) use (&$data) {
            $data['address'] = trim($node->text());
        });
        
        // Rating
        $crawler->filter('[itemprop="ratingValue"]')->each(function (Crawler $node) use (&$data) {
            $text = trim($node->text());
            if (is_numeric($text)) $data['rating'] = (float) $text;
        });
        
        // Review count
        $crawler->filter('[itemprop="reviewCount"]')->each(function (Crawler $node) use (&$data) {
            $content = $node->attr('content');
            if ($content && is_numeric($content)) $data['review_count'] = (int) $content;
        });
        
        // Working hours
        $data['working_hours'] = [];
        $crawler->filter('[itemprop="openingHours"]')->each(function (Crawler $node) use (&$data) {
            $content = $node->attr('content');
            if ($content) $data['working_hours'][] = $content;
        });
        
        // Description (filter out Yell boilerplate + format)
        $crawler->filter('.company__description')->each(function (Crawler $node) use (&$data) {
            // Get HTML and strip tags to preserve proper text flow
            $html = $node->html();
            $description = strip_tags($html);
            
            // Remove Yell footer - handles various phrases:
            // "Описание скопировано с Yell.ru", "Информация скопирована с Yell.ru", "Текст скопирован с Yell.ru"
            $description = preg_replace('/(?:Описание|Информация|Текст)\s+скопирован[ао]?\s+с\s+Yell\.ru.*/sui', '', $description);
            $description = trim($description);
            
            // Format text: split into paragraphs
            $description = $this->formatDescription($description);
            
            $data['description'] = $description;
        });
        
        // Website
        $crawler->filter('.company__contacts-item a[href^="http"]')->each(function (Crawler $node) use (&$data) {
            $href = $node->attr('href');
            if (!str_contains($href, 'yell.ru')) $data['website'] = $href;
        });
        
        // Email
        $crawler->filter('a[href^="mailto:"]')->each(function (Crawler $node) use (&$data) {
            $data['email'] = str_replace('mailto:', '', $node->attr('href'));
        });
        
        // Social links (filter Yell links)
        $socialPatterns = [
            'vk' => '/vk\.com|vkontakte/i',
            'telegram' => '/t\.me|telegram/i',
            'instagram' => '/instagram\.com/i',
            'facebook' => '/facebook\.com|fb\.com/i',
            'youtube' => '/youtube\.com|youtu\.be/i',
        ];
        
        $crawler->filter('a[href^="http"]')->each(function (Crawler $node) use (&$data, $socialPatterns) {
            $href = $node->attr('href');
            
            // Skip Yell links
            if (str_contains($href, 'yell.ru') || str_contains($href, 'vk.com/yellru')) return;
            
            foreach ($socialPatterns as $network => $pattern) {
                if (preg_match($pattern, $href)) {
                    if (!isset($data['social_links'][$network])) {
                        $data['social_links'][$network] = [];
                    }
                    if (!in_array($href, $data['social_links'][$network])) {
                        $data['social_links'][$network][] = $href;
                    }
                }
            }
        });
        
        // Images
        $data['images'] = [];
        $crawler->filter('.company__gallery img, img[itemprop="image"]')->each(function (Crawler $node) use (&$data) {
            $src = $node->attr('src') ?? $node->attr('data-src');
            if (!$src) return;
            
            if (preg_match('/\/imager\/[^\/]+\/(\d+x\d+)\/responses\/(.+)$/', $src, $matches)) {
                $originalUrl = 'https://image2.yell.ru/responses/' . $matches[2];
                $imageData = [
                    'preview' => $src,
                    'preview_size' => $matches[1],
                    'original' => $originalUrl,
                ];
                
                $exists = false;
                foreach ($data['images'] as $existing) {
                    if ($existing['original'] === $originalUrl) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) $data['images'][] = $imageData;
            }
        });
        
        // Feature groups
        $data['features'] = [];
        $data['feature_groups'] = [];
        $crawler->filter('.company__service__section')->each(function (Crawler $section) use (&$data) {
            $title = trim($section->filter('.company__service__title')->text(''));
            $count = trim($section->filter('.company__service__count')->text(''));
            
            if (!$title) return;
            
            $items = [];
            $section->filter('.company__service__item')->each(function (Crawler $item) use (&$items) {
                $text = trim($item->text());
                if ($text) $items[] = $text;
            });
            
            if (!empty($items)) {
                $data['feature_groups'][$title] = ['count' => $count, 'items' => $items];
                foreach ($items as $item) {
                    if (!in_array($item, $data['features'])) $data['features'][] = $item;
                }
            }
        });
        
        // Menu - try multiple sources
        $data['menu'] = [];
        
        // Source 1: Direct HTML rows (visible menu)
        $crawler->filter('.price__list-group')->each(function(Crawler $group) use (&$data) {
            $category = trim($group->filter('.price__list-title span')->text(''));
            if (!$category) $category = trim($group->filter('.price__list-title')->text(''));
            if (!$category) return;
            
            $items = [];
            $group->filter('.price__list-row')->each(function(Crawler $row) use (&$items) {
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
            
            if (!empty($items)) $data['menu'][$category] = $items;
        });
        
        // Source 2: JSON data embedded in page (for dynamically loaded menu)
        if (empty($data['menu'])) {
            $html = $crawler->html();
            
            // Look for menu JSON in script tags or data attributes
            if (preg_match('/"menu":\s*(\{.*?\}),\s*"/', $html, $m)) {
                $menuJson = json_decode($m[1], true);
                if ($menuJson && is_array($menuJson)) {
                    foreach ($menuJson as $category => $items) {
                        if (is_array($items)) {
                            $data['menu'][$category] = array_map(function($item) {
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
                            $data['menu'][$category] = array_map(function($item) {
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
        
        return $data;
    }
    
    /**
     * Check if establishment contains adult/sensitive content
     */
    private function isAdultContent(array $data): bool
    {
        $sensitiveKeywords = [
            // Эротика и стриптиз
            'стриптиз', 'strip', 'стрип', 'эротик', 'erotic', 'эротич',
            'танец на шесте', 'pole dance', 'пол дэнс', 'полденс',
            'мжм', 'жмж', 'swing', 'свинг', 'свингер',
            'bdsm', 'бдсм', 'фетиш', 'fetish',
            
            // Интимные услуги
            'интим', 'intim', 'секс', 'sex', 'порно', 'porno',
            'проститут', 'шлюх', 'путан', 'индивидуалка', 'индивидуалки',
            'салон эротического', 'эротический массаж',
            'тайский массаж', 'relax', 'релакс',
            'мужской клуб', 'клуб для мужчин', 'gentlemen',
            
            // Курение и табак
            'кальян', 'курение', 'курительн', 'табак', 'tabak',
            'сигарет', 'сигара', 'vape', 'вейп', 'электронная сигарета',
            'гильзы', 'снюс', 'snus', 'нюхательный',
            'hookah', 'хука', 'шиша', 'shisha',
            
            // Казино и азарт
            'казино', 'casino', 'азартн', 'игровые автоматы',
            'букмекер', 'тотализатор', 'ставки на спорт',
            'лотерея', 'лото', 'бинго', 'bingo',
            'покер', 'poker', 'рулетка', 'blackjack',
        ];
        
        $textToCheck = '';
        if (!empty($data['name'])) {
            $textToCheck .= mb_strtolower($data['name']) . ' ';
        }
        if (!empty($data['description'])) {
            $textToCheck .= mb_strtolower($data['description']) . ' ';
        }
        
        foreach ($sensitiveKeywords as $keyword) {
            if (str_contains($textToCheck, mb_strtolower($keyword))) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Save restaurant to database
     */
    public function saveRestaurant(array $data, int $cityId, ?string $sourceCategory = null): int
    {
        $data['city_id'] = $cityId;
        $data['yell_category'] = $sourceCategory; // Сохраняем исходную категорию Yell
        
        // Нормализуем описание и извлекаем website
        if (!empty($data['description'])) {
            $normalizer = new DescriptionNormalizer();
            $normalized = $normalizer->normalize($data['description'], $data);
            $data['description'] = $normalized['description'];
            
            // Если website не был спарсен отдельно, но есть в описании
            if (empty($data['website']) && !empty($normalized['website'])) {
                $data['website'] = $normalized['website'];
            }
        }
        
        // Check for adult content before saving
        $isAdult = $this->isAdultContent($data);
        if ($isAdult) {
            $data['name'] = '[МОДЕРАЦИЯ] ' . $data['name'];
        }
        
        $companyId = $this->repo->saveCompany($data);
        
        // Assign category based on source category from Yell (no auto-detection)
        // BUT: adult content always goes to 'raznoe' category
        if ($isAdult) {
            $categoryId = $this->getCategoryId('raznoe');
            if ($categoryId) {
                $this->db->prepare("DELETE FROM company_categories WHERE company_id = ?")
                         ->execute([$companyId]);
                $this->db->prepare("INSERT INTO company_categories (company_id, category_id) VALUES (?, ?)")
                         ->execute([$companyId, $categoryId]);
            }
        } elseif ($sourceCategory) {
            // Map Yell.ru category slugs to our category slugs
            // One our category can have multiple Yell source categories
            $yellToOurMap = [
                // Рестораны и кафе
                'restorany' => 'restorany-i-kafe',
                'restorany-i-kafe-s-zhivoj-muzykoj' => 'restorany-i-kafe',
                
                // Бары и клубы
                'bary' => 'bary-i-kluby',
                'bary-i-kluby' => 'bary-i-kluby',
                'karaoke-kluby' => 'bary-i-kluby',
                'tancevalnye-kluby' => 'bary-i-kluby',
                
                // Магазины
                'supermarkety' => 'magaziny',
                'magaziny' => 'magaziny',
                'apteki' => 'magaziny',
                'optiki' => 'magaziny',
                
                // Красота
                'salony-krasoty' => 'krasota',
                'barbershop' => 'krasota',
                'parikmakherskie' => 'krasota',
                'manikyur' => 'krasota',
                'pedikyur' => 'krasota',
                'manikyur-pedikyur' => 'krasota',
                'kosmetologiya' => 'krasota',
                'ehsteticheskie-kosmetologii' => 'krasota',
                'lazernaya-kosmetologiya' => 'krasota',
                'massazh' => 'krasota',
                'massazhnyj-salon' => 'krasota',
                'klassicheskiy-massaj' => 'krasota',
                'rasslablyayushchij-massazh' => 'krasota',
                'anticellyulitnyj-massazh' => 'krasota',
                'massazh-lica' => 'krasota',
                'massazh-spiny' => 'krasota',
                'ehsteticheskie-kosmetologii' => 'krasota',
                'epilyaciya' => 'krasota',
                'lazernaya-epilyaciya' => 'krasota',
                'depilyaciya' => 'krasota',
                'korrekciya-brovej' => 'krasota',
                'narashchivanie-resnic' => 'krasota',
                'narashchivanie-nogtej' => 'krasota',
                'dizajn-nogtej' => 'krasota',
                'apparatnyj-manikyur' => 'krasota',
                'gel-lak' => 'krasota',
                'laminirovanie-brovej' => 'krasota',
                'laminirovanie-resnic' => 'krasota',
                'permanentnyj-makiyazh' => 'krasota',
                'chistka-lica' => 'krasota',
                'piling-lica' => 'krasota',
                'mezoterapiya' => 'krasota',
                'obertyvanie' => 'krasota',
                'wellness' => 'krasota',
                
                // Медицина
                'medicina' => 'medicina',
                'meditsinskie-kliniki-tsentry-uslugi' => 'medicina',
                'stomatologii' => 'medicina',
                'stomatologicheskaya-klinika' => 'medicina',
                'detskie-kliniki' => 'medicina',
                'medicinskie-laboratorii' => 'medicina',
                'laboratornaya-diagnostika' => 'medicina',
                'uzi' => 'medicina',
                'ultrazvuk' => 'medicina',
                'uzi-sosudov' => 'medicina',
                'uzi-shchitovidnoj-zhelezy' => 'medicina',
                'flyuorografiya' => 'medicina',
                'funkcionalnaya-diagnostika' => 'medicina',
                'fizioterapiya' => 'medicina',
                'nevrologiya' => 'medicina',
                'nevrologii' => 'medicina',
                'travmatologiya' => 'medicina',
                'ortopediya' => 'medicina',
                'ortodontiya' => 'medicina',
                'implantaciya-zubov' => 'medicina',
                'protezirovanie-zubov' => 'medicina',
                'lechenie-kariesa' => 'medicina',
                'chistka-zubov' => 'medicina',
                'metallokeramika' => 'medicina',
                'viniry' => 'medicina',
                'udalenie-zuba' => 'medicina',
                'gosudarstvennye-lechebnye-uchrezhdeniya' => 'medicina',
                
                // Спорт
                'fitnes-kluby' => 'sport',
                'sport-i-fitnes' => 'sport',
                'sportivnye-bazy' => 'sport',
                'basseyny-plavatelnye' => 'sport',
                
                // Развлечения
                'razvlekatelnye-centry' => 'razvlecheniya',
                'razvlecheniya-i-otdyh' => 'razvlecheniya',
                'kinoteatry' => 'razvlecheniya',
                'kultura-i-iskusstvo' => 'razvlecheniya',
                'bany-saunyi' => 'razvlecheniya',
                'bani-basseiny' => 'razvlecheniya',
                'russkie-bani' => 'razvlecheniya',
                'bouling' => 'razvlecheniya',
                
                // Образование
                'kursy' => 'obrazovanie',
                'obrazovanie' => 'obrazovanie',
                'kyrsy-inostrannyh-jazykov' => 'obrazovanie',
                'detskie-sady' => 'obrazovanie',
                
                // Услуги для бизнеса
                'yuridicheskie-uslugi' => 'uslugi-biznes',
                'uslugi' => 'uslugi-biznes',
                'biznes-finansy-strahovanie' => 'uslugi-biznes',
                'marketing-i-reklama' => 'uslugi-biznes',
                'it-i-telekommunikacii' => 'uslugi-biznes',
                'proizvodstvo-i-optovaya-torgovlya' => 'uslugi-biznes',
                'transport-i-logistika' => 'uslugi-biznes',
                'gosudarstvo-pravo-i-obshchestvo' => 'uslugi-biznes',
                'izdatelstva-smi' => 'uslugi-biznes',
                'tipografii' => 'uslugi-biznes',
                'turfirmy' => 'uslugi-biznes',
                'puteshestviya-i-turizm' => 'uslugi-biznes',
                
                // Услуги для дома
                'remont-bytovoj-tehniki' => 'uslugi-dom',
                'remont-bytovoy-tekhniki' => 'uslugi-dom',
                'remontnye-servisy' => 'uslugi-dom',
                'remont-telefonov-smartfonov' => 'uslugi-dom',
                'khimchistka' => 'uslugi-dom',
                'stroitelstvo' => 'uslugi-dom',
                'sad-ogorod-rasteniya' => 'uslugi-dom',
                
                // Транспорт
                'avtoservisy' => 'transport',
                'avtoservisy-i-tyuning' => 'transport',
                'avto-moto' => 'transport',
                'zamena-masla-v-dvigatele' => 'transport',
                
                // Недвижимость
                'agentstva-nedvizhimosti' => 'nedvizhimost',
                'nedvizhimost' => 'nedvizhimost',
                
                // Отели и аренда
                'gostinicy' => 'oteli-arenda',
                'oteli' => 'oteli-arenda',
                'hostely' => 'oteli-arenda',
            ];
            
            $ourSlug = $yellToOurMap[$sourceCategory] ?? $sourceCategory;
            $categoryId = $this->getCategoryId($ourSlug);
            
            if ($categoryId) {
                // Delete any existing categories for this company (enforce single category)
                $this->db->prepare("DELETE FROM company_categories WHERE company_id = ?")
                         ->execute([$companyId]);
                // Insert new category
                $this->db->prepare("INSERT INTO company_categories (company_id, category_id) VALUES (?, ?)")
                         ->execute([$companyId, $categoryId]);
            }
        }
        
        // Save tags
        if (!empty($data['feature_groups'])) {
            $this->db->prepare("DELETE FROM company_tags WHERE company_id = ?")->execute([$companyId]);
            
            foreach ($data['feature_groups'] as $groupTitle => $groupData) {
                $tagCategory = null;
                if (str_contains($groupTitle, 'Кухня')) $tagCategory = 'кухня';
                elseif (str_contains($groupTitle, 'Тип')) $tagCategory = 'тип';
                elseif (str_contains($groupTitle, 'Музыка')) $tagCategory = 'музыка';
                elseif (str_contains($groupTitle, 'Услуги')) $tagCategory = 'услуги';
                elseif (str_contains($groupTitle, 'Способы оплаты')) $tagCategory = 'оплата';
                
                foreach ($groupData['items'] as $tagName) {
                    $tagId = $this->repo->saveOrGetTag($tagName, $tagCategory);
                    $this->repo->linkCompanyTag($companyId, $tagId);
                }
            }
        }
        
        return $companyId;
    }
    
    /**
     * Format description text: split into paragraphs
     */
    private function formatDescription(string $text): string
    {
        $text = trim($text);
        
        // Step 1: Remove newlines that split words (common in Yell.ru HTML)
        // Replace newline between letters with empty string
        $text = preg_replace('/([\p{L}])\n+([\p{L}])/u', '$1$2', $text);
        
        // Step 2: Fix glued text - add space after punctuation if followed by capital letter
        // Handles: "ответ!Сотрудники" → "ответ! Сотрудники"
        $text = preg_replace('/([.!?])([А-ЯA-Z])/u', '$1 $2', $text);
        
        // Step 3: Clean up multiple spaces
        $text = preg_replace('/[ \t]{2,}/', ' ', $text);
        
        // Step 4: Split by sentence endings followed by capital letter
        // After ? - single newline (question-answer together)
        $text = preg_replace('/\?\s+(?=[А-ЯA-Z])/u', "?\n", $text);
        
        // After ! - double newline (emotional break)
        $text = preg_replace('/!\s+(?=[А-ЯA-Z])/u', "!\n\n", $text);
        
        // After period + space + capital letter - double newline (new sentence/paragraph)
        // Split after sentences that are reasonably long (>30 chars) or end with specific patterns
        $text = preg_replace('/(.{30,}\.)(\s+)(?=[А-ЯA-Z][а-яa-z])/u', "$1\n\n", $text);
        
        // Step 5: Split before key phrases (address, phone, hours, etc.)
        $keyPhrases = [
            'Организация располагается',
            'Учреждение расположено',
            'Узнать подробности',
            'Двери заведения открыты',
            'Двери организации открыты',
            'Режим работы',
            'Телефон',
            'Номер телефона',
            'Адрес',
            'Адрес компании',
            'Компания ждёт посетителей',
            'Компания располагается',
            'Веб-сайт',
            'Ищите нас в соцсетях',
            'Ресторан работает',
            'Заведение работает',
            'Вы можете связаться',
            'Связаться с нами',
            'Позвоните по номеру',
            'По номеру телефона',
            'Приходите по адресу',
            'Ждём вас',
            'Ждем вас',
            'Будем рады видеть',
            'Сотрудники',
            'Также вы можете',
            'Дополнительно',
            'Обратите внимание',
            'Важно знать',
        ];
        
        foreach ($keyPhrases as $phrase) {
            // Split before phrase if preceded by period or other sentence ending
            $text = preg_replace('/([.!?])\s+(' . preg_quote($phrase, '/') . ')/ui', "$1\n\n$2", $text);
        }
        
        // Step 6: Split long sentences at conjunctions and commas for readability
        // Split after comma + space + capital letter (likely new thought)
        $text = preg_replace('/,\s+(?=[А-ЯA-Z][а-яa-z]{3,}\s+)(?![^\n]{0,50}\n)/u', ",\n", $text);
        
        // Clean up multiple newlines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        
        // Step 7: Remove "Ищите нас в соцсетях" and everything after it
        $text = preg_replace('/Ищите нас в соцсетях.*$/is', '', $text);
        
        // Final cleanup - remove trailing whitespace and newlines
        return trim($text);
    }
    
    /**
     * Detect category from page content (breadcrumbs, feature groups)
     */
    private function detectCategory(array $data, ?string $sourceCategory = null): ?int
    {
        // Priority 0: Check name for specific keywords that override source category
        $nameLower = mb_strtolower($data['name'] ?? '');
        
        $nameRules = [
            'magaziny' => ['ломбард', 'ломбард ', ' ломбард', 'ломбард,', 'ломбард.', 'ювелир', 'часов', 'сумок', 'обуви', 'одежды', 'подарков', 'книг', 'канцелярия', 'игрушки', 'детских товаров', 'косметика', 'парфюмерия', 'мебели', 'электроники', 'бытовой техники', 'спорттовары', 'товары для спорта', 'продукты', 'продукт', 'продуктов питания', 'доставка продуктов', 'доставка еды', 'супермаркет', 'гипермаркет', 'минимаркет', 'алкоголь', 'табак', 'цветы', 'зоомагазин', 'зоотовары', 'автозапчасти', 'автотовары', 'стройматериалы', 'сантехника', 'электрика', 'инструменты', 'ткани', 'швейная фурнитура', 'рукоделие', 'хобби', 'ручной работы', 'антиквариат', 'коллекционирование', 'монеты', 'марки', 'открытки', 'винтаж', 'секонд', 'комиссионный', 'вещей', 'продажа продуктов', 'продуктовый магазин', 'продукты питания', 'товары для здоровья', 'товары для красоты', 'торговый центр', 'трц', 'магазин'],
            'krasota' => ['салон красоты', 'парикмахерская', 'ногтевой', 'маникюр', 'педикюр', 'косметология', 'эпиляция', 'массаж', 'спа', 'барбершоп', 'барбер', 'make up', 'визаж', 'перманент', 'татуаж', 'ресницы', 'брови', 'стилист', 'имиджмейкер', 'студия красоты'],
            'medicina' => ['клиника', 'больница', 'поликлиника', 'стоматология', 'зубной', 'медицинский центр', 'диагностика', 'лаборатория', 'анализы', 'мрт', 'кт ', 'рентген', 'узи', 'физиотерапия', 'реабилитация', 'косметология медицинская', 'пластическая хирургия', 'роддом', 'женская консультация', 'детская поликлиника', 'скорая помощь', 'неотложная помощь', 'аптека', 'оптика', 'очки', 'контактные линзы', 'ортопедия', 'протезирование'],
            'restorany-i-kafe' => ['ресторан', 'кафе', 'столовая', 'бистро', 'фастфуд', 'фудкорт', 'пиццерия', 'суши', 'роллы', 'бургерная', 'шашлычная', 'гриль', 'кебаб', 'шаурма', 'вок', 'лапша', 'пельменная', 'блинная', 'кондитерская', 'пекарня', 'кофейня', 'чайная', 'винотека', 'паб', 'ночной клуб', 'антикафе', 'коворкинг', 'кальянная', 'кальян', 'вейп', 'электронные сигареты', 'харакири', 'сплетни'],
            'bary-i-kluby' => ['бар', 'паб', 'пивной', 'винный', 'коктейльный', 'ночной клуб', 'караоке', 'кальянная', 'кальян', 'вейп', 'электронные сигареты', 'strip', 'стриптиз', 'казино', 'букмекер', 'тотализатор', 'lounge'],
            'sport' => ['спорт', 'спортивный клуб', 'фитнес', 'тренажерный зал', 'бассейн', 'йога', 'пилатес', 'кроссфит', 'единоборства', 'бокс', 'кикбоксинг', 'mma', 'дзюдо', 'карате', 'тхэквондо', 'самбо', 'гимнастика', 'танцы', 'спортивная секция', 'спортшкола', 'лыжи', 'сноуборд', 'скейт', 'ролики', 'велосипед', 'прокат', 'экстрим', 'цигун', 'тайцзи'],
            'transport' => ['авто', 'автомойка', 'автосервис', 'шиномонтаж', 'автозапчасти', 'автосалон', 'автоломбард', 'такси', 'каршеринг', 'прокат авто', 'аренда авто', 'мото', 'вело', 'самокат', 'эвакуатор', 'автошкола', 'топливо', 'азс', 'заправка', 'газ', 'автострахование', 'осаго', 'каско', 'техцентр', 'автотехцентр', 'автомастерская', 'автоателье'],
            'nedvizhimost' => ['агентство недвижимости', 'недвижимость', 'риэлтор', 'квартира', 'дом', 'офис', 'склад', 'аренда', 'продажа', 'ипотека', 'ипотечный центр', 'жкх', 'управляющая компания', 'тсж', 'жск', 'оформление недвижимости', 'регистрация', 'кадастр', 'кадастровый', 'геодезия', 'оценка недвижимости', 'ипотечное агентство', 'центр аренды', 'участок', 'берега', 'море'],
            'obrazovanie' => ['школа', 'гимназия', 'лицей', 'детский сад', 'сады', 'кружок', 'секция', 'курсы', 'тренинг', 'обучение', 'репетитор', 'подготовка к егэ', 'подготовка к огэ', 'английский язык', 'иностранный язык', 'вуз', 'университет', 'институт', 'колледж', 'техникум', 'училище', 'автошкола', 'курсы вождения', 'музыкальная школа', 'художественная школа', 'танцевальная школа', 'спортивная школа', 'академия', 'центр языков', 'развивающий центр', 'центр иностранных языков'],
            'uslugi-dom' => ['сантехник', 'электрик', 'сварщик', 'слесарь', 'плотник', 'столяр', 'маляр', 'штукатур', 'плиточник', 'отделочник', 'ремонт', 'строительство', 'уборка', 'клининг', 'химчистка', 'прачечная', 'глажка', 'ремонт обуви', 'ремонт одежды', 'ремонт часов', 'ремонт ювелирных изделий', 'ремонт телефонов', 'ремонт компьютеров', 'ремонт техники', 'няня', 'сиделка', 'домработница', 'повар на дом', 'курьер', 'доставка', 'переезд', 'грузчики', 'хранение вещей', 'сервисный центр', 'компьютерный центр', 'сервисная компания', 'технобыт'],
            'uslugi-biznes' => ['бухгалтер', 'аудит', 'налоги', 'юрист', 'адвокат', 'нотариус', 'регистрация бизнеса', 'ликвидация', 'банкротство', 'коллектор', 'взыскание', 'кредит', 'ипотека', 'страхование', 'страховая компания', 'страхования', 'микрофинансовая', 'займы', 'оценка', 'экспертиза', 'консалтинг', 'консультации', 'маркетинг', 'реклама', 'pr', 'event', 'организация мероприятий', 'переводы', 'переводчик', 'копицентр', 'печать', 'полиграфия', 'типография', 'сувениры', 'промо', 'кадровое агентство', 'кадровый центр', 'рекрутинг', 'аутстаффинг', 'аутсорсинг', 'it-аутсорсинг', 'охрана', 'безопасность', 'видеонаблюдение', 'страхование выезжающих', 'выезжающих', 'франшиза', 'франшизы', 'бизнес под ключ'],
            'oteli-arenda' => ['отель', 'гостиница', 'пансионат', 'хостел', 'апартамент', 'квартиры посуточно', 'квартира посуточно', 'аренда посуточно', 'снять квартиру', 'жилье посуточно', 'мини-отель', 'гостевой дом', 'база отдыха'],
            'razvlecheniya' => ['кино', 'театр', 'концерт', 'выставка', 'музей', 'галерея', 'парк', 'аттракцион', 'боулинг', 'бильярд', 'игровой клуб', 'квест', 'лазертаг', 'пейнтбол', 'стрельба', 'картинг', 'квадроцикл', 'веревочный парк', 'зоопарк', 'дельфинарий', 'цирк', 'аквапарк', 'баня', 'сауна', 'хаммам', 'бассейн', 'пляж', 'пикник', 'рыбалка', 'охота', 'конная прогулка', 'верховая езда', 'прогулка на яхте', 'аренда яхты', 'коттеджи посуточно'],
        ];
        
        foreach ($nameRules as $catSlug => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($nameLower, $keyword)) {
                    return $this->getCategoryId($catSlug);
                }
            }
        }
        
        // Priority 1: Detect from description (can override sourceCategory)
        if (!empty($data['description'])) {
            $descLower = mb_strtolower($data['description']);
            
            $descRules = [
                'magaziny' => ['интернет-магазин', 'интернет магазин', 'интернет-бутик', 'интернет бутик', 'бутик', 'товаров для дома', 'посуда', 'текстиль', 'декор', 'предметы интерьера', 'мебельный', 'фурнитура', 'аксессуаров'],
                'restorany-i-kafe' => ['ресторан', 'кафе', 'кухня', 'блюда', 'меню', 'повар', 'шеф'],
                'krasota' => ['салон красоты', 'косметология', 'маникюр', 'педикюр', 'стрижка', 'уход за кожей'],
                'medicina' => ['клиника', 'медицинский центр', 'врач', 'лечение', 'диагностика', 'стоматология'],
                'sport' => ['фитнес', 'тренажерный', 'спортивный', 'тренировки', 'бассейн'],
                'uslugi-dom' => ['ремонт', 'сантехник', 'электрик', 'уборка', 'клининг', 'доставка'],
                'nedvizhimost' => ['агентство недвижимости', 'недвижимости', 'подберем участок', 'купить', 'продать', 'аренда', 'ипотека'],
                'obrazovanie' => ['курсы', 'мастерская', 'обучение', 'навыки', 'тренинг', 'школа'],
                'oteli-arenda' => ['отель', 'гостиница', 'пансионат', 'хостел', 'апартамент', 'квартиры посуточно', 'база отдыха'],
                'razvlecheniya' => ['кино', 'театр', 'концерт', 'выставка', 'музей', 'парк', 'боулинг', 'квест', 'баня', 'сауна'],
                'uslugi-biznes' => ['франшиза', 'франшизы', 'бизнес под ключ', 'страховая компания', 'микрофинансовая'],
            ];
            
            foreach ($descRules as $slug => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($descLower, $keyword)) {
                        return $this->getCategoryId($slug);
                    }
                }
            }
        }
        
        // Priority 2: Use source category from parsing context (which category page we came from)
        if ($sourceCategory) {
            // Map Yell category slugs to our category slugs
            $yellToOurMap = [
                'restorany' => 'restorany-i-kafe',
                'bary' => 'bary-i-kluby',
                'supermarkety' => 'magaziny',
                'salony-krasoty' => 'krasota',
                'medicinskie-centry' => 'medicina',
                'fitnes-kluby' => 'sport',
                'razvlekatelnye-centry' => 'razvlecheniya',
                'kursy' => 'obrazovanie',
                'yuridicheskie-uslugi' => 'uslugi-biznes',
                'remont-bytovoj-tehniki' => 'uslugi-dom',
                'avtoservisy' => 'transport',
                'agentstva-nedvizhimosti' => 'nedvizhimost',
                'gostinicy' => 'oteli-arenda',
                'oteli' => 'oteli-arenda',
                'hostely' => 'oteli-arenda',
            ];
            
            $ourSlug = $yellToOurMap[$sourceCategory] ?? $sourceCategory;
            return $this->getCategoryId($ourSlug);
        }
        
        // Priority 3: Detect from feature groups (Кухня, Тип ресторана, etc.)
        if (!empty($data['feature_groups'])) {
            $groupTitles = implode(' ', array_keys($data['feature_groups']));
            $groupLower = mb_strtolower($groupTitles);
            
            // Restaurant indicators
            if (str_contains($groupLower, 'кухня') || 
                str_contains($groupLower, 'тип ресторана') ||
                str_contains($groupLower, 'меню')) {
                return $this->getCategoryId('restorany-i-kafe');
            }
            
            // Bar indicators
            if (str_contains($groupLower, 'бар') || str_contains($groupLower, 'клуб')) {
                return $this->getCategoryId('bary-i-kluby');
            }
            
            // Beauty indicators
            if (str_contains($groupLower, 'услуги салона') || 
                str_contains($groupLower, 'косметология') ||
                str_contains($groupLower, 'ногтевой сервис')) {
                return $this->getCategoryId('krasota');
            }
            
            // Medical indicators
            if (str_contains($groupLower, 'медицинские услуги') ||
                str_contains($groupLower, 'специализации врачей')) {
                return $this->getCategoryId('medicina');
            }
            
            // Sport indicators
            if (str_contains($groupLower, 'спорт') || str_contains($groupLower, 'фитнес')) {
                return $this->getCategoryId('sport');
            }
        }
        
        // Priority 4: Detect from features array
        if (!empty($data['features'])) {
            $featuresLower = mb_strtolower(implode(' ', $data['features']));
            
            $featureRules = [
                'restorany-i-kafe' => ['ресторан', 'кафе', 'кухня', 'меню', 'блюдо'],
                'bary-i-kluby' => ['бар', 'клуб', 'коктейль', 'напиток'],
                'krasota' => ['салон', 'стрижка', 'маникюр', 'косметология'],
                'medicina' => ['клиника', 'врач', 'лечение', 'диагностика'],
                'sport' => ['тренажер', 'фитнес', 'тренировка', 'бассейн'],
            ];
            
            foreach ($featureRules as $slug => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($featuresLower, $keyword)) {
                        return $this->getCategoryId($slug);
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Get category ID by slug
     */
    private function getCategoryId(string $slug): ?int
    {
        static $cache = [];
        if (!isset($cache[$slug])) {
            $stmt = $this->db->prepare("SELECT id FROM categories WHERE slug = ?");
            $stmt->execute([$slug]);
            $cache[$slug] = $stmt->fetchColumn() ?: null;
        }
        return $cache[$slug];
    }
    
    /**
     * Update all existing restaurants
     */
    public function updateAll(): void
    {
        $companies = $this->db->query("SELECT id, yell_id, yell_url, city_id FROM companies ORDER BY id")->fetchAll();
        
        echo "=== Updating " . count($companies) . " restaurants ===\n\n";
        
        foreach ($companies as $index => $company) {
            echo "[" . ($index + 1) . "/" . count($companies) . "] {$company['yell_id']}\n";
            
            $detail = $this->parseDetail($company['yell_url'], (string)$company['yell_id']);
            if (!$detail) {
                echo "  ❌ Failed\n";
                continue;
            }
            
            $companyId = $this->saveRestaurant($detail, $company['city_id']);
            
            $imgCount = count($detail['images'] ?? []);
            $menuCount = count($detail['menu'] ?? []);
            $socialCount = count($detail['social_links'] ?? []);
            
            echo "  ✅ Updated | Photos: {$imgCount} | Menu: {$menuCount} | Social: {$socialCount}\n";
            
            if ($index < count($companies) - 1) sleep(2);
        }
    }
    
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

// === CLI ===

$parser = new YellParser();
$command = $argv[1] ?? 'help';

switch ($command) {
    case 'list':
        $city = $argv[2] ?? 'spb';
        $category = $argv[3] ?? 'restorany';
        $limit = (int) ($argv[4] ?? 10);
        
        $restaurants = $parser->parseList($city, $category, $limit);
        echo "\nFound restaurants:\n";
        foreach ($restaurants as $r) {
            echo "  - {$r['name']} ({$r['yell_id']})\n";
        }
        break;
        
    case 'update':
        $parser->updateAll();
        break;
        
    case 'parse':
        $url = $argv[2] ?? '';
        if (!$url) {
            echo "Usage: php parser.php parse <url>\n";
            exit(1);
        }
        preg_match('/_(\d+)\/$/', $url, $m);
        $yellId = $m[1] ?? '';
        $data = $parser->parseDetail($url, $yellId);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;
        
    case 'parse-city':
        // Parse N establishments from a city and save to DB with quality filtering
        $city = $argv[2] ?? '';
        $category = $argv[3] ?? 'restorany';
        $limit = (int) ($argv[4] ?? 0); // 0 = auto-calculate based on city weight
        
        if (!$city) {
            echo "Usage: php parser.php parse-city <city_slug> [category] [limit]\n";
            echo "Example: php parser.php parse-city yekaterinburg restorany 10\n";
            echo "Note: limit=0 or omitted = auto-calculate based on city size\n";
            exit(1);
        }
        
        // Get city data from DB
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, name, weight FROM cities WHERE slug = ?");
        $stmt->execute([$city]);
        $cityData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$cityData) {
            echo "❌ City '{$city}' not found in database\n";
            exit(1);
        }
        
        $cityId = $cityData['id'];
        $cityName = $cityData['name'];
        $cityWeight = (int) ($cityData['weight'] ?? 0);
        
        // Auto-calculate limit if not specified
        if ($limit <= 0) {
            $limit = $parser->calculateLimitByCityWeight($cityWeight);
        }
        
        echo "=== Parsing {$limit} establishments in {$cityName} ===\n";
        echo "    (City weight: {$cityWeight}, Category: {$category})\n";
        echo "    Filters: rating ≥4.0, reviews ≥5\n\n";
        
        // Parse list (no filtering on list page - Yell doesn't show ratings there)
        $restaurants = $parser->parseList($city, $category, $limit * 2); // Get more to account for filtering
        
        if (empty($restaurants)) {
            echo "❌ No restaurants found\n";
            exit(1);
        }
        
        $saved = 0;
        $skipped = 0;
        $totalRating = 0;
        $totalReviews = 0;
        
        foreach ($restaurants as $index => $r) {
            // Stop if we've saved enough
            if ($saved >= $limit) {
                echo "\n✅ Reached target of {$limit} establishments. Stopping.\n";
                break;
            }
            
            echo "[" . ($index + 1) . "/" . count($restaurants) . "] {$r['name']}\n";
            
            $detail = $parser->parseDetail($r['url'], $r['yell_id']);
            if (!$detail) {
                echo "  ❌ Failed to parse detail\n";
                continue;
            }
            
            // Apply quality filters after parsing detail
            $rating = $detail['rating'] ?? 0;
            $reviewCount = $detail['review_count'] ?? 0;
            
            if ($rating < 4.0 || $reviewCount < 5) {
                echo "  ⏭️ Skipped (rating: {$rating}, reviews: {$reviewCount})\n";
                $skipped++;
                continue;
            }
            
            $companyId = $parser->saveRestaurant($detail, $cityId, $category);
            $saved++;
            
            $totalRating += $rating;
            $totalReviews += $reviewCount;
            
            $menuCats = count($detail['menu'] ?? []);
            $menuItems = array_sum(array_map('count', $detail['menu'] ?? []));
            
            echo "  ✅ Saved (DB ID: {$companyId})\n";
            echo "  📍 " . ($detail['address'] ?? 'N/A') . "\n";
            echo "  📞 " . ($detail['phone'] ?? 'N/A') . "\n";
            echo "  ⭐ " . ($detail['rating'] ?? 'N/A') . " (" . ($detail['review_count'] ?? 0) . " reviews)\n";
            echo "  🍽️ {$menuCats} categories, {$menuItems} items\n\n";
            
            if ($index < count($restaurants) - 1) sleep(1);
        }
        
        $avgRating = $saved > 0 ? round($totalRating / $saved, 2) : 0;
        echo "\n=== Done! ===\n";
        echo "✅ Saved: {$saved} (target was {$limit})\n";
        echo "⏭️ Skipped (low quality): {$skipped}\n";
        echo "❌ Failed: " . (count($restaurants) - $saved - $skipped) . "\n";
        echo "📊 Average rating: {$avgRating}\n";
        echo "📝 Total reviews: {$totalReviews}\n";
        break;
        
    default:
        echo "Yell.ru Universal Parser\n\n";
        echo "Commands:\n";
        echo "  php parser.php list <city> <category> [limit]  - Parse list\n";
        echo "  php parser.php update                            - Update all\n";
        echo "  php parser.php parse <url>                       - Parse single\n";
}
