<?php
/** @var \YellParser\Repository $repo */
$cityId = (int) ($_GET['city_id'] ?? 0);

$city = $db->query("SELECT * FROM cities WHERE id = $cityId")->fetch();
if (!$city) {
    http_response_code(404);
    echo '<h1>Город не найден</h1>';
    exit;
}

// Get all categories for filtering
$categories = $db->query("SELECT c.id, c.name, COUNT(cc.company_id) as count 
                      FROM categories c 
                      LEFT JOIN company_categories cc ON c.id = cc.category_id 
                      LEFT JOIN companies comp ON cc.company_id = comp.id 
                      WHERE comp.city_id = $cityId 
                      GROUP BY c.id, c.name 
                      ORDER BY c.name")->fetchAll();

// Get companies with category info
$categoryId = (int) ($_GET['category_id'] ?? 0);

$whereClause = "comp.city_id = $cityId";
if ($categoryId > 0) {
    $whereClause .= " AND cc.category_id = $categoryId";
}

$companies = $db->query("SELECT comp.*, 
                           json_agg(DISTINCT cat.name) as categories,
                           json_agg(DISTINCT cat.id) as category_ids
                      FROM companies comp
                      LEFT JOIN company_categories cc ON comp.id = cc.company_id
                      LEFT JOIN categories cat ON cc.category_id = cat.id
                      WHERE $whereClause
                      GROUP BY comp.id
                      ORDER BY comp.rating DESC NULLS LAST, comp.name 
                      LIMIT 100")->fetchAll();

// Get sorting parameter
$sort = $_GET['sort'] ?? 'rating';
$sortOptions = [
    'rating' => 'По рейтингу',
    'name' => 'По названию',
    'reviews' => 'По отзывам'
];

if ($sort === 'rating') {
    usort($companies, function($a, $b) {
        return ($b['rating'] ?? 0) <=> ($a['rating'] ?? 0);
    });
} elseif ($sort === 'name') {
    usort($companies, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
} elseif ($sort === 'reviews') {
    usort($companies, function($a, $b) {
        return ($b['review_count'] ?? 0) <=> ($a['review_count'] ?? 0);
    });
}

// Get search query
$searchQuery = trim($_GET['q'] ?? '');
if ($searchQuery) {
    $searchLower = strtolower($searchQuery);
    $companies = array_filter($companies, function($company) use ($searchLower) {
        return strpos(strtolower($company['name']), $searchLower) !== false || 
               strpos(strtolower($company['address'] ?? ''), $searchLower) !== false;
    });
}

// Filter for "Open Now" if requested
$openNow = isset($_GET['open_now']);
if ($openNow) {
    $currentDay = date('D'); // Mon, Tue, etc.
    $currentTime = strtotime(date('H:i')); // Current time in seconds
    
    $companies = array_filter($companies, function($company) use ($currentDay, $currentTime) {
        $workingHours = json_decode($company['working_hours'] ?? '[]', true);
        
        foreach ($workingHours as $hour) {
            $parts = explode(': ', $hour);
            if (count($parts) == 2) {
                $daysPart = $parts[0];
                $timesPart = $parts[1];
                
                if (strpos($daysPart, $currentDay) !== false || strpos($daysPart, 'Daily') !== false) {
                    $timeParts = explode('-', $timesPart);
                    if (count($timeParts) == 2) {
                        $openTime = strtotime(trim($timeParts[0]));
                        $closeTime = strtotime(trim($timeParts[1]));
                        
                        if ($currentTime >= $openTime && $currentTime <= $closeTime) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    });
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($city['name']) ?> - Каталог заведений</title>
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
            --radius-xxl: 24px;
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
            background: var(--bg-secondary);
            color: var(--text-primary);
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        /* Header */
        .page-header {
            background: var(--bg-primary);
            padding: 20px 0;
            border-bottom: 1px solid var(--border-light);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .breadcrumb {
            font-size: 14px;
        }
        
        .breadcrumb a {
            color: var(--text-secondary);
            text-decoration: none;
            transition: var(--transition-fast);
        }
        
        .breadcrumb a:hover {
            color: var(--accent-primary);
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
        }
        
        /* Filters */
        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            padding: 24px 0;
            border-bottom: 1px solid var(--border-light);
            margin-bottom: 24px;
            background: var(--bg-primary);
            border-radius: var(--radius-xl);
            padding: 24px;
        }
        
        .filter-group {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .filter-item {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        
        .filter-label {
            font-size: 13px;
            color: var(--text-tertiary);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filter-select {
            padding: 12px 16px;
            border: 1px solid var(--border-medium);
            border-radius: var(--radius-large);
            font-size: 15px;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-width: 180px;
            transition: var(--transition-fast);
        }
        
        .filter-select:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(0, 122, 255, 0.1);
        }
        
        .search-input {
            padding: 12px 16px;
            border: 1px solid var(--border-medium);
            border-radius: var(--radius-large);
            font-size: 15px;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-width: 250px;
            transition: var(--transition-fast);
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(0, 122, 255, 0.1);
        }
        
        .open-now-btn {
            padding: 12px 20px;
            background: var(--accent-primary);
            color: white;
            border: none;
            border-radius: var(--radius-large);
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition-fast);
        }
        
        .open-now-btn:hover {
            background: #0062cc;
        }
        
        .open-now-btn.active {
            background: #4CAF50;
        }
        
        .reset-filters {
            padding: 12px 20px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            border: 1px solid var(--border-medium);
            border-radius: var(--radius-large);
            font-size: 15px;
            cursor: pointer;
            transition: var(--transition-fast);
        }
        
        .reset-filters:hover {
            background: #eaeaea;
        }
        
        /* Stats */
        .results-stats {
            padding: 16px 0;
            color: var(--text-secondary);
            font-size: 15px;
        }
        
        /* Companies Grid */
        .companies-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
            margin-bottom: 40px;
        }
        
        .company-card {
            background: var(--bg-primary);
            border-radius: var(--radius-xl);
            overflow: hidden;
            text-decoration: none;
            color: inherit;
            transition: var(--transition-medium);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-light);
        }
        
        .company-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-medium);
        }
        
        .company-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .company-content {
            padding: 24px;
        }
        
        .company-name {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text-primary);
            line-height: 1.4;
        }
        
        .company-address {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 16px;
            line-height: 1.4;
        }
        
        .company-meta {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .rating {
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            color: #FFAC33;
        }
        
        .rating-star {
            font-size: 16px;
        }
        
        .reviews-count {
            font-size: 14px;
            color: var(--text-tertiary);
        }
        
        .categories {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 16px;
        }
        
        .category-tag {
            font-size: 12px;
            padding: 5px 12px;
            background: var(--bg-tertiary);
            border-radius: 20px;
            color: var(--text-secondary);
            border: 1px solid var(--border-light);
        }
        
        .no-image {
            width: 100%;
            height: 200px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
        }
        
        /* Working Hours Status */
        .hours-status {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            margin-top: 8px;
        }
        
        .status-open {
            color: #4CAF50;
        }
        
        .status-closed {
            color: #f44336;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: stretch;
            }
            
            .page-title {
                text-align: center;
            }
            
            .filters {
                padding: 16px;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .search-input {
                min-width: auto;
                width: 100%;
            }
            
            .filter-select {
                min-width: auto;
                width: 100%;
            }
            
            .companies-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .filters {
                gap: 12px;
            }
            
            .filter-item {
                width: 100%;
            }
            
            .companies-grid {
                gap: 16px;
            }
            
            .company-content {
                padding: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="page-header">
        <div class="header-content">
            <div class="breadcrumb">
                <a href="/">← Все города</a>
            </div>
            <h1 class="page-title"><?= htmlspecialchars($city['name']) ?></h1>
        </div>
    </div>
    
    <div class="container">
        <div class="filters">
            <form method="GET" id="filter-form">
                <input type="hidden" name="city_id" value="<?= $cityId ?>">
                
                <div class="filter-group">
                    <div class="filter-item">
                        <label class="filter-label">Категория</label>
                        <select name="category_id" class="filter-select" onchange="document.getElementById('filter-form').submit()">
                            <option value="">Все категории</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= $category['id'] ?>" 
                                    <?= $categoryId == $category['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($category['name']) ?> (<?= $category['count'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-item">
                        <label class="filter-label">Сортировка</label>
                        <select name="sort" class="filter-select" onchange="document.getElementById('filter-form').submit()">
                            <?php foreach ($sortOptions as $value => $label): ?>
                                <option value="<?= $value ?>" 
                                    <?= $sort === $value ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-item">
                        <label class="filter-label">Поиск</label>
                        <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>" 
                               placeholder="Поиск по названию или адресу" class="search-input">
                    </div>
                </div>
                
                <div class="filter-group">
                    <button type="submit" class="open-now-btn <?= $openNow ? 'active' : '' ?>" 
                            name="open_now" value="<?= $openNow ? '' : '1' ?>">
                        <?= $openNow ? '🟢 Открыто сейчас' : 'Открыто сейчас' ?>
                    </button>
                    
                    <?php if ($categoryId || $searchQuery || $openNow || $sort !== 'rating'): ?>
                        <a href="?city_id=<?= $cityId ?>" class="reset-filters">Сбросить фильтры</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        
        <div class="results-stats">
            Найдено: <?= count($companies) ?> заведений
            <?php if ($openNow): ?>
                <span>(открыты сейчас)</span>
            <?php endif; ?>
        </div>
        
        <div class="companies-grid">
            <?php foreach ($companies as $company): 
                $images = json_decode($company['images'] ?? '[]', true);
                $features = json_decode($company['features'] ?? '[]', true);
                $workingHours = json_decode($company['working_hours'] ?? '[]', true);
                $categories = json_decode($company['categories'] ?? 'null', true);
                $firstImage = $images[0] ?? null;
                
                // Check if currently open
                $isCurrentlyOpen = false;
                $currentDay = date('D');
                $currentTime = strtotime(date('H:i')); // Current time in seconds
                
                foreach ($workingHours as $hour) {
                    $parts = explode(': ', $hour);
                    if (count($parts) == 2) {
                        $daysPart = $parts[0];
                        $timesPart = $parts[1];
                        
                        if (strpos($daysPart, $currentDay) !== false || strpos($daysPart, 'Daily') !== false) {
                            $timeParts = explode('-', $timesPart);
                            if (count($timeParts) == 2) {
                                $openTime = strtotime(trim($timeParts[0]));
                                $closeTime = strtotime(trim($timeParts[1]));
                                
                                if ($currentTime >= $openTime && $currentTime <= $closeTime) {
                                    $isCurrentlyOpen = true;
                                    break;
                                }
                            }
                        }
                    }
                }
            ?>
                <a href="/company/<?= $company['id'] ?>" class="company-card">
                    <?php if ($firstImage): ?>
                        <img src="<?= htmlspecialchars(is_array($firstImage) ? ($firstImage['preview'] ?? $firstImage) : $firstImage) ?>" 
                             alt="<?= htmlspecialchars($company['name']) ?>"
                             class="company-image"
                             loading="lazy">
                    <?php else: ?>
                        <div class="no-image">🏢</div>
                    <?php endif; ?>
                    
                    <div class="company-content">
                        <div class="company-name"><?= htmlspecialchars($company['name']) ?></div>
                        <div class="company-address"><?= htmlspecialchars($company['address'] ?? 'Адрес не указан') ?></div>
                        
                        <div class="company-meta">
                            <?php if ($company['rating']): ?>
                                <span class="rating">
                                    <span class="rating-star">★</span>
                                    <?= number_format($company['rating'], 1) ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($company['review_count']): ?>
                                <span class="reviews-count"><?= $company['review_count'] ?> отзывов</span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($isCurrentlyOpen): ?>
                            <div class="hours-status">
                                <span class="status-open">🟢 Открыто сейчас</span>
                            </div>
                        <?php elseif (!empty($workingHours)): ?>
                            <div class="hours-status">
                                <span class="status-closed">🔴 Закрыто</span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($categories): ?>
                            <div class="categories">
                                <?php foreach ($categories as $category): 
                                    if ($category):
                                ?>
                                    <span class="category-tag"><?= htmlspecialchars($category) ?></span>
                                <?php 
                                    endif;
                                endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    
    <script>
        // Handle form submission for search input
        document.querySelector('.search-input')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('filter-form').submit();
            }
        });
    </script>
</body>
</html>
