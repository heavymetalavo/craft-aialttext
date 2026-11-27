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

    private string $system = '';
    private string $prompt = '';
    private ?string $imageUrl = null;
    private array $imageSources = [];

    /**
     * Sets the system instructions for the request (task, output format, language, etc.), sent as
     * the top-level `system` parameter, which the model weights more strongly than text in the
     * user content — improving adherence to directives such as the output language.
     */
    public function setSystem(string $system): self
    {
        $this->system = $system;
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

    /**
     * Sets the image sources for the request (e.g., base64 data and mime type), sent in order, e.g. the frames of an
     * animated image. They're sent instead of the image URL.
     */
    public function setImageSources(array $imageSources): self
    {
        $this->imageSources = $imageSources;
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

        $sources = $this->imageSources;
        if (!$sources && $this->imageUrl) {
            $sources = [[
                'type' => 'url',
                'url' => $this->imageUrl,
            ]];
        }

        foreach ($sources as $source) {
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

        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
        ];

        if (!empty($this->system)) {
            $payload['system'] = $this->system;
        }

        return $payload;
    }
}
