<?php

namespace heavymetalavo\craftaialttext\models\api;

use craft\base\Model;
use craft\helpers\Json;
use Exception;
use Craft;

/**
 * Anthropic Response Model
 *
 * Represents a response from the Anthropic API.
 */
class AnthropicResponse extends Model
{
    public string $output_text = '';
    public ?array $content = null;
    public ?array $error = null;
    private ?array $rawData = null;

    /**
     * Parse the API response and populate the model properties
     */
    public function parseResponse(string $responseBody): bool
    {
        try {
            $responseData = Json::decode($responseBody);
            $this->rawData = $responseData;

            $this->output_text = '';
            $this->content = null;
            $this->error = null;

            if (isset($responseData['type']) && $responseData['type'] === 'error') {
                $errorMessage = isset($responseData['error']['message']) 
                    ? $responseData['error']['message'] 
                    : Json::encode($responseData['error']);
                $this->setError($errorMessage, $responseData['error'] ?? null);
                return false;
            }

            if (!empty($responseData['content'])) {
                $this->content = $responseData['content'];
                foreach ($responseData['content'] as $contentBlock) {
                    if (isset($contentBlock['type']) && $contentBlock['type'] === 'text') {
                        $this->output_text = trim($contentBlock['text']);
                        break;
                    }
                }
            } else {
                Craft::warning('Could not find content in Anthropic response: ' . Json::encode($responseData), __METHOD__);
                $this->setError('Could not parse response from Anthropic API.');
                return false;
            }

            return $this->validate();
        } catch (Exception $e) {
            Craft::error('Failed to parse Anthropic response: ' . $e->getMessage(), __METHOD__);
            $this->setError('Failed to parse response: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Set an error message on the response
     */
    public function setError(string $message, ?array $details = null): void
    {
        $this->error = [
            'message' => $message,
            'details' => $details,
        ];
    }

    /**
     * Get the raw response data
     */
    public function getRawData(): ?array
    {
        return $this->rawData;
    }

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            ['output_text', 'string'],
            ['content', 'safe'],
            ['error', 'safe'],
        ];
    }

    /**
     * Checks if the response contains an error.
     */
    public function hasError(): bool
    {
        return $this->error !== null;
    }

    /**
     * Gets the error message from the response.
     */
    public function getErrorMessage(): string
    {
        return $this->error['message'] ?? '';
    }

    /**
     * Gets the generated text from the response.
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
