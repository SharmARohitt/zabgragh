<?php declare(strict_types = 0);

namespace Modules\ZabGraph\Services;

class GeminiInsightService {

	public static function generate(array $input): array {
		$fallback = self::fallbackInsights($input);
		$api_key = getenv('GEMINI_API_KEY') ?: '';
		if ($api_key === '') {
			return $fallback + ['engine' => 'fallback'];
		}

		$prompt = self::buildPrompt($input);
		$payload = [
			'contents' => [
				[
					'parts' => [
						['text' => $prompt]
					]
				]
			]
		];

		$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key='.
			rawurlencode($api_key);

		$context = stream_context_create([
			'http' => [
				'method' => 'POST',
				'header' => "Content-Type: application/json\r\n",
				'content' => json_encode($payload),
				'timeout' => 6,
				'ignore_errors' => true
			]
		]);

		$result = @file_get_contents($url, false, $context);
		if ($result === false) {
			return $fallback + ['engine' => 'fallback'];
		}

		$data = json_decode($result, true);
		$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
		if (!is_string($text) || $text === '') {
			return $fallback + ['engine' => 'fallback'];
		}

		$parsed = self::parseResponse($text);
		return [
			'summary' => $parsed['summary'] !== '' ? $parsed['summary'] : $fallback['summary'],
			'root_cause' => $parsed['root_cause'] !== '' ? $parsed['root_cause'] : $fallback['root_cause'],
			'impact' => $parsed['impact'] !== '' ? $parsed['impact'] : $fallback['impact'],
			'suggested_fix' => $parsed['suggested_fix'] !== '' ? $parsed['suggested_fix'] : $fallback['suggested_fix'],
			'engine' => 'gemini'
		];
	}

	private static function buildPrompt(array $input): string {
		$cpu = $input['cpu'] ?? [];
		$memory = $input['memory'] ?? [];
		$events = $input['events'] ?? [];

		return "You are a senior SRE.\n\n"
			."Analyze this host data:\n"
			."- CPU usage: ".json_encode($cpu)."\n"
			."- Memory usage: ".json_encode($memory)."\n"
			."- Events: ".json_encode($events)."\n\n"
			."Return exactly these lines:\n"
			."Summary: ...\n"
			."Root Cause: ...\n"
			."Impact: ...\n"
			."Suggested Fix: ...\n"
			."Be concise and actionable.";
	}

	private static function parseResponse(string $text): array {
		$lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
		$out = [
			'summary' => '',
			'root_cause' => '',
			'impact' => '',
			'suggested_fix' => ''
		];

		foreach ($lines as $line) {
			$line = trim($line);
			if (stripos($line, 'Summary:') === 0) {
				$out['summary'] = trim(substr($line, 8));
			}
			elseif (stripos($line, 'Root Cause:') === 0) {
				$out['root_cause'] = trim(substr($line, 11));
			}
			elseif (stripos($line, 'Impact:') === 0) {
				$out['impact'] = trim(substr($line, 7));
			}
			elseif (stripos($line, 'Suggested Fix:') === 0) {
				$out['suggested_fix'] = trim(substr($line, 14));
			}
		}

		return $out;
	}

	private static function fallbackInsights(array $input): array {
		$cpu_last = (float) ($input['cpu']['current'] ?? 0);
		$mem_last = (float) ($input['memory']['current'] ?? 0);
		$event_count = (int) ($input['events_last_5m'] ?? 0);
		$network_spike = !empty($input['network_spike']);

		$root = 'High CPU likely caused by background process or traffic spike';
		if ($cpu_last > 85 && $mem_last > 80) {
			$root = 'Combined CPU and memory spike indicates possible memory leak or runaway worker';
		}
		elseif ($network_spike) {
			$root = 'Network surge likely caused by traffic burst or upstream retry storm';
		}

		$impact = $event_count > 0
			? 'May affect API response time and increase error rates under load'
			: 'Potential early degradation risk if current trend continues';

		$fix = 'Check running processes using top or htop, verify recent deploys, and inspect traffic patterns';

		return [
			'summary' => $event_count.' issue(s) detected recently with active resource pressure',
			'root_cause' => $root,
			'impact' => $impact,
			'suggested_fix' => $fix
		];
	}

	public static function generateKnowledgeAnswer(array $input): array {
		$question = (string) ($input['question'] ?? '');
		$signals = $input['signals'] ?? [];
		$stats = $input['graph_stats'] ?? [];

		$fallback = [
			'answer' => 'Mapped your request into a structured graph. Focus first on high-severity nodes, then follow upstream dependencies and event order.',
			'insight' => 'Signals: '.implode(', ', array_map(static fn(array $s): string => (string) ($s['label'] ?? ''), $signals))
		];

		$api_key = getenv('GEMINI_API_KEY') ?: '';
		if ($api_key === '') {
			return $fallback + ['engine' => 'fallback'];
		}

		$prompt = "You are an expert Zabbix observability copilot.\n"
			."Question: ".json_encode($question)."\n"
			."Signals: ".json_encode($signals)."\n"
			."Graph stats: ".json_encode($stats)."\n\n"
			."Return exactly:\n"
			."Answer: ...\n"
			."Insight: ...\n"
			."Be concise and operationally actionable.";

		$payload = [
			'contents' => [[
				'parts' => [['text' => $prompt]]
			]]
		];

		$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key='.
			rawurlencode($api_key);

		$context = stream_context_create([
			'http' => [
				'method' => 'POST',
				'header' => "Content-Type: application/json\r\n",
				'content' => json_encode($payload),
				'timeout' => 6,
				'ignore_errors' => true
			]
		]);

		$result = @file_get_contents($url, false, $context);
		if ($result === false) {
			return $fallback + ['engine' => 'fallback'];
		}

		$data = json_decode($result, true);
		$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
		if (!is_string($text) || $text === '') {
			return $fallback + ['engine' => 'fallback'];
		}

		$answer = '';
		$insight = '';
		$lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
		foreach ($lines as $line) {
			$line = trim($line);
			if (stripos($line, 'Answer:') === 0) {
				$answer = trim(substr($line, 7));
			}
			elseif (stripos($line, 'Insight:') === 0) {
				$insight = trim(substr($line, 8));
			}
		}

		return [
			'answer' => $answer !== '' ? $answer : $fallback['answer'],
			'insight' => $insight !== '' ? $insight : $fallback['insight'],
			'engine' => 'gemini'
		];
	}
}
