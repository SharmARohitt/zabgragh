<?php declare(strict_types = 0);

namespace Modules\ZabGraph\Actions;

use API;
use CController;
use CRoleHelper;
use Modules\ZabGraph\Services\AIAnalyzer;
use Modules\ZabGraph\Services\IncidentGraphService;

class CControllerZabGraphGraphData extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'eventid' => 'required|id',
			'layer' => 'in causal,infrastructure,timeline,merged'
		];
		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS);
	}

	protected function doAction(): void {
		header('Content-Type: application/json; charset=UTF-8');

		$eventid = (string) $this->getInput('eventid');
		$layer = (string) $this->getInput('layer', 'merged');

		$events = API::Event()->get([
			'output' => ['eventid', 'objectid', 'clock', 'name', 'severity', 'r_eventid'],
			'eventids' => [$eventid],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER
		]);

		if (empty($events)) {
			echo json_encode(['success' => false, 'error' => _('Event not found')]);
			exit;
		}

		$problem = $events[0];
		$triggerid = (int) ($problem['objectid'] ?? 0);
		if ($triggerid <= 0) {
			echo json_encode(['success' => false, 'error' => _('Trigger not found for event')]);
			exit;
		}

		$triggers = API::Trigger()->get([
			'output' => ['triggerid', 'description', 'expression', 'priority'],
			'triggerids' => [$triggerid],
			'selectHosts' => ['hostid', 'host', 'name'],
			'selectItems' => ['itemid', 'name', 'key_', 'value_type', 'units']
		]);

		if (empty($triggers)) {
			echo json_encode(['success' => false, 'error' => _('Unable to load trigger details')]);
			exit;
		}

		$trigger = $triggers[0];
		$host = $trigger['hosts'][0] ?? [];
		$items = $trigger['items'] ?? [];

		$ai = AIAnalyzer::analyze([
			'problem' => $problem,
			'trigger' => $trigger,
			'host' => $host,
			'items' => $items
		]);

		$payload = IncidentGraphService::buildGraphPayload([
			'problem' => $problem,
			'trigger' => $trigger,
			'host' => $host,
			'items' => $items,
			'ai' => $ai
		], $layer);

		echo json_encode($payload);
		exit;
	}
}
