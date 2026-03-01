<?php
/** @var \YellParser\Repository $repo */
/** @var PDO $db */

$query = $_GET['q'] ?? '';
$cityId = isset($_GET['city']) ? (int)$_GET['city'] : null;
$selectedTags = $_GET['tags'] ?? [];
if (!is_array($selectedTags)) $selectedTags = [$selectedTags];

// Получаем все теги по категориям
$tagsByCategory = $repo->getTagsByCategory();

// Выполняем поиск
$companies = [];
if ($query || !empty($selectedTags) || $cityId) {
    $tagIds = array_map('intval', $selectedTags);
    $companies = $repo->searchCompanies($query, $cityId, $tagIds, 50);
}

// Получаем список городов
$cities = $db->query("SELECT id, name FROM cities ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Поиск заведений</title>
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
        .search-header {
            margin-bottom: 40px;
        }
        .search-box {
            position: relative;
            margin-bottom: 24px;
        }
        .search-input {
            width: 100%;
            padding: 20px 24px;
            font-size: 18px;
            border: 2px solid #e0e0e0;
            border-radius: 16px;
            outline: none;
            transition: border-color 0.2s;
        }
        .search-input:focus {
            border-color: #0066cc;
        }
        .search-button {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            padding: 12px 24px;
            background: #0066cc;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            cursor: pointer;
        }
        .search-button:hover {
            background: #0055aa;
        }
        
        /* Фильтры */
        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 24px;
            align-items: center;
        }
        .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .filter-label {
            font-size: 14px;
            color: #666;
            font-weight: 500;
        }
        .filter-select {
            padding: 10px 16px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 14px;
            background: white;
            cursor: pointer;
        }
        
        /* Быстрые фильтры */
        .quick-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }
        .quick-filter {
            padding: 8px 16px;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 20px;
            font-size: 14px;
            color: #555;
            text-decoration: none;
            transition: all 0.2s;
        }
        .quick-filter:hover,
        .quick-filter.active {
            background: #0066cc;
            color: white;
            border-color: #0066cc;
        }
        
        /* Раскрывающиеся фильтры */
        .advanced-filters {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
        }
        .advanced-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #0066cc;
            font-size: 14px;
            cursor: pointer;
            margin-bottom: 16px;
        }
        .advanced-content {
            display: grid;
            gap: 20px;
        }
        .filter-category {
            border-bottom: 1px solid #f0f0f0;
            padding-bottom: 16px;
        }
        .filter-category:last-child {
            border-bottom: none;
        }
        .filter-category-title {
            font-size: 14px;
            font-weight: 600;
            color: #333;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .filter-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .filter-tag {
            padding: 8px 14px;
            background: #f5f5f5;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
            cursor: pointer;
            transition: all 0.2s;
        }
        .filter-tag:hover {
            background: #e8e8e8;
        }
        .filter-tag.active {
            background: #0066cc;
            color: white;
            border-color: #0066cc;
        }
        .filter-tag input {
            display: none;
        }
        
        /* Результаты */
        .results-count {
            font-size: 16px;
            color: #666;
            margin-bottom: 20px;
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
        .no-results-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="search-header">
            <form action="/search" method="GET">
                <div class="search-box">
                    <input type="text" name="q" class="search-input" 
                           placeholder="Поиск ресторанов, кафе, баров..." 
                           value="<?= htmlspecialchars($query) ?>">
                    <button type="submit" class="search-button">Найти</button>
                </div>
                
                <div class="filters">
                    <div class="filter-group">
                        <span class="filter-label">Город:</span>
                        <select name="city" class="filter-select">
                            <option value="">Все города</option>
                            <?php foreach ($cities as $city): ?>
                                <option value="<?= $city['id'] ?>" <?= $cityId == $city['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($city['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Быстрые фильтры -->
                <div class="quick-filters">
                    <a href="/search?tags[]=1" class="quick-filter <?= in_array('1', $selectedTags) ? 'active' : '' ?>">Кафе</a>
                    <a href="/search?tags[]=2" class="quick-filter <?= in_array('2', $selectedTags) ? 'active' : '' ?>">Рестораны</a>
                    <a href="/search?tags[]=3" class="quick-filter <?= in_array('3', $selectedTags) ? 'active' : '' ?>">Бары</a>
                    <a href="/search?tags[]=4" class="quick-filter <?= in_array('4', $selectedTags) ? 'active' : '' ?>">Фудкорты</a>
                </div>
                
                <!-- Расширенные фильтры -->
                <div class="advanced-filters">
                    <div class="advanced-toggle" onclick="this.nextElementSibling.classList.toggle('hidden')">
                        <span>⚙️</span>
                        <span>Все фильтры</span>
                    </div>
                    <div class="advanced-content">
                        <?php foreach ($tagsByCategory as $category => $tagsJson): 
                            $tags = json_decode($tagsJson, true);
                        ?>
                            <div class="filter-category">
                                <div class="filter-category-title"><?= htmlspecialchars($category) ?></div>
                                <div class="filter-tags">
                                    <?php foreach ($tags as $tag): ?>
                                        <label class="filter-tag <?= in_array($tag['id'], $selectedTags) ? 'active' : '' ?>">
                                            <input type="checkbox" name="tags[]" value="<?= $tag['id'] ?>" 
                                                   <?= in_array($tag['id'], $selectedTags) ? 'checked' : '' ?>>
                                            <?= htmlspecialchars($tag['name']) ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Результаты -->
        <?php if ($query || !empty($selectedTags) || $cityId): ?>
            <div class="results-count">
                Найдено: <?= count($companies) ?> заведений
            </div>
            
            <?php if (empty($companies)): ?>
                <div class="no-results">
                    <div class="no-results-icon">🔍</div>
                    <p>Ничего не найдено. Попробуйте изменить запрос или фильтры.</p>
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
                                <img src="<?= htmlspecialchars($firstImage['preview'] ?? $firstImage) ?>" 
                                     alt="" class="company-image" loading="lazy">
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
                                        <?php foreach (array_slice($companyTags, 0, 4) as $tag): ?>
                                            <span class="company-tag"><?= htmlspecialchars($tag['name']) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <script>
        // Автоматическая отправка формы при изменении чекбоксов
        document.querySelectorAll('.filter-tag input').forEach(input => {
            input.addEventListener('change', () => {
                input.closest('form').submit();
            });
        });
    </script>
</body>
</html>
