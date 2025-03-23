<?php

namespace heavymetalavo\craftaialttext\models\api;

use craft\base\Model;

/**
 * OpenAI Response Model
 * 
 * Represents a response from the OpenAI chat completion API.
 * This model handles the structure and validation of API responses, including the generated content and any errors.
 * 
 * @property array $output The generated content from the API
 * @property array|null $error Error information if the request failed
 */
class OpenAiResponse extends Model
{
    public array $output = [];
    public ?array $error = null;

    /**
     * Defines the validation rules for the response model.
     * 
     * @return array The validation rules
     */
    public function defineRules(): array
    {
        return [
            ['output', 'required'],
            ['output', 'isArray'],
            ['error', 'isArray'],
        ];
    }

    /**
     * Checks if the response contains an error.
     * 
     * @return bool True if there is an error, false otherwise
     */
    public function hasError(): bool
    {
        return $this->error !== null;
    }

    /**
     * Gets the error message from the response.
     * 
     * @return string The error message, or an empty string if there is no error
     */
    public function getErrorMessage(): string
    {
        return $this->error['message'] ?? '';
    }

    /**
     * Gets the generated text from the response.
     * 
     * @return string The generated text, or an empty string if no text is available
     */
    public function getText(): string
    {
        return is_string($this->output) ? $this->output : '';
    }

    /**
     * @inheritdoc
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        $data = parent::toArray($fields, $expand, $recursive);

        if ($this->error) {
            $data['error'] = $this->error;
        }

        return $data;
    }
} 