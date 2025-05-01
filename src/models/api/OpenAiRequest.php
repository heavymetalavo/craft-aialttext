<?php

namespace heavymetalavo\craftaialttext\models\api;

use craft\base\Model;

/**
 * OpenAI Request Model
 *
 * Represents a request to the OpenAI API for vision analysis.
 * This model handles the structure and validation of API requests, including the model to use and input to send.
 *
 * @property string $model The OpenAI model to use (e.g., 'gpt-4o-mini')
 * @property array $input The input array containing the role and content
 * @property-read string $detail The detail level for image analysis
 */
class OpenAiRequest extends Model
{
    public string $model = '';
    public array $input = [];

    private string $prompt = '';
    private string $imageUrl = '';
    private string $detail = 'low';

    /**
     * Gets the detail level for image analysis
     *
     * @return string The detail level
     */
    public function getDetail(): string
    {
        return $this->detail;
    }

    /**
     * Sets the prompt text for the request
     *
     * @param string $prompt The prompt text to use
     * @return self For method chaining
     */
    public function setPrompt(string $prompt): self
    {
        $this->prompt = $prompt;
        $this->buildInput();
        return $this;
    }

    /**
     * Sets the image URL for the request
     *
     * @param string $imageUrl The URL of the image to analyze
     * @return self For method chaining
     */
    public function setImageUrl(string $imageUrl): self
    {
        $this->imageUrl = $imageUrl;
        $this->buildInput();
        return $this;
    }

    /**
     * Sets the detail level for image analysis
     *
     * @param string $detail The detail level (auto, low, or high)
     * @return self For method chaining
     */
    public function setDetail(string $detail): self
    {
        $this->detail = $detail;
        $this->buildInput();
        return $this;
    }

    /**
     * Builds the input array structure from the current properties
     */
    private function buildInput(): void
    {
        $content = [];

        // Add text content if prompt is set
        if (!empty($this->prompt)) {
            $content[] = [
                'type' => 'input_text',
                'text' => $this->prompt
            ];
        }

        // Add image content if imageUrl is set
        if (!empty($this->imageUrl)) {
            $content[] = [
                'type' => 'input_image',
                'image_url' => $this->imageUrl,
                'detail' => $this->detail
            ];
        }

        // Set the input array with the built content
        $this->input = [
            [
                'role' => 'user',
                'content' => $content
            ]
        ];
    }

    /**
     * Defines the validation rules for the request model.
     *
     * @return array The validation rules
     */
    public function defineRules(): array
    {
        return [
            [['model', 'input'], 'required'],
            ['model', 'string'],
            ['input', 'safe'],
            ['model', 'validateDetail'],
        ];
    }

    /**
     * Validates the detail property
     *
     * @return void
     */
    public function validateDetail(): void
    {
        // Custom validator for detail value
        if (!in_array($this->detail, ['auto', 'low', 'high'])) {
            $this->addError('detail', 'Detail must be one of: auto, low, high');
        }
    }

    /**
     * @inheritdoc
     */
    public function fields(): array
    {
        $fields = parent::fields();
        // Add virtual fields
        $fields['detail'] = function(self $model) {
            return $model->getDetail();
        };
        return $fields;
    }

    /**
     * @inheritdoc
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        // Override the parent toArray to ensure our specific structure
        return [
            'model' => $this->model,
            'input' => $this->input,
        ];
    }
}
