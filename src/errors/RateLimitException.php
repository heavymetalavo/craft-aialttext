<?php

namespace heavymetalavo\craftaialttext\errors;

use Exception;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Thrown when a provider answers HTTP 429: the account, or the model's own provider, is getting too many requests. A
 * queued job catches it and tries again later, see GenerateAiAltText::execute().
 */
class RateLimitException extends Exception
{
    /**
     * @param string $message The provider's error message
     * @param int|null $retryAfter How many seconds the provider said to wait, from its `Retry-After` header, if it said
     * @param Throwable|null $previous The exception that caused it
     */
    public function __construct(string $message, public readonly ?int $retryAfter = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 429, $previous);
    }

    /**
     * The RateLimitException in an exception's chain, as a provider may wrap it to add to its message.
     */
    public static function find(?Throwable $e): ?self
    {
        for (; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof self) {
                return $e;
            }
        }

        return null;
    }

    /**
     * How many seconds a response's `Retry-After` header says to wait, as seconds (`20`) or a date
     * (`Wed, 21 Oct 2026 07:28:00 GMT`), or null without one.
     */
    public static function retryAfter(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));

        if ($value === '') {
            return null;
        }

        $seconds = is_numeric($value) ? (int)ceil((float)$value) : (($time = strtotime($value)) !== false ? $time - time() : null);

        return $seconds === null ? null : max(1, $seconds);
    }
}
