<?php
/** @var \YellParser\Repository $repo */
$companyId = (int) ($_GET['id'] ?? 0);

$company = $db->query("SELECT c.*, ci.name as city_name, ci.slug as city_slug 
                       FROM companies c 
                       LEFT JOIN cities ci ON c.city_id = ci.id 
                       WHERE c.id = $companyId")->fetch();

if (!$company) {
    http_response_code(404);
    echo '<h1>Заведение не найдено</h1>';
    exit;
}

$images = json_decode($company['images'] ?? '[]', true);
$features = json_decode($company['features'] ?? '[]', true);
$workingHours = json_decode($company['working_hours'] ?? '[]', true);
$menu = json_decode($company['menu'] ?? '[]', true);
$socialLinks = json_decode($company['social_links'] ?? '[]', true);

// Получаем категории
$categories = $db->query("SELECT cat.name 
                          FROM categories cat 
                          JOIN company_categories cc ON cat.id = cc.category_id 
                          WHERE cc.company_id = $companyId")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($company['name']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }
        .container {
            max-width: 1000px;
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
        .company-header {
            background: white;
            border-radius: 20px;
            padding: 32px;
            margin-bottom: 24px;
        }
        .company-name {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 16px;
            color: #1a1a1a;
        }
        .company-meta {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .rating {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 20px;
            font-weight: 700;
            color: #f5a623;
        }
        .rating-star {
            font-size: 24px;
        }
        .reviews-count {
            font-size: 16px;
            color: #666;
        }
        .categories {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .category-tag {
            font-size: 13px;
            padding: 6px 14px;
            background: #f0f0f0;
            border-radius: 20px;
            color: #555;
        }
        .gallery {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 12px;
            margin-bottom: 24px;
            border-radius: 20px;
            overflow: hidden;
        }
        .gallery-main {
            grid-row: span 2;
        }
        .gallery img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            min-height: 200px;
        }
        .gallery-main img {
            min-height: 412px;
        }
        .info-section {
            background: white;
            border-radius: 20px;
            padding: 32px;
            margin-bottom: 24px;
        }
        .info-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #1a1a1a;
        }
        .info-grid {
            display: grid;
            gap: 16px;
        }
        .info-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .info-icon {
            font-size: 20px;
            width: 24px;
            text-align: center;
        }
        .info-content {
            flex: 1;
        }
        .info-label {
            font-size: 13px;
            color: #999;
            margin-bottom: 4px;
        }
        .info-value {
            font-size: 15px;
            color: #333;
        }
        .info-value a {
            color: #0066cc;
            text-decoration: none;
        }
        .info-value a:hover {
            text-decoration: underline;
        }
        .description {
            font-size: 16px;
            line-height: 1.6;
            color: #444;
        }
        .description p {
            margin-bottom: 12px;
        }
        .description p:last-child {
            margin-bottom: 0;
        }
        .features-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .feature-group {
            margin-bottom: 24px;
        }
        .feature-group:last-child {
            margin-bottom: 0;
        }
        .feature-group-title {
            font-size: 16px;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .feature-count {
            font-size: 13px;
            font-weight: 400;
            color: #999;
            background: #f0f0f0;
            padding: 2px 10px;
            border-radius: 20px;
        }
        .feature-item {
            font-size: 14px;
            padding: 10px 18px;
            background: #f8f8f8;
            border-radius: 12px;
            color: #555;
            border: 1px solid #e8e8e8;
        }
        .tag-link {
            text-decoration: none;
            transition: all 0.2s;
        }
        .tag-link:hover {
            background: #0066cc;
            color: white;
            border-color: #0066cc;
        }
        .menu-category {
            margin-bottom: 28px;
        }
        .menu-category:last-child {
            margin-bottom: 0;
        }
        .menu-category-title {
            font-size: 18px;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #f0f0f0;
        }
        .menu-items {
            display: grid;
            gap: 12px;
        }
        .menu-item {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            padding: 12px 0;
            border-bottom: 1px solid #f5f5f5;
        }
        .menu-item:last-child {
            border-bottom: none;
        }
        .menu-item-info {
            flex: 1;
        }
        .menu-item-name {
            font-size: 15px;
            color: #333;
            margin-bottom: 4px;
        }
        .menu-item-portion {
            font-size: 13px;
            color: #999;
        }
        .menu-item-price {
            font-size: 15px;
            font-weight: 600;
            color: #1a1a1a;
            white-space: nowrap;
        }
        .social-links {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        .social-link {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: #f8f8f8;
            border-radius: 12px;
            text-decoration: none;
            color: #333;
            transition: all 0.2s;
            border: 1px solid #e8e8e8;
        }
        .social-link:hover {
            background: #0066cc;
            color: white;
            border-color: #0066cc;
        }
        .social-icon {
            font-size: 14px;
            font-weight: 700;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e8e8e8;
            border-radius: 8px;
        }
        .social-link:hover .social-icon {
            background: rgba(255,255,255,0.2);
        }
        .social-name {
            font-size: 14px;
            text-transform: capitalize;
        }
        .working-hours {
            display: grid;
            gap: 8px;
        }
        .hour-item {
            font-size: 14px;
            padding: 8px 12px;
            background: #f8f8f8;
            border-radius: 8px;
        }
        .features-collapsible .features-content {
            display: none;
        }
        .features-collapsible.expanded .features-content {
            display: block;
        }
        .features-toggle {
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            user-select: none;
        }
        .toggle-icon {
            font-size: 14px;
            transition: transform 0.2s;
        }
        .features-collapsible.expanded .toggle-icon {
            transform: rotate(180deg);
        }
        @media (max-width: 768px) {
            .gallery {
                grid-template-columns: 1fr;
            }
            .gallery-main {
                grid-row: span 1;
            }
            .gallery-main img {
                min-height: 250px;
            }
            .company-name {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="breadcrumb">
            <a href="/">← Все города</a> / 
            <a href="/city/<?= $company['city_id'] ?>"><?= htmlspecialchars($company['city_name']) ?></a>
        </div>

        <div class="company-header">
            <h1 class="company-name"><?= htmlspecialchars($company['name']) ?></h1>
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
            <?php if ($categories): ?>
                <div class="categories">
                    <?php foreach ($categories as $cat): ?>
                        <span class="category-tag"><?= htmlspecialchars($cat) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($images): ?>
            <div class="gallery">
                <?php foreach (array_slice($images, 0, 3) as $i => $img): 
                    $imgUrl = is_array($img) ? ($img['preview'] ?? $img) : $img;
                ?>
                    <div class="<?= $i === 0 ? 'gallery-main' : '' ?>">
                        <img src="<?= htmlspecialchars($imgUrl) ?>" 
                             alt="<?= htmlspecialchars($company['name']) ?>"
                             loading="lazy">
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($socialLinks): ?>
            <div class="info-section">
                <h2 class="info-title">Социальные сети</h2>
                <div class="social-links">
                    <?php foreach ($socialLinks as $network => $urls): 
                        $icons = [
                            'vk' => 'VK',
                            'telegram' => 'TG',
                            'instagram' => 'IG',
                            'facebook' => 'FB',
                            'youtube' => 'YT',
                            'whatsapp' => 'WA',
                            'viber' => 'VB',
                        ];
                        $icon = $icons[$network] ?? strtoupper(substr($network, 0, 2));
                        foreach ($urls as $url):
                            if (str_contains($url, 'yell.ru') || str_contains($url, 'vk.com/yellru')) continue; // Skip yell.ru links
                    ?>
                        <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener" class="social-link">
                            <span class="social-icon"><?= $icon ?></span>
                            <span class="social-name"><?= htmlspecialchars($network) ?></span>
                        </a>
                    <?php endforeach; endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="info-section">
            <h2 class="info-title">Контакты</h2>
            <div class="info-grid">
                <?php if ($company['address']): ?>
                    <div class="info-item">
                        <span class="info-icon">📍</span>
                        <div class="info-content">
                            <div class="info-label">Адрес</div>
                            <div class="info-value"><?= htmlspecialchars($company['address']) ?></div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($company['phone']): ?>
                    <div class="info-item">
                        <span class="info-icon">📞</span>
                        <div class="info-content">
                            <div class="info-label">Телефон</div>
                            <div class="info-value">
                                <a href="tel:<?= preg_replace('/[^\d+]/', '', $company['phone']) ?>">
                                    <?= htmlspecialchars($company['phone']) ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($company['website']): ?>
                    <div class="info-item">
                        <span class="info-icon">🌐</span>
                        <div class="info-content">
                            <div class="info-label">Сайт</div>
                            <div class="info-value">
                                <a href="<?= htmlspecialchars($company['website']) ?>" target="_blank" rel="noopener">
                                    <?= htmlspecialchars(preg_replace('/^https?:\/\//', '', $company['website'])) ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($company['email']): ?>
                    <div class="info-item">
                        <span class="info-icon">✉️</span>
                        <div class="info-content">
                            <div class="info-label">Email</div>
                            <div class="info-value">
                                <a href="mailto:<?= htmlspecialchars($company['email']) ?>">
                                    <?= htmlspecialchars($company['email']) ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($company['metro_station']): ?>
                    <div class="info-item">
                        <span class="info-icon">🚇</span>
                        <div class="info-content">
                            <div class="info-label">Метро</div>
                            <div class="info-value">
                                <?= htmlspecialchars($company['metro_station']) ?>
                                <?php if ($company['metro_distance']): ?>
                                    (<?= htmlspecialchars($company['metro_distance']) ?>)
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($workingHours): ?>
            <div class="info-section">
                <h2 class="info-title">Часы работы</h2>
                <div class="working-hours">
                    <?php foreach ($workingHours as $hour): ?>
                        <div class="hour-item"><?= htmlspecialchars($hour) ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($company['description']): ?>
            <div class="info-section">
                <h2 class="info-title">Описание</h2>
                <div class="description">
                    <?php
                    // Normalize newlines: convert single \n between sentences to \n\n
                    $desc = $company['description'];
                    // Add extra newline after period+space+capital letter (new sentence)
                    $desc = preg_replace('/\.\s+(?=[А-ЯA-Z])/u', ".\n\n", $desc);
                    // Split into paragraphs
                    $paragraphs = array_filter(array_map('trim', explode("\n\n", $desc)));
                    foreach ($paragraphs as $paragraph): 
                        if (strlen($paragraph) > 5):
                    ?>
                        <p><?= nl2br(htmlspecialchars($paragraph)) ?></p>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                </div>
                <?php if ($company['website']): ?>
                    <div class="website-link" style="margin-top: 20px;">
                        <a href="<?= htmlspecialchars($company['website']) ?>" target="_blank" rel="noopener" class="social-link" style="display: inline-flex;">
                            <span class="social-icon">🌐</span>
                            <span class="social-name">Перейти на сайт</span>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($menu): ?>
            <div class="info-section">
                <h2 class="info-title">Меню</h2>
                <?php foreach ($menu as $category => $items): ?>
                    <div class="menu-category">
                        <div class="menu-category-title"><?= htmlspecialchars($category) ?></div>
                        <div class="menu-items">
                            <?php foreach ($items as $item): ?>
                                <div class="menu-item">
                                    <div class="menu-item-info">
                                        <div class="menu-item-name"><?= htmlspecialchars($item['name']) ?></div>
                                        <?php if ($item['portion']): ?>
                                            <div class="menu-item-portion"><?= htmlspecialchars($item['portion'] ?? '') ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="menu-item-price"><?= htmlspecialchars($item['price'] ?? '') ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php 
        $companyTags = $repo->getCompanyTags($companyId);
        if ($companyTags): 
            // Группируем теги по категориям
            $groupedTags = [];
            foreach ($companyTags as $tag) {
                $cat = $tag['category'] ?? 'другое';
                $groupedTags[$cat][] = $tag;
            }
        ?>
            <div class="info-section features-collapsible">
                <h2 class="info-title features-toggle" onclick="this.parentElement.classList.toggle('expanded')">
                    Особенности
                    <span class="toggle-icon">▼</span>
                </h2>
                <div class="features-content">
                    <?php foreach ($groupedTags as $category => $tags): ?>
                        <div class="feature-group">
                            <div class="feature-group-title">
                                <?= htmlspecialchars($category) ?>
                                <span class="feature-count"><?= count($tags) ?></span>
                            </div>
                            <div class="features-grid">
                                <?php foreach ($tags as $tag): ?>
                                    <a href="/tag/<?= htmlspecialchars($tag['slug']) ?>" class="feature-item tag-link">
                                        <?= htmlspecialchars($tag['name']) ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
