<?php

namespace YellParser;

class DescriptionNormalizer
{
    /**
     * Нормализует описание заведения
     * Возвращает массив [description, extracted_website]
     */
    public function normalize(string $description, array $companyData = []): array
    {
        // 1. Извлекаем URL из описания ДО удаления служебных строк
        $extractedWebsite = $this->extractWebsite($description);
        
        // 2. Удаляем служебные строки в конце
        $description = $this->removeBoilerplate($description);
        
        // 3. Извлекаем телефоны из описания
        $extractedPhones = $this->extractPhones($description);
        
        // 4. Проверяем, нужно ли форматирование (только если текст слипшийся)
        // Если в тексте нет переносов строк и он длинный — разбиваем на абзацы
        if (strlen($description) > 200 && substr_count($description, "\n") === 0) {
            $description = $this->formatText($description);
        }
        
        return [
            'description' => trim($description),
            'website' => $extractedWebsite,
            'phones' => $extractedPhones,
        ];
    }
    
    /**
     * Извлекает URL сайта из описания и удаляет все ссылки
     */
    private function extractWebsite(string &$text): ?string
    {
        $foundWebsite = null;
        
        // Ищем URL в тексте (http:// или https://)
        if (preg_match('/(https?:\/\/[^\s,]+)/i', $text, $matches)) {
            $url = $matches[1];
            
            // Очищаем URL от пунктуации в конце
            $url = rtrim($url, '.,;:!?)\'"');
            
            // Если это НЕ ссылка на Yell.ru — сохраняем как website
            if (!str_contains($url, 'yell.ru')) {
                $foundWebsite = $url;
            }
            
            // Удаляем URL из описания в любом случае
            $text = preg_replace('/' . preg_quote($matches[0], '/') . '/', '', $text);
        }
        
        // Ищем домен без протокола (например: antoniorest.com или lavashok.qr-cafe.ru)
        // Домен может содержать дефисы и точки
        if (preg_match('/\b([a-z0-9][a-z0-9\-]*(?:\.[a-z0-9][a-z0-9\-]*)*\.[a-z]{2,})(?:\/[^\s,]*)?/i', $text, $matches)) {
            $domain = $matches[1];
            
            // Проверяем что это не email
            if (!preg_match('/[a-z0-9._%+-]+@' . preg_quote($domain, '/') . '/i', $text)) {
                // Проверяем что это не common domain
                $commonDomains = ['yandex.ru', 'google.com', 'vk.com', 'facebook.com', 'instagram.com', 'youtube.com'];
                
                if (!in_array(strtolower($domain), $commonDomains)) {
                    // Если ещё не нашли website — берём этот домен
                    if (!$foundWebsite) {
                        $foundWebsite = 'https://' . $matches[0];
                    }
                }
            }
            
            // Удаляем домен из описания в любом случае
            $text = preg_replace('/\b' . preg_quote($matches[0], '/') . '/i', '', $text);
        }
        
        return $foundWebsite;
    }
    
    /**
     * Удаляет служебные строки
     */
    private function removeBoilerplate(string $text): string
    {
        // Удаляем "Ищите нас в соцсетях:..." и всё что после
        $text = preg_replace('/Ищите нас в соцсетях:.*$/isu', '', $text);
        
        // Удаляем "Информация скопирована с Yell.ru..." и всё что после
        $text = preg_replace('/Информация скопирована с Yell\.ru.*$/isu', '', $text);
        
        // Удаляем "Описание скопировано с Yell.ru..."
        $text = preg_replace('/Описание скопировано с Yell\.ru.*$/isu', '', $text);
        
        // Удаляем "Текст скопирован с Yell.ru..."
        $text = preg_replace('/Текст скопирован с Yell\.ru.*$/isu', '', $text);
        
        // Удаляем добавленные нормалайзером блоки "Особенности: ..." (могут дублироваться)
        // Учитываем что в базе могут быть \n как текст или реальные переносы
        // Удаляем блок Особенности со всем содержимым до следующего блока или конца текста
        $text = preg_replace('/\\n\\nОсобенности:.*?($|\\n\\n|\\nВ меню)/isu', "\n\n", $text);
        $text = preg_replace('/\\nОсобенности:.*?($|\\n\\n|\\nВ меню)/isu', "\n", $text);
        $text = preg_replace('/\n\nОсобенности:.*?($|\n\n|\nВ меню)/isu', "\n\n", $text);
        $text = preg_replace('/\nОсобенности:.*?($|\n\n|\nВ меню)/isu', "\n", $text);
        
        // Удаляем добавленные нормалайзером блоки "В меню представлены: ..."
        $text = preg_replace('/\\n\\nВ меню представлены:.*?($|\\n\\n|\\nОсобенности)/isu', "\n\n", $text);
        $text = preg_replace('/\\nВ меню представлены:.*?($|\\n\\n|\\nОсобенности)/isu', "\n", $text);
        $text = preg_replace('/\n\nВ меню представлены:.*?($|\n\n|\nОсобенности)/isu', "\n\n", $text);
        $text = preg_replace('/\nВ меню представлены:.*?($|\n\n|\nОсобенности)/isu', "\n", $text);
        
        // Удаляем оставшиеся пустые блоки в конце текста
        $text = preg_replace('/Особенности:.*$/isu', '', $text);
        $text = preg_replace('/В меню представлены:.*$/isu', '', $text);
        $text = preg_replace('/\\nОсобенности:.*$/isu', '', $text);
        $text = preg_replace('/\\nВ меню представлены:.*$/isu', '', $text);
        
        return $text;
    }
    
