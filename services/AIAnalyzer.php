<?php declare(strict_types = 0);

namespace Modules\ZabGraph\Services;

class AIAnalyzer {

	/**
	 * Rule-based analyzer with a stable response contract for future LLM plug-in.
	 */
	public static function analyze(array $context): array {
		$problem = $context['problem'] ?? [];
		$trigger = $context['trigger'] ?? [];
		$items = $context['items'] ?? [];
		$host = $context['host'] ?? [];

		$problem_name = mb_strtolower((string) ($problem['name'] ?? 'incident'));
		$trigger_desc = mb_strtolower((string) ($trigger['description'] ?? ''));
		$severity = (int) ($problem['severity'] ?? 0);
		$host_name = (string) ($host['name'] ?? $host['host'] ?? 'Unknown host');

		$signals = self::extractSignals($problem_name, $trigger_desc, $items);
		$root = self::detectRootCause($signals);
		$confidence = self::estimateConfidence($signals, $severity);

		$causal_chain = self::buildCausalChain($signals, $root);
		$impact = self::buildImpactSummary($host_name, $severity, $signals);
		$timeline = self::buildTimeline($problem, $signals);
		$suggested_actions = self::buildSuggestedActions($root, $severity, $signals);

		$explanation = sprintf(
			'Incident on %s appears to be driven by %s. The detected pattern indicates downstream effects across service latency and availability.',
			$host_name,
			$root
		);

		return [
			'root_cause' => $root,
			'confidence' => $confidence,
			'causal_chain' => $causal_chain,
			'impact' => $impact,
			'timeline' => $timeline,
			'suggested_actions' => $suggested_actions,
			'explanation' => $explanation,
			'engine' => [
				'type' => 'rule-based',
				'gpt_ready' => true,
				'version' => '1.0'
			]
		];
	}

	private static function extractSignals(string $problem_name, string $trigger_desc, array $items): array {
		$keys = [];
		foreach ($items as $item) {
			$keys[] = mb_strtolower((string) ($item['key_'] ?? ''));
		}

		$haystack = $problem_name.' '.$trigger_desc.' '.implode(' ', $keys);

		return [
			'cpu' => self::containsAny($haystack, ['cpu', 'system.cpu', 'load']),
			'memory' => self::containsAny($haystack, ['memory', 'mem', 'swap']),
			'disk' => self::containsAny($haystack, ['disk', 'io', 'iops', 'filesystem']),
			'db' => self::containsAny($haystack, ['db', 'database', 'mysql', 'postgres', 'sql']),
			'network' => self::containsAny($haystack, ['icmp', 'latency', 'packet', 'network', 'interface']),
			'api' => self::containsAny($haystack, ['api', 'http', '5xx', 'availability', 'timeout'])
		];
	}

	private static function detectRootCause(array $signals): string {
		if ($signals['disk']) {
			return 'high disk I/O saturation';
		}
		if ($signals['cpu']) {
			return 'sustained CPU pressure';
		}
		if ($signals['memory']) {
			return 'memory exhaustion risk';
		}
		if ($signals['network']) {
			return 'network path degradation';
		}
		if ($signals['db']) {
			return 'database contention';
		}
		if ($signals['api']) {
			return 'application-layer failure';
		}
		return 'multi-factor infrastructure anomaly';
	}

	private static function estimateConfidence(array $signals, int $severity): float {
		$signal_count = 0;
		foreach ($signals as $value) {
			if ($value) {
				$signal_count++;
			}
		}
		$base = 0.55 + min(0.25, $signal_count * 0.05);
		$severity_bonus = min(0.15, max(0, $severity) * 0.02);
		return round(min(0.97, $base + $severity_bonus), 2);
	}

