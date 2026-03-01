<?php
/** @var \YellParser\Repository $repo */
/** @var PDO $db */

$cityId = isset($_GET['city']) ? (int)$_GET['city'] : null;
$categorySlug = $_GET['category'] ?? null;
$searchQuery = $_GET['q'] ?? '';

// Получаем список городов
$cities = $db->query("SELECT id, name FROM cities ORDER BY name")->fetchAll();

// Получаем 12 основных категорий
$categories = $db->query("SELECT id, name, slug FROM categories ORDER BY id LIMIT 12")->fetchAll();

// Формируем запрос для компаний
$where = [];
$params = [];

if ($cityId) {
    $where[] = "c.city_id = ?";
    $params[] = $cityId;
}

if ($categorySlug) {
    $where[] = "cat.slug = ?";
    $params[] = $categorySlug;
}

if ($searchQuery) {
    $where[] = "(c.name ILIKE ? OR c.description ILIKE ? OR c.address ILIKE ?)";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}

$countSql = "SELECT COUNT(DISTINCT c.id) FROM companies c LEFT JOIN company_categories cc ON c.id = cc.company_id LEFT JOIN categories cat ON cc.category_id = cat.id WHERE " . ($where ? implode(' AND ', $where) : '1=1');
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalCount = $countStmt->fetchColumn();

$sql = "SELECT DISTINCT ON (c.id) c.*, ci.name as city_name 
        FROM companies c
        LEFT JOIN cities ci ON c.city_id = ci.id
        LEFT JOIN company_categories cc ON c.id = cc.company_id
        LEFT JOIN categories cat ON cc.category_id = cat.id
        WHERE " . ($where ? implode(' AND ', $where) : '1=1') . "
        ORDER BY c.id, c.rating DESC NULLS LAST
        LIMIT 100";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$companies = $stmt->fetchAll();

