<?php

namespace heavymetalavo\craftaialttext\models\api;

use craft\base\Model;

/**
 * Chat Completions Request Model
 *
 * Represents a request to a Chat Completions API (`POST {baseUrl}/chat/completions`) for vision analysis.
 */
class ChatCompletionsRequest extends Model
{
    public string $model = '';

    private string $instructions = '';
    private string $prompt = '';
    /** @var string[] */
    private array $imageUrls = [];
    private string $detail = 'low';

    public function getDetail(): string
    {
        return $this->detail;
    }

    /**
     * Sets the system-level instructions (task, output format, language, etc.), sent as the system message.
     */
    public function setInstructions(string $instructions): self
    {
        $this->instructions = $instructions;
        return $this;
    }

    public function setPrompt(string $prompt): self
    {
        $this->prompt = $prompt;
        return $this;
    }

    /**
     * Sets the images, as URLs or base64 data URLs, sent in order after the prompt, e.g. the frames of an animated image.
     *
     * @param string[] $imageUrls
     */
    public function setImageUrls(array $imageUrls): self
    {
        $this->imageUrls = $imageUrls;
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
        if (!in_array($this->detail, ['low', 'high', 'auto'])) {
            $this->addError('detail', 'Detail must be one of: low, high, auto');
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
                'type' => 'text',
                'text' => $this->prompt,
            ];
        }

        foreach ($this->imageUrls as $imageUrl) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $imageUrl,
                    'detail' => $this->detail,
                ],
            ];
        }

        $messages = [];

        if (!empty($this->instructions)) {
            $messages[] = [
                'role' => 'system',
                'content' => $this->instructions,
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $content,
        ];

        return [
            'model' => $this->model,
            'messages' => $messages,
        ];
    }
}
