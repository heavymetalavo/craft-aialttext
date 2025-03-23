<?php

namespace heavymetalavo\craftaialttext\models\api;

use craft\base\Model;

/**
 * OpenAI Response Model
 * 
 * Represents a response from the OpenAI API.
 * This model handles the structure and validation of API responses, including the generated content and any errors.
 * 
 * @property string $output_text The generated content from the API
 * @property array|null $error Error information if the request failed
 */
class OpenAiResponse extends Model
{
    public string $output_text = '';
    public ?array $error = null;

    /**
     * Defines the validation rules for the response model.
     * 
     * @return array The validation rules
     */
    public function defineRules(): array
    {
        return [
            ['output_text', 'string'],
            ['error', 'safe'],
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
     * @return string The generated text
     */
    public function getText(): string
    {
        return $this->output_text;
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