<?php

namespace Anibalealvarezs\GoogleHubDriver\Analytics;

use InvalidArgumentException;

/** Driver-owned meanings; availability, compatibility and cache coverage are independent. */
final class GoogleAnalyticsCatalog
{
    public const VERSION = 2;

    public static function recipes(array $baseline): array
    {
        return $baseline + [
            'engagement_matrix' => [
                'metrics' => ['sessions', 'engagedSessions', 'userEngagementDuration', 'engagementRate'],
                'dimensions' => ['date', 'sessionDefaultChannelGroup', 'sessionSourceMedium', 'landingPagePlusQueryString', 'deviceCategory', 'countryId'],
                'measurement_scope' => 'session', 'attribution' => 'session',
            ],
            'ecommerce_matrix' => [
                'metrics' => ['ecommercePurchases', 'purchaseRevenue', 'grossPurchaseRevenue', 'refundAmount', 'transactions'],
                'dimensions' => ['date', 'sessionDefaultChannelGroup', 'sessionSourceMedium', 'sessionCampaignName', 'deviceCategory', 'countryId'],
                'measurement_scope' => 'event', 'attribution' => 'session',
            ],
            'product_matrix' => [
                'metrics' => ['itemsViewed', 'itemsAddedToCart', 'itemsPurchased', 'itemsRefunded', 'itemRevenue', 'grossItemRevenue', 'itemRefundAmount'],
                'dimensions' => ['date', 'itemId', 'itemName', 'itemBrand', 'itemCategory', 'itemVariant'],
                'measurement_scope' => 'item', 'attribution' => 'item',
            ],
        ];
    }

    public static function definitions(): array
    {
        $definitions = [];
        foreach (['screenPageViews', 'sessions', 'eventCount', 'conversions', 'keyEvents', 'engagedSessions', 'userEngagementDuration', 'ecommercePurchases', 'transactions', 'itemsViewed', 'itemsAddedToCart', 'itemsPurchased', 'itemsRefunded'] as $name) {
            $definitions[$name] = ['reducer' => 'sum', 'unit' => $name === 'userEngagementDuration' ? 'seconds' : 'count'];
        }
        foreach (['totalRevenue', 'purchaseRevenue', 'grossPurchaseRevenue', 'refundAmount', 'itemRevenue', 'grossItemRevenue', 'itemRefundAmount'] as $name) {
            $definitions[$name] = ['reducer' => 'sum', 'unit' => 'property_currency', 'currency_metadata_path' => ['ga4_report_quality', 'currencyCode']];
        }
        foreach (['newUsers', 'activeUsers', 'totalUsers'] as $name) {
            $definitions[$name] = ['reducer' => 'source_window_distinct', 'daily_reducer' => 'sum_of_reported_user_counts_not_window_unique', 'unit' => 'users'];
        }
        foreach (['cohortActiveUsers', 'cohortTotalUsers'] as $name) $definitions[$name] = ['reducer' => 'source_report_only', 'unit' => 'users'];
        foreach (['bounceRate', 'averageSessionDuration', 'engagementRate'] as $name) {
            $definitions[$name] = ['reducer' => 'companion_weighted_average', 'weight_names' => ['sessions'], 'unit' => $name === 'averageSessionDuration' ? 'seconds' : 'ratio'];
        }
        return $definitions;
    }

    public static function project(array $metadata, array $recipes, array $context = []): array
    {
        $result = ['version' => self::VERSION, 'metrics' => [], 'dimensions' => [], 'recipes' => array_combine(array_keys($recipes), array_map(static fn ($recipe, $key) => $recipe + ['label' => ucwords(str_replace(['_matrix', '_'], ['', ' '], $key))], $recipes, array_keys($recipes))), 'context' => $context];
        foreach (['metrics', 'dimensions'] as $kind) {
            foreach ($metadata[$kind] ?? [] as $field) {
                $name = $field['apiName'] ?? '';
                if ($name === '') continue;
                $custom = !empty($field['customDefinition']);
                $definition = $kind === 'metrics' ? (self::definitions()[$name] ?? ['reducer' => 'source_report_only', 'unit' => $field['type'] ?? 'unknown']) : [];
                if ($kind === 'metrics' && $custom) {
                    $definition = ['reducer' => str_starts_with($name, 'averageCustomEvent:') ? 'source_report_only' : 'sum', 'unit' => $field['type'] ?? 'unknown'];
                }
                $result[$kind][$name] = [
                    'raw_name' => $name, 'label' => $field['uiName'] ?? $name,
                    'description' => $field['description'] ?? '', 'type' => $field['type'] ?? 'string',
                    'custom' => $custom, 'property_available' => true,
                    'implemented' => $kind === 'dimensions' || $definition !== [],
                    'selected_for_sync' => false, 'cache_coverage' => 'unknown', 'daily_cache_supported' => (bool)array_filter($recipes, fn ($recipe) => in_array($name, $recipe[$kind] ?? [], true)),
                    'reportable' => 'requires_compatible_definition_and_completed_window',
                    'scope' => self::scope($name), 'recipes' => array_keys(array_filter($recipes, fn ($recipe) => in_array($name, $recipe[$kind] ?? [], true))),
                ] + $definition;
            }
            ksort($result[$kind]);
        }
        $result['revision'] = self::hash([$result['metrics'], $result['dimensions']]);
        $result['advanced'] = [
            'window' => ['endpoint' => 'runReport', 'rollup' => 'forbidden', 'exactness' => 'source_reported_window_distinct'],
            'cohort' => ['endpoint' => 'runReport/cohortSpec', 'rollup' => 'forbidden'],
            'funnel' => ['endpoint' => 'v1alpha/runFunnelReport', 'status' => 'requires_explicit_enablement_and_source_validation'],
            'warehouse' => ['status' => 'separate_connector_required', 'reason' => 'Aggregated reports cannot reconstruct unrestricted user journeys or unavailable history.'],
        ];
        return $result;
    }

