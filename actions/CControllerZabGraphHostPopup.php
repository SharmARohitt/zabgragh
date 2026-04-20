<?php declare(strict_types = 0);

namespace Modules\ZabGraph\Actions;

use API;
use CControllerResponseData;

class CControllerZabGraphHostPopup extends CControllerZabGraphView {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'hostid' => 'required|id'
		];
		return $this->validateInput($fields);
	}

	protected function doAction(): void {
		$hostid = (int) $this->getInput('hostid');

		// Try to get recent open problems first
		$problems = API::Problem()->get([
			'output' => ['eventid', 'clock'],
			'hostids' => [$hostid],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'recent' => true,
			'sortfield' => ['clock'],
			'sortorder' => ZBX_SORT_DOWN,
			'limit' => 1
		]);

		$eventid = null;

		if (!empty($problems)) {
			$eventid = (string) $problems[0]['eventid'];
		}
		else {
			// Try to get any recent trigger event (open or resolved)
			$events = API::Event()->get([
				'output' => ['eventid', 'clock'],
				'hostids' => [$hostid],
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'sortfield' => ['clock'],
				'sortorder' => ZBX_SORT_DOWN,
				'limit' => 1
			]);

			if (!empty($events)) {
				$eventid = (string) $events[0]['eventid'];
			}
			else {
				// Last resort: redirect to host observability page instead of showing error
				$this->setResponse(
					(new CControllerResponseData())->setRedirect(
						(new \CUrl('index.php'))
							->setArgument('action', 'voiceai.hostgraphs')
							->setArgument('hostid', $hostid)
							->getUrl()
					)
				);
				return;
			}
		}
		$data = [
			'eventid' => $eventid,
			'is_single' => true,
			'is_popup' => true,
			'view_mode' => 'cards',
			'severity_filter' => [],
			'show_acks' => 1,
			'show_resolved' => 0,
			'limit' => 50,
			'problems' => [],
			'triggers' => [],
			'actions_by_event' => [],
			'users' => [],
			'mediatypes' => [],
			'hosts' => [],
			'maintenances_by_event' => [],
			'maps_by_host' => [],
			'templates_by_host' => [],
			'item_values_at_problem' => [],
			'item_values_at_resolution' => [],
			'item_template_by_id' => [],
			'trigger_template_by_id' => [],
			'item_tags_by_id' => [],
			'triggers_by_item' => []
		];

		$this->fetchSingleProblemWorkflow($data, $eventid);

		if (empty($data['problems'])) {
			$this->setResponse(
				(new CControllerResponseData())->setRedirect(
					(new \CUrl('index.php'))
						->setArgument('action', 'voiceai.hostgraphs')
						->setArgument('hostid', $hostid)
						->getUrl()
				)
			);
			return;
		}

		$this->setResponse(new CControllerResponseData($data));
	}
}
