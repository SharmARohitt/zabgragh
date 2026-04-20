<?php declare(strict_types = 0);

namespace Modules\ZabGraph\Actions;

use CController;
use CControllerResponseData;
use CProfile;
use CRoleHelper;

class CControllerVoiceAIHostGraphsMapLayoutSave extends CController {

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'hostid' => 'required|id',
			'layout' => 'required|string'
		];
		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS);
	}

	protected function doAction(): void {
		$hostid = (int) $this->getInput('hostid');
		$layout = (string) $this->getInput('layout', '{}');
		$data = json_decode($layout, true);

		if (!is_array($data)) {
			$this->setResponse(new CControllerResponseData([
				'main_block' => json_encode(['success' => false, 'error' => 'Invalid layout payload'])
			]));
			return;
		}

		$sanitized = [];
		if (isset($data['pan']) && is_array($data['pan'])) {
			$sanitized['pan'] = [
				'x' => round((float) ($data['pan']['x'] ?? 0), 1),
				'y' => round((float) ($data['pan']['y'] ?? 0), 1)
			];
		}
		if (isset($data['zoom'])) {
			$sanitized['zoom'] = round((float) $data['zoom'], 3);
		}
		if (isset($data['nodes']) && is_array($data['nodes'])) {
			$sanitized['nodes'] = [];
			foreach ($data['nodes'] as $node_id => $pos) {
				if (!is_array($pos)) {
					continue;
				}
				$sanitized['nodes'][(string) $node_id] = [
					'x' => round((float) ($pos['x'] ?? 0), 1),
					'y' => round((float) ($pos['y'] ?? 0), 1)
				];
			}
		}

		CProfile::update('zg.voiceai.map.layout.'.$hostid, json_encode($sanitized), PROFILE_TYPE_STR);
		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode(['success' => true])
		]));
	}
}
