<?php
/** @var \YellParser\Repository $repo */

// Get filter and sort parameters
$filter = $_GET['filter'] ?? 'all';
$sort = $_GET['sort'] ?? 'name_asc';

// Build query
$whereClause = '';
if ($filter === 'with') {
    $whereClause = 'WHERE c.id IN (SELECT DISTINCT city_id FROM companies)';
} elseif ($filter === 'without') {
    $whereClause = 'WHERE c.id NOT IN (SELECT DISTINCT city_id FROM companies)';
}

// Get cities with counts
$cities = $db->query("
    SELECT c.*, COUNT(comp.id) as establishment_count 
    FROM cities c 
    LEFT JOIN companies comp ON c.id = comp.city_id 
    $whereClause
    GROUP BY c.id
    ORDER BY 
        " . match($sort) {
            'name_asc' => 'c.name ASC',
            'name_desc' => 'c.name DESC',
            'count_asc' => 'establishment_count ASC, c.name ASC',
            'count_desc' => 'establishment_count DESC, c.name ASC',
            default => 'c.name ASC'
        } . "
")->fetchAll();
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
        h1 {
            font-size: 32px;
            margin-bottom: 20px;
            color: #1a1a1a;
        }
        .controls {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
            flex-wrap: wrap;
            align-items: center;
        }
        .control-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .control-group label {
            font-size: 14px;
            color: #666;
        }
        .control-group select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            background: white;
            cursor: pointer;
        }
        .control-group select:hover {
            border-color: #999;
        }
        .stats {
            margin-left: auto;
            font-size: 14px;
            color: #666;
        }
        .cities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
        .city-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s ease;
            border: 1px solid #e0e0e0;
        }
        .city-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
            border-color: #ccc;
        }
        .city-card.empty {
            opacity: 0.6;
        }
        .city-name {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .city-region {
            font-size: 14px;
            color: #666;
        }
        .city-count {
            margin-top: 12px;
            font-size: 14px;
            color: #999;
        }
        .city-count.has-places {
            color: #2e7d32;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Выберите город</h1>
        
        <div class="controls">
            <div class="control-group">
                <label for="sort">Сортировка:</label>
                <select id="sort" onchange="updateSort(this.value)">
                    <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>По алфавиту (А-Я)</option>
                    <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>По алфавиту (Я-А)</option>
                    <option value="count_desc" <?= $sort === 'count_desc' ? 'selected' : '' ?>>По числу заведений (убыв)</option>
                    <option value="count_asc" <?= $sort === 'count_asc' ? 'selected' : '' ?>>По числу заведений (возр)</option>
                </select>
            </div>
            
            <div class="control-group">
                <label for="filter">Фильтр:</label>
                <select id="filter" onchange="updateFilter(this.value)">
                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>Все города</option>
                    <option value="with" <?= $filter === 'with' ? 'selected' : '' ?>>С заведениями</option>
                    <option value="without" <?= $filter === 'without' ? 'selected' : '' ?>>Без заведений</option>
                </select>
            </div>
            
            <div class="stats">
                Показано: <?= count($cities) ?> городов
            </div>
        </div>
        
        <div class="cities-grid">
            <?php foreach ($cities as $city): ?>
                <a href="/city/<?= $city['id'] ?>" class="city-card <?= $city['establishment_count'] == 0 ? 'empty' : '' ?>">
                    <div class="city-name"><?= htmlspecialchars($city['name']) ?></div>
                    <div class="city-region"><?= htmlspecialchars($city['region'] ?? '') ?></div>
                    <div class="city-count <?= $city['establishment_count'] > 0 ? 'has-places' : '' ?>">
                        <?= $city['establishment_count'] ?> заведений
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    
    <script>
        function updateSort(value) {
            const url = new URL(window.location);
            url.searchParams.set('sort', value);
            window.location.href = url.toString();
        }
        
        function updateFilter(value) {
            const url = new URL(window.location);
            url.searchParams.set('filter', value);
            window.location.href = url.toString();
        }
    </script>
</body>
</html>
