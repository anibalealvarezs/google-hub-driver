<?php

namespace Anibalealvarezs\GoogleHubDriver\Services;

use Psr\Log\LoggerInterface;

class GoogleSearchConsoleProcessor
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function disaggregateResults(array $results): array
    {
        $this->logger->info("Starting disaggregation of " . count($results) . " result sets");
        $uniqueMetrics = [];
        foreach ($results as $result) {
            $dims = $result['dimensions'];
            $this->logger->info("Processing combination: " . implode(',', $dims) . ", rows=" . count($result['rows'] ?? []));
            foreach ($result['rows'] ?? [] as $row) {
                $uniqueMetrics[] = $row;
            }
        }
        $this->logger->info("Bypassed disaggregation: " . count($uniqueMetrics) . " rows preserved");
        return $uniqueMetrics;
    }
}
