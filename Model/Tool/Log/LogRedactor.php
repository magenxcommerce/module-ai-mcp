<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Log;

/**
 * Masks the personal data and secrets a Magento log line commonly carries.
 *
 * Logs are written by every module in the installation, none of which expected
 * an agent to read them back: exception messages quote customer e-mail
 * addresses, payment modules dump request payloads, and a failed API call can
 * log the header that authenticated it. Everything the log tools return passes
 * through here first.
 *
 * This is best effort and says so. Patterns catch the shapes that are
 * recognisable — an address, a bearer token, a `"password": "…"` pair, a card
 * number that passes Luhn — and nothing can recognise a street address or a
 * name in free text. That is why the log tools sit behind a grant of their own
 * rather than relying on this class to make their output harmless.
 */
class LogRedactor
{
    /** Key names whose value is a secret wherever it appears. */
    private const SECRET_KEYS = 'password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key'
        . '|client[_-]?secret|authorization|signature|cvv|cvc|cc[_-]?number|card[_-]?number|form[_-]?key'
        . '|session[_-]?id|phpsessid|cookie';

    /**
     * Mask everything this class knows how to recognise in one line.
     *
     * @param string $text
     * @return string
     */
    public function redact(string $text): string
    {
        // Authorization schemes first: the credential that follows is often
        // short enough to slip past the generic token rule below.
        $text = (string) preg_replace(
            '/\b(Bearer|Basic|OAuth)\s+[A-Za-z0-9._~+\/=-]+/i',
            '$1 [redacted]',
            $text
        );

        // key=value, key: value and "key":"value", including the escaped quotes
        // of JSON that was itself logged inside a JSON context. A header the
        // rule above already masked is left as it is.
        $text = (string) preg_replace(
            '/((?:\\\\?["\'])?[\w-]*(?:' . self::SECRET_KEYS . ')[\w-]*(?:\\\\?["\'])?\s*(?:=>|[:=])\s*)'
            . '((?:\\\\?["\'])?)(?!(?:Bearer|Basic|OAuth) \[)[^"\'\s&,;}\\\\]+/i',
            '$1$2[redacted]',
            $text
        );

        $text = (string) preg_replace(
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/',
            '[email]',
            $text
        );

        $text = (string) preg_replace_callback(
            '/(?<![\d.])\d(?:[ -]?\d){12,18}(?![\d.])/',
            fn (array $match): string => $this->passesLuhn($match[0]) ? '[card]' : $match[0],
            $text
        );

        // Long opaque strings with digits in them: OAuth and integration
        // tokens, session ids, API keys. Requiring a digit spares the long
        // identifiers a stack trace is full of.
        $text = (string) preg_replace(
            '/(?<![\w\/\\\\.-])(?=[A-Za-z0-9_-]*\d)(?=[A-Za-z0-9_-]*[A-Za-z])[A-Za-z0-9_-]{32,}(?![\w\/\\\\.-])/',
            '[token]',
            $text
        );

        // A customer's IP address is personal data, but the network it came
        // from is still useful when tracing a fault, so only the host is masked.
        return (string) preg_replace(
            '/\b((?:25[0-5]|2[0-4]\d|1?\d?\d)\.(?:25[0-5]|2[0-4]\d|1?\d?\d)\.(?:25[0-5]|2[0-4]\d|1?\d?\d))'
            . '\.(?:25[0-5]|2[0-4]\d|1?\d?\d)\b/',
            '$1.x',
            $text
        );
    }

    /**
     * Whether a run of digits is a plausible payment card number.
     *
     * Order increments, timestamps and report ids are long digit runs too, and
     * masking those would hide exactly what a fault is being traced by. Luhn
     * lets roughly one random number in ten through, which is an acceptable
     * cost for not leaking real card numbers.
     *
     * @param string $candidate
     * @return bool
     */
    private function passesLuhn(string $candidate): bool
    {
        $digits = (string) preg_replace('/\D/', '', $candidate);
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }
}
