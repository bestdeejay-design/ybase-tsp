<?php
/** @var \YellParser\Repository $repo */
/** @var PDO $db */

$cityId = $_GET['city'] ?? null;
$categorySlug = $_GET['category'] ?? null;
$searchQuery = $_GET['search'] ?? '';

// Получаем данные
$cities = $db->query("SELECT * FROM cities ORDER BY name")->fetchAll();
$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();

$currentCity = $cityId ? $db->query("SELECT * FROM cities WHERE id = " . (int)$cityId)->fetch() : null;
$currentCategory = null;
if ($categorySlug) {
    $catStmt = $db->prepare("SELECT * FROM categories WHERE slug = ?");
    $catStmt->execute([$categorySlug]);
    $currentCategory = $catStmt->fetch();
}

// Формируем SQL
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

$sql = "SELECT DISTINCT ON (c.id) c.*, ci.name as city_name FROM companies c LEFT JOIN cities ci ON c.city_id = ci.id LEFT JOIN company_categories cc ON c.id = cc.company_id LEFT JOIN categories cat ON cc.category_id = cat.id WHERE " . ($where ? implode(' AND ', $where) : '1=1') . " ORDER BY c.id, c.rating DESC NULLS LAST LIMIT 50";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$companies = $stmt->fetchAll();