	private static function buildCausalChain(array $signals, string $root): array {
		$chain = [
			['from' => $root, 'to' => 'service latency increase', 'relation' => 'causes']
		];

		if ($signals['db']) {
			$chain[] = ['from' => 'service latency increase', 'to' => 'database response degradation', 'relation' => 'amplifies'];
		}
		if ($signals['api']) {
			$chain[] = ['from' => 'database response degradation', 'to' => 'API failure symptoms', 'relation' => 'propagates'];
		}
		$chain[] = ['from' => 'API failure symptoms', 'to' => 'trigger fired and alert generated', 'relation' => 'results_in'];

		return $chain;
	}

	private static function buildImpactSummary(string $host_name, int $severity, array $signals): array {
		$severity_label = self::severityLabel($severity);
		$services = [];
		if ($signals['api']) {
			$services[] = 'public API';
		}
		if ($signals['db']) {
			$services[] = 'database cluster';
		}
		if (empty($services)) {
			$services[] = 'core infrastructure service';
		}

		return [
			['type' => 'host', 'name' => $host_name, 'severity' => $severity_label],
			['type' => 'services', 'name' => implode(', ', $services), 'severity' => $severity_label],
			['type' => 'users', 'name' => $severity >= 4 ? 'high user-facing impact' : 'moderate operational impact', 'severity' => $severity_label]
		];
	}

	private static function buildTimeline(array $problem, array $signals): array {
		$clock = (int) ($problem['clock'] ?? time());
		$entries = [
			['timestamp' => $clock - 180, 'label' => 'anomaly indicators detected', 'phase' => 'before'],
			['timestamp' => $clock - 120, 'label' => 'resource pressure increased', 'phase' => 'before'],
			['timestamp' => $clock - 60, 'label' => 'service latency breached threshold', 'phase' => 'during'],
			['timestamp' => $clock, 'label' => 'trigger fired and incident opened', 'phase' => 'during']
		];

		if ($signals['api']) {
			$entries[] = ['timestamp' => $clock + 60, 'label' => 'API errors observed downstream', 'phase' => 'after'];
		}
		$entries[] = ['timestamp' => $clock + 120, 'label' => 'stabilization actions recommended', 'phase' => 'after'];

		return $entries;
	}

	private static function buildSuggestedActions(string $root, int $severity, array $signals): array {
		$actions = [
			['title' => 'Validate trigger context', 'automation_ready' => false, 'priority' => 'high'],
			['title' => 'Collect latest host diagnostics', 'automation_ready' => true, 'priority' => 'high']
		];

		if ($signals['disk']) {
			$actions[] = ['title' => 'Reduce disk-heavy workload or rotate logs', 'automation_ready' => true, 'priority' => 'high'];
		}
		if ($signals['cpu']) {
			$actions[] = ['title' => 'Scale CPU resources or rebalance workload', 'automation_ready' => true, 'priority' => 'high'];
		}
		if ($signals['db']) {
			$actions[] = ['title' => 'Restart affected DB service during maintenance window', 'automation_ready' => false, 'priority' => 'medium'];
		}
		if ($signals['api']) {
			$actions[] = ['title' => 'Restart API workers and clear stale queues', 'automation_ready' => true, 'priority' => 'medium'];
		}
		if ($severity >= 4) {
			$actions[] = ['title' => 'Trigger incident broadcast to on-call recipients', 'automation_ready' => true, 'priority' => 'critical'];
		}

		$actions[] = ['title' => 'Run post-incident replay to confirm stabilization', 'automation_ready' => false, 'priority' => 'low'];

		return $actions;
	}

	private static function containsAny(string $haystack, array $needles): bool {
		foreach ($needles as $needle) {
			if ($needle !== '' && mb_strpos($haystack, $needle) !== false) {
				return true;
			}
		}
		return false;
	}

	private static function severityLabel(int $severity): string {
		$labels = [
			0 => 'not_classified',
			1 => 'information',
			2 => 'warning',
			3 => 'average',
			4 => 'high',
			5 => 'disaster'
		];
		return $labels[$severity] ?? 'unknown';
	}
}
