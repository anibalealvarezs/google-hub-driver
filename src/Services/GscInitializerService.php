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
    private \Anibalealvarezs\ApiSkeleton\Enums\Channel $channelEnum;

    public function __construct(EntityManagerInterface $entityManager, ?LoggerInterface $logger = null)
    {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->channelEnum = \Anibalealvarezs\ApiSkeleton\Enums\Channel::google_search_console;
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

            if (\Anibalealvarezs\ApiDriverCore\Helpers\Helpers::isAssetFiltered($normalizedSiteUrl, $config)) {
                $this->logger?->info("Skipping filtered GSC site: $normalizedSiteUrl");
                continue;
            }

            $title = $site['title'] ?? $siteUrl;
            $hostname = $site['hostname'] ?? parse_url($siteUrl, PHP_URL_HOST) ?? str_replace('sc-domain:', '', $siteUrl);

            $canonicalId = \Helpers\Helpers::getCanonicalPageId($normalizedSiteUrl, null, 'website');
            $platformIdForAccount = md5($normalizedSiteUrl); // Unique id for THIS sc site

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
                ->addPlatformId($canonicalId) 
                ->addData(['source' => 'gsc_site'])
                ->addUpdatedAt(new DateTime());

            $this->entityManager->persist($pageEntity);

            // Initialize ChanneledAccount for cross-reference in monitoring/analytics
            $chanAccountClass = '\Entities\Analytics\Channeled\ChanneledAccount';
            $accountClass = '\Entities\Analytics\Account';

            if (class_exists($chanAccountClass) && class_exists($accountClass)) {
                $chanAccountRepository = $this->entityManager->getRepository($chanAccountClass);
                $accountRepository = $this->entityManager->getRepository($accountClass);

                // Resolve specific account for this site, fallback to global
                $siteSpecificAccountName = $site['account'] ?? $config['accounts_group_name'] ?? 'Google Search Console';
                $parentAccount = $accountRepository->findOneBy(['name' => $siteSpecificAccountName]);
                
                if (!$parentAccount) {
                    $parentAccount = $accountRepository->findOneBy(['name' => 'Google Search Console']) 
                                   ?? $accountRepository->findOneBy([]) // Fallback to first available account
                                   ?? (new $accountClass())->addName('Google Search Console');
                    
                    if (!$parentAccount->getId()) {
                        $this->entityManager->persist($parentAccount);
                    }
                }

                // Associate Page with Account as well
                $pageEntity->addAccount($parentAccount);
                $this->entityManager->persist($pageEntity);

                $ca = $chanAccountRepository->findOneBy([
                    'platformId' => $platformIdForAccount,
                    'channel' => $this->channelEnum->value
                ]);

                if (!$ca) {
                    $ca = new $chanAccountClass();
                    $ca->addPlatformId($platformIdForAccount)
                       ->addChannel($this->channelEnum->value);
                }

                $ca->addAccount($parentAccount)
                   ->addName($title)
                   ->addType('gsc_site')
                   ->addUpdatedAt(new DateTime());

                $this->entityManager->persist($ca);
            }

            if ($isNew) $stats['initialized']++; else $stats['skipped']++;
        }

        $this->entityManager->flush();
        return $stats;
    }
}
