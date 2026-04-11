<?php

declare(strict_types=1);

namespace Anibalealvarezs\GoogleHubDriver\Services;

use Doctrine\ORM\EntityManagerInterface;
use Anibalealvarezs\ApiSkeleton\Enums\Channel;

class GscResetService
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function reset(string $channelName, string $mode = 'all'): array
    {
        $connection = $this->entityManager->getConnection();
        $enum = Channel::tryFromName($channelName);
        if (!$enum) {
            return ['error' => "Unknown channel: $channelName"];
        }

        $channelId = $enum->value;
        $channelSlug = $enum->name;

        if ($mode === 'all' || $mode === 'metrics') {
            // 1. Clear Jobs for Metrics
            $connection->executeStatement(
                "DELETE FROM jobs WHERE channel = ? AND entity = 'metric'",
                [$channelSlug],
                [\Doctrine\DBAL\ParameterType::STRING]
            );

            // 2. Clear Metrics
            $connection->executeStatement("
                DELETE FROM channeled_metrics WHERE metric_id IN (
                    SELECT m.id FROM metrics m 
                    JOIN metric_configs mc ON m.metric_config_id = mc.id 
                    WHERE mc.channel = ?
                )", [$channelId], [\Doctrine\DBAL\ParameterType::INTEGER]);

            $connection->executeStatement("
                DELETE FROM metrics WHERE metric_config_id IN (
                    SELECT id FROM metric_configs WHERE channel = ?
                )", [$channelId], [\Doctrine\DBAL\ParameterType::INTEGER]);

            $connection->executeStatement("DELETE FROM metric_configs WHERE channel = ?", [$channelId], [\Doctrine\DBAL\ParameterType::INTEGER]);
        }

        if ($mode === 'all' || $mode === 'entities') {
            // 1. Clear Jobs for Entities
            $connection->executeStatement(
                "DELETE FROM jobs WHERE channel = ? AND entity != 'metric'",
                [$channelSlug],
                [\Doctrine\DBAL\ParameterType::STRING]
            );

            // 2. Clear Pages
            $connection->executeStatement("
                DELETE FROM pages 
                WHERE account_id IN (SELECT id FROM channeled_accounts WHERE channel = ?)
            ", [$channelId], [\Doctrine\DBAL\ParameterType::INTEGER]);

            $connection->executeStatement("DELETE FROM channeled_accounts WHERE channel = ?", [$channelId], [\Doctrine\DBAL\ParameterType::INTEGER]);
        }

        return ['cleared' => 0];
    }
}
