<?php

declare(strict_types=1);

namespace YellParser;

class ParserService
{
    private Parser $parser;
    private Repository $repository;

    public function __construct(?Parser $parser = null, ?Repository $repository = null)
    {
        $this->parser = $parser ?? new Parser();
        $this->repository = $repository ?? new Repository();
    }

    /**
     * Парсит компании по категории и городу
     */
    public function parseCategory(string $category, string $city, int $maxPages = 5): array
    {
        $this->repository->logParseTask('category', 'started', "Parsing {$category} in {$city}");
        
        $totalParsed = 0;
        $errors = [];

        try {
            for ($page = 1; $page <= $maxPages; $page++) {
                echo "Parsing page {$page}...\n";
                
                $companies = $this->parser->parseSearchResults($category, $city, $page);
                
                if (empty($companies)) {
                    echo "No more results on page {$page}\n";
                    break;
                }

                foreach ($companies as $companyData) {
                    try {
                        $this->saveCompany($companyData, $city);
                        $totalParsed++;
                    } catch (\Exception $e) {
                        $errors[] = $e->getMessage();
                        error_log("Error saving company: " . $e->getMessage());
                    }
                }

                // Задержка между запросами
                sleep(rand(2, 4));
            }

            $this->repository->logParseTask(
                'category', 
                'completed', 
                "Parsed {$totalParsed} companies", 
                $totalParsed
            );

            return [
                'success' => true,
                'parsed' => $totalParsed,
                'errors' => $errors,
            ];

        } catch (\Exception $e) {
            $this->repository->logParseTask('category', 'failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Парсит детальную информацию о компаниях
     */
    public function parseCompanyDetails(int $limit = 50): array
    {
        $this->repository->logParseTask('details', 'started', "Parsing details for {$limit} companies");
        
        $companies = $this->repository->getCompaniesForDetailParsing($limit);
        $parsed = 0;
        $errors = [];

        foreach ($companies as $company) {
            if (empty($company['yell_url'])) {
                continue;
            }

            try {
                echo "Parsing details for: {$company['name']}\n";
                
                $details = $this->parser->parseCompanyDetail($company['yell_url']);
                
                if ($details) {
                    $this->repository->saveCompany(array_merge($company, $details));
                    $parsed++;
                }

                // Задержка между запросами
                sleep(rand(2, 5));

            } catch (\Exception $e) {
                $errors[] = "{$company['name']}: {$e->getMessage()}";
                error_log("Error parsing company details: " . $e->getMessage());
            }
        }

        $this->repository->logParseTask('details', 'completed', "Parsed {$parsed} details", $parsed);

        return [
            'success' => true,
            'parsed' => $parsed,
            'errors' => $errors,
        ];
    }

    /**
     * Парсит отзывы для компаний
     */
    public function parseReviews(int $companyId): array
    {
        $this->repository->logParseTask('reviews', 'started', "Parsing reviews for company {$companyId}");

        // Получаем URL компании
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT yell_url FROM companies WHERE id = ?");
        $stmt->execute([$companyId]);
        $company = $stmt->fetch();

        if (!$company || empty($company['yell_url'])) {
            throw new \RuntimeException("Company not found or has no URL");
        }

        try {
            $reviews = $this->parser->parseReviews($company['yell_url']);
            $saved = 0;

            foreach ($reviews as $review) {
                try {
                    $this->repository->saveReview($companyId, $review);
                    $saved++;
                } catch (\Exception $e) {
                    error_log("Error saving review: " . $e->getMessage());
                }
            }

            $this->repository->logParseTask('reviews', 'completed', "Saved {$saved} reviews", $saved);

            return [
                'success' => true,
                'saved' => $saved,
                'total' => count($reviews),
            ];

        } catch (\Exception $e) {
            $this->repository->logParseTask('reviews', 'failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Сохраняет компанию
     */
    private function saveCompany(array $data, string $citySlug): void
    {
        // Получаем или создаем город
        $cityId = $this->repository->saveOrGetCity(
            $citySlug,
            $citySlug,
            null,
            "https://www.yell.ru/{$citySlug}/"
        );

        // Подготавливаем данные компании
        $companyData = [
            'name' => $data['name'],
            'yell_url' => $data['url'] ?? null,
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'rating' => $data['rating'] ?? null,
            'review_count' => $data['review_count'] ?? 0,
            'city_id' => $cityId,
        ];

        // Извлекаем yell_id из URL
        if (!empty($data['url'])) {
            preg_match('/\/([^\/]+)\/$/', $data['url'], $matches);
            $companyData['yell_id'] = $matches[1] ?? null;
        }

        $this->repository->saveCompany($companyData);
    }

    /**
     * Получает статистику
     */
    public function getStats(): array
    {
        return $this->repository->getStats();
    }
}
