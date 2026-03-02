<?php declare(strict_types = 0);

namespace Modules\WorkflowOps\Actions;

use CController;
use CControllerResponseData;
use CProfile;
use CWebUser;
use CRoleHelper;

class CControllerWorkflowOpsLayoutSave extends CController {

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		$fields = [
			'layout' => 'string'
		];
		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return CWebUser::checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS);
	}

	protected function doAction(): void {
		$layout = $this->getInput('layout', '{}');
		$data = json_decode($layout, true);
		if (!is_array($data)) {
			$this->setResponse(new CControllerResponseData([
				'main_block' => json_encode(['error' => ['title' => _('Error'), 'messages' => [_('Invalid layout data')]]])
			]));
			return;
		}

		$sanitized = [];
		if (isset($data['panX'])) {
			$sanitized['panX'] = round((float) $data['panX'], 1);
		}
		if (isset($data['panY'])) {
			$sanitized['panY'] = round((float) $data['panY'], 1);
		}
		if (isset($data['zoom'])) {
			$sanitized['zoom'] = round((float) $data['zoom'], 3);
		}
		if (isset($data['nodes']) && is_array($data['nodes'])) {
			$sanitized['nodes'] = [];
			foreach ($data['nodes'] as $idx => $pos) {
				$idx = (int) $idx;
				if ($idx >= 0 && isset($pos['x'], $pos['y'])) {
					$sanitized['nodes'][$idx] = [
						'x' => round((float) $pos['x'], 1),
						'y' => round((float) $pos['y'], 1)
					];
				}
			}
		}

		CProfile::update('mnz.workflow.ops.layout', json_encode($sanitized), PROFILE_TYPE_STR);

		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode(['success' => ['title' => _('Layout saved')]])
		]));
	}
}
