<?php

namespace heavymetalavo\craftaialttext\models\api;

use CraftCms\Cms\Component\Component;
use CraftCms\Cms\Support\Json;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI Response Model
 *
 * Parses and represents a response from the OpenAI Responses API.
 */
class OpenAiResponse extends Component
{
    public string $outputText = '';
    public ?array $output = null;
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
            $this->output = null;
            $this->content = null;
            $this->error = null;

            if (isset($responseData['error'])) {
                $errorDetails = is_array($responseData['error']) ? $responseData['error'] : null;
                $errorMessage = is_array($responseData['error'])
                    ? ($responseData['error']['message'] ?? Json::encode($responseData['error']))
                    : $responseData['error'];
                $this->setError($errorMessage, $errorDetails);
                return false;
            }

            if (isset($responseData['output']) && is_array($responseData['output'])) {
                $this->output = $responseData['output'];

                foreach ($responseData['output'] as $outputItem) {
                    if (isset($outputItem['content']) && is_array($outputItem['content'])) {
                        $this->content = $outputItem['content'];

                        foreach ($outputItem['content'] as $contentItem) {
                            if (isset($contentItem['type']) && $contentItem['type'] === 'output_text' && isset($contentItem['text'])) {
                                $this->outputText = $contentItem['text'];
                                break 2;
                            }
                        }
                    }
                }
            } elseif (isset($responseData['output_text'])) {
                $this->outputText = $responseData['output_text'];
            } elseif (isset($responseData['choices'][0]['message']['content'])) {
                $this->outputText = $responseData['choices'][0]['message']['content'];
                $this->content = [
                    ['type' => 'output_text', 'text' => $this->outputText],
                ];
            } else {
                Log::warning('Could not find output_text in response: ' . Json::encode($responseData));
                $this->setError('Could not parse response from OpenAI API.');
                return false;
            }

            return $this->validate();
        } catch (Exception $e) {
            Log::error('Failed to parse OpenAI response: ' . $e->getMessage());
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
