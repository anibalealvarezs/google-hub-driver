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
        $accountMap = $identityMapper('accounts', ['names' => ['Google Search Console']]) ?? [];

        $parentAccount = $accountMap['Google Search Console'] ?? null;

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
                $page->setPlatformId($platformIdForAccount);
                $toPersist->add($page);
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
            } elseif ($ca->getPlatformId() !== $platformIdForAccount || ($ca->getData()['permissionLevel'] ?? null) !== ($site['permissionLevel'] ?? null)) {
                $ca->setPlatformId($platformIdForAccount);
                $data = $ca->getData() ?? [];
                $data['permissionLevel'] = $site['permissionLevel'] ?? 'siteRestrictedUser';
                $ca->setData($data);
                $toPersist->add($ca);
            }

            if ($toPersist->count() > 0) {
                $dataProcessor($toPersist, 'initialization');
            }

            if ($isNew) $stats['initialized']++; else $stats['skipped']++;
        }

        return $stats;
    }
}
