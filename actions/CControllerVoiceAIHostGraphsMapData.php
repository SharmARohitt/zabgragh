<?php declare(strict_types = 0);

namespace Modules\ZabGraph\Actions;

use CController;
use CProfile;
use CRoleHelper;
use Modules\ZabGraph\Services\KnowledgeMapService;

class CControllerVoiceAIHostGraphsMapData extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'hostid' => 'required|id'
		];
		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS);
	}

	protected function doAction(): void {
		header('Content-Type: application/json; charset=UTF-8');

		$hostid = (int) $this->getInput('hostid');
		$payload = KnowledgeMapService::buildHostMap($hostid);
		$layout_raw = CProfile::get('zg.voiceai.map.layout.'.$hostid, '{}');
		$layout = json_decode((string) $layout_raw, true);
		if (!is_array($layout)) {
			$layout = [];
		}
		$payload['layout'] = $layout;

		echo json_encode($payload);
		exit;
	}
}
