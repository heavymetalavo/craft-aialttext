<?php

namespace heavymetalavo\craftaialttext\models\api;

use craft\base\Model;
use craft\helpers\Json;
use Exception;
use Craft;

/**
 * OpenAI Response Model
 *
 * Represents a response from the OpenAI API.
 * This model handles the structure and validation of API responses, including the generated content and any errors.
 *
 * @property string $output_text The generated content from the API
 * @property array|null $output The full output structure from the API
 * @property array|null $content The content array from the API
 * @property array|null $error Error information if the request failed
 * @property array|null $rawData The raw response data from the API
 */
class OpenAiResponse extends Model
{
    public string $output_text = '';
    public ?array $output = null;
    public ?array $content = null;
    public ?array $error = null;
    private ?array $rawData = null;

    /**
     * Parse the API response and populate the model properties
     *
     * @param string $responseBody The raw response body from the API
     * @return bool True if parsing was successful, false otherwise
     */
    public function parseResponse(string $responseBody): bool
    {
        try {
            $responseData = Json::decode($responseBody);
            $this->rawData = $responseData;

            // Reset properties
            $this->output_text = '';
            $this->output = null;
            $this->content = null;
            $this->error = null;

            // Check for error messages first
            if (isset($responseData['error'])) {
                $errorMessage = is_array($responseData['error'])
                    ? ($responseData['error']['message'] ?? Json::encode($responseData['error']))
                    : $responseData['error'];
                $this->setError($errorMessage, $responseData['error']);
                return false;
            }

            // Store the output if it exists
            if (isset($responseData['output']) && is_array($responseData['output'])) {
                $this->output = $responseData['output'];

                // Look for the message in the output array
                foreach ($responseData['output'] as $outputItem) {
                    if (isset($outputItem['content']) && is_array($outputItem['content'])) {
                        $this->content = $outputItem['content'];

                        // Look for the text content
                        foreach ($outputItem['content'] as $contentItem) {
                            if (isset($contentItem['type']) && $contentItem['type'] === 'output_text' && isset($contentItem['text'])) {
                                $this->output_text = $contentItem['text'];
                                break 2; // Break out of both loops
                            }
                        }
                    }
                }
            }
            // Check if output_text exists directly
            elseif (isset($responseData['output_text'])) {
                $this->output_text = $responseData['output_text'];
            }
            // Check for ChatGPT completion style responses
            elseif (isset($responseData['choices']) && is_array($responseData['choices'])) {
                if (isset($responseData['choices'][0]['message']['content'])) {
                    $this->output_text = $responseData['choices'][0]['message']['content'];
                    $this->content = [
                        ['type' => 'output_text', 'text' => $this->output_text]
                    ];
                }
            }
            // If we can't find any output, log the entire response
            else {
                Craft::warning('Could not find output_text in response: ' . Json::encode($responseData), __METHOD__);
                $this->setError('Could not parse response from OpenAI API.');
                return false;
            }

            return $this->validate();
        } catch (Exception $e) {
            Craft::error('Failed to parse OpenAI response: ' . $e->getMessage(), __METHOD__);
            $this->setError('Failed to parse response: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Set an error message on the response
     *
     * @param string $message The error message
     * @param array|null $details Additional error details
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
     *
     * @return array|null The raw response data
     */
    public function getRawData(): ?array
    {
        return $this->rawData;
    }

    /**
     * Defines the validation rules for the response model.
     *
     * @return array The validation rules
     */
    public function defineRules(): array
    {
        return [
            ['output_text', 'string'],
            ['output', 'safe'],
            ['content', 'safe'],
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
