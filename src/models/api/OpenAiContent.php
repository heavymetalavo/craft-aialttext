<?php

namespace heavymetalavo\craftaialttext\models\api;

use craft\base\Model;

/**
 * OpenAI Content Model
 * 
 * Represents a content item in an OpenAI message that can be either text or an image.
 * This model handles the structure and validation of content items as specified in the OpenAI API.
 * 
 * @property string $type The type of content ('text' or 'image_url')
 * @property TextContent|null $text The text content when type is 'text'
 * @property ImageContent|null $image_url The image content when type is 'image_url'
 */
class OpenAiContent extends Model
{
    public string $type;
    public ?TextContent $text = null;
    public ?ImageContent $image_url = null;

    /**
     * Defines the validation rules for the content model.
     * 
     * @return array The validation rules
     */
    public function defineRules(): array
    {
        return [
            [['type'], 'required'],
            ['type', 'string'],
            ['type', 'in', 'range' => ['text', 'image_url']],
            ['text', 'required', 'when' => fn($model) => $model->type === 'text'],
            ['image_url', 'required', 'when' => fn($model) => $model->type === 'image_url'],
        ];
    }

    /**
     * Converts the model to an array format suitable for the OpenAI API.
     * 
     * @return array The formatted array representation
     */
    public function toArray(): array
    {
        $data = ['type' => $this->type];

        if ($this->type === 'text') {
            $data['text'] = $this->text->text;
        } else {
            $data['image_url'] = [
                'url' => $this->image_url->url,
            ];
            if ($this->image_url->detail) {
                $data['image_url']['detail'] = $this->image_url->detail;
            }
        }

        return $data;
    }
}

/**
 * Text Content Model
 * 
 * Represents a text content item in an OpenAI message.
 * This model handles the structure and validation of text content.
 * 
 * @property string $text The text content
 */
class TextContent extends Model
{
    public string $text;

    /**
     * Defines the validation rules for the text content model.
     * 
     * @return array The validation rules
     */
    public function defineRules(): array
    {
        return [
            [['text'], 'required'],
            ['text', 'string'],
        ];
    }
}

/**
 * Image Content Model
 * 
 * Represents an image content item in an OpenAI message.
 * This model handles the structure and validation of image content.
 * 
 * @property string $url The URL of the image
 * @property string|null $detail The detail level of the image ('low' or 'high')
 */
class ImageContent extends Model
{
    public string $url;
    public ?string $detail = null;

    /**
     * Defines the validation rules for the image content model.
     * 
     * @return array The validation rules
     */
    public function defineRules(): array
    {
        return [
            [['url'], 'required'],
            ['url', 'string'],
            ['detail', 'string'],
            ['detail', 'in', 'range' => ['low', 'high']],
        ];
    }
} 