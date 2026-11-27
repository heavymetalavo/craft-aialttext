<?php

namespace heavymetalavo\craftaialttext\models\api;

use Craft;
use craft\base\Model;
use craft\helpers\Json;
use Exception;

/**
 * Chat Completions Response Model
 *
 * Represents a response from a Chat Completions API provider. This model handles the structure and validation of API
 * responses, including the generated content and any errors.
 */
class ChatCompletionsResponse extends Model
{
    /**
     * @var int|null Set when the provider answered HTTP 429: how many seconds its `Retry-After` header said to wait, or 0
     * if it didn't say. Null otherwise.
     */
    public ?int $rateLimitedFor = null;

    public string $outputText = '';
    public ?array $choices = null;
    public ?array $error = null;
    private ?array $rawData = null;
    private string $providerLabel = 'API';

    /**
     * Names the provider in this model's messages.
     */
    public function setProviderLabel(string $label): self
    {
        $this->providerLabel = $label;
        return $this;
    }

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

            $this->outputText = '';
            $this->choices = null;
            $this->error = null;

            if (isset($responseData['error'])) {
                $errorDetails = is_array($responseData['error']) ? $responseData['error'] : null;
                $errorMessage = is_array($responseData['error'])
                    ? ($responseData['error']['message'] ?? Json::encode($responseData['error']))
                    : $responseData['error'];
                $this->setError($errorMessage, $errorDetails);
                return false;
            }

            if (!isset($responseData['choices'][0]) || !is_array($responseData['choices'][0])) {
                Craft::warning('Could not find a choice in response: ' . Json::encode($responseData), __METHOD__);
                $this->setError('Could not parse response from ' . $this->providerLabel . ' API.');
                return false;
            }

            $this->choices = $responseData['choices'];
            $choice = $responseData['choices'][0];
            $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];

            // A refusal is a distinct outcome from an empty response, and worth reporting as such
            if (is_string($message['refusal'] ?? null) && $message['refusal'] !== '') {
                $this->setError($this->providerLabel . ' refused the request: ' . $message['refusal']);
                return false;
            }

            // The text is a string, or on some providers a list of parts, of which the text ones are joined
            $content = $message['content'] ?? '';
            if (is_array($content)) {
                $content = implode('', array_map(
                    fn($part) => is_array($part) && ($part['type'] ?? 'text') === 'text' ? (string)($part['text'] ?? '') : '',
                    $content
                ));
            }
            $this->outputText = trim((string)$content);

            // A reasoning model can spend its entire output budget on reasoning and return no text at all, and a
            // content filter can cut the answer off. Without this the failure surfaced as the generic "Empty alt
            // text generated for asset", which gives an admin nothing to act on.
            $finishReason = $choice['finish_reason'] ?? null;
            if ($this->outputText === '' && in_array($finishReason, ['length', 'content_filter'], true)) {
                $this->setError(
                    sprintf('%s returned an incomplete response (reason: %s).', $this->providerLabel, $finishReason),
                    ['finish_reason' => $finishReason]
                );
                return false;
            }

            return $this->validate();
        } catch (Exception $e) {
            Craft::error('Failed to parse ' . $this->providerLabel . ' response: ' . $e->getMessage(), __METHOD__);
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
            ['outputText', 'string'],
            ['choices', 'safe'],
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
