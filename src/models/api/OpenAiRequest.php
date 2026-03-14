<?php

namespace heavymetalavo\craftaialttext\models\api;

use craft\base\Model;

/**
 * OpenAI Request Model
 *
 * Represents a request to the OpenAI Responses API for vision analysis.
 */
class OpenAiRequest extends Model
{
    public string $model = '';

    private string $prompt = '';
    private string $imageUrl = '';
    private string $detail = 'low';

    public function getDetail(): string
    {
        return $this->detail;
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

        return [
            'model' => $this->model,
            'input' => [
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
        ];
    }
}