// Получаем название текущего города
$currentCity = null;
if ($cityId) {
    foreach ($cities as $city) {
        if ($city['id'] == $cityId) {
            $currentCity = $city;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Каталог заведений</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        .breadcrumb {
            margin-bottom: 20px;
            font-size: 14px;
        }
        .breadcrumb a {
            color: #666;
            text-decoration: none;
        }
        .breadcrumb a:hover {
            color: #333;
        }
        .header {
            margin-bottom: 32px;
        }
        .header h1 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .header p {
            color: #666;
        }
        
        /* Фильтры */
        .filters {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
        }
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 16px;
        }
        .filter-row:last-child {
            margin-bottom: 0;
        }
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        .filter-label {
            font-size: 13px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .filter-select, .filter-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            background: white;
        }
        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: #0066cc;
        }
        .search-button {
            padding: 12px 32px;
            background: #0066cc;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            align-self: flex-end;
        }
        .search-button:hover {
            background: #0055aa;
        }
        
        /* Быстрые категории */
        .quick-categories {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 24px;
        }
        .quick-category {
            padding: 8px 16px;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 20px;
            font-size: 14px;
            color: #555;
            text-decoration: none;
            transition: all 0.2s;
        }
        .quick-category:hover,
        .quick-category.active {
            background: #0066cc;
            color: white;
            border-color: #0066cc;
        }
        
        /* Панель категорий */
        .categories-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 32px;
            padding: 16px;
            background: white;
            border-radius: 16px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .category-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            background: #f5f5f5;
            border: 2px solid transparent;
            border-radius: 24px;
            font-size: 14px;
            color: #555;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s;
        }
        .category-pill:hover {
            background: #e8e8e8;
            color: #333;
        }
        .category-pill.active {
            background: #0066cc;
            color: white;
            border-color: #0066cc;
        }
        .category-icon {
            font-size: 18px;
        }
        
        /* Результаты */
        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .results-count {
            font-size: 16px;
            color: #666;
        }
        .companies-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
        }
        .company-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s ease;
            border: 1px solid #e0e0e0;
        }
        .company-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 32px rgba(0,0,0,0.12);
        }
        .company-image {
            width: 100%;
            height: 180px;
            object-fit: cover;
            background: #eee;
        }
        .company-content {
            padding: 20px;
        }
        .company-name {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #1a1a1a;
        }
        .company-address {
            font-size: 14px;
            color: #666;
            margin-bottom: 12px;
        }
        .company-meta {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .rating {
            display: flex;
            align-items: center;
            gap: 4px;
            font-weight: 600;
            color: #f5a623;
        }
        .company-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 12px;
        }
        .company-tag {
            font-size: 12px;
            padding: 4px 10px;
            background: #f0f0f0;
            border-radius: 20px;
            color: #666;
        }
        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="breadcrumb">
            <a href="/">← На главную</a>
        </div>
        
        <div class="header">
            <h1>Каталог заведений</h1>
            <p><?= $currentCity ? htmlspecialchars($currentCity['name']) : 'Все города' ?> • Найдено: <?= $totalCount ?> (показано <?= count($companies) ?>)</p>
        </div>
        
        <!-- Фильтры -->
        <div class="filters">
            <form action="/catalog" method="GET">
                <div class="filter-row">
                    <div class="filter-group">
                        <div class="filter-label">Город</div>
                        <select name="city" class="filter-select" onchange="this.form.submit()">
                            <option value="">Все города</option>
                            <?php foreach ($cities as $city): ?>
                                <option value="<?= $city['id'] ?>" <?= $cityId == $city['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($city['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <div class="filter-label">Категория</div>
                        <select name="category" class="filter-select" onchange="this.form.submit()">
                            <option value="">Все категории</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat['slug']) ?>" <?= $categorySlug == $cat['slug'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="filter-row">
                    <div class="filter-group" style="flex: 2;">
                        <div class="filter-label">Поиск</div>
                        <input type="text" name="q" class="filter-input" placeholder="Название, адрес, описание..." value="<?= htmlspecialchars($searchQuery) ?>">
                    </div>
                    <button type="submit" class="search-button">Найти</button>
                </div>
            </form>
        </div>
        
        <!-- Категории -->
        <div class="categories-bar">
            <a href="/catalog?city=<?= $cityId ?>" class="category-pill <?= !$categorySlug ? 'active' : '' ?>">
                <span class="category-icon">🏪</span>
                <span>Все</span>
            </a>
            <?php foreach ($categories as $cat): 
                $icons = [
                    'restorany-i-kafe' => '🍽️',
                    'bary-i-kluby' => '🍸',
                    'magaziny' => '🛒',
                    'krasota' => '💇',
                    'medicina' => '🏥',
                    'sport' => '⚽',
                    'razvlecheniya' => '🎭',
                    'obrazovanie' => '🎓',
                    'uslugi-biznes' => '💼',
                    'uslugi-dom' => '🔧',
                    'transport' => '🚗',
                    'nedvizhimost' => '🏠'
                ];
                $icon = $icons[$cat['slug']] ?? '📌';
            ?>
                <a href="/catalog?city=<?= $cityId ?>&category=<?= $cat['slug'] ?>" 
                   class="category-pill <?= $categorySlug == $cat['slug'] ? 'active' : '' ?>">
                    <span class="category-icon"><?= $icon ?></span>
                    <span><?= htmlspecialchars($cat['name']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        
        <!-- Результаты -->
        <?php if (empty($companies)): ?>
            <div class="no-results">
                <div style="font-size: 48px; margin-bottom: 16px;">🔍</div>
                <p>Ничего не найдено. Попробуйте изменить фильтры.</p>
            </div>
        <?php else: ?>
            <div class="companies-grid">
                <?php foreach ($companies as $company): 
                    $images = json_decode($company['images'] ?? '[]', true);
                    $firstImage = $images[0] ?? null;
                    $companyTags = $repo->getCompanyTags($company['id']);
                ?>
                    <a href="/company/<?= $company['id'] ?>" class="company-card">
                        <?php if ($firstImage): ?>
                            <img src="<?= htmlspecialchars($firstImage['preview'] ?? $firstImage) ?>" alt="" class="company-image" loading="lazy">
                        <?php else: ?>
                            <div class="company-image" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);"></div>
                        <?php endif; ?>
                        <div class="company-content">
                            <div class="company-name"><?= htmlspecialchars($company['name']) ?></div>
                            <div class="company-address"><?= htmlspecialchars($company['address'] ?? '') ?></div>
                            <div class="company-meta">
                                <?php if ($company['rating']): ?>
                                    <span class="rating">★ <?= number_format($company['rating'], 1) ?></span>
                                <?php endif; ?>
                                <?php if ($company['review_count']): ?>
                                    <span><?= $company['review_count'] ?> отзывов</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($companyTags): ?>
                                <div class="company-tags">
                                    <?php foreach (array_slice($companyTags, 0, 3) as $tag): ?>
                                        <span class="company-tag"><?= htmlspecialchars($tag['name']) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
