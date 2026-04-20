<?php declare(strict_types = 0);

namespace Modules\ZabGraph\Actions;

use CController;
use CRoleHelper;
use Modules\ZabGraph\Services\KnowledgeMapService;

class CControllerVoiceAIHostGraphsMapUpload extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'hostid' => 'id',
			'json_text' => 'string'
		];
		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS);
	}

	protected function doAction(): void {
		header('Content-Type: application/json; charset=UTF-8');

		$json_text = (string) $this->getInput('json_text', '');
		$file_json = '';
		if (!empty($_FILES['file']) && is_array($_FILES['file'])) {
			$tmp = (string) ($_FILES['file']['tmp_name'] ?? '');
			$name = (string) ($_FILES['file']['name'] ?? 'upload.json');
			if ($tmp !== '' && is_uploaded_file($tmp)) {
				$ext = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));
				if ($ext !== 'json') {
					echo json_encode(['success' => false, 'error' => 'Only JSON files are supported.']);
					exit;
				}
				$size = (int) ($_FILES['file']['size'] ?? 0);
				if ($size > (2 * 1024 * 1024)) {
					echo json_encode(['success' => false, 'error' => 'JSON file is too large (max 2MB).']);
					exit;
				}
				$content = @file_get_contents($tmp);
				if (is_string($content)) {
					$file_json = $content;
				}
			}
		}

		$raw = trim($file_json !== '' ? $file_json : $json_text);
		if ($raw === '') {
			echo json_encode(['success' => false, 'error' => 'Attach a JSON file or provide JSON text.']);
			exit;
		}

		try {
			$payload = KnowledgeMapService::parseJsonUpload($raw);
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
