<?php declare(strict_types = 0);

namespace Modules\ZabGraph\Actions;

use CController;
use CControllerResponseData;

class CControllerZabGraphPopup extends CControllerZabGraphView {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'eventid' => 'required|id',
			'triggerid' => 'id'
		];
		return $this->validateInput($fields);
	}

	protected function doAction(): void {
		$eventid = $this->getInput('eventid');
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
				(new CControllerResponseData([
					'main_block' => json_encode([
						'error' => [
							'title' => _('Error'),
							'messages' => [_('Problem not found.')]
						]
					])
				]))->disableView()
			);
			return;
		}

		$this->setResponse(new CControllerResponseData($data));
	}
}
