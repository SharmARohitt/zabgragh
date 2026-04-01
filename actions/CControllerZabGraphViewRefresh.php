<?php declare(strict_types = 0);

namespace Modules\ZabGraph\Actions;

use CControllerResponseData;

class CControllerZabGraphViewRefresh extends CControllerZabGraphView {

	protected function doAction(): void {
		parent::doAction();
		$response = $this->getResponse();
		if ($response instanceof CControllerResponseData) {
			$data = $response->getData();
			$data['is_refresh'] = true;
			$response->setData($data);
		}
	}
}
