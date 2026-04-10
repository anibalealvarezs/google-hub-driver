<?php

declare(strict_types=1);

namespace Anibalealvarezs\GoogleHubDriver\Services;

use Anibalealvarezs\ApiDriverCore\Classes\AssetRegistry;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class GscInitializerService
{
    private EntityManagerInterface $entityManager;
    private ?LoggerInterface $logger;

    public function __construct(EntityManagerInterface $entityManager, ?LoggerInterface $logger = null)
    {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }

    public function initialize(string $channel, array $config, array $sites): array
    {
        $stats = ['initialized' => 0, 'skipped' => 0];
        
        $pageClass = '\Entities\Analytics\Page';
        $pageTypeClass = '\Enums\PageType';

        if (!class_exists($pageClass)) return $stats;

        $pageRepository = $this->entityManager->getRepository($pageClass);

        foreach ($sites as $site) {
            $siteUrl = $site['url'];
            $normalizedSiteUrl = rtrim($siteUrl, '/');
            $title = $site['title'] ?? $siteUrl;
            $hostname = $site['hostname'] ?? parse_url($siteUrl, PHP_URL_HOST) ?? str_replace('sc-domain:', '', $siteUrl);

            $typeEnum = defined("$pageTypeClass::WEBSITE") ? constant("$pageTypeClass::WEBSITE") : 'WEBSITE';
            $canonicalId = AssetRegistry::getCanonicalId($normalizedSiteUrl, null, $typeEnum);

            $pageEntity = $pageRepository->findOneBy(['canonicalId' => $canonicalId]);
            $isNew = false;
            if (!$pageEntity) {
                $pageEntity = new $pageClass();
                $pageEntity->addCanonicalId($canonicalId);
                $isNew = true;
                $this->logger?->info("Initializing new GSC Page: URL=$normalizedSiteUrl");
            }

            $pageEntity->addUrl($normalizedSiteUrl)
                ->addTitle($title)
                ->addHostname($hostname)
                ->addPlatformId(md5($normalizedSiteUrl))
                ->addData(['source' => 'gsc_site'])
                ->addUpdatedAt(new DateTime());

            $this->entityManager->persist($pageEntity);
            if ($isNew) $stats['initialized']++; else $stats['skipped']++;
        }

        $this->entityManager->flush();
        return $stats;
    }
}
