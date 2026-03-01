<?php
/** @var \YellParser\Repository $repo */
$cityId = (int) ($_GET['city_id'] ?? 0);

$city = $db->query("SELECT * FROM cities WHERE id = $cityId")->fetch();
if (!$city) {
    http_response_code(404);
    echo '<h1>Город не найден</h1>';
    exit;
}

$companies = $db->query("SELECT * FROM companies WHERE city_id = $cityId ORDER BY rating DESC NULLS LAST, name LIMIT 100")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($city['name']) ?> - Каталог заведений</title>
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
        h1 {
            font-size: 28px;
            margin-bottom: 30px;
            color: #1a1a1a;
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
            height: 200px;
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
        .rating-star {
            font-size: 16px;
        }
        .reviews-count {
            font-size: 14px;
            color: #999;
        }
        .features {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
        }
        .feature-tag {
            font-size: 12px;
            padding: 4px 10px;
            background: #f0f0f0;
            border-radius: 20px;
            color: #666;
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
    </style>
</head>
<body>
    <div class="container">
        <div class="breadcrumb">
            <a href="/">← Все города</a>
        </div>
        <h1><?= htmlspecialchars($city['name']) ?></h1>
        <div class="companies-grid">
            <?php foreach ($companies as $company): 
                $images = json_decode($company['images'] ?? '[]', true);
                $features = json_decode($company['features'] ?? '[]', true);
                $firstImage = $images[0] ?? null;
            ?>
                <a href="/company/<?= $company['id'] ?>" class="company-card">
                    <?php if ($firstImage): ?>
                        <img src="<?= htmlspecialchars($firstImage['preview'] ?? $firstImage) ?>" 
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
                        <?php if ($features): ?>
                            <div class="features">
                                <?php foreach (array_slice($features, 0, 3) as $feature): ?>
                                    <span class="feature-tag"><?= htmlspecialchars($feature) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
