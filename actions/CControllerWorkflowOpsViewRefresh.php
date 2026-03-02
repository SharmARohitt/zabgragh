<?php declare(strict_types = 0);

namespace Modules\WorkflowOps\Actions;

use CControllerResponseData;

class CControllerWorkflowOpsViewRefresh extends CControllerWorkflowOpsView {

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
