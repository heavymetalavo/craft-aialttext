<?php

namespace heavymetalavo\craftaialttext\models\api;

use craft\base\Model;

/**
 * Anthropic Request Model
 *
 * Represents a request to the Anthropic Messages API.
 */
class AnthropicRequest extends Model
{
    public string $model = '';
    public int $maxTokens = 1024;

    private string $prompt = '';
    private ?string $imageUrl = null;
    private ?array $imageSource = null;

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

    /**
     * Sets the image source content for the request (e.g., base64 data and mime type)
     */
    public function setImageSource(array $imageSource): self
    {
        $this->imageSource = $imageSource;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['model', 'maxTokens'], 'required'],
            ['model', 'string'],
            ['maxTokens', 'integer'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        $content = [];

        $source = $this->imageSource;
        if (!$source && $this->imageUrl) {
            $source = [
                'type' => 'url',
                'url' => $this->imageUrl,
            ];
        }

        if ($source) {
            $content[] = [
                'type' => 'image',
                'source' => $source,
            ];
        }

        if (!empty($this->prompt)) {
            $content[] = [
                'type' => 'text',
                'text' => $this->prompt,
            ];
        }

        return [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
        ];
    }
}
