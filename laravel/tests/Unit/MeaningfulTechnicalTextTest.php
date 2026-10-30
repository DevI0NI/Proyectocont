<?php

namespace Tests\Unit;

use App\Rules\MeaningfulTechnicalText;
use PHPUnit\Framework\TestCase;

class MeaningfulTechnicalTextTest extends TestCase
{
    public function test_obvious_filler_is_rejected_but_technical_words_codes_and_measurements_are_allowed(): void
    {
        $rule = new MeaningfulTechnicalText;
        foreach (['123456789012345678901234567890', '!!! --- ### ...', 'Falla !!!!!!!!!!!!', 'Se reparo '.str_repeat('r', 12),
            'Se reviso '.str_repeat('asdf', 7), 'panel panel panel panel panel', 'Numero: 12345678901234567890',
            'Falla: abcdefghijklmnopqrstuvwxyzabcdefghijklmn', str_repeat('texto de relleno ', 4)] as $text) {
            $messages = [];
            $rule->validate('notes', $text, function (string $message) use (&$messages): void {
                $messages[] = $message;
            });
            $this->assertNotEmpty($messages, $text);
        }
        foreach (['Se reemplazó el empaque del panel lateral.', 'PTI OK; presión 125 PSI; fuga de 2.5 mm en válvula #4.',
            'Contenedor SUDU5353440, repuesto ABC-1234567890123456789.', "Primera revisión: sin fugas.\nSegunda revisión: soldadura conforme.",
            'No se encontraron daños; unidad y box en buen estado.'] as $text) {
            $messages = [];
            $rule->validate('notes', $text, function (string $message) use (&$messages): void {
                $messages[] = $message;
            });
            $this->assertSame([], $messages, $text);
        }
    }
}
