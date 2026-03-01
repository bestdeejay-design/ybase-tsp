<?php
require_once __DIR__ . '/../src/Repository.php';
require_once __DIR__ . '/../src/Database.php';

$companyId = (int) ($_GET['id'] ?? 0);

// Генерируем уникальный ID пользователя (можно заменить на авторизацию)
$userId = $_COOKIE['user_id'] ?? uniqid('user_', true);
setcookie('user_id', $userId, time() + 365*24*60*60, '/'); // 1 год

$db = \YellParser\Database::getInstance();

/** @var PDO $db */
$repo = new \YellParser\Repository($db);

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

// Проверяем, добавлено ли заведение в избранное
$isFavorite = false;
$favCheck = $db->prepare("SELECT 1 FROM favorites WHERE user_id = ? AND company_id = ?");
$favCheck->execute([$userId, $companyId]);
$isFavorite = $favCheck->fetch() !== false;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($company['name']) ?></title>
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
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
            padding-bottom: 100px; /* Add space for fixed action bar */
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

        .breadcrumb {
            padding: 0 20px 12px;
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

        /* Hero Section */
        .hero {
            position: relative;
            height: 400px;
            margin-bottom: -80px;
            z-index: 10;
        }

        .hero-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 64px;
            color: white;
        }

        .hero-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 120px;
            background: linear-gradient(to top, rgba(0,0,0,0.6), transparent);
        }

        .hero-content {
            position: absolute;
            bottom: 24px;
            left: 24px;
            right: 24px;
            color: white;
            z-index: 2;
        }

        .hero-name {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.5);
        }

        .hero-meta {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 16px;
        }

        .hero-rating {
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
        }

        .hero-category {
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 14px;
        }

        /* Main Content */
        .main-content {
            position: relative;
            z-index: 20;
            margin-top: 80px;
            margin-bottom: 100px; /* Ensure content doesn't get covered by action bar */
        }

        /* Info Cards */
        .info-section {
            background: var(--bg-primary);
            border-radius: var(--radius-xl);
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-light);
        }

        .section-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 24px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-icon {
            font-size: 20px;
            color: var(--accent-primary);
        }

        .info-grid {
            display: grid;
            gap: 20px;
        }

        .info-item {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-light);
        }
        
        .info-item:last-child {
            border-bottom: none;
        }

        .info-icon {
            font-size: 20px;
            width: 24px;
            text-align: center;
            color: var(--accent-primary);
        }

        .info-content {
            flex: 1;
        }

        .info-label {
            font-size: 13px;
            color: var(--text-tertiary);
            margin-bottom: 4px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 15px;
            color: var(--text-primary);
            font-weight: 500;
        }
        
        .info-value.normal-weight {
            font-weight: normal;
        }

        .info-value a {
            color: var(--accent-primary);
            text-decoration: none;
            transition: var(--transition-fast);
        }

        .info-value a:hover {
            text-decoration: underline;
        }

        /* Gallery */
        .gallery {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 12px;
            margin-bottom: 24px;
            border-radius: var(--radius-xl);
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
            background: var(--bg-secondary);
        }

        .gallery-main img {
            min-height: 412px;
        }

        /* Description */
        .description {
            font-size: 16px;
            line-height: 1.7;
            color: var(--text-secondary);
        }

        .description p {
            margin-bottom: 16px;
        }

        .description p:last-child {
            margin-bottom: 0;
        }

        /* Menu */
        .menu-category {
            margin-bottom: 32px;
        }

        .menu-category:last-child {
            margin-bottom: 0;
        }

        .menu-category-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--border-light);
        }

        .menu-items {
            display: grid;
            gap: 16px;
        }

        .menu-item {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            padding: 16px 0;
            border-bottom: 1px solid var(--border-light);
        }

        .menu-item:last-child {
            border-bottom: none;
        }

        .menu-item-info {
            flex: 1;
        }

        .menu-item-name {
            font-size: 16px;
            color: var(--text-primary);
            margin-bottom: 4px;
            font-weight: 500;
        }

        .menu-item-portion {
            font-size: 14px;
            color: var(--text-tertiary);
        }

        .menu-item-description {
            font-size: 14px;
            color: var(--text-secondary);
            margin-top: 4px;
        }

        .menu-item-price {
            font-size: 18px;
            font-weight: 700;
            color: var(--accent-primary);
            white-space: nowrap;
            align-self: flex-start;
        }
        
        .menu-item-name {
            font-size: 16px;
            color: var(--text-primary);
            margin-bottom: 4px;
            font-weight: 500;
        }
        
        .menu-item-portion {
            font-size: 14px;
            color: var(--text-tertiary);
            margin-bottom: 4px;
        }
        
        .menu-item-description {
            font-size: 14px;
            color: var(--text-secondary);
            margin-top: 4px;
        }

        /* Features */
        .features-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .feature-item {
            font-size: 14px;
            padding: 10px 16px;
            background: var(--bg-secondary);
            border-radius: 20px;
            color: var(--text-secondary);
            border: 1px solid var(--border-light);
            transition: var(--transition-fast);
        }

        .feature-item:hover {
            background: var(--accent-primary);
            color: white;
            border-color: var(--accent-primary);
        }

        /* Social Links */
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
            background: var(--bg-secondary);
            border-radius: 12px;
            text-decoration: none;
            color: var(--text-primary);
            transition: var(--transition-fast);
            border: 1px solid var(--border-light);
        }

        .social-link:hover {
            background: var(--accent-primary);
            color: white;
            border-color: var(--accent-primary);
        }

        .social-icon {
            font-size: 14px;
            font-weight: 700;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-tertiary);
            border-radius: 8px;
        }

        .social-link:hover .social-icon {
            background: rgba(255,255,255,0.2);
        }

        .social-name {
            font-size: 14px;
            text-transform: capitalize;
        }

        /* Working Hours */
        .working-hours {
            display: grid;
            gap: 8px;
        }

        .hour-item {
            font-size: 14px;
            padding: 12px 16px;
            background: var(--bg-secondary);
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
        }

        /* Action Bar */
        .action-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--bg-primary);
            border-top: 1px solid var(--border-light);
            padding: 16px 20px;
            display: flex;
            gap: 12px;
            z-index: 1000;
        }

        .action-btn {
            flex: 1;
            padding: 16px;
            border: none;
            border-radius: var(--radius-large);
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition-fast);
        }

        .action-btn.primary {
            background: var(--accent-primary);
            color: white;
        }

        .action-btn.secondary {
            background: var(--bg-secondary);
            color: var(--text-primary);
            border: 1px solid var(--border-light);
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero {
                height: 300px;
            }
            
            .hero-name {
                font-size: 24px;
            }
            
            .gallery {
                grid-template-columns: 1fr;
            }
            
            .gallery-main {
                grid-row: span 1;
            }
            
            .gallery-main img {
                min-height: 250px;
            }
            
            .action-bar {
                padding: 16px;
            }
            
            .info-section {
                padding: 24px;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 0 16px;
            }
            
            .action-bar {
                flex-direction: column;
                gap: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="page-header">
        <div class="breadcrumb">
            <a href="/">← Все города</a> / 
            <a href="/catalog?city=<?= $company['city_id'] ?>"><?= htmlspecialchars($company['city_name']) ?></a>
        </div>
    </div>

    <!-- Hero Section -->
    <div class="hero">
        <?php if ($images && count($images) > 0): ?>
            <div class="hero-image">
                <?php foreach ($images as $index => $image): ?>
                    <img src="<?= htmlspecialchars(is_array($image) ? ($image['preview'] ?? $image) : $image) ?>" 
                         alt="<?= htmlspecialchars($company['name']) ?> - фото <?= $index + 1 ?>" 
                         style="width: 100%; height: 100%; object-fit: cover; <?= $index > 0 ? 'display: none;' : '' ?>" 
                         class="hero-img <?= $index === 0 ? 'active' : '' ?>" 
                         data-index="<?= $index ?>">
                <?php endforeach; ?>
                <?php if (count($images) > 1): ?>
                    <div class="gallery-controls" style="position: absolute; bottom: 16px; left: 50%; transform: translateX(-50%); display: flex; gap: 8px;">
                        <?php foreach ($images as $index => $image): ?>
                            <div class="gallery-dot" 
                                 style="width: 10px; height: 10px; border-radius: 50%; background: rgba(255,255,255,0.5); cursor: pointer; <?= $index === 0 ? 'background: white;' : '' ?>" 
                                 onclick="showImage(<?= $index ?>)"></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="gallery-counter" style="position: absolute; top: 16px; right: 16px; background: rgba(0,0,0,0.6); color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px;">
                        <span id="current-image">1</span> / <?= count($images) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="hero-image">🏢</div>
        <?php endif; ?>
        <div class="hero-overlay"></div>
        <div class="hero-content">
            <h1 class="hero-name"><?= htmlspecialchars($company['name']) ?></h1>
            <?php
                // Category SVG icons
                $catIcons = [
                    'restorany-i-kafe' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path d="M11 9H9V2H7v7H5V2H3v7c0 2.12 1.66 3.84 3.75 3.97V22h2.5v-9.03C11.34 12.84 13 11.12 13 9V2h-2v7zm5-3c-1.1 0-2 .9-2 2v8c0 1.1.9 2 2 2h4v-2h-4v-2h4v-2h-4V8h4V6h-4z"/></svg>',
                    'bary-i-kluby' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path d="M21 5V3H3v2l8 12v4H7v2h4v-2h2v2h4v-2h4v-2h-4v-4l2-6h2V5h-2zm-4 6.91L13 7h8l-4 5.91z"/></svg>',
                    'krasota' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg>',
                    'medicina' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-8 14h-2v-4H5v-2h4V7h2v4h4v2h-4v4z"/></svg>',
                    'sport' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm.31-8.86c-1.77-.45-2.34-.94-2.34-1.67 0-.84.79-1.43 2.1-1.43.96 0 1.46.25 1.78.58.21.21.51.24.76.11.3-.15.67-.55 1.16-1.06.54-.58.8-1.12.8-1.59 0-.59-.41-1.11-1.11-1.32-.84-.25-1.56.04-1.98.35-.32.24-.6.59-.77 1.02-.18.44-.42.71-.82.71-.31 0-.56-.14-.73-.43-.18-.3-.46-.44-.82-.44-.4 0-.7.18-.88.55-.18.37-.28.87-.28 1.51 0 .69.17 1.22.5 1.59.34.38.84.59 1.5.63v.1c-.78.15-1.33.47-1.65 1.01-.32.53-.49 1.3-.49 2.3 0 1.12.25 1.9.74 2.34.5.45 1.2.67 2.1.67 1.12 0 1.9-.37 2.34-.96.44-.6.66-1.5.66-2.7v-.54c-.84.52-1.5.78-1.98.78-.28 0-.5-.08-.66-.25-.16-.17-.24-.4-.24-.69 0-.34.15-.58.45-.73.29-.14.64-.21 1.04-.21.42 0 .77.1 1.04.3.27.2.49.48.66.86.17.38.34.7.52.96.18.26.4.45.66.57.26.12.58.18.96.18.42 0 .76-.08 1.02-.24.26-.16.46-.39.59-.69.13-.3.19-.71.19-1.23 0-.67-.2-1.2-.6-1.59-.4-.38-.94-.57-1.62-.57h-.24z"/></svg>',
                    'razvlecheniya' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4 14h-2v-3h-2v3h-2v-6h2v3h2v-3h2v6z"/></svg>',
                    'magaziny' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path d="M7 18c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1V2h4zm9 12c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z"/></svg>',
                    'obrazovanie' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path d="M5 13L1 9l11 6v6l4-2.22V15L12 17L1 9l4-2.18L12 3z"/></svg>',
                    'uslugi-biznes' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path d="M20 6h-2.18c.11-.31.18-.65.18-1a2.996 2.996 0 0 0-5.5-1.65l-.5.67-.5-.68C10.96 2.54 10.05 2 9 2 7.34 2 6 3.34 6 5c0 .35.07.69.18 1H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-5-2c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1z"/></svg>',
                    'uslugi-dom' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path d="M19 9.3V4h-3v2.6L12 3 2 12h3v8h6v-6h2v6h6v-8h3l-3-2.7zm-9 .7c0-1.1.9-2 2-2s2 .9 2 2h-4z"/></svg>',
                    'transport' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.85 7h10.29l1.08 3.11H5.77L6.85 7zM19 17H5v-5h14v5z"/><circle cx="7.5" cy="14.5" r="1.5"/><circle cx="16.5" cy="14.5" r="1.5"/></svg>',
                    'nedvizhimost' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>',
                    'oteli-arenda' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path d="M7 13c1.66 0 3-1.34 3-3S8.66 7 7 7s-3 1.34-3 3 1.34 3 3 3zm12-6h-8v7H3V6H1v15h2v-3h18v3h2v-9c0-2.21-1.79-4-4-4z"/></svg>',
                ];
                $categorySlug = $categories ? $categories[0] : '';
                $categoryName = $categories ? $categories[0] : '';
                $icon = $catIcons[$categorySlug] ?? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>';
            ?>
            <div class="hero-meta">
                <?php if ($company['rating']): ?>
                    <span class="hero-rating">
                        ★ <?= number_format($company['rating'], 1) ?>
                        <?php if ($company['review_count']): ?>
                            (<?= $company['review_count'] ?>)
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
                <?php if ($categories): ?>
                    <span class="hero-category">
                        <span style="margin-right: 6px; vertical-align: middle; display: inline-block;"><?= $icon ?></span>
                        <?= htmlspecialchars($categoryName) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
    let currentImageIndex = 0;
    const totalImages = <?= count($images) ?>;
    
    function showImage(index) {
        if (index < 0 || index >= totalImages) return;
        
        // Скрываем все изображения
        const allImages = document.querySelectorAll('.hero-img');
        allImages.forEach(img => {
            img.style.display = 'none';
            img.classList.remove('active');
        });
        
        // Показываем выбранное изображение
        const selectedImage = document.querySelector(`.hero-img[data-index="${index}"]`);
        if (selectedImage) {
            selectedImage.style.display = 'block';
            selectedImage.classList.add('active');
        }
        
        // Обновляем точки индикатора
        const dots = document.querySelectorAll('.gallery-dot');
        dots.forEach((dot, i) => {
            if (i === index) {
                dot.style.background = 'white';
            } else {
                dot.style.background = 'rgba(255,255,255,0.5)';
            }
        });
        
        // Обновляем счетчик
        document.getElementById('current-image').textContent = index + 1;
        
        currentImageIndex = index;
    }
    
    function nextImage() {
        const nextIndex = (currentImageIndex + 1) % totalImages;
        showImage(nextIndex);
    }
    
    function prevImage() {
        const prevIndex = (currentImageIndex - 1 + totalImages) % totalImages;
        showImage(prevIndex);
    }
    
    // Автоматическая смена изображений каждые 5 секунд
    setInterval(() => {
        if (totalImages > 1) {
            nextImage();
        }
    }, 5000);
    </script>

    <div class="main-content">
        <?php if ($socialLinks): ?>
            <div class="info-section">
                <h2 class="section-title">
                    <span class="section-icon">🔗</span>
                    Социальные сети
                </h2>
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
                            if (str_contains($url, 'yell.ru') || str_contains($url, 'vk.com/yellru')) continue;
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
            <h2 class="section-title">
                <span class="section-icon">📍</span>
                Контакты
            </h2>
            <div class="info-grid">
                <?php if ($company['address']): ?>
                    <div class="info-item">
                        <span class="info-icon">📍</span>
                        <div class="info-content">
                            <div class="info-label">Адрес</div>
                            <div class="info-value">
                                <a href="https://yandex.ru/maps/?text=<?= urlencode($company['name'] . ' ' . $company['address'] . ' ' . $company['city_name']) ?>" target="_blank" style="text-decoration: none; color: inherit; display: flex; align-items: center; gap: 8px;">
                                    <?= htmlspecialchars($company['address']) ?>
                                    <?php if ($company['city_name']): ?>
                                        , <?= htmlspecialchars($company['city_name']) ?>
                                    <?php endif; ?>
                                    <span style="font-size: 12px; color: var(--text-tertiary);">Открыть на Яндекс.Картах</span>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($company['phone']): ?>
                    <div class="info-item">
                        <span class="info-icon">📞</span>
                        <div class="info-content">
                            <div class="info-label">Телефон</div>
                            <div class="info-value">
                                <a href="tel:<?= preg_replace('/[^\d+]/', '', $company['phone']) ?>" style="display: flex; align-items: center; gap: 8px;">
                                    <?= htmlspecialchars($company['phone']) ?>
                                    <span style="font-size: 12px; color: var(--text-tertiary);">Позвонить</span>
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
                                <a href="<?= htmlspecialchars($company['website']) ?>" target="_blank" rel="noopener" style="display: flex; align-items: center; gap: 8px;">
                                    <?= htmlspecialchars(preg_replace('/^https?:\/\//', '', $company['website'])) ?>
                                    <span style="font-size: 12px; color: var(--text-tertiary);">Перейти</span>
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
                                <a href="mailto:<?= htmlspecialchars($company['email']) ?>" style="display: flex; align-items: center; gap: 8px;">
                                    <?= htmlspecialchars($company['email']) ?>
                                    <span style="font-size: 12px; color: var(--text-tertiary);">Написать</span>
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
                                    <span style="color: var(--text-tertiary); font-size: 13px;">&nbsp;(<?= htmlspecialchars($company['metro_distance']) ?>)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($workingHours): ?>
            <div class="info-section">
                <h2 class="section-title">
                    <span class="section-icon">🕐</span>
                    Часы работы
                </h2>
                
                <?php 
                // Enhanced working hours display
                $weekDays = ['Mon' => 'Пн', 'Tue' => 'Вт', 'Wed' => 'Ср', 'Thu' => 'Чт', 'Fri' => 'Пт', 'Sat' => 'Сб', 'Sun' => 'Вс'];
                $currentDay = date('D');
                $currentTime = time();
                $currentDate = date('d.m.Y');
                $isCurrentlyOpen = false;
                $nextOpeningTime = null;
                $formattedHours = [];
                
                foreach ($workingHours as $hour) {
                    $parts = explode(': ', $hour);
                    if (count($parts) == 2) {
                        $daysPart = $parts[0];
                        $timesPart = $parts[1];
                        
                        // Parse days
                        $dayNames = [];
                        foreach ($weekDays as $en => $ru) {
                            if (strpos($daysPart, $en) !== false) {
                                $dayNames[] = $ru;
                            }
                        }
                        
                        if (empty($dayNames) && strpos($daysPart, 'Daily') !== false) {
                            $dayNames = array_values($weekDays);
                        }
                        
                        $formattedHours[] = [
                            'days' => implode('-', $dayNames),
                            'times' => $timesPart,
                            'raw_days' => $daysPart,
                            'raw_times' => $timesPart
                        ];
                        
                        // Check if currently open
                        if (strpos($daysPart, $currentDay) !== false || strpos($daysPart, 'Daily') !== false) {
                            $timeParts = explode('-', $timesPart);
                            if (count($timeParts) == 2) {
                                $openTime = strtotime(trim($timeParts[0]));
                                $closeTime = strtotime(trim($timeParts[1]));
                                
                                if ($currentTime >= $openTime && $currentTime <= $closeTime) {
                                    $isCurrentlyOpen = true;
                                } else {
                                    // Calculate next opening time if closed
                                    if (!$isCurrentlyOpen && !$nextOpeningTime) {
                                        $todayOpenTime = strtotime(trim($timeParts[0]));
                                        $todayCloseTime = strtotime(trim($timeParts[1]));
                                        
                                        if ($currentTime < $todayOpenTime) {
                                            $nextOpeningTime = [
                                                'day' => 'сегодня',
                                                'time' => trim($timeParts[0])
                                            ];
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                
                // Calculate next opening time for other days if not set today
                if (!$isCurrentlyOpen && !$nextOpeningTime) {
                    $daysOfWeek = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                    $currentDayIndex = array_search($currentDay, $daysOfWeek);
                    
                    for ($i = 1; $i <= 7; $i++) {
                        $dayIndex = ($currentDayIndex + $i) % 7;
                        $checkDay = $daysOfWeek[$dayIndex];
                        
                        foreach ($workingHours as $hour) {
                            $parts = explode(': ', $hour);
                            if (count($parts) == 2) {
                                $daysPart = $parts[0];
                                $timesPart = $parts[1];
                                
                                if (strpos($daysPart, $checkDay) !== false || strpos($daysPart, 'Daily') !== false) {
                                    $timeParts = explode('-', $timesPart);
                                    if (count($timeParts) == 2) {
                                        $nextOpeningTime = [
                                            'day' => $weekDays[$checkDay],
                                            'time' => trim($timeParts[0])
                                        ];
                                        break 2; // Break from both loops
                                    }
                                }
                            }
                        }
                    }
                }
                
                // Display current status
                if ($isCurrentlyOpen) {
                    echo '<div style="margin-bottom: 16px; padding: 12px; background: #E3FCEC; border-radius: 8px; text-align: center;">
                         <span style="color: #00C853; font-weight: 600; font-size: 16px;">🟢 Открыто сейчас</span>
                         </div>';
                } else {
                    if ($nextOpeningTime) {
                        echo '<div style="margin-bottom: 16px; padding: 12px; background: #FEF2F2; border-radius: 8px; text-align: center;">
                             <span style="color: #EF4444; font-weight: 600; font-size: 16px;">🔴 Закрыто</span><br>
                             <span style="color: #666; font-size: 14px;">Откроется ' . $nextOpeningTime['day'] . ' в ' . $nextOpeningTime['time'] . '</span>
                             </div>';
                    } else {
                        echo '<div style="margin-bottom: 16px; padding: 12px; background: #FEF2F2; border-radius: 8px; text-align: center;">
                             <span style="color: #EF4444; font-weight: 600; font-size: 16px;">🔴 Закрыто</span>
                             </div>';
                    }
                }
                ?>
                
                <div class="working-hours">
                    <?php foreach ($formattedHours as $hour): ?>
                        <div class="hour-item">
                            <span style="font-weight: 500;"> <?= htmlspecialchars($hour['days']) ?></span>
                            <span><?= htmlspecialchars($hour['times']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($company['description']): ?>
            <div class="info-section">
                <h2 class="section-title">
                    <span class="section-icon">📝</span>
                    Описание
                </h2>
                <div class="description">
                    <?php
                    $desc = $company['description'];
                    $desc = preg_replace('/\.\s+(?=[А-ЯA-Z])/u', ".\n\n", $desc);
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
            </div>
        <?php endif; ?>

        <?php if ($menu): ?>
            <div class="info-section">
                <h2 class="section-title" onclick="toggleMenu()" style="cursor: pointer; display: flex; justify-content: space-between;">
                    <span><span class="section-icon">📋</span> Меню</span>
                    <span id="menu-toggle">−</span>
                </h2>
                <div id="menu-content">
                    <?php foreach ($menu as $category => $items): ?>
                        <div class="menu-category">
                            <div class="menu-category-title"><?= htmlspecialchars($category) ?></div>
                            <div class="menu-items">
                                <?php foreach ($items as $item): ?>
                                    <div class="menu-item">
                                        <div class="menu-item-info">
                                            <div class="menu-item-name"><?= htmlspecialchars($item['name'] ?? '') ?></div>
                                            <?php if (isset($item['portion']) && $item['portion']): ?>
                                                <div class="menu-item-portion"><?= htmlspecialchars($item['portion']) ?></div>
                                            <?php endif; ?>
                                            <?php if (isset($item['description']) && $item['description']): ?>
                                                <div class="menu-item-description"><?= htmlspecialchars($item['description']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="menu-item-price"><?= htmlspecialchars($item['price'] ?? '') ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <script>
        function toggleMenu() {
            const content = document.getElementById('menu-content');
            const toggle = document.getElementById('menu-toggle');
            
            if (content.style.display === 'none') {
                content.style.display = 'block';
                toggle.textContent = '−';
            } else {
                content.style.display = 'none';
                toggle.textContent = '+';
            }
        }
        
        // Изначально скрываем контент меню
        document.addEventListener('DOMContentLoaded', function() {
            const menuContent = document.getElementById('menu-content');
            if (menuContent) {
                menuContent.style.display = 'none';
                document.getElementById('menu-toggle').textContent = '+';
            }
        });
        </script>

        <?php 
        $companyTags = $repo->getCompanyTags($companyId);
        if ($companyTags): 
            $groupedTags = [];
            foreach ($companyTags as $tag) {
                $cat = $tag['category'] ?? 'другое';
                $groupedTags[$cat][] = $tag;
            }
        ?>
            <div class="info-section">
                <h2 class="section-title" style="display: flex; justify-content: space-between;">
                    <span><span class="section-icon">🏷️</span> Особенности</span>
                </h2>
                <div class="features-categories">
                    <?php foreach ($groupedTags as $category => $tags): ?>
                        <div class="feature-category">
                            <h3 class="category-header" onclick="toggleCategory('<?= md5($category) ?>')" style="cursor: pointer; display: flex; justify-content: space-between; font-size: 16px; font-weight: 600; margin: 16px 0 12px; color: var(--text-primary);">
                                <span><?= htmlspecialchars($category) ?></span>
                                <span class="category-toggle" id="toggle-<?= md5($category) ?>">−</span>
                            </h3>
                            <div class="category-content" id="content-<?= md5($category) ?>" style="display: block;">
                                <div class="features-grid">
                                    <?php foreach ($tags as $tag): ?>
                                        <a href="/tag/<?= htmlspecialchars($tag['slug']) ?>" class="feature-item">
                                            <?= htmlspecialchars($tag['name']) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <script>
        function toggleCategory(categoryId) {
            const content = document.getElementById('content-' + categoryId);
            const toggle = document.getElementById('toggle-' + categoryId);
            
            if (content.style.display === 'none') {
                content.style.display = 'block';
                toggle.textContent = '−';
            } else {
                content.style.display = 'none';
                toggle.textContent = '+';
            }
        }
        
        // Изначально скрываем контент особенностей
        document.addEventListener('DOMContentLoaded', function() {
            const categoryContents = document.querySelectorAll('.category-content');
            categoryContents.forEach(content => {
                content.style.display = 'none';
            });
            
            const toggles = document.querySelectorAll('.category-toggle');
            toggles.forEach(toggle => {
                toggle.textContent = '+';
            });
        });
        
        // Объединяем со скриптом меню
        function toggleMenu() {
            const content = document.getElementById('menu-content');
            const toggle = document.getElementById('menu-toggle');
            
            if (content.style.display === 'none') {
                content.style.display = 'block';
                toggle.textContent = '−';
            } else {
                content.style.display = 'none';
                toggle.textContent = '+';
            }
        }
        
        // Изначально скрываем контент меню
        document.addEventListener('DOMContentLoaded', function() {
            const menuContent = document.getElementById('menu-content');
            if (menuContent) {
                menuContent.style.display = 'none';
                document.getElementById('menu-toggle').textContent = '+';
            }
        });
        </script>
    </div>

    <!-- Action Bar -->
    <div class="action-bar">
        <button class="action-btn secondary" onclick="navigator.share ? navigator.share({title: '<?= addslashes(htmlspecialchars($company['name'])) ?>', url: window.location.href}) : alert('Функция недоступна в вашем браузере')">
            <span style="display: flex; align-items: center; justify-content: center; gap: 6px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="18" cy="5" r="3"></circle>
                    <circle cx="6" cy="12" r="3"></circle>
                    <circle cx="18" cy="19" r="3"></circle>
                    <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line>
                    <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line>
                </svg>
                Поделиться
            </span>
        </button>
        <button class="action-btn secondary" onclick="toggleFavorite(<?= $company['id'] ?>)" id="favorite-btn">
            <span style="display: flex; align-items: center; justify-content: center; gap: 6px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" id="favorite-icon">
                    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" fill="<?= $isFavorite ? '#ff3b30' : 'none' ?>" stroke="<?= $isFavorite ? '#ff3b30' : 'currentColor' ?>"></path>
                </svg>
                <span id="favorite-text"><?= $isFavorite ? 'В избранном' : 'В избранное' ?></span>
            </span>
        </button>
        <?php if ($company['phone']): ?>
            <a href="tel:<?= preg_replace('/[^\d+]/', '', $company['phone']) ?>" class="action-btn primary" style="text-decoration: none; color: white; display: flex; align-items: center; justify-content: center; gap: 6px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                </svg>
                Позвонить
            </a>
        <?php else: ?>
            <button class="action-btn primary" disabled style="opacity: 0.5; cursor: not-allowed;">
                <span style="display: flex; align-items: center; justify-content: center; gap: 6px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                    </svg>
                    Позвонить
                </span>
            </button>
        <?php endif; ?>
    </div>
    
    <script>
        async function toggleFavorite(companyId) {
            try {
                const response = await fetch('/api/favorites', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        company_id: companyId,
                        user_id: '<?= $userId ?>'
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Обновляем состояние кнопки
                    const icon = document.getElementById('favorite-icon');
                    const text = document.getElementById('favorite-text');
                    const btn = document.getElementById('favorite-btn');
                    
                    if (result.is_favorite) {
                        icon.setAttribute('fill', '#ff3b30');
                        icon.setAttribute('stroke', '#ff3b30');
                        text.textContent = 'В избранном';
                        btn.classList.add('primary');
                        btn.classList.remove('secondary');
                    } else {
                        icon.setAttribute('fill', 'none');
                        icon.setAttribute('stroke', 'currentColor');
                        text.textContent = 'В избранное';
                        btn.classList.add('secondary');
                        btn.classList.remove('primary');
                    }
                }
            } catch (error) {
                console.error('Ошибка при добавлении в избранное:', error);
                alert('Произошла ошибка. Попробуйте еще раз.');
            }
        }
    </script>
</body>
</html>