<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class MeaningfulTechnicalText implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $text = trim((string) $value);
        if (! preg_match('/\p{L}/u', $text)) {
            $fail('Describe el problema o el trabajo con palabras, no solo números o símbolos.');

            return;
        }
        if (preg_match('/(\p{L})\1{7,}/iu', $text)
            || preg_match('/([^\p{L}\p{N}\s])\1{7,}/u', $text)
            || preg_match('/(\p{L}{2,6})\1{5,}/iu', $text)
            || preg_match('/(?<!\p{L})(\p{L}+)(?:\s+\1){4,}(?!\p{L})/iu', $text)
            || preg_match('/(?<!\p{L})(\p{L}+(?:\s+\p{L}+){1,7})(?:\s+\1){2,}(?!\p{L})/iu', $text)) {
            $fail('Evita repetir letras, palabras o secuencias para completar la descripción.');

            return;
        }
        if (preg_match('/\p{N}{20,}|\p{L}{40,}/u', $text)) {
            $fail('Revisa la descripción: contiene una secuencia de letras o números demasiado larga sin separar.');
        }
    }
}
