<?php

namespace heavymetalavo\craftaialttext\models\api;

use Craft;
use craft\base\Model;
use craft\helpers\Json;
use Exception;

/**
 * Interactions Response Model
 *
 * Represents a response from a provider that serves Google's Interactions API. The model's answer is in `steps`:
 * the step of type `model_output` holds `content` items, and the text ones carry the generated text. Other steps,
 * such as the echoed `user_input` or a thought summary, are ignored.
 */
class InteractionsResponse extends Model
{
    /**
     * @var int|null Set when the provider answered HTTP 429: how many seconds its `Retry-After` header said to wait, or 0
     * if it didn't say. Null otherwise.
     */
    public ?int $rateLimitedFor = null;

    public string $outputText = '';
    public ?array $error = null;
    private string $providerLabel = 'Google';

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

            $this->outputText = '';
            $this->error = null;

            if (isset($responseData['error'])) {
                $errorDetails = is_array($responseData['error']) ? $responseData['error'] : null;
                $this->setError(
                    is_array($responseData['error']) ? ($responseData['error']['message'] ?? Json::encode($responseData['error'])) : (string)$responseData['error'],
                    $errorDetails
                );
                return false;
            }

            $status = $responseData['status'] ?? null;

            // A model that thinks can spend its whole output budget on thinking and return no text, which is
            // reported as `incomplete`. Without this it surfaced as the generic "no alt text generated".
            if ($status === 'incomplete') {
                $this->setError(sprintf('%s returned an incomplete response.', $this->providerLabel), $responseData);
                return false;
            }

            if ($status === 'failed') {
                $this->setError(sprintf('%s could not complete the request: %s', $this->providerLabel, $responseData['errors'][0]['message'] ?? 'no reason given'), $responseData);
                return false;
            }

            if (!isset($responseData['steps']) || !is_array($responseData['steps'])) {
                Craft::warning('Could not find steps in response: ' . Json::encode($responseData), __METHOD__);
                $this->setError('Could not parse response from ' . $this->providerLabel . ' API.');
                return false;
            }

            $texts = [];
            foreach ($responseData['steps'] as $step) {
                if (($step['type'] ?? null) !== 'model_output') {
                    continue;
                }

                foreach ($step['content'] ?? [] as $content) {
                    if (($content['type'] ?? null) === 'text' && is_string($content['text'] ?? null)) {
                        $texts[] = trim($content['text']);
                    }
                }
            }

            $this->outputText = trim(implode("\n", array_filter($texts)));

            // No text and a recorded fault, such as a safety block, is a refusal worth reporting as one, as it
            // is a different outcome from a model that simply returned nothing
            if ($this->outputText === '' && !empty($responseData['errors'][0])) {
                $this->setError(sprintf('%s did not return alt text: %s', $this->providerLabel, $responseData['errors'][0]['message'] ?? $responseData['errors'][0]['code'] ?? 'no reason given'), $responseData['errors'][0]);
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
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            ['outputText', 'string'],
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
     * Gets the error message from the response, or an empty string if there is none.
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
        return $this->outputText;
    }
}
