<?php
namespace Inspire\Frontend\controllers;

use craft\web\Controller;

use yii\web\Response;

class LogsController extends Controller
{
    // Public Methods
    // =========================================================================

    public function actionLogs(): Response
    {
        return $this->redirect('settings/plugins/inspire-frontend?tab=logs');
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();

        \Inspire\Frontend\Frontend::$plugin->getLogs()->clear();

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        return $this->redirect('settings/plugins/inspire-frontend?tab=logs');
    }
}
