<?php
/**
 * Review moderated establishments and reassign to correct categories
 * Usage: php review-moderated.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use YellParser\Database;

$db = Database::getInstance();

// Get all moderated establishments
$moderated = $db->query("
    SELECT c.id, c.name, c.description, c.yell_category, cat.id as current_category_id, cat.name as current_category
    FROM companies c
    JOIN company_categories cc ON c.id = cc.company_id
    JOIN categories cat ON cc.category_id = cat.id
    WHERE cat.slug = 'raznoe'
    ORDER BY c.id
")->fetchAll();

echo "=== Reviewing " . count($moderated) . " moderated establishments ===\n\n";

// Category mapping rules based on name/description keywords
$categoryRules = [
    // Медицина
    'medicina' => [
        'keywords' => [
            'стоматолог', 'зуб', 'лечение зубов', 'имплант', 'ортодонт',
            'клиника', 'медицинск', 'больница', 'поликлиника', 'диагностика',
            'лаборатория', 'анализ', 'узи', 'рентген', 'флюорография',
            'врач', 'терапевт', 'хирург', 'невролог', 'кардиолог',
            'гинеколог', 'уролог', 'дерматолог', 'офтальмолог', 'лор',
            'мрт', 'кт', 'маммология', 'эндокринолог', 'гастроэнтеролог',
        ],
        'exclude' => ['ветеринар', 'зоо'],
    ],
    
    // Образование
    'obrazovanie' => [
        'keywords' => [
            'школа', 'курсы', 'образование', 'обучение', 'центр языков',
            'английский', 'немецкий', 'французский', 'китайский', 'японский',
            'репетитор', 'подготовка к егэ', 'подготовка к огэ', 'экзамен',
            'танцевальная школа', 'школа танцев', 'хореография', 'балет',
            'музыкальная школа', 'арт-школа', 'рисование', 'творчество',
            'детский сад', 'дошкольное', 'логопед', 'дефектолог',
            'программирование', 'it-курсы', 'робототехника',
        ],
        'exclude' => [],
    ],
    
    // Рестораны и кафе
    'restorany-i-kafe' => [
        'keywords' => [
            'ресторан', 'кафе', 'бар', 'паб', 'пиццерия', 'суши', 'роллы',
            'бургерная', 'кофейня', 'чайная', 'кондитерская', 'пекарня',
            'столовая', 'фастфуд', 'шашлычная', 'гриль', 'кулинария',
            'доставка еды', 'фудкорт', 'бистро', 'закусочная',
        ],
        'exclude' => ['кальян', 'hookah'],
    ],
    
    // Красота
    'krasota' => [
        'keywords' => [
            'салон красоты', 'парикмахерская', 'барбершоп', 'маникюр', 'педикюр',
            'косметология', 'косметолог', 'эпиляция', 'депиляция', 'шугаринг',
            'макияж', 'визажист', 'брови', 'ресницы', 'наращивание',
            'массаж', 'спа', 'wellness', 'солярий', 'загар',
            'стилист', 'имиджмейкер',
        ],
        'exclude' => ['эротический', 'intim', 'relax-'],
    ],
    
    // Спорт
    'sport' => [
        'keywords' => [
            'фитнес', 'тренажерный зал', 'спортивный клуб', 'бассейн', 'плавание',
            'йога', 'пилатес', 'кроссфит', 'бокс', 'единоборства',
            'каратэ', 'дзюдо', 'самбо', 'тхэквондо', 'теннис',
            'скалодром', 'велосипед', 'бег', 'триатлон',
        ],
        'exclude' => ['pole dance', 'стриптиз'],
    ],
    
    // Развлечения
    'razvlecheniya' => [
        'keywords' => [
            'кино', 'театр', 'концерт', 'выставка', 'музей', 'галерея',
            'боулинг', 'бильярд', 'квест', 'игровая', 'vr', 'виртуальная реальность',
            'парк развлечений', 'аттракцион', 'зоопарк', 'аквариум',
            'баня', 'сауна', 'хаммам', 'spa-центр',
        ],
        'exclude' => ['казино', 'casino'],
    ],
    
    // Магазины
    'magaziny' => [
        'keywords' => [
            'магазин', 'супермаркет', 'гипермаркет', 'торговый центр',
            'аптека', 'оптика', 'цветы', 'флористика', 'книжный',
            'электроника', 'техника', 'одежда', 'обувь', 'ювелирный',
        ],
        'exclude' => ['табак', 'кальян', 'vape'],
    ],
    
    // Услуги для дома
    'uslugi-dlya-doma' => [
        'keywords' => [
            'ремонт', 'сантехник', 'электрик', 'сервисный центр', 'мастер',
            'химчистка', 'прачечная', 'уборка', 'клининг', 'няня',
        ],
        'exclude' => [],
    ],
    
    // Недвижимость
    'nedvizhimost' => [
        'keywords' => [
            'агентство недвижимости', 'риэлтор', 'квартира', 'недвижимость',
            'жилье', 'аренда', 'ипотека', 'оценка недвижимости',
        ],
        'exclude' => [],
    ],
    
    // Бары и клубы
    'bary-i-kluby' => [
        'keywords' => [
            'бар', 'pub', 'паб', 'клуб', 'ночной клуб', 'lounge',
            'караоке', 'karaoke', 'бильярд', 'боулинг',
        ],
        'exclude' => ['стриптиз', 'strip', 'казино'],
    ],
];

// Adult content keywords that should stay in moderation
$adultKeywords = [
    'стриптиз', 'strip', 'стрип', 'эротик', 'erotic', 'эротич',
    'pole dance', 'пол дэнс', 'полденс', 'танец на шесте',
    'мжм', 'жмж', 'swing', 'свинг', 'свингер',
    'bdsm', 'бдсм', 'фетиш', 'fetish',
    'интим', 'intim', 'секс', 'sex', 'порно', 'porno',
    'проститут', 'шлюх', 'путан', 'индивидуалка', 'индивидуалки',
    'салон эротического', 'эротический массаж',
    'мужской клуб', 'клуб для мужчин', 'gentlemen',
    'казино', 'casino', 'игровые автоматы',
];

// Known safe brands/establishments that should never be moderated
$safeBrands = [
    'руки вверх', 'nebar', 'петровские бани',
    'фитнес', 'кафе', 'бар', 'ресторан',
    'медицинский центр', 'медцентр', 'клиника',
    'сервисная компания', 'сервис',
];

// Tobacco/smoking keywords that should stay in moderation
$tobaccoKeywords = [
    'кальян', 'hookah', 'хука', 'шиша', 'shisha',
    'табак', 'tabak', 'сигарет', 'сигара', 'vape', 'вейп',
    'электронная сигарета', 'гильзы', 'снюс', 'snus',
];

$stats = [
    'total' => count($moderated),
    'reassigned' => 0,
    'stay_moderated' => 0,
    'by_category' => [],
];

foreach ($moderated as $item) {
    $name = mb_strtolower($item['name']);
    $description = mb_strtolower($item['description'] ?? '');
    $text = $name . ' ' . $description;
    
    // Remove [МОДЕРАЦИЯ] prefix for analysis
    $cleanName = str_replace('[модерация]', '', $name);
    $cleanText = $cleanName . ' ' . $description;
    
    echo "Analyzing: {$item['name']}\n";
    
    // Check if it's a known safe brand
    $isSafeBrand = false;
    foreach ($safeBrands as $brand) {
        if (str_contains($cleanName, mb_strtolower($brand))) {
            $isSafeBrand = true;
            break;
        }
    }
    
    // Check if should stay in moderation (adult content)
    $isAdult = false;
    $matchedKeyword = '';
    
    foreach ($adultKeywords as $keyword) {
        if (str_contains($text, mb_strtolower($keyword))) {
            $isAdult = true;
            $matchedKeyword = $keyword;
            break;
        }
    }
    
    // Check tobacco
    $isTobacco = false;
    if (!$isAdult) {
        foreach ($tobaccoKeywords as $keyword) {
            if (str_contains($text, mb_strtolower($keyword))) {
                $isTobacco = true;
                $matchedKeyword = $keyword;
                break;
            }
        }
    }
    
    if (($isAdult || $isTobacco) && !$isSafeBrand) {
        echo "  → Staying in moderation (matched: {$matchedKeyword})\n";
        $stats['stay_moderated']++;
        continue;
    }
    
    // Find best matching category
    $bestCategory = null;
    $bestScore = 0;
    
    foreach ($categoryRules as $slug => $rule) {
        $score = 0;
        
        foreach ($rule['keywords'] as $keyword) {
            if (str_contains($cleanText, mb_strtolower($keyword))) {
                $score++;
            }
        }
        
        // Check exclusions
        foreach ($rule['exclude'] as $exclude) {
            if (str_contains($text, mb_strtolower($exclude))) {
                $score = 0;
                break;
            }
        }
        
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestCategory = $slug;
        }
    }
    
    if ($bestCategory && $bestScore > 0) {
        // Get category ID
        $stmt = $db->prepare("SELECT id, name FROM categories WHERE slug = ?");
        $stmt->execute([$bestCategory]);
        $category = $stmt->fetch();
        
        if ($category) {
            // Remove [МОДЕРАЦИЯ] prefix from name
            $newName = preg_replace('/^\[МОДЕРАЦИЯ\]\s*/i', '', $item['name']);
            
            // Update company name
            $db->prepare("UPDATE companies SET name = ? WHERE id = ?")
               ->execute([$newName, $item['id']]);
            
            // Reassign category
            $db->prepare("DELETE FROM company_categories WHERE company_id = ?")
               ->execute([$item['id']]);
            $db->prepare("INSERT INTO company_categories (company_id, category_id) VALUES (?, ?)")
               ->execute([$item['id'], $category['id']]);
            
            echo "  → Reassigned to: {$category['name']} (score: {$bestScore})\n";
            
            $stats['reassigned']++;
            $stats['by_category'][$category['name']] = ($stats['by_category'][$category['name']] ?? 0) + 1;
        }
    } else {
        echo "  → Staying in moderation (no matching category found)\n";
        $stats['stay_moderated']++;
    }
    
    echo "\n";
}

echo "\n=== SUMMARY ===\n";
echo "Total analyzed: {$stats['total']}\n";
echo "Reassigned: {$stats['reassigned']}\n";
echo "Staying in moderation: {$stats['stay_moderated']}\n";
echo "\nBy category:\n";
foreach ($stats['by_category'] as $cat => $count) {
    echo "  {$cat}: {$count}\n";
}
