<?php

declare(strict_types=1);

namespace Anibalealvarezs\GoogleHubDriver\Services;

use Psr\Log\LoggerInterface;

class GscInitializerService
{
    private ?LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Initializes GSC assets by resolving identities and persisting them via callbacks.
     *
     * @param string $channel
     * @param array $config
     * @param array $sites
     * @param callable $identityMapper Callback to resolve existing entities
     * @param callable $dataProcessor Callback to persist new/updated entities
     * @return array
     */
    public function initialize(
        string $channel,
        array $config,
        array $sites,
        callable $identityMapper,
        callable $dataProcessor
    ): array {
        $stats = ['initialized' => 0, 'skipped' => 0];
        
        $urls = array_map(fn($s) => rtrim($s['url'], '/'), $sites);
        $caPlatformIds = array_map(fn($u) => md5($u), $urls);

        // 1. Batch Resolve Identities
        $pageMap = $identityMapper('pages', ['urls' => $urls]) ?? [];
        $caMap = $identityMapper('channeled_accounts', ['platform_ids' => array_merge($urls, $caPlatformIds)]) ?? [];
        $defaultGroupName = \Anibalealvarezs\GoogleHubDriver\Drivers\SearchConsoleDriver::getChannelLabel();
        $groupName = $config['accounts_group_name'] ?? $defaultGroupName;

        $accountNames = array_unique([$groupName, $defaultGroupName, 'Google Search Console']);
        $accountMap = $identityMapper('accounts', ['names' => $accountNames]) ?? [];
        $parentAccount = $accountMap[$groupName] ?? ($accountMap[$defaultGroupName] ?? ($accountMap['Google Search Console'] ?? null));

        foreach ($sites as $site) {
            $siteUrl = rtrim($site['url'], '/');
            $platformIdForAccount = md5($siteUrl);
            $canonicalId = \Anibalealvarezs\ApiDriverCore\Classes\AssetRegistry::getCanonicalId($siteUrl, null, 'website');

            $page = $pageMap[$siteUrl] ?? null;
            $ca = $caMap[$platformIdForAccount] ?? ($caMap[$siteUrl] ?? null);

            $isNew = false;
            $toPersist = new \Doctrine\Common\Collections\ArrayCollection();

            // 1. Resolve/Create Page
            if (!$page) {
                $page = new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity();
                $page->setPlatformId($platformIdForAccount)
                    ->setCanonicalId($canonicalId)
                    ->setTitle($site['title'] ?? $siteUrl)
                    ->setUrl($siteUrl)
                    ->setHostname($site['hostname'] ?? parse_url($siteUrl, PHP_URL_HOST))
                    ->setData(['source' => 'gsc_site']);
                
                if ($parentAccount) {
                    $page->setContext(['account' => $parentAccount]);
                }
                
                $toPersist->add($page);
                $isNew = true;
            } elseif ($page->getPlatformId() !== $platformIdForAccount) {
                // Correct Platform ID via direct SQL to avoid Upsert issues if it was a key
                $manager->getConnection()->executeStatement(
                    "UPDATE pages SET platform_id = ? WHERE canonical_id = ?",
                    [$platformIdForAccount, $canonicalId]
                );
                // Also update the object in memory for downstream use
                $page->setPlatformId($platformIdForAccount);
            }

            // 2. Resolve/Create ChanneledAccount
            if (!$ca) {
                $ca = new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity();
                $ca->setPlatformId($platformIdForAccount)
                    ->setChannel($channel)
                    ->setType('gsc_site')
                    ->setTitle($site['title'] ?? $siteUrl)
                    ->setData(['permissionLevel' => $site['permissionLevel'] ?? 'siteRestrictedUser']);
                
                if ($parentAccount) {
                    $ca->setContext(['account' => $parentAccount]);
                }
                
                if ($page) {
                    // Associate with page (link context)
                    $context = $ca->getContext();
                    $context['page'] = $page;
                    $ca->setContext($context);
                }

                $toPersist->add($ca);
            } else {
                $oldPlatformId = $ca->getPlatformId();
                if ($oldPlatformId !== $platformIdForAccount) {
                    // CRITICAL: Update Platform ID via direct SQL because it is part of the UNIQUE KEY for Upsert
                    // If we use Upsert, it will create a DUPLICATE instead of updating.
                    $manager->getConnection()->executeStatement(
                        "UPDATE channeled_accounts SET platform_id = ? WHERE platform_id = ? AND channel = ?",
                        [$platformIdForAccount, $oldPlatformId, $channel]
                    );
                    $ca->setPlatformId($platformIdForAccount);
                }
                // Always sync metadata
                $data = $ca->getData() ?? [];
                if (($data['permissionLevel'] ?? null) !== ($site['permissionLevel'] ?? null)) {
                    $data['permissionLevel'] = $site['permissionLevel'] ?? 'siteRestrictedUser';
                    $ca->setData($data);
                    $toPersist->add($ca);
                }
            }

            if ($toPersist->count() > 0) {
                $dataProcessor($toPersist, 'initialization');
            }

            if ($isNew) $stats['initialized']++; else $stats['skipped']++;
        }

        return $stats;
    }
}