    public static function compile(array $definition, array $metadata, array $context): array
    {
        $kind = $definition['kind'] ?? 'report';
        if (!in_array($kind, ['report', 'window', 'cohort', 'funnel'], true)) throw new InvalidArgumentException('Unsupported report family.');
        $dimensions = array_values(array_unique($definition['dimensions'] ?? []));
        $metrics = array_values(array_unique($definition['metrics'] ?? []));
        if ($kind === 'funnel') {
            if (empty($context['funnel_enabled'])) throw new InvalidArgumentException('Funnel reporting is disabled until its alpha source path has been validated and enabled for this tenant.');
            $metrics = ['activeUsers']; $dimensions = [];
        }
        if (!$metrics || count($metrics) > 10 || count($dimensions) > 9) throw new InvalidArgumentException('Reports require 1–10 metrics and at most nine dimensions, including date.');
        $availableMetrics = array_column($metadata['metrics'] ?? [], 'apiName');
        $availableDimensions = array_column($metadata['dimensions'] ?? [], 'apiName');
        if (array_diff($metrics, $availableMetrics) || array_diff($dimensions, $availableDimensions)) throw new InvalidArgumentException('A selected field is unavailable for this property. Refresh its catalog.');
        $start = self::date($definition['start_date'] ?? '');
        $end = self::date($definition['end_date'] ?? '');
        if ($start > $end || $start->diff($end)->days > 365) throw new InvalidArgumentException('Select an ordered window of at most 366 days.');
        if (empty($context['property_id']) || empty($context['time_zone']) || empty($context['authorization_scope'])) throw new InvalidArgumentException('Property, timezone and authorization context are required.');
        new \DateTimeZone($context['time_zone']);
        if ($kind === 'window' && (in_array('date', $dimensions, true) || array_diff($metrics, ['activeUsers', 'totalUsers', 'newUsers']))) throw new InvalidArgumentException('Exact-window users must be requested without a daily date dimension.');
        $itemFields = array_filter($dimensions, fn ($name) => str_starts_with($name, 'item') || str_starts_with($name, 'customItem:'));
        if ($itemFields && array_intersect($metrics, ['totalRevenue', 'purchaseRevenue', 'grossPurchaseRevenue', 'refundAmount', 'transactions', 'ecommercePurchases'])) throw new InvalidArgumentException('Transaction measures cannot be repeated across item rows. Select item measures or a transaction report.');
        $payload = [
            'dimensions' => array_map(fn ($name) => ['name' => $name], $dimensions),
            'metrics' => array_map(fn ($name) => ['name' => $name], $metrics),
            'dateRanges' => [['startDate' => $start->format('Y-m-d'), 'endDate' => $end->format('Y-m-d')]],
            'returnPropertyQuota' => true,
            'limit' => min(10000, max(1, (int)($definition['max_rows'] ?? 10000))),
        ];
        if ($kind === 'report') $payload['metricAggregations'] = ['TOTAL'];
        if (!empty($definition['filters'])) $payload['dimensionFilter'] = self::filters($definition['filters'], $availableDimensions);
        if ($kind === 'cohort') {
            $granularity = $definition['cohort_granularity'] ?? 'DAILY';
            if (!in_array($granularity, ['DAILY', 'WEEKLY', 'MONTHLY'], true) || !in_array('cohort', $dimensions, true)) throw new InvalidArgumentException('Cohorts require a cohort dimension and supported granularity.');
            if (array_diff($metrics, ['cohortActiveUsers', 'cohortTotalUsers'])) throw new InvalidArgumentException('Select cohort measures; daily user measures cannot substitute cohort populations.');
            $offset = (int)($definition['cohort_end_offset'] ?? 7);
            if ($offset < 0 || $offset > 90) throw new InvalidArgumentException('Cohort follow-up must be between zero and 90 offsets.');
            $followupDays = $offset * ['DAILY' => 1, 'WEEKLY' => 7, 'MONTHLY' => 31][$granularity];
            if ($start->diff($end)->days + $followupDays > 365) throw new InvalidArgumentException('Cohort selection plus follow-up exceeds the source window budget.');
            $payload['cohortSpec'] = ['cohorts' => [['name' => 'selected', 'dimension' => 'firstSessionDate', 'dateRange' => $payload['dateRanges'][0]]], 'cohortsRange' => ['granularity' => $granularity, 'startOffset' => 0, 'endOffset' => $offset]];
            unset($payload['dateRanges']);
        }
        if ($kind === 'funnel') {
            $events = $definition['steps'] ?? [];
            if (count($events) < 2 || count($events) > 10) throw new InvalidArgumentException('Select 2–10 ordered funnel event steps.');
            foreach ($events as $event) if (!is_string($event) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,39}$/D', $event)) throw new InvalidArgumentException('Use valid source event names for funnel steps.');
            $payload['funnel'] = ['isOpenFunnel' => (bool)($definition['open_funnel'] ?? false), 'steps' => array_map(fn ($event) => ['name' => $event, 'filterExpression' => ['funnelEventFilter' => ['eventName' => $event]]], $events)];
            unset($payload['metrics'], $payload['dimensions']);
        }
        $fields = [];
        foreach (['metrics' => $metrics, 'dimensions' => $dimensions] as $group => $selected) {
            foreach ($metadata[$group] ?? [] as $field) {
                if (in_array($field['apiName'], $selected, true)) $fields[$group][$field['apiName']] = array_intersect_key($field, array_flip(['apiName', 'uiName', 'type', 'description', 'customDefinition'])) + ($group === 'metrics' ? self::definitions()[$field['apiName']] ?? ['reducer' => 'source_report_only'] : []);
            }
        }
        $frozen = ['contract_version' => self::VERSION, 'kind' => $kind, 'payload' => $payload, 'context' => $context, 'field_definitions' => $fields, 'definition_revision' => $definition['revision'] ?? 1];
        return $frozen + ['hash' => self::hash($frozen), 'rollup' => 'forbidden', 'exactness' => $kind === 'window' ? 'source_reported_window_distinct' : 'source_reported'];
    }