    /**
     * Форматирует текст — добавляет переносы строк
     */
    private function formatText(string $text): string
    {
        // Убираем лишние пробелы, но сохраняем переносы
        $text = preg_replace('/[ \t]+/', ' ', $text);
        
        // Разбиваем на абзацы по смыслу:
        // 1. После вопросительного знака — просто перенос (вопрос-ответ вместе)
        $text = preg_replace('/\?(\s+)(?=[А-ЯA-Z])/u', "?\n$1", $text);
        
        // 2. После восклицательного знака — пустая строка (эмоциональное разделение)
        $text = preg_replace('/!(\s+)(?=[А-ЯA-Z])/u', "!\n\n$1", $text);
        
        // 3. После точки в длинных предложениях (>100 символов) — пустая строка
        $text = preg_replace('/(.{100,}\.)(\s+)(?=[А-ЯA-Z])/u', "$1\n\n$2", $text);
        
        // 4. Перед ключевыми фразами — пустая строка
        $keyPhrases = [
            'Организация располагается по адресу',
            'Учреждение расположено по адресу',
            'Узнать подробности можно',
            'Двери заведения открыты',
            'Режим работы',
            'Телефон',
            'Номер телефона',
            'Адрес',
            'Компания ждёт посетителей',
            'Веб-сайт',
            'Ищите нас в соцсетях',
        ];
        
        foreach ($keyPhrases as $phrase) {
            $text = preg_replace('/(\S.*?)(' . preg_quote($phrase, '/') . ')/u', "$1\n\n$2", $text);
        }
        
        // Убираем множественные переносы (более 2 подряд)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        
        // Убираем пробелы в начале строк
        $text = preg_replace('/^ +/m', '', $text);
        
        return trim($text);
    }
    
    /**
     * Добавляет структурированную информацию
     */
    private function addStructuredInfo(string $text, array $companyData): string
    {
        $additions = [];
        
        // Добавляем особенности если есть
        if (!empty($companyData['features'])) {
            $features = is_string($companyData['features']) 
                ? json_decode($companyData['features'], true) 
                : $companyData['features'];
            
            if (is_array($features) && count($features) > 0) {
                $additions[] = "\n\nОсобенности: " . implode(', ', array_slice($features, 0, 5));
            }
        }
        
        // Добавляем информацию о меню если есть
        if (!empty($companyData['menu'])) {
            $menu = is_string($companyData['menu']) 
                ? json_decode($companyData['menu'], true) 
                : $companyData['menu'];
            
            if (is_array($menu) && count($menu) > 0) {
                $categories = array_column($menu, 'category');
                $additions[] = "\n\nВ меню представлены: " . implode(', ', array_slice($categories, 0, 3));
            }
        }
        
        return $text . implode('', $additions);
    }
    
    /**
     * Извлекает телефоны из текста
     * Возвращает массив найденных телефонов
     */
    private function extractPhones(string &$text): array
    {
        $phones = [];
        
        // Паттерны для российских номеров
        $patterns = [
            // +7 (XXX) XXX-XX-XX
            '/\+7\s*\(?\d{3}\)?\s*\d{3}[-\s]?\d{2}[-\s]?\d{2}/u',
            // 8 (XXX) XXX-XX-XX
            '/8\s*\(?\d{3}\)?\s*\d{3}[-\s]?\d{2}[-\s]?\d{2}/u',
            // XXX-XX-XX (внутри текста с префиксом)
            '/(?:тел|телефон|факс)\.?\s*:?\s*(\+7|8)?\s*\(?\d{3}\)?\s*\d{3}[-\s]?\d{2}[-\s]?\d{2}/ui',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[0] as $phone) {
                    // Нормализуем номер
                    $normalized = $this->normalizePhone($phone);
                    if ($normalized && !in_array($normalized, $phones)) {
                        $phones[] = $normalized;
                    }
                }
            }
        }
        
        // Удаляем найденные телефоны из описания
        foreach ($phones as $phone) {
            $text = str_replace($phone, '', $text);
        }
        
        return $phones;
    }
    
    /**
     * Нормализует телефон к формату +7XXXXXXXXXX
     */
    private function normalizePhone(string $phone): ?string
    {
        // Оставляем только цифры
        $digits = preg_replace('/\D/', '', $phone);
        
        // Если номер начинается с 8 и имеет 11 цифр — меняем на +7
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        }
        
        // Если номер имеет 10 цифр — добавляем 7 в начало
        if (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }
        
        // Проверяем что получился валидный российский номер
        if (strlen($digits) === 11 && $digits[0] === '7') {
            return '+' . $digits;
        }
        
        return null;
    }
    
    /**
     * Создаёт краткое описание (превью)
     */
    public function createExcerpt(string $description, int $length = 150): string
    {
        $text = strip_tags($description);
        $text = str_replace("\n", ' ', $text);
        
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        
        $excerpt = mb_substr($text, 0, $length);
        $lastSpace = mb_strrpos($excerpt, ' ');
        
        if ($lastSpace !== false) {
            $excerpt = mb_substr($excerpt, 0, $lastSpace);
        }
        
        return $excerpt . '...';
    }
}
