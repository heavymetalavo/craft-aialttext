<?php

namespace heavymetalavo\craftaialttext\models\api;

use CraftCms\Cms\Component\Component;

/**
 * OpenAI Request Model
 *
 * Represents a request to the OpenAI Responses API for vision analysis.
 */
class OpenAiRequest extends Component
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
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            'model' => ['required', 'string'],
        ]);
    }

    public function validateDetail(): void
    {
        if (!in_array($this->detail, ['low', 'high', 'original', 'auto'])) {
            $this->errors()->add('detail', 'Detail must be one of: low, high, original, auto');
        }
    }

    /**
     * Build the JSON payload for the OpenAI Responses API.
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

    private function isReasoningModel(): bool
    {
        return str_starts_with($this->model, 'gpt-5') || str_starts_with($this->model, 'o');
    }
}
