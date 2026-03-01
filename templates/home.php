<?php
/** @var \YellParser\Repository $repo */
/** @var PDO $db */

// Get filter and sort parameters
$filter = $_GET['filter'] ?? 'all';
$sort = $_GET['sort'] ?? 'name_asc';

// Build WHERE clause
$whereClause = '';
if ($filter === 'with') {
    $whereClause = 'WHERE comp.id IS NOT NULL';
} elseif ($filter === 'without') {
    $whereClause = 'WHERE comp.id IS NULL';
}

// Get cities with sorting
$orderBy = match($sort) {
    'name_asc' => 'c.name ASC',
    'name_desc' => 'c.name DESC',
    'count_asc' => 'COUNT(comp.id) ASC, c.name ASC',
    'count_desc' => 'COUNT(comp.id) DESC, c.name ASC',
    'weight_asc' => 'c.weight ASC, c.name ASC',
    'weight_desc' => 'c.weight DESC, c.name ASC',
    default => 'c.name ASC'
};

$cities = $db->query("
    SELECT c.*, COUNT(comp.id) as count 
    FROM cities c 
    LEFT JOIN companies comp ON c.id = comp.city_id 
    $whereClause
    GROUP BY c.id 
    ORDER BY $orderBy
")->fetchAll();

$totalCompanies = $db->query("SELECT COUNT(*) FROM companies")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Каталог заведений — Yell Parser</title>
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
        
        /* Hero section */
        .hero {
            text-align: center;
            padding: 60px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            margin-bottom: 40px;
            color: white;
        }
        .hero h1 {
            font-size: 42px;
            font-weight: 700;
            margin-bottom: 16px;
        }
        .hero p {
            font-size: 18px;
            opacity: 0.9;
            margin-bottom: 32px;
        }
        .hero-stats {
            display: flex;
            justify-content: center;
            gap: 40px;
            margin-bottom: 32px;
        }
        .hero-stat {
            text-align: center;
        }
        .hero-stat-number {
            font-size: 36px;
            font-weight: 700;
        }
        .hero-stat-label {
            font-size: 14px;
            opacity: 0.8;
        }
        .btn-primary {
            display: inline-block;
            padding: 16px 32px;
            background: white;
            color: #667eea;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.2s;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.2);
        }
        
        /* Cities section */
        .section-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 24px;
            color: #1a1a1a;
        }
        .cities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 16px;
        }
        .city-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s ease;
            border: 1px solid #e0e0e0;
            text-align: center;
        }
        .city-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
            border-color: #667eea;
        }
        .city-name {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .city-count {
            font-size: 13px;
            color: #666;
        }
        
        /* Quick filters */
        .quick-filters {
            margin-top: 40px;
            padding: 24px;
            background: white;
            border-radius: 16px;
        }
        .quick-filters-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
        }
        .quick-filters-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .quick-filter {
            padding: 10px 18px;
            background: #f5f5f5;
            border-radius: 20px;
            text-decoration: none;
            color: #333;
            font-size: 14px;
            transition: all 0.2s;
        }
        .quick-filter:hover {
            background: #667eea;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Hero -->
        <div class="hero">
            <h1>Каталог заведений</h1>
            <p>Поиск ресторанов, кафе, баров и услуг в вашем городе</p>
            <div class="hero-stats">
                <div class="hero-stat">
                    <div class="hero-stat-number"><?= count($cities) ?></div>
                    <div class="hero-stat-label">городов</div>
                </div>
                <div class="hero-stat">
                    <div class="hero-stat-number"><?= $totalCompanies ?></div>
                    <div class="hero-stat-label">заведений</div>
                </div>
            </div>
            <a href="/catalog" class="btn-primary">Открыть каталог</a>
        </div>
        
        <!-- Cities -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
            <h2 class="section-title" style="margin: 0;">Выберите город</h2>
            
            <div style="display: flex; gap: 12px; align-items: center;">
                <select name="sort" onchange="window.location.href='?sort='+this.value+'&filter=<?= $filter ?>'" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; cursor: pointer;">
                    <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>По алфавиту (А-Я)</option>
                    <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>По алфавиту (Я-А)</option>
                    <option value="count_desc" <?= $sort === 'count_desc' ? 'selected' : '' ?>>По числу заведений (убыв)</option>
                    <option value="count_asc" <?= $sort === 'count_asc' ? 'selected' : '' ?>>По числу заведений (возр)</option>
                    <option value="weight_desc" <?= $sort === 'weight_desc' ? 'selected' : '' ?>>По весу города (убыв)</option>
                    <option value="weight_asc" <?= $sort === 'weight_asc' ? 'selected' : '' ?>>По весу города (возр)</option>
                </select>
                
                <select name="filter" onchange="window.location.href='?sort=<?= $sort ?>&filter='+this.value" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; cursor: pointer;">
                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>Все города</option>
                    <option value="with" <?= $filter === 'with' ? 'selected' : '' ?>>С заведениями</option>
                    <option value="without" <?= $filter === 'without' ? 'selected' : '' ?>>Без заведений</option>
                </select>
                
                <span style="font-size: 14px; color: #666;"><?= count($cities) ?> городов</span>
            </div>
        </div>
        
        <div class="cities-grid">
            <?php foreach ($cities as $city): ?>
                <a href="/catalog?city=<?= $city['id'] ?>" class="city-card">
                    <div class="city-name"><?= htmlspecialchars($city['name']) ?></div>
                    <div class="city-count"><?= $city['count'] ?> заведений</div>
                </a>
            <?php endforeach; ?>
        </div>
        
        <!-- Quick filters -->
        <div class="quick-filters">
            <div class="quick-filters-title">Быстрый поиск по категориям</div>
            <div class="quick-filters-grid">
                <a href="/catalog?category=restorany-i-kafe" class="quick-filter">🍽️ Рестораны</a>
                <a href="/catalog?category=bary-i-kluby" class="quick-filter">🍸 Бары</a>
                <a href="/catalog?category=krasota" class="quick-filter">💇 Красота</a>
                <a href="/catalog?category=medicina" class="quick-filter">🏥 Медицина</a>
                <a href="/catalog?category=sport" class="quick-filter">🏃 Спорт</a>
                <a href="/catalog?category=razvlecheniya" class="quick-filter">🎭 Развлечения</a>
            </div>
        </div>
    </div>
</body>
</html>
