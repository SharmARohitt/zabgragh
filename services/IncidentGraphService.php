<?php declare(strict_types = 0);

namespace Modules\ZabGraph\Services;

class IncidentGraphService {

	public static function buildGraphPayload(array $context, string $active_layer = 'merged'): array {
		$problem = $context['problem'] ?? [];
		$trigger = $context['trigger'] ?? [];
		$host = $context['host'] ?? [];
		$items = $context['items'] ?? [];
		$ai = $context['ai'] ?? [];

		$host_name = (string) ($host['name'] ?? $host['host'] ?? 'Unknown host');
		$trigger_name = (string) ($trigger['description'] ?? ($problem['name'] ?? 'Trigger'));
		$eventid = (string) ($problem['eventid'] ?? '0');
		$severity = (int) ($problem['severity'] ?? 0);
		$status = ((int) ($problem['r_eventid'] ?? 0) > 0) ? 'resolved' : 'active';

		$layers = [
			'causal' => self::buildCausalLayer($eventid, $host_name, $trigger_name, $ai),
			'infrastructure' => self::buildInfrastructureLayer($eventid, $host_name, $trigger_name, $items, $severity, $status),
			'timeline' => self::buildTimelineLayer($eventid, $ai)
		];

		$merged = self::mergeLayers($layers);
		$replay = self::buildReplayFrames($ai['timeline'] ?? []);

		return [
			'success' => true,
			'active_layer' => $active_layer,
			'layers' => $layers,
			'merged' => $merged,
			'impact_analysis' => [
				'severity' => $severity,
				'status' => $status,
				'impact_nodes' => self::extractImpactNodes($merged['nodes']),
				'downstream_edges' => $merged['edges']
			],
			'suggested_actions' => $ai['suggested_actions'] ?? [],
			'right_panel' => [
				'root_cause' => $ai['root_cause'] ?? 'unknown',
				'confidence' => $ai['confidence'] ?? 0,
				'explanation' => $ai['explanation'] ?? '',
				'fixes' => $ai['suggested_actions'] ?? []
			],
			'incident_replay' => $replay,
			'similar_incidents' => []
		];
	}

	public static function detectSimilarIncidents(array $target, array $history): array {
		$target_problem = mb_strtolower((string) ($target['name'] ?? ''));
		$target_trigger = (int) ($target['objectid'] ?? 0);

		$similar = [];
		foreach ($history as $row) {
			$score = 0.0;
			$name = mb_strtolower((string) ($row['name'] ?? ''));
			if ($target_problem !== '' && $name !== '') {
				$score += (similar_text($target_problem, $name) / 100.0) * 0.7;
			}
			if ($target_trigger > 0 && (int) ($row['objectid'] ?? 0) === $target_trigger) {
				$score += 0.25;
			}
			if ((int) ($row['severity'] ?? -1) === (int) ($target['severity'] ?? -2)) {
				$score += 0.05;
			}

			if ($score >= 0.45) {
				$similar[] = [
					'eventid' => (string) ($row['eventid'] ?? '0'),
					'name' => (string) ($row['name'] ?? ''),
					'severity' => (int) ($row['severity'] ?? 0),
					'match_score' => round(min(1.0, $score), 2)
				];
			}
		}

		usort($similar, static function(array $a, array $b): int {
			return $b['match_score'] <=> $a['match_score'];
		});

		return array_slice($similar, 0, 10);
	}

	private static function buildCausalLayer(string $eventid, string $host_name, string $trigger_name, array $ai): array {
		$root = (string) ($ai['root_cause'] ?? 'infrastructure anomaly');
		$nodes = [
			['id' => 'c_root', 'label' => $root, 'type' => 'cause', 'status' => 'critical'],
			['id' => 'c_latency', 'label' => 'service latency increase', 'type' => 'effect', 'status' => 'warning'],
			['id' => 'c_trigger', 'label' => $trigger_name, 'type' => 'trigger', 'status' => 'critical'],
			['id' => 'c_problem', 'label' => 'event #'.$eventid, 'type' => 'problem', 'status' => 'critical'],
			['id' => 'c_host', 'label' => $host_name, 'type' => 'host', 'status' => 'warning']
		];

		$edges = [
			['source' => 'c_root', 'target' => 'c_latency', 'label' => 'causes'],
			['source' => 'c_latency', 'target' => 'c_trigger', 'label' => 'breaches'],
			['source' => 'c_trigger', 'target' => 'c_problem', 'label' => 'creates'],
			['source' => 'c_problem', 'target' => 'c_host', 'label' => 'impacts']
		];

		return ['nodes' => $nodes, 'edges' => $edges, 'title' => 'Causal Graph'];
	}

