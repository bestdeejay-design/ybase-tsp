<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use YellParser\ParserService;
use YellParser\Repository;
use Dotenv\Dotenv;

// Загрузка переменных окружения
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// CLI интерфейс
$command = $argv[1] ?? 'help';
$service = new ParserService();

switch ($command) {
    case 'parse':
        $category = $argv[2] ?? null;
        $city = $argv[3] ?? 'moskva';
        $pages = (int) ($argv[4] ?? 5);

        if (!$category) {
            echo "Usage: php cli.php parse <category> [city] [pages]\n";
            echo "Example: php cli.php parse restorany moskva 3\n";
            exit(1);
        }

        echo "Starting parser for category '{$category}' in '{$city}'...\n";
        $result = $service->parseCategory($category, $city, $pages);
        echo "Parsed {$result['parsed']} companies\n";
        break;

    case 'details':
        $limit = (int) ($argv[2] ?? 50);
        
        echo "Parsing company details...\n";
        $result = $service->parseCompanyDetails($limit);
        echo "Parsed {$result['parsed']} company details\n";
        break;

    case 'reviews':
        $companyId = (int) ($argv[2] ?? 0);
        
        if (!$companyId) {
            echo "Usage: php cli.php reviews <company_id>\n";
            exit(1);
        }

        echo "Parsing reviews for company {$companyId}...\n";
        $result = $service->parseReviews($companyId);
        echo "Saved {$result['saved']} reviews\n";
        break;

    case 'stats':
        $stats = $service->getStats();
        echo "Database Statistics:\n";
        echo "  Companies: {$stats['companies']}\n";
        echo "  Reviews: {$stats['reviews']}\n";
        echo "  Categories: {$stats['categories']}\n";
        echo "  Cities: {$stats['cities']}\n";
        break;

    case 'help':
    default:
        echo "Yell.ru Parser CLI\n";
        echo "==================\n\n";
        echo "Commands:\n";
        echo "  parse <category> [city] [pages]  - Parse companies by category\n";
        echo "  details [limit]                  - Parse company details\n";
        echo "  reviews <company_id>             - Parse reviews for company\n";
        echo "  stats                            - Show database statistics\n";
        echo "  help                             - Show this help\n";
        break;
}
