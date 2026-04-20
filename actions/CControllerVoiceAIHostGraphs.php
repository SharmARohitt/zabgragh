<?php declare(strict_types = 0);

namespace Modules\ZabGraph\Actions;

use API;
use CController;
use CControllerResponseData;
use CRoleHelper;

class CControllerVoiceAIHostGraphs extends CController {

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
		$hostid = (int) $this->getInput('hostid');
		$premium_enabled_env = getenv('ZG_PREMIUM_MAP_ENABLED');
		$premium_enabled = ($premium_enabled_env === false || $premium_enabled_env === '' || $premium_enabled_env === '1');
		$hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name'],
			'hostids' => [$hostid],
			'selectTags' => ['tag', 'value']
		]);

		if (empty($hosts)) {
			$data = [
				'error' => _('Host not found.'),
				'hostid' => $hostid,
				'host' => null,
				'premium_map_enabled' => $premium_enabled
			];
		}
		else {
			$data = [
				'error' => null,
				'hostid' => $hostid,
				'host' => $hosts[0],
				'premium_map_enabled' => $premium_enabled
			];
		}

		$data['premium_map_endpoints'] = [
			'graph_data' => (new \CUrl('zabbix.php'))->setArgument('action', 'voiceai.hostgraphs.map.data')->getUrl(),
			'chat' => (new \CUrl('zabbix.php'))->setArgument('action', 'voiceai.hostgraphs.map.chat')->getUrl(),
			'upload' => (new \CUrl('zabbix.php'))->setArgument('action', 'voiceai.hostgraphs.map.upload')->getUrl(),
			'layout_save' => (new \CUrl('zabbix.php'))->setArgument('action', 'voiceai.hostgraphs.map.layout.save')->getUrl()
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Host Observability'));
		$this->setResponse($response);
	}
}
