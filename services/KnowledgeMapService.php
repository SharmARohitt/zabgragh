<?php declare(strict_types = 0);

namespace Modules\ZabGraph\Services;

use API;

class KnowledgeMapService {

	public static function buildHostMap(int $hostid): array {
		$hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name', 'status'],
			'selectTags' => ['tag', 'value'],
			'hostids' => [$hostid]
		]);

		if (empty($hosts)) {
			return [
				'success' => false,
				'error' => 'Host not found'
			];
		}

		$host = $hosts[0];
		$host_name = (string) ($host['name'] ?: $host['host']);

		$items = API::Item()->get([
			'output' => ['itemid', 'name', 'key_', 'value_type', 'units', 'lastvalue'],
			'hostids' => [$hostid],
			'monitored' => true,
			'sortfield' => 'name',
			'limit' => 40
		]);

		$triggers = API::Trigger()->get([
			'output' => ['triggerid', 'description', 'priority', 'value'],
			'hostids' => [$hostid],
			'monitored' => true,
			'limit' => 25
		]);

		$events = API::Event()->get([
			'output' => ['eventid', 'clock', 'name', 'severity'],
			'hostids' => [$hostid],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'time_from' => time() - 86400,
			'sortfield' => 'clock',
			'sortorder' => ZBX_SORT_DOWN,
			'limit' => 20
		]);

		$nodes = [];
		$edges = [];

		$nodes[] = [
			'id' => 'host_'.$hostid,
			'label' => $host_name,
			'type' => 'host',
			'status' => ((int) ($host['status'] ?? 0) === HOST_STATUS_MONITORED) ? 'healthy' : 'warning'
		];

		foreach (($host['tags'] ?? []) as $idx => $tag) {
			$tag_id = 'tag_'.$hostid.'_'.$idx;
			$nodes[] = [
				'id' => $tag_id,
				'label' => (string) ($tag['tag'] ?? '').'='.(string) ($tag['value'] ?? ''),
				'type' => 'tag',
				'status' => 'normal'
			];
			$edges[] = ['source' => 'host_'.$hostid, 'target' => $tag_id, 'label' => 'tagged'];
		}

		foreach ($items as $idx => $item) {
			if ($idx >= 15) {
				break;
			}
			$item_id = 'item_'.(int) $item['itemid'];
			$nodes[] = [
				'id' => $item_id,
				'label' => (string) ($item['name'] ?: $item['key_']),
				'type' => 'metric',
				'status' => 'normal'
			];
			$edges[] = ['source' => 'host_'.$hostid, 'target' => $item_id, 'label' => 'collects'];
		}

		foreach ($triggers as $idx => $trigger) {
			if ($idx >= 10) {
				break;
			}
			$trigger_id = 'trigger_'.(int) $trigger['triggerid'];
			$priority = (int) ($trigger['priority'] ?? 0);
			$nodes[] = [
				'id' => $trigger_id,
				'label' => (string) ($trigger['description'] ?? 'Trigger'),
				'type' => 'trigger',
				'status' => $priority >= 4 ? 'critical' : ($priority >= 2 ? 'warning' : 'normal')
			];
			$edges[] = ['source' => 'host_'.$hostid, 'target' => $trigger_id, 'label' => 'guards'];
		}

		foreach ($events as $idx => $event) {
			if ($idx >= 8) {
				break;
			}
			$event_id = 'event_'.(string) $event['eventid'];
			$severity = (int) ($event['severity'] ?? 0);
			$nodes[] = [
				'id' => $event_id,
				'label' => date('H:i', (int) ($event['clock'] ?? time())).' '.(string) ($event['name'] ?? 'Event'),
				'type' => 'event',
				'status' => $severity >= 4 ? 'critical' : ($severity >= 2 ? 'warning' : 'normal')
			];
			$edges[] = ['source' => 'host_'.$hostid, 'target' => $event_id, 'label' => 'timeline'];
		}

		return [
			'success' => true,
			'graph' => [
				'nodes' => self::dedupeNodes($nodes),
				'edges' => self::dedupeEdges($edges)
			],
			'suggestions' => [
				'Ask for root cause with dependency map',
				'Correlate alerts with CPU, memory, and network changes',
				'Attach JSON and merge it into the host topology graph'
			],
			'summary' => 'Knowledge map generated from live host telemetry and incidents.'
		];
	}

	public static function answerQuestion(int $hostid, string $question, array $context_graph = []): array {
		$question = trim($question);
		if ($question === '') {
			return [
				'success' => false,
				'error' => 'Question is required'
			];
		}

		$base = self::buildHostMap($hostid);
		if (empty($base['success'])) {
			return $base;
		}

		$signals = self::extractSignalsFromQuestion($question);
		$graph = self::mergeGraphs($base['graph'], $context_graph);
		$graph = self::mergeGraphs($graph, self::questionSignalsToGraph($signals));

		$ai = GeminiInsightService::generateKnowledgeAnswer([
			'question' => $question,
			'signals' => $signals,
			'graph_stats' => [
				'nodes' => count($graph['nodes'] ?? []),
				'edges' => count($graph['edges'] ?? [])
			]
		]);

		return [
			'success' => true,
			'message' => $ai['answer'] ?? 'Map updated with interpreted relationships from your question.',
			'insight' => $ai['insight'] ?? '',
			'graph' => $graph
		];
	}

	public static function parseJsonUpload(string $json_raw): array {
		$json_raw = trim($json_raw);
		if ($json_raw === '') {
			return [
				'success' => false,
				'error' => 'JSON content is empty'
			];
		}

		$data = json_decode($json_raw, true);
		if (!is_array($data)) {
			return [
				'success' => false,
				'error' => 'Invalid JSON payload'
			];
		}

		$graph = self::arrayToGraph($data, 'uploaded');
		return [
			'success' => true,
			'graph' => $graph,
			'message' => 'JSON parsed and converted into map nodes.'
		];
	}

	public static function mergeGraphs(array $a, array $b): array {
		$nodes = array_merge($a['nodes'] ?? [], $b['nodes'] ?? []);
		$edges = array_merge($a['edges'] ?? [], $b['edges'] ?? []);
		return [
			'nodes' => self::dedupeNodes($nodes),
			'edges' => self::dedupeEdges($edges)
		];
	}

	private static function extractSignalsFromQuestion(string $question): array {
		$q = mb_strtolower($question);
		$signals = [];
		if (mb_strpos($q, 'cpu') !== false) {
			$signals[] = ['key' => 'cpu', 'label' => 'CPU Pressure'];
		}
		if (mb_strpos($q, 'memory') !== false || mb_strpos($q, 'ram') !== false) {
			$signals[] = ['key' => 'memory', 'label' => 'Memory Pressure'];
		}
		if (mb_strpos($q, 'network') !== false || mb_strpos($q, 'traffic') !== false) {
			$signals[] = ['key' => 'network', 'label' => 'Network Surge'];
		}
		if (mb_strpos($q, 'disk') !== false || mb_strpos($q, 'iops') !== false) {
			$signals[] = ['key' => 'disk', 'label' => 'Disk Saturation'];
		}
		if (empty($signals)) {
			$signals[] = ['key' => 'inference', 'label' => 'General Correlation'];
		}
		return $signals;
	}

	private static function questionSignalsToGraph(array $signals): array {
		$nodes = [];
		$edges = [];
		$root_id = 'q_root_'.time();
		$nodes[] = [
			'id' => $root_id,
			'label' => 'User Intent',
			'type' => 'query',
			'status' => 'warning'
		];

		foreach ($signals as $idx => $signal) {
			$node_id = 'q_signal_'.$idx.'_'.md5((string) $signal['key']);
			$nodes[] = [
				'id' => $node_id,
				'label' => (string) ($signal['label'] ?? 'Signal'),
				'type' => 'signal',
				'status' => 'warning'
			];
			$edges[] = [
				'source' => $root_id,
				'target' => $node_id,
				'label' => 'asks'
			];
		}

		return ['nodes' => $nodes, 'edges' => $edges];
	}

	private static function arrayToGraph(array $data, string $root_label): array {
		$nodes = [];
		$edges = [];
		$root = 'json_root_'.md5($root_label.(string) microtime(true));
		$nodes[] = [
			'id' => $root,
			'label' => $root_label,
			'type' => 'json-root',
			'status' => 'healthy'
		];
		self::walkArray($data, $root, '$', $nodes, $edges, 0);
		return [
			'nodes' => self::dedupeNodes($nodes),
			'edges' => self::dedupeEdges($edges)
		];
	}

	private static function walkArray(array $data, string $parent_id, string $path, array &$nodes, array &$edges, int $depth): void {
		if ($depth > 5) {
			return;
		}

		$count = 0;
		foreach ($data as $key => $value) {
			if ($count >= 120) {
				break;
			}
			$key_str = is_string($key) ? $key : ('['.$key.']');
			$node_id = 'json_'.md5($path.'.'.$key_str);
			$label = (string) $key_str;
			$type = is_array($value) ? 'json-object' : 'json-value';
			if (!is_array($value)) {
				$label .= ': '.self::scalarLabel($value);
			}

			$nodes[] = [
				'id' => $node_id,
				'label' => mb_substr($label, 0, 120),
				'type' => $type,
				'status' => is_array($value) ? 'normal' : 'healthy'
			];
			$edges[] = [
				'source' => $parent_id,
				'target' => $node_id,
				'label' => 'contains'
			];

			if (is_array($value)) {
				self::walkArray($value, $node_id, $path.'.'.$key_str, $nodes, $edges, $depth + 1);
			}
			$count++;
		}
	}

	private static function scalarLabel($value): string {
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if ($value === null) {
			return 'null';
		}
		if (is_scalar($value)) {
			return (string) $value;
		}
		return 'value';
	}

	private static function dedupeNodes(array $nodes): array {
		$seen = [];
		$out = [];
		foreach ($nodes as $node) {
			$id = (string) ($node['id'] ?? '');
			if ($id === '' || isset($seen[$id])) {
				continue;
			}
			$seen[$id] = true;
			$out[] = $node;
		}
		return $out;
	}

	private static function dedupeEdges(array $edges): array {
		$seen = [];
		$out = [];
		foreach ($edges as $edge) {
			$source = (string) ($edge['source'] ?? '');
			$target = (string) ($edge['target'] ?? '');
			$label = (string) ($edge['label'] ?? '');
			if ($source === '' || $target === '') {
				continue;
			}
			$key = $source.'>'.$target.'>'.$label;
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$out[] = [
				'source' => $source,
				'target' => $target,
				'label' => $label
			];
		}
		return $out;
	}
}
