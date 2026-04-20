<?php declare(strict_types = 0);

namespace Modules\ZabGraph\Services;

use API;

class HostObservabilityService {

	public static function buildPayload(int $hostid, int $window_sec = 21600, string $severity_filter = 'all', string $metric_filter = 'all'): array {
		$window_sec = max(3600, min(86400, $window_sec));
		$time_from = time() - $window_sec;

		$hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name', 'status'],
			'selectTags' => ['tag', 'value'],
			'hostids' => [$hostid]
		]);

		if (empty($hosts)) {
			return ['success' => false, 'error' => 'Host not found'];
		}

		$host = $hosts[0];
		$items = API::Item()->get([
			'output' => ['itemid', 'name', 'key_', 'value_type', 'units', 'lastvalue', 'lastclock'],
			'hostids' => [$hostid],
			'monitored' => true,
			'sortfield' => 'name'
		]);

		$selected = self::selectMetricItems($items);
		$series = [];
		$current = [];

		foreach ($selected as $metric => $item) {
			if ($item === null) {
				$series[$metric] = [];
				$current[$metric] = null;
				continue;
			}

			$history = self::fetchItemHistory((int) $item['itemid'], (int) $item['value_type'], $time_from, 220);
			if (empty($history)) {
				$history = self::fallbackSeriesFromLast($item, $window_sec);
			}
			$series[$metric] = $history;
			$current[$metric] = empty($history)
				? (is_numeric($item['lastvalue'] ?? null) ? (float) $item['lastvalue'] : null)
				: (float) $history[count($history) - 1]['value'];
		}

		$events = API::Event()->get([
			'output' => ['eventid', 'clock', 'name', 'severity', 'objectid'],
			'hostids' => [$hostid],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'time_from' => $time_from,
			'sortfield' => 'clock',
			'sortorder' => ZBX_SORT_DOWN,
			'limit' => 250
		]);

		$triggers = API::Trigger()->get([
			'output' => ['triggerid', 'description', 'priority', 'value', 'lastchange'],
			'hostids' => [$hostid],
			'monitored' => true,
			'filter' => ['value' => TRIGGER_VALUE_TRUE],
			'limit' => 50
		]);

		$issues = self::buildIssues($triggers, $severity_filter);
		$detected_alerts = self::buildSyntheticAlerts($series);
		$status = self::hostStatus($issues, $detected_alerts, (int) ($host['status'] ?? 0));
		$spikes = self::detectSpikes($series);
		$timeline = self::buildTimeline($events, $spikes, $detected_alerts);
		$event_count_5m = self::countRecentEvents($events, 300);

		$insight = GeminiInsightService::generate([
			'cpu' => [
				'current' => $current['cpu'] ?? null,
				'spike' => $spikes['cpu_spike'] ?? false
			],
			'memory' => [
				'current' => $current['memory'] ?? null,
				'spike' => $spikes['memory_spike'] ?? false
			],
			'events' => array_slice($timeline, -6),
			'events_last_5m' => $event_count_5m,
			'network_spike' => $spikes['network_spike'] ?? false,
			'detected_alerts' => array_slice($detected_alerts, 0, 5)
		]);

		$overlays = self::buildAlertOverlays($events, $series, $metric_filter, $detected_alerts);
		$badges = self::buildInsightBadges($spikes);

		$visible_name = (string) ($host['name'] ?: $host['host']);

		return [
			'success' => true,
			'host' => [
				'hostid' => (int) $host['hostid'],
				'host' => (string) $host['host'],
				'name' => $visible_name,
				'tags' => $host['tags'] ?? []
			],
			'status' => $status,
			'alert_summary' => self::buildAlertSummary($event_count_5m, $detected_alerts),
			'issues' => $issues,
			'detected_alerts' => $detected_alerts,
			'insight' => $insight,
			'timeline' => $timeline,
			'badges' => $badges,
			'metrics' => [
				'cpu' => self::metricPayload('CPU usage', '%', $series['cpu'] ?? []),
				'memory' => self::metricPayload('Memory usage', '%', $series['memory'] ?? []),
				'network_in' => self::metricPayload('Network In', 'bps', $series['network_in'] ?? []),
				'network_out' => self::metricPayload('Network Out', 'bps', $series['network_out'] ?? []),
				'disk' => self::metricPayload('Disk usage', '%', $series['disk'] ?? [])
			],
			'overlays' => $overlays,
			'thresholds' => [
				'cpu_warning' => 70,
				'cpu_critical' => 85,
				'memory_warning' => 70,
				'memory_critical' => 80
			],
			'server_time' => time()
		];
	}

	private static function metricPayload(string $label, string $unit, array $series): array {
		return [
			'label' => $label,
			'unit' => $unit,
			'series' => $series
		];
	}

	private static function selectMetricItems(array $items): array {
		$cpu = self::pickItem($items, ['system.cpu.util', 'cpu.util', 'system.cpu.load', 'cpu']);
		$memory = self::pickItem($items, [
			'vm.memory.util',
			'memory.util',
			'vm.memory.size[pused',
			'vm.memory.size[available',
			'vm.memory.size[pavailable',
			'memory'
		]);
		$net_in = self::pickItem($items, ['net.if.in', 'network in', 'incoming']);
		$net_out = self::pickItem($items, ['net.if.out', 'network out', 'outgoing']);
		$disk = self::pickItem($items, ['vfs.fs.size', 'disk.util', 'vfs.dev.read', 'disk']);

		return [
			'cpu' => $cpu,
			'memory' => $memory,
			'network_in' => $net_in,
			'network_out' => $net_out,
			'disk' => $disk
		];
	}

	private static function pickItem(array $items, array $needles): ?array {
		foreach ($items as $item) {
			$hay = mb_strtolower((string) (($item['key_'] ?? '').' '.($item['name'] ?? '')));
			foreach ($needles as $needle) {
				if (mb_strpos($hay, $needle) !== false) {
					return $item;
				}
			}
		}
		return null;
	}

	private static function fetchItemHistory(int $itemid, int $value_type, int $time_from, int $limit): array {
		$history_type = self::historyType($value_type);
		$rows = API::History()->get([
			'output' => ['clock', 'value'],
			'history' => $history_type,
			'itemids' => [$itemid],
			'time_from' => $time_from,
			'sortfield' => 'clock',
			'sortorder' => ZBX_SORT_ASC,
			'limit' => $limit
		]);

		$series = [];
		foreach ($rows as $row) {
			$series[] = [
				'ts' => (int) $row['clock'] * 1000,
				'value' => (float) $row['value']
			];
		}
		return $series;
	}

	private static function fallbackSeriesFromLast(array $item, int $window_sec): array {
		$last = $item['lastvalue'] ?? null;
		if (!is_numeric($last)) {
			return [];
		}

		$lastclock = (int) ($item['lastclock'] ?? 0);
		if ($lastclock <= 0) {
			$lastclock = time();
		}

		$base_ts = ($lastclock * 1000);
		$offset = (int) max(60000, min(300000, ($window_sec * 1000) / 30));
		$v = (float) $last;

		return [
			['ts' => $base_ts - $offset, 'value' => $v],
			['ts' => $base_ts, 'value' => $v]
		];
	}

	private static function historyType(int $value_type): int {
		if ($value_type === ITEM_VALUE_TYPE_UINT64) {
			return HISTORY_TYPE_UINT;
		}
		if ($value_type === ITEM_VALUE_TYPE_FLOAT) {
			return HISTORY_TYPE_FLOAT;
		}
		return HISTORY_TYPE_FLOAT;
	}

	private static function buildIssues(array $triggers, string $severity_filter): array {
		usort($triggers, static function (array $a, array $b): int {
			$pa = (int) ($a['priority'] ?? 0);
			$pb = (int) ($b['priority'] ?? 0);
			if ($pa !== $pb) {
				return $pb <=> $pa;
			}
			return ((int) ($b['lastchange'] ?? 0)) <=> ((int) ($a['lastchange'] ?? 0));
		});

		$filtered = [];
		foreach ($triggers as $tr) {
			$priority = (int) ($tr['priority'] ?? 0);
			if ($severity_filter === 'critical' && $priority < 4) {
				continue;
			}
			if ($severity_filter === 'warning' && $priority < 2) {
				continue;
			}
			$filtered[] = [
				'triggerid' => (int) $tr['triggerid'],
				'description' => (string) ($tr['description'] ?? 'Issue'),
				'priority' => $priority,
				'label' => self::severityLabel($priority),
				'lastchange' => (int) ($tr['lastchange'] ?? 0)
			];
			if (count($filtered) >= 3) {
				break;
			}
		}
		return $filtered;
	}

	private static function hostStatus(array $issues, array $detected_alerts, int $host_status): array {
		if ($host_status == HOST_STATUS_NOT_MONITORED) {
			return ['level' => 'warning', 'label' => 'Warning'];
		}
		$max_priority = 0;
		foreach ($issues as $issue) {
			$max_priority = max($max_priority, (int) ($issue['priority'] ?? 0));
		}
		$max_detected = 0;
		foreach ($detected_alerts as $alert) {
			$sev = (string) ($alert['severity'] ?? 'normal');
			if ($sev === 'critical' || $sev === 'disaster') {
				$max_detected = max($max_detected, 4);
			}
			elseif ($sev === 'warning') {
				$max_detected = max($max_detected, 2);
			}
		}
		$max_priority = max($max_priority, $max_detected);
		if ($max_priority >= 4) {
			return ['level' => 'critical', 'label' => 'Critical'];
		}
		if ($max_priority >= 2) {
			return ['level' => 'warning', 'label' => 'Warning'];
		}
		return ['level' => 'healthy', 'label' => 'Healthy'];
	}

	private static function detectSpikes(array $series): array {
		$cpu = self::lastValue($series['cpu'] ?? []);
		$memory = self::lastValue($series['memory'] ?? []);
		$net_in = self::lastValue($series['network_in'] ?? []);
		$net_out = self::lastValue($series['network_out'] ?? []);

		$network_threshold = 100000000;
		return [
			'cpu_spike' => $cpu !== null && $cpu > 85,
			'memory_spike' => $memory !== null && $memory > 80,
			'network_spike' => ($net_in !== null && $net_in > $network_threshold)
				|| ($net_out !== null && $net_out > $network_threshold)
		];
	}

	private static function lastValue(array $series): ?float {
		if (empty($series)) {
			return null;
		}
		$last = $series[count($series) - 1];
		return (float) ($last['value'] ?? 0);
	}

	private static function buildTimeline(array $events, array $spikes, array $detected_alerts = []): array {
		$timeline = [];
		foreach (array_reverse($events) as $ev) {
			$timeline[] = [
				'ts' => (int) $ev['clock'],
				'label' => (string) ($ev['name'] ?? 'Alert event'),
				'severity' => self::severityLabel((int) ($ev['severity'] ?? 0))
			];
		}

		if (!empty($spikes['cpu_spike'])) {
			$timeline[] = ['ts' => time() - 120, 'label' => 'CPU spike', 'severity' => 'high'];
		}
		if (!empty($spikes['memory_spike'])) {
			$timeline[] = ['ts' => time() - 90, 'label' => 'Memory increase', 'severity' => 'high'];
		}
		if (!empty($spikes['network_spike'])) {
			$timeline[] = ['ts' => time() - 60, 'label' => 'Network surge detected', 'severity' => 'warning'];
		}

		foreach ($detected_alerts as $alert) {
			$timeline[] = [
				'ts' => (int) ($alert['ts'] ?? time()),
				'label' => (string) ($alert['title'] ?? 'Auto alert'),
				'severity' => (string) ($alert['severity'] ?? 'warning')
			];
		}

		usort($timeline, static fn(array $a, array $b): int => ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0));
		return array_slice($timeline, -30);
	}

	private static function countRecentEvents(array $events, int $seconds): int {
		$from = time() - $seconds;
		$count = 0;
		foreach ($events as $ev) {
			if ((int) ($ev['clock'] ?? 0) >= $from) {
				$count++;
			}
		}
		return $count;
	}

	private static function buildAlertOverlays(array $events, array $series, string $metric_filter, array $detected_alerts = []): array {
		$allowed = ['all', 'cpu', 'memory', 'network', 'disk'];
		if (!in_array($metric_filter, $allowed, true)) {
			$metric_filter = 'all';
		}

		$points = [];
		foreach ($events as $ev) {
			$points[] = [
				'ts' => (int) $ev['clock'] * 1000,
				'label' => (string) ($ev['name'] ?? 'Trigger fired'),
				'severity' => self::severityLabel((int) ($ev['severity'] ?? 0))
			];
		}

		foreach ($detected_alerts as $alert) {
			$points[] = [
				'ts' => (int) (($alert['ts'] ?? time()) * 1000),
				'label' => (string) ($alert['title'] ?? 'Auto alert'),
				'severity' => (string) ($alert['severity'] ?? 'warning')
			];
		}

		$filtered = [];
		foreach ($points as $pt) {
			$text = mb_strtolower((string) ($pt['label'] ?? ''));
			if ($metric_filter === 'cpu' && mb_strpos($text, 'cpu') === false) {
				continue;
			}
			if ($metric_filter === 'memory' && mb_strpos($text, 'mem') === false) {
				continue;
			}
			if ($metric_filter === 'network' && mb_strpos($text, 'net') === false && mb_strpos($text, 'icmp') === false) {
				continue;
			}
			if ($metric_filter === 'disk' && mb_strpos($text, 'disk') === false && mb_strpos($text, 'fs') === false) {
				continue;
			}
			$filtered[] = $pt;
		}

		if (empty($filtered)) {
			$filtered = array_slice($points, 0, 10);
		}

		return [
			'points' => $filtered,
			'zones' => [
				['name' => 'Normal', 'from' => 0, 'to' => 70, 'color' => 'rgba(40,167,69,0.12)'],
				['name' => 'Warning', 'from' => 70, 'to' => 85, 'color' => 'rgba(255,193,7,0.16)'],
				['name' => 'Critical', 'from' => 85, 'to' => 100, 'color' => 'rgba(220,53,69,0.16)']
			]
		];
	}

	private static function buildInsightBadges(array $spikes): array {
		$out = [];
		if (!empty($spikes['cpu_spike'])) {
			$out[] = ['icon' => '🔴', 'label' => 'CPU Spike'];
		}
		if (!empty($spikes['memory_spike'])) {
			$out[] = ['icon' => '⚠️', 'label' => 'Memory Pressure'];
		}
		if (!empty($spikes['network_spike'])) {
			$out[] = ['icon' => '🟠', 'label' => 'Traffic Surge'];
		}
		if (empty($out)) {
			$out[] = ['icon' => '🟢', 'label' => 'Stable'];
		}
		return $out;
	}

	private static function buildAlertSummary(int $event_count_5m, array $detected_alerts): string {
		$critical = 0;
		$warning = 0;
		foreach ($detected_alerts as $alert) {
			$sev = (string) ($alert['severity'] ?? 'warning');
			if ($sev === 'critical' || $sev === 'disaster') {
				$critical++;
			}
			else {
				$warning++;
			}
		}

		if ($critical === 0 && $warning === 0 && $event_count_5m === 0) {
			return 'No active alerts detected in selected window.';
		}

		return $event_count_5m.' event(s) in last 5 minutes; auto alerts: '.$critical.' critical, '.$warning.' warning.';
	}

	private static function buildSyntheticAlerts(array $series): array {
		$alerts = [];
		$alerts = array_merge($alerts, self::metricThresholdAlerts('cpu', 'CPU usage', $series['cpu'] ?? [], 70.0, 85.0, '%'));
		$alerts = array_merge($alerts, self::metricThresholdAlerts('memory', 'Memory usage', $series['memory'] ?? [], 70.0, 80.0, '%'));
		$alerts = array_merge($alerts, self::metricThresholdAlerts('disk', 'Disk usage', $series['disk'] ?? [], 80.0, 90.0, '%'));

		$net_in_last = self::lastValue($series['network_in'] ?? []);
		$net_out_last = self::lastValue($series['network_out'] ?? []);
		$net_total = (($net_in_last ?? 0.0) + ($net_out_last ?? 0.0));
		$net_series = self::mergeNetworkSeries($series['network_in'] ?? [], $series['network_out'] ?? []);
		$net_trend = self::isSurging($net_series, 1.8);

		if ($net_total >= 300000000 || ($net_total >= 100000000 && $net_trend)) {
			$severity = $net_total >= 300000000 ? 'critical' : 'warning';
			$alerts[] = [
				'id' => 'network-'.time(),
				'metric' => 'network',
				'severity' => $severity,
				'title' => 'Network traffic surge',
				'reason' => 'Inbound + outbound traffic crossed threshold'.($net_trend ? ' with rapid upward trend.' : '.'),
				'value' => round($net_total, 2),
				'unit' => 'bps',
				'threshold' => $severity === 'critical' ? 300000000 : 100000000,
				'ts' => self::lastTsSeconds($net_series),
				'suggestion' => 'Check top talkers, packet drops, and recent deploy/network changes.'
			];
		}

		usort($alerts, static function(array $a, array $b): int {
			$rank = ['critical' => 3, 'disaster' => 3, 'warning' => 2, 'normal' => 1];
			$ra = $rank[(string) ($a['severity'] ?? 'normal')] ?? 1;
			$rb = $rank[(string) ($b['severity'] ?? 'normal')] ?? 1;
			if ($ra !== $rb) {
				return $rb <=> $ra;
			}
			return ((int) ($b['ts'] ?? 0)) <=> ((int) ($a['ts'] ?? 0));
		});

		return array_slice($alerts, 0, 8);
	}

	private static function metricThresholdAlerts(string $metric_key, string $metric_label, array $series, float $warning, float $critical, string $unit): array {
		$last = self::lastValue($series);
		if ($last === null) {
			return [];
		}

		$surging = self::isSurging($series, 1.5);
		$severity = null;
		$threshold = null;
		if ($last >= $critical) {
			$severity = 'critical';
			$threshold = $critical;
		}
		elseif ($last >= $warning) {
			$severity = 'warning';
			$threshold = $warning;
		}

		if ($severity === null) {
			return [];
		}

		return [[
			'id' => $metric_key.'-'.time(),
			'metric' => $metric_key,
			'severity' => $severity,
			'title' => $metric_label.' threshold exceeded',
			'reason' => $metric_label.' is at '.round($last, 2).$unit.' which is above '.round($threshold, 2).$unit.($surging ? ' and rising quickly.' : '.'),
			'value' => round($last, 2),
			'unit' => $unit,
			'threshold' => $threshold,
			'ts' => self::lastTsSeconds($series),
			'suggestion' => 'Inspect recent process, workload, and config changes for '.$metric_label.'.'
		]];
	}

	private static function isSurging(array $series, float $ratio): bool {
		$count = count($series);
		if ($count < 8) {
			return false;
		}

		$tail = array_slice($series, -4);
		$prev = array_slice($series, -8, 4);
		$tail_avg = self::avgSeriesValue($tail);
		$prev_avg = self::avgSeriesValue($prev);
		if ($prev_avg <= 0.0) {
			return false;
		}

		return ($tail_avg / $prev_avg) >= $ratio;
	}

	private static function avgSeriesValue(array $series): float {
		if (empty($series)) {
			return 0.0;
		}
		$sum = 0.0;
		foreach ($series as $point) {
			$sum += (float) ($point['value'] ?? 0.0);
		}
		return $sum / count($series);
	}

	private static function lastTsSeconds(array $series): int {
		if (empty($series)) {
			return time();
		}
		$last = $series[count($series) - 1];
		$ts_ms = (int) ($last['ts'] ?? (time() * 1000));
		return (int) floor($ts_ms / 1000);
	}

	private static function mergeNetworkSeries(array $net_in, array $net_out): array {
		$by_ts = [];
		foreach ($net_in as $point) {
			$ts = (int) ($point['ts'] ?? 0);
			$by_ts[$ts] = ($by_ts[$ts] ?? 0.0) + (float) ($point['value'] ?? 0.0);
		}
		foreach ($net_out as $point) {
			$ts = (int) ($point['ts'] ?? 0);
			$by_ts[$ts] = ($by_ts[$ts] ?? 0.0) + (float) ($point['value'] ?? 0.0);
		}
		ksort($by_ts);
		$out = [];
		foreach ($by_ts as $ts => $value) {
			$out[] = ['ts' => (int) $ts, 'value' => (float) $value];
		}
		return $out;
	}

	private static function severityLabel(int $severity): string {
		if ($severity >= 5) return 'disaster';
		if ($severity >= 4) return 'critical';
		if ($severity >= 2) return 'warning';
		return 'normal';
	}
}