	private static function buildInfrastructureLayer(string $eventid, string $host_name, string $trigger_name, array $items, int $severity, string $status): array {
		$sev_status = $severity >= 4 ? 'critical' : ($severity >= 2 ? 'warning' : 'healthy');
		$nodes = [
			['id' => 'i_host', 'label' => $host_name, 'type' => 'host', 'status' => $sev_status],
			['id' => 'i_service', 'label' => 'Monitored Service', 'type' => 'service', 'status' => $sev_status],
			['id' => 'i_app', 'label' => 'Application', 'type' => 'application', 'status' => $sev_status],
			['id' => 'i_problem', 'label' => 'event #'.$eventid.' ('.$status.')', 'type' => 'problem', 'status' => $sev_status]
		];

		$edges = [
			['source' => 'i_host', 'target' => 'i_service', 'label' => 'hosts'],
			['source' => 'i_service', 'target' => 'i_app', 'label' => 'runs'],
			['source' => 'i_app', 'target' => 'i_problem', 'label' => 'affected_by']
		];

		$item_idx = 0;
		foreach ($items as $item) {
			if ($item_idx >= 8) {
				break;
			}
			$item_id = 'i_item_'.$item_idx;
			$nodes[] = [
				'id' => $item_id,
				'label' => (string) ($item['name'] ?? $item['key_'] ?? ('item-'.$item_idx)),
				'type' => 'item',
				'status' => $sev_status
			];
			$edges[] = ['source' => 'i_host', 'target' => $item_id, 'label' => 'collects'];
			$edges[] = ['source' => $item_id, 'target' => 'i_problem', 'label' => 'signals'];
			$item_idx++;
		}

		$nodes[] = ['id' => 'i_trigger', 'label' => $trigger_name, 'type' => 'trigger', 'status' => $sev_status];
		$edges[] = ['source' => 'i_trigger', 'target' => 'i_problem', 'label' => 'fires'];

		return ['nodes' => $nodes, 'edges' => $edges, 'title' => 'Infrastructure Graph'];
	}

	private static function buildTimelineLayer(string $eventid, array $ai): array {
		$timeline = $ai['timeline'] ?? [];
		if (empty($timeline)) {
			$timeline = [
				['timestamp' => time() - 60, 'label' => 'incident precursor', 'phase' => 'before'],
				['timestamp' => time(), 'label' => 'incident detected', 'phase' => 'during'],
				['timestamp' => time() + 60, 'label' => 'recovery started', 'phase' => 'after']
			];
		}

		$nodes = [];
		$edges = [];
		$prev = null;
		foreach ($timeline as $idx => $point) {
			$id = 't_'.$idx;
			$nodes[] = [
				'id' => $id,
				'label' => date('H:i:s', (int) ($point['timestamp'] ?? time())).' '.$point['label'],
				'type' => 'timeline',
				'phase' => (string) ($point['phase'] ?? 'during'),
				'status' => ($point['phase'] ?? 'during') === 'after' ? 'healthy' : 'warning'
			];
			if ($prev !== null) {
				$edges[] = ['source' => $prev, 'target' => $id, 'label' => 'next'];
			}
			$prev = $id;
		}

		$nodes[] = ['id' => 't_problem', 'label' => 'event #'.$eventid, 'type' => 'problem', 'status' => 'critical'];
		if (!empty($nodes)) {
			$edges[] = ['source' => 't_'.max(0, count($timeline) - 1), 'target' => 't_problem', 'label' => 'result'];
		}

		return ['nodes' => $nodes, 'edges' => $edges, 'title' => 'Timeline Graph'];
	}

	private static function mergeLayers(array $layers): array {
		$nodes = [];
		$edges = [];
		$seen_nodes = [];
		$seen_edges = [];

		foreach ($layers as $layer_name => $layer) {
			foreach ($layer['nodes'] as $node) {
				$k = $node['id'];
				if (!isset($seen_nodes[$k])) {
					$seen_nodes[$k] = true;
					$node['layer'] = $layer_name;
					$nodes[] = $node;
				}
			}
			foreach ($layer['edges'] as $edge) {
				$k = $edge['source'].'>'.$edge['target'];
				if (!isset($seen_edges[$k])) {
					$seen_edges[$k] = true;
					$edge['layer'] = $layer_name;
					$edges[] = $edge;
				}
			}
		}

		return ['nodes' => $nodes, 'edges' => $edges, 'title' => 'Merged Incident Graph'];
	}

	private static function buildReplayFrames(array $timeline): array {
		$frames = [];
		$step = 0;
		foreach ($timeline as $point) {
			$frames[] = [
				'step' => $step,
				'timestamp' => (int) ($point['timestamp'] ?? time()),
				'label' => (string) ($point['label'] ?? 'timeline step'),
				'phase' => (string) ($point['phase'] ?? 'during')
			];
			$step++;
		}

		return [
			'enabled' => true,
			'frame_count' => count($frames),
			'frames' => $frames
		];
	}

	private static function extractImpactNodes(array $nodes): array {
		$impact = [];
		foreach ($nodes as $node) {
			if (($node['status'] ?? '') === 'critical' || ($node['status'] ?? '') === 'warning') {
				$impact[] = $node;
			}
		}
		return $impact;
	}
}
