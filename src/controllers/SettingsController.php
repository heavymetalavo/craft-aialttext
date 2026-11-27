<?php

namespace heavymetalavo\craftaialttext\controllers;

use craft\web\Controller;
use heavymetalavo\craftaialttext\AiAltText;
use yii\web\{BadRequestHttpException, Response};

/**
 * Settings Controller
 */
class SettingsController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * The model suggestions for the OpenRouter or Hugging Face Model setting, so the settings page can fill that
     * dropdown when the provider is chosen. The page only fetches them itself for the saved provider.
     *
     * @return Response `{models: [{name, hint}]}`, empty if the provider's models API couldn't be reached
     * @throws BadRequestHttpException
     */
    public function actionModelSuggestions(): Response
    {
        $this->requireCpRequest();
        $this->requireAdmin(false);
        $this->requireAcceptsJson();

        $plugin = AiAltText::getInstance();
        $suggestions = match ($this->request->getRequiredQueryParam('provider')) {
            'openrouter' => $plugin->openRouterService->visionModelSuggestions(),
            'huggingface' => $plugin->huggingFaceService->visionModelSuggestions(),
            default => throw new BadRequestHttpException('Model suggestions are only available for OpenRouter and Hugging Face.'),
        };

        return $this->asJson(['models' => $suggestions[0]['data'] ?? []]);
    }
}
