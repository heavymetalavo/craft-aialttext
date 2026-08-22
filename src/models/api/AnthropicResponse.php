<?php

namespace heavymetalavo\craftaialttext\models\api;

use CraftCms\Cms\Component\Component;
use CraftCms\Cms\Support\Json;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic Response Model
 *
 * Parses and represents a response from the Anthropic Messages API.
 */
class AnthropicResponse extends Component
{
    public string $outputText = '';
    public ?array $content = null;
    public ?array $error = null;
    private ?array $rawData = null;

    /**
     * Parse the API response and populate the model properties.
     */
    public function parseResponse(string $responseBody): bool
    {
        try {
            $responseData = Json::decode($responseBody);
            $this->rawData = $responseData;

            $this->outputText = '';
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
                        $this->outputText = trim($contentBlock['text']);
                        break;
                    }
                }
            } else {
                Log::warning('Could not find content in Anthropic response: ' . Json::encode($responseData));
                $this->setError('Could not parse response from Anthropic API.');
                return false;
            }

            return $this->validate();
        } catch (Exception $e) {
            Log::error('Failed to parse Anthropic response: ' . $e->getMessage());
            $this->setError('Failed to parse response: ' . $e->getMessage());
            return false;
        }
    }

    public function setError(string $message, ?array $details = null): void
    {
        $this->error = [
            'message' => $message,
            'details' => $details,
        ];
    }

    public function getRawData(): ?array
    {
        return $this->rawData;
    }

    /**
     * @inheritdoc
     */
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            'outputText' => ['nullable', 'string'],
        ]);
    }

    public function hasError(): bool
    {
        return $this->error !== null;
    }

    public function getErrorMessage(): string
    {
        return $this->error['message'] ?? '';
    }

    public function getText(): string
    {
        return $this->outputText;
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
