<?php
require_once __DIR__ . '/../vendor/autoload.php';

use YellParser\Database;

$db = Database::getInstance();

$stmt = $db->query("
    SELECT id, name, city_id, 
           LENGTH(description) as len,
           description
    FROM companies 
    WHERE LENGTH(description) > 1000
    ORDER BY LENGTH(description) DESC
    LIMIT 5
");

$rows = $stmt->fetchAll();

echo "<h1>Заведения с длинным описанием</h1>";

foreach ($rows as $i => $row) {
    echo "<p>";
    echo "<b>" . ($i + 1) . ". " . htmlspecialchars($row['name']) . "</b><br>";
    echo "ID: " . $row['id'] . ", Город: " . $row['city_id'] . "<br>";
    echo "Длина: " . $row['len'] . " символов<br>";
    echo "Переносов \\n: " . substr_count($row['description'], "\n") . "<br>";
    echo "Двойных \\n\\n: " . substr_count($row['description'], "\n\n") . "<br>";
    echo "<a href='/company/" . $row['id'] . "'>Посмотреть</a>";
    echo "</p>";
}