// Получаем теги для фильтров
$allTags = $repo->getTagsByCategory();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Каталог — LOVII Style</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-main: #E9E4D4;
            --text-primary: #4A5447;
            --accent-terra: #C97C5D;
            --accent-sage: #8F9E8B;
            --card-bg: #F0EDDE;
            --border-color: rgba(74, 84, 71, 0.1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-main);
            color: var(--text-primary);
            min-height: 100vh;
            padding-bottom: 80px;
        }
        
        /* Header */
        .header {
            background: var(--bg-main);
            padding: 16px 20px;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--border-color);
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        
        .logo {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .header-actions {
            display: flex;
            gap: 12px;
        }
        
        .icon-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--card-bg);
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 18px;
        }
        
        /* Search */
        .search-box {
            position: relative;
        }
        
        .search-input {
            width: 100%;
            padding: 14px 20px 14px 48px;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            font-size: 16px;
            background: var(--card-bg);
            color: var(--text-primary);
        }
        
        .search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 18px;
            opacity: 0.5;
        }
        
        /* Filters */
        .filters {
            padding: 16px 20px;
        }
        
        .filters-desktop {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .filters-mobile {
            display: none;
        }
        
        .category-select {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            font-size: 16px;
            background: var(--card-bg);
            color: var(--text-primary);
            cursor: pointer;
        }
        
        .filter-chip {
            padding: 10px 18px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s;
        }
        
        .filter-chip.active {
            background: var(--accent-terra);
            color: white;
            border-color: var(--accent-terra);
        }
        
        /* Всегда показываем выпадающий список для удобства */
        .filters-desktop {
            display: none;
        }
        .filters-mobile {
            display: block;
        }
        
        /* Stats */
        .stats {
            padding: 0 20px 16px;
            font-size: 14px;
            color: var(--text-primary);
            opacity: 0.7;
        }
        
        /* Cards Grid */
        .cards-grid {
            padding: 0 20px;
            display: grid;
            gap: 16px;
        }
        
        .place-card {
            background: var(--card-bg);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(79, 107, 92, 0.08);
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        
        .place-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(79, 107, 92, 0.15);
        }
        
        .place-image {
            width: 100%;
            height: 180px;
            object-fit: cover;
        }
        
        .place-content {
            padding: 16px;
        }
        
        .place-header {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 12px;
        }
        
        .place-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(143, 158, 139, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }
        
        .place-info {
            flex: 1;
        }
        
        .place-name {
            font-size: 17px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
            line-height: 1.3;
        }
        
        .place-category {
            font-size: 13px;
            color: var(--accent-sage);
            font-weight: 500;
        }
        
        .place-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border-color);
        }
        
        .rating {
            display: flex;
            align-items: center;
            gap: 4px;
            font-weight: 600;
            color: var(--accent-terra);
        }
        
        .rating-star {
            font-size: 14px;
        }
        
        .reviews {
            font-size: 13px;
            color: var(--text-primary);
            opacity: 0.6;
        }
        
        .place-location {
            font-size: 13px;
            color: var(--text-primary);
            opacity: 0.7;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        /* Bottom Nav */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--bg-main);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-around;
            padding: 8px 0 20px;
            z-index: 100;
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            padding: 8px 16px;
            text-decoration: none;
            color: var(--text-primary);
            opacity: 0.6;
            transition: all 0.2s;
        }
        
        .nav-item.active {
            opacity: 1;
            color: var(--accent-terra);
        }
        
        .nav-icon {
            font-size: 24px;
        }
        
        .nav-label {
            font-size: 11px;
            font-weight: 500;
        }
        
        .nav-center {
            position: relative;
            margin-top: -20px;
        }
        
        .nav-center-btn {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: var(--accent-terra);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: white;
            box-shadow: 0 4px 12px rgba(201, 124, 93, 0.4);
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            opacity: 0.6;
        }
        
        .empty-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }
        
        /* Desktop */
        @media (min-width: 768px) {
            body {
                max-width: 480px;
                margin: 0 auto;
                box-shadow: 0 0 40px rgba(0,0,0,0.1);
            }
            
            .bottom-nav {
                max-width: 480px;
                margin: 0 auto;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-top">
            <div class="logo">LOVII</div>
            <div class="header-actions">
                <button class="icon-btn">🔔</button>
                <button class="icon-btn">👤</button>
            </div>
        </div>
        
        <form action="/catalog" method="GET" class="search-box">
            <span class="search-icon">🔍</span>
            <input type="text" name="search" class="search-input" placeholder="Поиск заведений..." value="<?= htmlspecialchars($searchQuery) ?>">
            <?php if ($cityId): ?>
                <input type="hidden" name="city" value="<?= $cityId ?>">
            <?php endif; ?>
        </form>
    </header>
    
    <!-- Filters -->
    <div class="filters">
        <!-- Десктоп: горизонтальные чипы -->
        <div class="filters-desktop">
            <a href="/catalog" class="filter-chip <?= !$categorySlug ? 'active' : '' ?>">Все</a>
            <?php foreach ($categories as $cat): ?>
                <a href="/catalog?<?= http_build_query(array_merge($_GET, ['category' => $cat['slug']])) ?>" 
                   class="filter-chip <?= $categorySlug === $cat['slug'] ? 'active' : '' ?>">
                    <?= htmlspecialchars($cat['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
        
        <!-- Мобильный: выпадающий список -->
        <div class="filters-mobile">
            <select class="category-select" onchange="window.location.href=this.value">
                <option value="/catalog">Все категории</option>
                <?php foreach ($categories as $cat): 
                    $url = '/catalog?' . http_build_query(array_merge($_GET, ['category' => $cat['slug']]));
                ?>
                    <option value="<?= $url ?>" <?= $categorySlug === $cat['slug'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    
    <!-- Stats -->
    <div class="stats">
        <?= $currentCity ? htmlspecialchars($currentCity['name']) : 'Все города' ?> 
        • <?= $totalCount ?> заведений
    </div>
    
    <!-- Cards -->
    <div class="cards-grid">
        <?php if (empty($companies)): ?>
            <div class="empty-state">
                <div class="empty-icon">🔍</div>
                <p>Ничего не найдено</p>
                <p>Попробуйте изменить фильтры</p>
            </div>
        <?php else: ?>
            <?php foreach ($companies as $company): 
                $images = json_decode($company['images'] ?? '[]', true);
                $imgUrl = !empty($images) ? (is_array($images[0]) ? ($images[0]['preview'] ?? $images[0]) : $images[0]) : '';
                
                // Category emoji
                $catEmoji = [
                    'restorany-i-kafe' => '🍽️',
                    'bary-i-kluby' => '🍸',
                    'krasota' => '💇',
                    'medicina' => '🏥',
                    'sport' => '🏃',
                    'razvlecheniya' => '🎭',
                    'produkty' => '🛒',
                    'obrazovanie' => '📚',
                    'uslugi-biznes' => '💼',
                    'uslugi-dom' => '🔧',
                    'transport' => '🚗',
                    'nedvizhimost' => '🏠',
                ];
                $companyCats = $db->query("SELECT cat.slug, cat.name FROM categories cat JOIN company_categories cc ON cat.id = cc.category_id WHERE cc.company_id = {$company['id']} LIMIT 1")->fetch();
                $emoji = $catEmoji[$companyCats['slug'] ?? ''] ?? '🏢';
            ?>
                <a href="/company/<?= $company['id'] ?>" class="place-card">
                    <?php if ($imgUrl): ?>
                        <img src="<?= htmlspecialchars($imgUrl) ?>" alt="" class="place-image">
                    <?php endif; ?>
                    
                    <div class="place-content">
                        <div class="place-header">
                            <div class="place-icon"><?= $emoji ?></div>
                            <div class="place-info">
                                <div class="place-name"><?= htmlspecialchars($company['name']) ?></div>
                                <div class="place-category"><?= htmlspecialchars($companyCats['name'] ?? 'Заведение') ?></div>
                            </div>
                        </div>
                        
                        <div class="place-meta">
                            <?php if ($company['rating']): ?>
                                <span class="rating">
                                    <span class="rating-star">★</span>
                                    <?= number_format($company['rating'], 1) ?>
                                </span>
                            <?php endif; ?>
                            
                            <?php if ($company['review_count']): ?>
                                <span class="reviews"><?= $company['review_count'] ?> отзывов</span>
                            <?php endif; ?>
                            
                            <span class="place-location">
                                📍 <?= htmlspecialchars($company['city_name'] ?? '') ?>
                            </span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="/" class="nav-item">
            <span class="nav-icon">🏠</span>
            <span class="nav-label">Главная</span>
        </a>
        <a href="/catalog" class="nav-item active">
            <span class="nav-icon">🔍</span>
            <span class="nav-label">Поиск</span>
        </a>
        <div class="nav-item nav-center">
            <div class="nav-center-btn">+</div>
        </div>
        <a href="#" class="nav-item">
            <span class="nav-icon">❤️</span>
            <span class="nav-label">Избранное</span>
        </a>
        <a href="#" class="nav-item">
            <span class="nav-icon">👤</span>
            <span class="nav-label">Профиль</span>
        </a>
    </nav>
</body>
</html>
