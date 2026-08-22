<?php

namespace heavymetalavo\craftaialttext\models\api;

use craft\base\Model;
use craft\helpers\Json;
use Craft;

/**
 * OpenAI Request Model
 *
 * Represents a request to the OpenAI Responses API for vision analysis.
 */
class OpenAiRequest extends Model
{
    public string $model = '';

    private string $instructions = '';
    private string $prompt = '';
    private string $imageUrl = '';
    private string $detail = 'low';
    private string $reasoningEffort = 'minimal';

    public function getDetail(): string
    {
        return $this->detail;
    }

    /**
     * Sets the system-level instructions (task, output format, language, etc.), emitted as the
     * Responses API top-level `instructions` parameter, which the model weights more strongly than
     * text in the user input.
     */
    public function setInstructions(string $instructions): self
    {
        $this->instructions = $instructions;
        return $this;
    }

    public function setReasoningEffort(string $reasoningEffort): self
    {
        $this->reasoningEffort = $reasoningEffort;
        return $this;
    }

    public function setPrompt(string $prompt): self
    {
        $this->prompt = $prompt;
        return $this;
    }

    public function setImageUrl(string $imageUrl): self
    {
        $this->imageUrl = $imageUrl;
        return $this;
    }

    public function setDetail(string $detail): self
    {
        $this->detail = $detail;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            ['model', 'required'],
            ['model', 'string'],
            ['model', 'validateDetail'],
        ];
    }

    public function validateDetail(): void
    {
        if (!in_array($this->detail, ['low', 'high', 'original', 'auto'])) {
            $this->addError('detail', 'Detail must be one of: low, high, original, auto');
        }
    }

    /**
     * @inheritdoc
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        $content = [];

        if (!empty($this->prompt)) {
            $content[] = [
                'type' => 'input_text',
                'text' => $this->prompt,
            ];
        }

        if (!empty($this->imageUrl)) {
            $content[] = [
                'type' => 'input_image',
                'image_url' => $this->imageUrl,
                'detail' => $this->detail,
            ];
        }


        $payload = [
            'model' => $this->model,
            'input' => [
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
        ];

        if (!empty($this->instructions)) {
            $payload['instructions'] = $this->instructions;
        }

        if ($this->isReasoningModel()) {
            $payload['reasoning']['effort'] = $this->reasoningEffort;
        }

        return $payload;
    }

    /**
     * Whether the configured model takes a `reasoning` parameter.
     *
     * The previous check was `str_starts_with($model, 'o')`, which matched *any* model name
     * beginning with "o", and a bare `gpt-5` prefix, which also matched non-reasoning chat
     * variants such as `gpt-5-chat-latest`. Either way the request failed with an
     * invalid_request_error, which generateAltText() then misread as the provider being unable to
     * fetch the image URL - so it retried the whole thing as base64 before giving up.
     */
    private function isReasoningModel(): bool
    {
        // Chat variants don't accept a reasoning parameter, whatever family they're in.
        if (str_contains($this->model, '-chat')) {
            return false;
        }

        // o-series (o1, o3, o4-mini, ...) and the gpt-5 family onwards, including point releases
        // such as gpt-5.1 and future two-digit majors.
        return (bool) preg_match('/^(o\d|gpt-([5-9]|\d{2,}))/', $this->model);
    }
}