    private static function filters(array $filters, array $available): array
    {
        if (count($filters) > 12) throw new InvalidArgumentException('At most 12 fixed report filters are supported.');
        $expressions = [];
        foreach ($filters as $filter) {
            if (!in_array($filter['field'] ?? '', $available, true)) throw new InvalidArgumentException('Unsupported report filter dimension.');
            $values = array_values(array_map('strval', (array)($filter['values'] ?? [])));
            if (!$values || count($values) > 50) throw new InvalidArgumentException('Select 1–50 filter values.');
            $expressions[] = ['filter' => ['fieldName' => $filter['field'], 'inListFilter' => ['values' => $values, 'caseSensitive' => true]]];
        }
        return ['andGroup' => ['expressions' => $expressions]];
    }

    private static function date(string $date): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('Use explicit YYYY-MM-DD dates.');
        return $parsed;
    }

    private static function scope(string $name): string
    {
        if (str_starts_with($name, 'customUser:') || in_array($name, ['activeUsers', 'newUsers', 'totalUsers'], true)) return 'user';
        if (str_starts_with($name, 'item') || str_starts_with($name, 'customItem:')) return 'item';
        if (str_starts_with($name, 'session')) return 'session';
        return 'event_or_property';
    }

    public static function hash(array $value): string
    {
        $canonical = function (mixed $item) use (&$canonical): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item);
            return array_map($canonical, $item);
        };
        return hash('sha256', json_encode($canonical($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public static function quotaDelay(array $quota): int
    {
        foreach (['tokensPerDay' => 3600, 'tokensPerHour' => 300, 'tokensPerProjectPerHour' => 300, 'serverErrorsPerProjectPerHour' => 300, 'concurrentRequests' => 60] as $key => $delay) {
            if (isset($quota[$key]['remaining']) && (int)$quota[$key]['remaining'] <= 0) return $delay;
        }
        return 0;
    }
}
