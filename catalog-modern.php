<?php
require_once __DIR__ . '/../src/Repository.php';

/** @var PDO $db */
$repo = new \YellParser\Repository($db);

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

// Фильтр "Открытые сейчас"
if (isset($_GET['open_now']) && $_GET['open_now'] == 1) {
    // Для фильтра "Открытые сейчас" нужно будет выполнить дополнительную проверку после получения результатов
    $openNowFilter = true;
} else {
    $openNowFilter = false;
}

$countSql = "SELECT COUNT(DISTINCT c.id) FROM companies c LEFT JOIN cities ci ON c.city_id = ci.id LEFT JOIN company_categories cc ON c.id = cc.company_id LEFT JOIN categories cat ON cc.category_id = cat.id WHERE " . ($where ? implode(' AND ', $where) : '1=1');
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalCount = $countStmt->fetchColumn();

$sql = "SELECT DISTINCT ON (c.id) c.*, ci.name as city_name FROM companies c LEFT JOIN cities ci ON c.city_id = ci.id LEFT JOIN company_categories cc ON c.id = cc.company_id LEFT JOIN categories cat ON cc.category_id = cat.id WHERE " . ($where ? implode(' AND ', $where) : '1=1') . " ORDER BY c.id, c.rating DESC NULLS LAST LIMIT 50";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$companies = $stmt->fetchAll();

// Обработка параметра сортировки
$sortBy = $_GET['sort'] ?? 'rating';
$order = $_GET['order'] ?? 'desc';

$orderBy = match($sortBy) {
    'name' => 'c.name',
    'date' => 'c.created_at',
    'rating' => 'c.rating',
    'reviews' => 'c.review_count',
    default => 'c.rating'
};

$orderDirection = ($order === 'asc') ? 'ASC' : 'DESC';

