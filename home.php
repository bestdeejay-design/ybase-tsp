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
        
        .logo {
            font-size: 24px;
            font-weight: 700;
            color: var(--accent-primary);
            text-decoration: none;
        }
        
        /* Hero section */
        .hero {
            text-align: center;
            padding: 80px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: var(--radius-xxl);
            margin: 40px 0;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at center, rgba(255,255,255,0.1) 0%, transparent 70%);
        }
        
        .hero-content {
            position: relative;
            z-index: 2;
        }
        
        .hero h1 {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 16px;
            letter-spacing: -0.5px;
        }
        
        .hero p {
            font-size: 20px;
            opacity: 0.9;
            margin-bottom: 40px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .hero-stats {
            display: flex;
            justify-content: center;
            gap: 60px;
            margin-bottom: 40px;
            flex-wrap: wrap;
        }
        
        .hero-stat {
            text-align: center;
        }
        
        .hero-stat-number {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .hero-stat-label {
            font-size: 16px;
            opacity: 0.8;
            font-weight: 500;
        }
        
        .btn-primary {
            display: inline-block;
            padding: 18px 36px;
            background: white;
            color: #667eea;
            text-decoration: none;
            border-radius: var(--radius-xl);
            font-weight: 600;
            font-size: 18px;
            transition: var(--transition-medium);
            box-shadow: var(--shadow-medium);
            border: none;
            cursor: pointer;
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-heavy);
        }
        
        /* Cities section */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .section-title {
            font-size: 32px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
        }
        
        .controls {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .control-select {
            padding: 12px 16px;
            border: 1px solid var(--border-medium);
            border-radius: var(--radius-large);
            font-size: 15px;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-width: 200px;
            transition: var(--transition-fast);
        }
        
        .control-select:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(0, 122, 255, 0.1);
        }
        
        .stats-badge {
            padding: 8px 16px;
            background: var(--bg-tertiary);
            border-radius: var(--radius-large);
            font-size: 15px;
            color: var(--text-secondary);
        }
        
        .cities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .city-card {
            background: var(--bg-primary);
            border-radius: var(--radius-xl);
            padding: 24px;
            text-decoration: none;
            color: inherit;
            transition: var(--transition-medium);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-light);
            text-align: center;
        }
        
        .city-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-medium);
            border-color: var(--accent-primary);
        }
        
        .city-name {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-primary);
        }
        
        .city-count {
            font-size: 14px;
            color: var(--text-secondary);
        }
        
        /* Quick filters */
        .quick-filters {
            background: var(--bg-primary);
            border-radius: var(--radius-xl);
            padding: 32px;
            margin-bottom: 40px;
            box-shadow: var(--shadow-light);
        }
        
        .quick-filters-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--text-primary);
        }
        
        .quick-filters-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .quick-filter {
            padding: 12px 20px;
            background: var(--bg-tertiary);
            border-radius: var(--radius-large);
            text-decoration: none;
            color: var(--text-primary);
            font-size: 15px;
            font-weight: 500;
            transition: var(--transition-fast);
            border: 1px solid var(--border-light);
        }
        
        .quick-filter:hover {
            background: var(--accent-primary);
            color: white;
            border-color: var(--accent-primary);
        }
        
        /* Footer */
        .footer {
            text-align: center;
            padding: 40px 0;
            color: var(--text-secondary);
            font-size: 14px;
            border-top: 1px solid var(--border-light);
            margin-top: 40px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: stretch;
            }
            
            .hero {
                padding: 60px 20px;
            }
            
            .hero h1 {
                font-size: 36px;
            }
            
            .hero p {
                font-size: 18px;
            }
            
            .hero-stats {
                gap: 30px;
            }
            
            .hero-stat-number {
                font-size: 36px;
            }
            
            .section-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .controls {
                width: 100%;
                justify-content: space-between;
            }
            
            .cities-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
                gap: 16px;
            }
            
            .quick-filters {
                padding: 24px;
            }
        }
        
        @media (max-width: 480px) {
            .hero {
                padding: 40px 16px;
            }
            
            .hero h1 {
                font-size: 28px;
            }
            
            .hero p {
                font-size: 16px;
            }
            
            .hero-stats {
                flex-direction: column;
                gap: 20px;
            }
            
            .cities-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            }
            
            .control-select {
                width: 100%;
                min-width: auto;
            }
            
            .btn-primary {
                padding: 16px 24px;
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="page-header">
        <div class="header-content">
            <a href="/" class="logo">YellParser</a>
        </div>
    </div>
    
    <div class="container">
        <!-- Hero -->
        <div class="hero">
            <div class="hero-content">
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
        </div>
        
        <!-- Cities -->
        <div class="section-header">
            <h2 class="section-title">Выберите город</h2>
            
            <div class="controls">
                <select name="sort" class="control-select" onchange="window.location.href='?sort='+this.value+'&filter=<?= $filter ?>'">
                    <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>По алфавиту (А-Я)</option>
                    <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>По алфавиту (Я-А)</option>
                    <option value="count_desc" <?= $sort === 'count_desc' ? 'selected' : '' ?>>По числу заведений (убыв)</option>
                    <option value="count_asc" <?= $sort === 'count_asc' ? 'selected' : '' ?>>По числу заведений (возр)</option>
                    <option value="weight_desc" <?= $sort === 'weight_desc' ? 'selected' : '' ?>>По весу города (убыв)</option>
                    <option value="weight_asc" <?= $sort === 'weight_asc' ? 'selected' : '' ?>>По весу города (возр)</option>
                </select>
                
                <select name="filter" class="control-select" onchange="window.location.href='?sort=<?= $sort ?>&filter='+this.value">
                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>Все города</option>
                    <option value="with" <?= $filter === 'with' ? 'selected' : '' ?>>С заведениями</option>
                    <option value="without" <?= $filter === 'without' ? 'selected' : '' ?>>Без заведений</option>
                </select>
                
                <span class="stats-badge"><?= count($cities) ?> городов</span>
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
        
        <div class="footer">
            <p>© 2024 YellParser — Каталог заведений</p>
        </div>
    </div>
</body>
</html>
