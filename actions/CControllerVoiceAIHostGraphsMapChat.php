<?php declare(strict_types = 0);

namespace Modules\ZabGraph\Actions;

use CController;
use CRoleHelper;
use Modules\ZabGraph\Services\KnowledgeMapService;

class CControllerVoiceAIHostGraphsMapChat extends CController {

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'hostid' => 'required|id',
			'question' => 'required|string'
		];
		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS);
	}

	protected function doAction(): void {
		header('Content-Type: application/json; charset=UTF-8');

		$hostid = (int) $this->getInput('hostid');
		$question = (string) $this->getInput('question', '');
		$context = $this->getInput('context_graph', []);
		if (!is_array($context)) {
			$context = [];
		}

		try {
			$payload = KnowledgeMapService::answerQuestion($hostid, $question, $context);
			echo json_encode($payload);
		}
		catch (\Throwable $e) {
			echo json_encode([
				'success' => false,
				'error' => $e->getMessage()
			]);
		}
		exit;
	}
}
