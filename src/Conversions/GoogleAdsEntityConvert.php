<?php

declare(strict_types=1);

namespace Anibalealvarezs\GoogleHubDriver\Conversions;

use Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity;
use Carbon\Carbon;
use Doctrine\Common\Collections\ArrayCollection;

class GoogleAdsEntityConvert
{
    /**
     * Convert Google Ads campaign rows into UniversalEntity collection
     *
     * @param array $campaignResults
     * @param string $customerId
     * @return ArrayCollection<int, UniversalEntity>
     */
    public static function campaigns(array $campaignResults, string $customerId): ArrayCollection
    {
        $collection = new ArrayCollection();

        foreach ($campaignResults as $row) {
            $c = $row['campaign'] ?? $row;
            if (empty($c['id'])) {
                continue;
            }

            $entity = new UniversalEntity($c);
            $entity->channel = 'google_ads';
            $entity->category = 'campaign';
            $entity->type = $c['advertisingChannelType'] ?? 'SEARCH';
            $entity->platformId = (string)$c['id'];
            $entity->name = $c['name'] ?? ('Campaign ' . $c['id']);
            $entity->status = $c['status'] ?? 'ENABLED';
            $entity->channeledAccountId = $customerId;
            $entity->startDate = !empty($c['startDateTime']) ? Carbon::parse($c['startDateTime']) : null;
            $entity->endDate = !empty($c['endDateTime']) ? Carbon::parse($c['endDateTime']) : null;
            $entity->data = $c;

            $collection->add($entity);
        }

        return $collection;
    }

    /**
     * Convert Google Ads ad group rows into UniversalEntity collection
     *
     * @param array $adGroupResults
     * @param string $customerId
     * @return ArrayCollection<int, UniversalEntity>
     */
    public static function adGroups(array $adGroupResults, string $customerId): ArrayCollection
    {
        $collection = new ArrayCollection();

        foreach ($adGroupResults as $row) {
            $ag = $row['adGroup'] ?? $row;
            $camp = $row['campaign'] ?? [];
            if (empty($ag['id'])) {
                continue;
            }

            $entity = new UniversalEntity($ag);
            $entity->channel = 'google_ads';
            $entity->category = 'ad_group';
            $entity->type = $ag['type'] ?? 'SEARCH_STANDARD';
            $entity->platformId = (string)$ag['id'];
            $entity->name = $ag['name'] ?? ('AdGroup ' . $ag['id']);
            $entity->status = $ag['status'] ?? 'ENABLED';
            $entity->channeledAccountId = $customerId;
            $entity->channeledCampaignId = (string)($camp['id'] ?? ($ag['campaign'] ?? ''));
            $entity->data = $ag;

            $collection->add($entity);
        }

        return $collection;
    }

    /**
     * Convert Google Ads ad rows into UniversalEntity collection
     *
     * @param array $adResults
     * @param string $customerId
     * @return ArrayCollection<int, UniversalEntity>
     */
    public static function ads(array $adResults, string $customerId): ArrayCollection
    {
        $collection = new ArrayCollection();

        foreach ($adResults as $row) {
            $adGroupAd = $row['adGroupAd'] ?? [];
            $ad = $adGroupAd['ad'] ?? ($row['ad'] ?? $row);
            $camp = $row['campaign'] ?? [];
            $adGroup = $row['adGroup'] ?? [];
            if (empty($ad['id'])) {
                continue;
            }

            $entity = new UniversalEntity($ad);
            $entity->channel = 'google_ads';
            $entity->category = 'ad';
            $entity->type = $ad['type'] ?? 'RESPONSIVE_SEARCH_AD';
            $entity->platformId = (string)$ad['id'];
            $entity->name = $ad['name'] ?? ('Ad ' . $ad['id']);
            $entity->status = $adGroupAd['status'] ?? ($ad['status'] ?? 'ENABLED');
            $entity->channeledAccountId = $customerId;
            $entity->channeledCampaignId = (string)($camp['id'] ?? '');
            $entity->channeledAdGroupId = (string)($adGroup['id'] ?? '');
            $entity->data = array_merge($ad, ['final_urls' => $ad['finalUrls'] ?? []]);

            $collection->add($entity);
        }

        return $collection;
    }
}
