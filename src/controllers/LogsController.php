<?php
namespace Kdgraphics\BaseTemplate\controllers;

use craft\web\Controller;

use yii\web\Response;

class LogsController extends Controller
{
    // Public Methods
    // =========================================================================

    public function actionLogs(): Response
    {
        return $this->redirect('settings/plugins/base-template?tab=logs');
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();

        \Kdgraphics\BaseTemplate\BaseTemplate::$plugin->getLogs()->clear();

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        return $this->redirect('settings/plugins/base-template?tab=logs');
    }
}
