<?php

namespace heavymetalavo\craftaialttext\models\api;

use craft\base\Model;

/**
 * OpenAI Message Model
 * 
 * Represents a message in the OpenAI chat completion API.
 * This model handles the structure and validation of messages, including their role and content.
 * 
 * @property string $role The role of the message sender ('user' or 'assistant')
 * @property OpenAiContent[] $content Array of content items in the message
 */
class OpenAiMessage extends Model
{
    public string $role;
    public array $content;

    /**
     * Defines the validation rules for the message model.
     * 
     * @return array The validation rules
     */
    public function defineRules(): array
    {
        return [
            [['role', 'content'], 'required'],
            ['role', 'string'],
            ['role', 'in', 'range' => ['user', 'assistant']],
            ['content', 'validateContent'],
        ];
    }

    /**
     * Validates the content array of the message.
     * 
     * This method ensures that:
     * - The content is an array
     * - Each content item is an instance of OpenAiContent
     * - Each content item is valid
     * 
     * @param string $attribute The attribute being validated
     * @param array $params Additional parameters for validation
     */
    public function validateContent($attribute, $params): void
    {
        if (!is_array($this->$attribute)) {
            $this->addError($attribute, 'Content must be an array');
            return;
        }

        foreach ($this->$attribute as $index => $item) {
            if (!$item instanceof OpenAiContent) {
                $this->addError($attribute, "Content item {$index} must be an instance of OpenAiContent");
                continue;
            }

            if (!$item->validate()) {
                foreach ($item->getErrors() as $field => $errors) {
                    $this->addError($attribute, "Content item {$index} {$field}: " . implode(', ', $errors));
                }
            }
        }
    }

    /**
     * Converts the model to an array format suitable for the OpenAI API.
     * 
     * @return array The formatted array representation
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'content' => array_map(fn($content) => $content->toArray(), $this->content),
        ];
    }
} 