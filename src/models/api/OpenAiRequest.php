<?php

namespace heavymetalavo\craftaialttext\models\api;

use craft\base\Model;

/**
 * OpenAI Request Model
 * 
 * Represents a request to the OpenAI chat completion API.
 * This model handles the structure and validation of API requests, including the model to use and messages to send.
 * 
 * @property string $model The OpenAI model to use (e.g., 'gpt-4-vision-preview')
 * @property OpenAiMessage[] $messages Array of messages in the conversation
 * @property int|null $max_tokens Maximum number of tokens to generate in the response
 */
class OpenAiRequest extends Model
{
    public string $model;
    public array $input;
    public ?int $max_tokens = null;

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
            ['max_tokens', 'integer'],
        ];
    }

    /**
     * Validates the messages array of the request.
     * 
     * This method ensures that:
     * - The messages is an array
     * - Each message is an instance of OpenAiMessage
     * - Each message is valid
     * 
     * @param string $attribute The attribute being validated
     * @param array $params Additional parameters for validation
     */
    public function validateMessages($attribute, $params): void
    {
        if (!is_array($this->$attribute)) {
            $this->addError($attribute, 'Messages must be an array');
            return;
        }

        foreach ($this->$attribute as $index => $message) {
            if (!$message instanceof OpenAiMessage) {
                $this->addError($attribute, "Message {$index} must be an instance of OpenAiMessage");
                continue;
            }

            if (!$message->validate()) {
                foreach ($message->getErrors() as $field => $errors) {
                    $this->addError($attribute, "Message {$index} {$field}: " . implode(', ', $errors));
                }
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        $data = parent::toArray($fields, $expand, $recursive);

        if ($this->messages) {
            $data['messages'] = array_map(function($message) {
                return $message->toArray();
            }, $this->messages);
        }

        if ($this->max_tokens !== null) {
            $data['max_tokens'] = $this->max_tokens;
        }

        return $data;
    }
} 