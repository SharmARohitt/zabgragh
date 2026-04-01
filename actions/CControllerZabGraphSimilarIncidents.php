<?php declare(strict_types = 0);

namespace Modules\ZabGraph\Actions;

use API;
use CController;
use CRoleHelper;
use Modules\ZabGraph\Services\IncidentGraphService;

class CControllerZabGraphSimilarIncidents extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'eventid' => 'required|id'
		];
		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS);
	}

	protected function doAction(): void {
		header('Content-Type: application/json; charset=UTF-8');

		$eventid = (string) $this->getInput('eventid');
		$events = API::Event()->get([
			'output' => ['eventid', 'objectid', 'name', 'severity', 'clock'],
			'eventids' => [$eventid],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER
		]);

		if (empty($events)) {
			echo json_encode(['success' => false, 'error' => _('Event not found')]);
			exit;
		}

		$target = $events[0];
		$history = API::Event()->get([
			'output' => ['eventid', 'objectid', 'name', 'severity', 'clock'],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'value' => TRIGGER_VALUE_TRUE,
			'time_from' => time() - 180 * 86400,
			'sortfield' => ['clock'],
			'sortorder' => 'DESC',
			'limit' => 200
		]);

		$similar = IncidentGraphService::detectSimilarIncidents($target, $history);
		echo json_encode([
			'success' => true,
			'eventid' => $eventid,
			'count' => count($similar),
			'incidents' => $similar
		]);
		exit;
	}
}
