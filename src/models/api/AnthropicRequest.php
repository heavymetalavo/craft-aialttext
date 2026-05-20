<?php

namespace heavymetalavo\craftaialttext\models\api;

use CraftCms\Cms\Component\Component;

/**
 * Anthropic Request Model
 *
 * Represents a request to the Anthropic Messages API.
 */
class AnthropicRequest extends Component
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
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            'model' => ['required', 'string'],
            'maxTokens' => ['required', 'integer'],
        ]);
    }

    /**
     * Build the JSON payload for the Anthropic Messages API.
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
