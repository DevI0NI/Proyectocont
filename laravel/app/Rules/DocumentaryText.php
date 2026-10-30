<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class DocumentaryText implements ValidationRule
{
    public function __construct(public string $kind) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $text = trim((string) $value);
        $pattern = $this->kind === 'driver'
            ? '/^[\p{L}\p{M}\s.\x{2019}\x{27}-]+$/u'
            : '/^[\p{L}\p{M}\p{N}\s.,&()\x{2019}\x{27}\/-]+$/u';
        if (! preg_match('/\p{L}/u', $text) || ! preg_match($pattern, $text)) {
            $fail(! preg_match('/\p{L}/u', $text)
                ? match ($this->kind) {
                    'brand' => 'La marca debe incluir letras.',
                    'driver' => 'El nombre debe incluir letras.',
                    default => 'La empresa debe incluir letras.',
                }
                : ($this->kind === 'driver' ? 'El nombre no admite números ni símbolos.' : 'Revisa los signos utilizados.'));

            return;
        }
        $letters = preg_match_all('/\p{L}/u', $text);
        $numbers = preg_match_all('/\p{N}/u', $text);
        if ($numbers > $letters * 2) {
            $fail('Demasiados números para un nombre.');

            return;
        }
        if (preg_match('/\p{N}{12,}/u', $text)) {
            $fail('La cadena de números es demasiado larga.');

            return;
        }
        if (preg_match('/qwerty|asdfgh|zxcvbn|qazwsx/iu', $text)) {
            $fail('Evita las secuencias de teclado.');

            return;
        }
        (new MeaningfulTechnicalText)->validate($attribute, $text, function (string $message) use ($fail): void {
            $fail('Evita repeticiones o secuencias largas.');
        });
    }
}
