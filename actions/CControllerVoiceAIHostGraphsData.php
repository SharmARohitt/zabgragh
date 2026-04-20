<?php declare(strict_types = 0);

namespace Modules\ZabGraph\Actions;

use CController;
use CRoleHelper;
use Modules\ZabGraph\Services\HostObservabilityService;

class CControllerVoiceAIHostGraphsData extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'hostid' => 'required|id',
			'window' => 'in 1h,6h,24h',
			'severity' => 'in all,critical,warning',
			'metric' => 'in all,cpu,memory,network,disk'
		];
		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS);
	}

	protected function doAction(): void {
		header('Content-Type: application/json; charset=UTF-8');

		$hostid = (int) $this->getInput('hostid');
		$window = (string) $this->getInput('window', '6h');
		$severity = (string) $this->getInput('severity', 'all');
		$metric = (string) $this->getInput('metric', 'all');

		$window_sec = match ($window) {
			'1h' => 3600,
			'24h' => 86400,
			default => 21600
		};

		try {
			$payload = HostObservabilityService::buildPayload($hostid, $window_sec, $severity, $metric);
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
