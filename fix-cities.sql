UPDATE cities SET slug = 'yekaterinburg' WHERE name = 'Екатеринбург';
UPDATE cities SET slug = 'chelyabinsk' WHERE name = 'Челябинск';
UPDATE cities SET slug = 'nizhniynovgorod' WHERE name = 'Нижний Новгород';
SELECT name, slug FROM cities ORDER BY id;
