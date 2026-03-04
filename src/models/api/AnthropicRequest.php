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
    public int $max_tokens = 1024;
    public array $messages = [];

    private string $prompt = '';
    private ?string $imageUrl = null;
    private ?array $imageSource = null;

    /**
     * Sets the prompt text for the request
     */
    public function setPrompt(string $prompt): self
    {
        $this->prompt = $prompt;
        $this->buildMessages();
        return $this;
    }

    /**
     * Sets the image URL for the request
     */
    public function setImageUrl(string $imageUrl): self
    {
        $this->imageUrl = $imageUrl;
        $this->buildMessages();
        return $this;
    }

    /**
     * Sets the image source content for the request (e.g., base64 string and mime type)
     */
    public function setImageSource(array $imageSource): self
    {
        $this->imageSource = $imageSource;
        $this->buildMessages();
        return $this;
    }

    /**
     * Builds the messages array structure from the current properties
     */
    private function buildMessages(): void
    {
        $content = [];

        $source = $this->imageSource;
        if (!$source && $this->imageUrl) {
            $source = [
                'type' => 'url',
                'url' => $this->imageUrl
            ];
        }

        if ($source) {
            $content[] = [
                'type' => 'image',
                'source' => $source
            ];
        }

        if (!empty($this->prompt)) {
            $content[] = [
                'type' => 'text',
                'text' => $this->prompt
            ];
        }

        $this->messages = [
            [
                'role' => 'user',
                'content' => $content
            ]
        ];
    }

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['model', 'messages', 'max_tokens'], 'required'],
            ['model', 'string'],
            ['max_tokens', 'integer'],
            ['messages', 'safe'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'model' => $this->model,
            'max_tokens' => $this->max_tokens,
            'messages' => $this->messages,
        ];
    }
}