// Применяем фильтр "Открытые сейчас" после получения данных
if ($openNowFilter) {
    $sql = "SELECT DISTINCT ON (c.id) c.*, ci.name as city_name FROM companies c LEFT JOIN cities ci ON c.city_id = ci.id LEFT JOIN company_categories cc ON c.id = cc.company_id LEFT JOIN categories cat ON cc.category_id = cat.id WHERE " . ($where ? implode(' AND ', $where) : '1=1') . " ORDER BY c.id LIMIT 50";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $allCompanies = $stmt->fetchAll();
    
    $filteredCompanies = [];
    $currentTime = time();
    $currentDay = date('D');
    $currentDayNum = date('N'); // 1 (Monday) to 7 (Sunday)
    
    foreach ($allCompanies as $company) {
        $workingHours = json_decode($company['working_hours'] ?? '[]', true);
        if (!empty($workingHours)) {
            foreach ($workingHours as $hour) {
                $parts = explode(': ', $hour);
                if (count($parts) == 2) {
                    $days = $parts[0];
                    $times = $parts[1];
                    
                    // Проверяем день недели с учетом числового формата
                    $isDayMatch = strpos($days, $currentDay) !== false || strpos($days, 'Daily') !== false || 
                               strpos($days, 'All') !== false || strpos($days, 'Every') !== false ||
                               ($currentDayNum == 1 && (strpos($days, 'Mon') !== false || strpos($days, 'Mo') !== false)) ||
                               ($currentDayNum == 2 && (strpos($days, 'Tue') !== false || strpos($days, 'Tu') !== false)) ||
                               ($currentDayNum == 3 && (strpos($days, 'Wed') !== false || strpos($days, 'We') !== false)) ||
                               ($currentDayNum == 4 && (strpos($days, 'Thu') !== false || strpos($days, 'Th') !== false)) ||
                               ($currentDayNum == 5 && (strpos($days, 'Fri') !== false || strpos($days, 'Fr') !== false)) ||
                               ($currentDayNum == 6 && (strpos($days, 'Sat') !== false || strpos($days, 'Sa') !== false)) ||
                               ($currentDayNum == 7 && (strpos($days, 'Sun') !== false || strpos($days, 'Su') !== false));
                    
                    if ($isDayMatch) {
                        $timeParts = explode('-', $times);
                        if (count($timeParts) == 2) {
                            $openTimeStr = trim($timeParts[0]);
                            $closeTimeStr = trim($timeParts[1]);
                            
                            // Проверяем формат времени и приводим к стандартному виду
                            if (!preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', $openTimeStr)) {
                                $openTimeStr = date('H:i', strtotime($openTimeStr));
                            }
                            if (!preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', $closeTimeStr)) {
                                $closeTimeStr = date('H:i', strtotime($closeTimeStr));
                            }
                            
                            $openTime = strtotime($openTimeStr);
                            $closeTime = strtotime($closeTimeStr);
                            
                            if ($currentTime >= $openTime && $currentTime <= $closeTime) {
                                $filteredCompanies[] = $company;
                                break;
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Сортируем отфильтрованные компании
    usort($filteredCompanies, function($a, $b) use ($orderBy, $orderDirection) {
        $fieldA = $a[str_replace('c.', '', $orderBy)] ?? null;
        $fieldB = $b[str_replace('c.', '', $orderBy)] ?? null;
        
        if ($fieldA === null && $fieldB === null) return 0;
        if ($fieldA === null) return ($orderDirection === 'ASC') ? 1 : -1;
        if ($fieldB === null) return ($orderDirection === 'ASC') ? -1 : 1;
        
        if (is_numeric($fieldA) && is_numeric($fieldB)) {
            $diff = floatval($fieldA) - floatval($fieldB);
            return ($orderDirection === 'ASC') ? $diff : -$diff;
        }
        
        $cmp = strcmp((string)$fieldA, (string)$fieldB);
        return ($orderDirection === 'ASC') ? $cmp : -$cmp;
    });
    
    $companies = $filteredCompanies;
    $totalCount = count($filteredCompanies);
} else {
    // Обычная сортировка без фильтра "Открытые сейчас"
    $sql = "SELECT DISTINCT ON (c.id) c.*, ci.name as city_name FROM companies c LEFT JOIN cities ci ON c.city_id = ci.id LEFT JOIN company_categories cc ON c.id = cc.company_id LEFT JOIN categories cat ON cc.category_id = cat.id WHERE " . ($where ? implode(' AND ', $where) : '1=1') . " ORDER BY c.id, $orderBy $orderDirection NULLS LAST LIMIT 50";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $companies = $stmt->fetchAll();
}

// Получаем теги для фильтров
$allTags = $repo->getTagsByCategory();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Каталог — LOVII Style</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #fafafa;
            --bg-tertiary: #f5f5f5;
            --text-primary: #1a1a1a;
            --text-secondary: #666666;
            --text-tertiary: #888888;
            --accent-primary: #007aff;
            --accent-secondary: #5ac8fa;
            --border-light: #e0e0e0;
            --border-medium: #d0d0d0;
            --border-dark: #c0c0c0;
            --shadow-light: 0 2px 8px rgba(0,0,0,0.08);
            --shadow-medium: 0 4px 16px rgba(0,0,0,0.12);
            --shadow-heavy: 0 8px 32px rgba(0,0,0,0.16);
            --radius-small: 8px;
            --radius-medium: 12px;
            --radius-large: 16px;
            --radius-xl: 20px;
            --transition-fast: 0.2s ease;
            --transition-medium: 0.3s ease;
            --transition-slow: 0.4s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            padding-bottom: 120px;
        }

        /* Header */
        .header {
            background: var(--bg-primary);
            padding: 20px 24px 16px;
            position: sticky;
            top: 0;
            z-index: 1000;
            border-bottom: 1px solid var(--border-light);
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
            letter-spacing: -0.5px;
        }

        .header-actions {
            display: flex;
            gap: 16px;
        }

        .icon-btn {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 18px;
            transition: var(--transition-fast);
        }

        .icon-btn:hover {
            background: var(--bg-tertiary);
            transform: scale(1.05);
        }

        /* Search */
        .search-container {
            position: relative;
            margin-bottom: 16px;
        }

        .search-input {
            width: 100%;
            padding: 16px 20px 16px 52px;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-large);
            font-size: 16px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            transition: var(--transition-fast);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--accent-primary);
            background: var(--bg-primary);
            box-shadow: 0 0 0 3px rgba(0, 122, 255, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 18px;
            color: var(--text-tertiary);
        }

        /* Filters */
        .filters {
            padding: 0 24px 16px;
            margin-bottom: 16px;
        }

        .filters-container {
            display: flex;
            gap: 12px;
            overflow-x: auto;
            padding: 8px 0;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }

        .filters-container::-webkit-scrollbar {
            display: none;
        }

        .filter-chip {
            padding: 10px 20px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: 30px;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-secondary);
            text-decoration: none;
            white-space: nowrap;
            transition: var(--transition-medium);
            cursor: pointer;
        }

        .filter-chip:hover {
            background: var(--bg-tertiary);
        }

        .filter-chip.active {
            background: var(--accent-primary);
            color: white;
            border-color: var(--accent-primary);
        }

        /* Stats */
        .stats {
            padding: 0 24px 16px;
            font-size: 14px;
            color: var(--text-secondary);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stats-count {
            font-weight: 500;
        }

        .sort-select {
            padding: 8px 12px;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-medium);
            font-size: 14px;
            background: var(--bg-secondary);
            color: var(--text-secondary);
            cursor: pointer;
        }

        /* Cards Grid */
        .cards-grid {
            padding: 0 24px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .cards-grid {
                grid-template-columns: 1fr;
                padding: 0 16px;
            }
        }

        /* Place Card */
        .place-card {
            background: var(--bg-primary);
            border-radius: var(--radius-xl);
            overflow: hidden;
            box-shadow: var(--shadow-light);
            transition: var(--transition-medium);
            text-decoration: none;
            color: inherit;
            display: block;
            border: 1px solid var(--border-light);
            position: relative;
        }

        .place-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-heavy);
            border-color: var(--accent-primary);
        }
        
        .place-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            z-index: 10;
        }
        
        .badge-open {
            background: #E3FCEC;
            color: #00C853;
        }
        
        .badge-closed {
            background: #FEF2F2;
            color: #EF4444;
        }
        
        .badge-new {
            background: #EFF6FF;
            color: #1D4ED8;
        }

        .place-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
        }

        .place-content {
            padding: 20px;
        }

        .place-header {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 16px;
        }

        .place-icon {
            width: 56px;
            height: 56px;
            border-radius: 18px;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: white;
        }

        .place-icon svg {
            width: 24px;
            height: 24px;
            fill: currentColor;
        }

        .place-info {
            flex: 1;
        }

        .place-name {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
            line-height: 1.3;
        }

        .place-category {
            font-size: 14px;
            color: var(--text-secondary);
            font-weight: 500;
            margin-bottom: 8px;
        }

        .place-description {
            font-size: 14px;
            color: var(--text-tertiary);
            line-height: 1.4;
            margin-bottom: 12px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .place-meta {
            display: flex;
            align-items: center;
            gap: 16px;
            padding-top: 12px;
            border-top: 1px solid var(--border-light);
        }

        .rating {
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            color: #ff9500;
        }

        .rating-star {
            font-size: 16px;
        }

        .reviews {
            font-size: 13px;
            color: var(--text-tertiary);
        }

        .place-location {
            font-size: 13px;
            color: var(--text-tertiary);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .place-status {
            margin-top: 8px;
            font-size: 13px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 80px 24px;
            color: var(--text-tertiary);
        }

        .empty-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }

        .empty-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-secondary);
        }

        .empty-subtitle {
            font-size: 16px;
            color: var(--text-tertiary);
        }

        /* Bottom Navigation */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--bg-primary);
            border-top: 1px solid var(--border-light);
            display: flex;
            justify-content: space-around;
            padding: 12px 0 24px;
            z-index: 1000;
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            padding: 8px 16px;
            text-decoration: none;
            color: var(--text-tertiary);
            transition: var(--transition-fast);
        }

        .nav-item.active {
            color: var(--accent-primary);
        }

        .nav-item:hover {
            color: var(--accent-primary);
        }

        .nav-icon {
            font-size: 24px;
        }

        .nav-label {
            font-size: 11px;
            font-weight: 500;
        }

        /* Loading */
        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 40px;
            color: var(--text-secondary);
        }

        .loading-spinner {
            width: 24px;
            height: 24px;
            border: 2px solid var(--border-light);
            border-top: 2px solid var(--accent-primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive adjustments */
        @media (min-width: 768px) {
            body {
                max-width: 1200px;
                margin: 0 auto;
                box-shadow: 0 0 60px rgba(0,0,0,0.08);
            }
            
            .bottom-nav {
                max-width: 1200px;
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
        
        <div class="search-container">
            <span class="search-icon">🔍</span>
            <form action="/catalog" method="GET" style="margin: 0; display: flex; gap: 8px;">
                <input type="text" name="search" class="search-input" placeholder="Поиск заведений..." value="<?= htmlspecialchars($searchQuery) ?>" style="flex: 1;">
                <?php if ($cityId): ?>
                    <input type="hidden" name="city" value="<?= $cityId ?>">
                <?php endif; ?>
                <?php if ($categorySlug): ?>
                    <input type="hidden" name="category" value="<?= $categorySlug ?>">
                <?php endif; ?>
                <?php if (isset($_GET['open_now'])): ?>
                    <input type="hidden" name="open_now" value="1">
                <?php endif; ?>
                <?php if (isset($_GET['sort'])): ?>
                    <input type="hidden" name="sort" value="<?= $_GET['sort'] ?>">
                <?php endif; ?>
                <?php if (isset($_GET['order'])): ?>
                    <input type="hidden" name="order" value="<?= $_GET['order'] ?>">
                <?php endif; ?>
                <?php if ($searchQuery): ?>
                    <button type="button" class="clear-search" onclick="clearSearch()" style="padding: 0 12px; border: 1px solid var(--border-light); border-radius: 8px; background: var(--bg-secondary); cursor: pointer;">✕</button>
                <?php endif; ?>
            </form>
        </div>
        <script>
        function clearSearch() {
            const params = new URLSearchParams(window.location.search);
            params.delete('search');
            window.location.search = params.toString();
        }
        
        // Объединяем со скриптами выше
        function updateSort(sortValue) {
            const params = new URLSearchParams(window.location.search);
            params.set('sort', sortValue);
            window.location.search = params.toString();
        }
        
        function updateOrder(orderValue) {
            const params = new URLSearchParams(window.location.search);
            params.set('order', orderValue);
            window.location.search = params.toString();
        }
        
        function resetFilters() {
            window.location.href = '/catalog';
        }
        </script>
    </header>
    
    <!-- Filters -->
    <div class="filters">
        <div class="filters-container">
            <a href="/catalog" class="filter-chip <?= (!$categorySlug && !isset($_GET['open_now'])) ? 'active' : '' ?>">Все</a>
            <a href="/catalog?open_now=1" class="filter-chip <?= isset($_GET['open_now']) ? 'active' : '' ?>">Открытые сейчас</a>
            <div class="category-dropdown">
                <button class="dropdown-btn" onclick="toggleDropdown()">
                    <span>Категории</span>
                    <span class="dropdown-arrow">▼</span>
                </button>
                <div class="dropdown-content" id="category-dropdown">
                    <?php foreach ($categories as $cat): ?>
                        <a href="/catalog?<?= http_build_query(array_merge($_GET, ['category' => $cat['slug'], 'open_now' => null])) ?>" 
                           class="filter-chip <?= $categorySlug === $cat['slug'] ? 'active' : '' ?>">
                            <?= htmlspecialchars($cat['name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <style>
        .category-dropdown {
            position: relative;
            display: inline-block;
        }
        
        .dropdown-btn {
            padding: 10px 20px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: 30px;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }
        
        .dropdown-content {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--bg-primary);
            border: 1px solid var(--border-light);
            border-radius: 12px;
            box-shadow: var(--shadow-medium);
            z-index: 100;
            max-height: 300px;
            overflow-y: auto;
            margin-top: 8px;
        }
        
        .dropdown-content.show {
            display: block;
        }
        
        .dropdown-content a {
            display: block;
            padding: 12px 16px;
            text-decoration: none;
            color: var(--text-primary);
            border-bottom: 1px solid var(--border-light);
        }
        
        .dropdown-content a:last-child {
            border-bottom: none;
        }
        
        .dropdown-content a:hover {
            background: var(--bg-secondary);
        }
        </style>
        <script>
        function toggleDropdown() {
            const dropdown = document.getElementById('category-dropdown');
            dropdown.classList.toggle('show');
        }
        
        // Закрытие выпадающего списка при клике вне его
        window.onclick = function(event) {
            if (!event.target.matches('.dropdown-btn') && !event.target.closest('.category-dropdown')) {
                const dropdowns = document.getElementsByClassName('dropdown-content');
                for (let i = 0; i < dropdowns.length; i++) {
                    const openDropdown = dropdowns[i];
                    if (openDropdown.classList.contains('show')) {
                        openDropdown.classList.remove('show');
                    }
                }
            }
        }
        
        // Обновляем функции сортировки
        function updateSort(sortValue) {
            const params = new URLSearchParams(window.location.search);
            params.set('sort', sortValue);
            // Удаляем старые параметры сортировки чтобы избежать конфликта
            params.delete('order');
            window.location.search = params.toString();
        }
        
        function updateOrder(orderValue) {
            const params = new URLSearchParams(window.location.search);
            params.set('order', orderValue);
            window.location.search = params.toString();
        }
        
        function resetFilters() {
            window.location.href = '/catalog';
        }
        </script>
    </div>
    
    <!-- Stats -->
    <div class="stats">
        <div class="stats-count">
            <?= $currentCity ? htmlspecialchars($currentCity['name']) : 'Все города' ?> 
            • <?= $totalCount ?> заведений
        </div>
        <div class="sort-container" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <select class="sort-select" onchange="updateSort(this.value)">
                <option value="rating" <?= (($_GET['sort'] ?? 'rating') === 'rating') ? 'selected' : '' ?>>По рейтингу</option>
                <option value="name" <?= (($_GET['sort'] ?? 'rating') === 'name') ? 'selected' : '' ?>>По названию</option>
                <option value="date" <?= (($_GET['sort'] ?? 'rating') === 'date') ? 'selected' : '' ?>>По дате</option>
                <option value="reviews" <?= (($_GET['sort'] ?? 'rating') === 'reviews') ? 'selected' : '' ?>>По количеству отзывов</option>
            </select>
            <select class="sort-select" onchange="updateOrder(this.value)">
                <option value="desc" <?= (($_GET['order'] ?? 'desc') === 'desc') ? 'selected' : '' ?>>По убыванию</option>
                <option value="asc" <?= (($_GET['order'] ?? 'desc') === 'asc') ? 'selected' : '' ?>>По возрастанию</option>
            </select>
            <button class="reset-btn" onclick="resetFilters()" style="padding: 8px 12px; border: 1px solid var(--border-light); border-radius: 8px; background: var(--bg-secondary); cursor: pointer; font-size: 14px;">
                Сбросить
            </button>
        </div>
        <script>
        // Удаляем дублирующиеся функции, оставляем только определения в первом скрипте
        </script>
    </div>
    
    <!-- Cards -->
    <div class="cards-grid">
        <?php if (empty($companies)): ?>
            <div class="empty-state">
                <div class="empty-icon">🔍</div>
                <div class="empty-title">Ничего не найдено</div>
                <div class="empty-subtitle">Попробуйте изменить фильтры или поисковый запрос</div>
            </div>
        <?php else: ?>
            <?php foreach ($companies as $company): 
                $images = json_decode($company['images'] ?? '[]', true);
                $imgUrl = !empty($images) ? (is_array($images[0]) ? ($images[0]['preview'] ?? $images[0]) : $images[0]) : '';
                
                // Category SVG icons
                $catIcons = [
                    'restorany-i-kafe' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M11 9H9V2H7v7H5V2H3v7c0 2.12 1.66 3.84 3.75 3.97V22h2.5v-9.03C11.34 12.84 13 11.12 13 9V2h-2v7zm5-3c-1.1 0-2 .9-2 2v8c0 1.1.9 2 2 2h4v-2h-4v-2h4v-2h-4V8h4V6h-4z"/></svg>',
                    'bary-i-kluby' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M21 5V3H3v2l8 12v4H7v2h4v-2h2v2h4v-2h4v-2h-4v-4l2-6h2V5h-2zm-4 6.91L13 7h8l-4 5.91z"/></svg>',
                    'krasota' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg>',
                    'medicina' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-8 14h-2v-4H5v-2h4V7h2v4h4v2h-4v4z"/></svg>',
                    'sport' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm.31-8.86c-1.77-.45-2.34-.94-2.34-1.67 0-.84.79-1.43 2.1-1.43.96 0 1.46.25 1.78.58.21.21.51.24.76.11.3-.15.67-.55 1.16-1.06.54-.58.8-1.12.8-1.59 0-.59-.41-1.11-1.11-1.32-.84-.25-1.56.04-1.98.35-.32.24-.6.59-.77 1.02-.18.44-.42.71-.82.71-.31 0-.56-.14-.73-.43-.18-.3-.46-.44-.82-.44-.4 0-.7.18-.88.55-.18.37-.28.87-.28 1.51 0 .69.17 1.22.5 1.59.34.38.84.59 1.5.63v.1c-.78.15-1.33.47-1.65 1.01-.32.53-.49 1.3-.49 2.3 0 1.12.25 1.9.74 2.34.5.45 1.2.67 2.1.67 1.12 0 1.9-.37 2.34-.96.44-.6.66-1.5.66-2.7v-.54c-.84.52-1.5.78-1.98.78-.28 0-.5-.08-.66-.25-.16-.17-.24-.4-.24-.69 0-.34.15-.58.45-.73.29-.14.64-.21 1.04-.21.42 0 .77.1 1.04.3.27.2.49.48.66.86.17.38.34.7.52.96.18.26.4.45.66.57.26.12.58.18.96.18.42 0 .76-.08 1.02-.24.26-.16.46-.39.59-.69.13-.3.19-.71.19-1.23 0-.67-.2-1.2-.6-1.59-.4-.38-.94-.57-1.62-.57h-.24z"/></svg>',
                    'razvlecheniya' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4 14h-2v-3h-2v3h-2v-6h2v3h2v-3h2v6z"/></svg>',
                    'magaziny' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M7 18c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1V2h4zm9 12c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z"/></svg>',
                    'obrazovanie' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M5 13L1 9l11 6v6l4-2.22V15L12 17L1 9l4-2.18L12 3z"/></svg>',
                    'uslugi-biznes' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M20 6h-2.18c.11-.31.18-.65.18-1a2.996 2.996 0 0 0-5.5-1.65l-.5.67-.5-.68C10.96 2.54 10.05 2 9 2 7.34 2 6 3.34 6 5c0 .35.07.69.18 1H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-5-2c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1z"/></svg>',
                    'uslugi-dom' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19 9.3V4h-3v2.6L12 3 2 12h3v8h6v-6h2v6h6v-8h3l-3-2.7zm-9 .7c0-1.1.9-2 2-2s2 .9 2 2h-4z"/></svg>',
                    'transport' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.85 7h10.29l1.08 3.11H5.77L6.85 7zM19 17H5v-5h14v5z"/><circle cx="7.5" cy="14.5" r="1.5"/><circle cx="16.5" cy="14.5" r="1.5"/></svg>',
                    'nedvizhimost' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>',
                    'oteli-arenda' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M7 13c1.66 0 3-1.34 3-3S8.66 7 7 7s-3 1.34-3 3 1.34 3 3 3zm12-6h-8v7H3V6H1v15h2v-3h18v3h2v-9c0-2.21-1.79-4-4-4z"/></svg>',
                ];
                $companyCats = $db->query("SELECT cat.slug, cat.name FROM categories cat JOIN company_categories cc ON cat.id = cc.category_id WHERE cc.company_id = {$company['id']} LIMIT 1")->fetch();
                $icon = $catIcons[$companyCats['slug'] ?? ''] ?? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>';

                $description = $company['description'] ?? '';
                $shortDesc = strlen($description) > 100 ? substr($description, 0, 100) . '...' : $description;

                // Working hours status
                $workingHours = json_decode($company['working_hours'] ?? '[]', true);
                $isOpen = false;
                $isClosed = false;
                $nowText = '';
                
                if (!empty($workingHours)) {
                    $today = date('N'); // 1 (Monday) to 7 (Sunday)
                    $currentTime = strtotime(date('H:i'));
                    
                    foreach ($workingHours as $hour) {
                        $parts = explode(': ', $hour);
                        if (count($parts) == 2) {
                            $days = $parts[0];
                            $times = $parts[1];
                            
                            // Check if today's day is in the schedule
                            if (strpos($days, date('D')) !== false || strpos($days, 'Daily') !== false || strpos($days, 'All') !== false || 
                                ($today == 1 && strpos($days, 'Mon') !== false) || ($today == 2 && strpos($days, 'Tue') !== false) ||
                                ($today == 3 && strpos($days, 'Wed') !== false) || ($today == 4 && strpos($days, 'Thu') !== false) ||
                                ($today == 5 && strpos($days, 'Fri') !== false) || ($today == 6 && strpos($days, 'Sat') !== false) ||
                                ($today == 7 && strpos($days, 'Sun') !== false)) {
                                
                                $timeParts = explode('-', $times);
                                if (count($timeParts) == 2) {
                                    $openTime = strtotime(trim($timeParts[0]));
                                    $closeTime = strtotime(trim($timeParts[1]));
                                    
                                    if ($currentTime >= $openTime && $currentTime <= $closeTime) {
                                        $isOpen = true;
                                        $nowText = "Открыто до " . trim($timeParts[1]);
                                        break;
                                    } else {
                                        $isClosed = true;
                                        $nowText = "Закрыто до " . trim($timeParts[0]) . " сегодня";
                                    }
                                }
                            }
                        }
                    }
                }
            ?>
                <a href="/company/<?= $company['id'] ?>" class="place-card">
                    <div class="place-image">
                        <?php if ($images && count($images) > 0): ?>
                            <img src="<?= htmlspecialchars(is_array($images[0]) ? ($images[0]['preview'] ?? $images[0]) : $images[0]) ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php if (count($images) > 1): ?>
                                <div class="image-counter" style="position: absolute; bottom: 8px; right: 8px; background: rgba(0,0,0,0.6); color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px;">
                                    <?= count($images) ?> фото
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 48px;">🏢</div>
                        <?php endif; ?>
                        
                        <?php if ($isOpen): ?>
                            <div class="place-badge badge-open">Открыто</div>
                        <?php elseif ($isClosed): ?>
                            <div class="place-badge badge-closed">Закрыто</div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="place-content">
                        <div class="place-header">
                            <div class="place-icon"><?= $icon ?></div>
                            <div class="place-info">
                                <div class="place-name"><?= htmlspecialchars($company['name']) ?></div>
                                <div class="place-category"><?= htmlspecialchars($companyCats['name'] ?? 'Заведение') ?></div>
                            </div>
                        </div>
                        
                        <?php if ($shortDesc): ?>
                            <div class="place-description"><?= htmlspecialchars($shortDesc) ?></div>
                        <?php endif; ?>
                        
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
                        
                        <?php if ($nowText): ?>
                            <div class="place-status" style="margin-top: 8px; font-size: 13px;">
                                <?= $nowText ?>
                            </div>
                        <?php endif; ?>
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
        <a href="#" class="nav-item">
            <span class="nav-icon">❤️</span>
            <span class="nav-label">Избранное</span>
        </a>
        <a href="#" class="nav-item">
            <span class="nav-icon">📊</span>
            <span class="nav-label">Статистика</span>
        </a>
        <a href="#" class="nav-item">
            <span class="nav-icon">👤</span>
            <span class="nav-label">Профиль</span>
        </a>
    </nav>

    <script>
        // Smooth scrolling for better UX
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.place-card');
            cards.forEach(card => {
                card.addEventListener('click', function(e) {
                    // Add smooth transition effect
                    this.style.transform = 'scale(0.98)';
                    setTimeout(() => {
                        this.style.transform = '';
                    }, 150);
                });
            });

            // Filter chips interaction
            const chips = document.querySelectorAll('.filter-chip');
            chips.forEach(chip => {
                chip.addEventListener('click', function(e) {
                    if (!this.classList.contains('active')) {
                        chips.forEach(c => c.classList.remove('active'));
                        this.classList.add('active');
                    }
                });
            });
        });
    </script>
</body>
</html>