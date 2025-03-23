<?php

namespace heavymetalavo\craftaialttext\models\api;

use craft\base\Model;

/**
 * OpenAI Request Model
 * 
 * Represents a request to the OpenAI API.
 * This model handles the structure and validation of API requests, including the model to use and input to send.
 * 
 * @property string $model The OpenAI model to use (e.g., 'gpt-4o-mini')
 * @property array $input The input array containing the role and content
 */
class OpenAiRequest extends Model
{
    public string $model;
    public $input = [];

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
            ['input', 'array'],
        ];
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