<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\Amount;
use InvalidArgumentException;

final class AmountTest extends TestCase
{
    public function testLitLesFormatsDuCsv(): void
    {
        $this->assertSame(2500, Amount::parse('25'));
        $this->assertSame(6990, Amount::parse('69,9'));
        $this->assertSame(267, Amount::parse('2,67'));
        $this->assertSame(40000, Amount::parse('400,00'));
        $this->assertSame(264505, Amount::parse('2645,05'));
    }

    public function testTolereLesEspacesDeMilliers(): void
    {
        $this->assertSame(264031, Amount::parse('2 640,31'));
        $this->assertSame(100000, Amount::parse("1\u{00A0}000,00"));
        $this->assertSame(123456789, Amount::parse("1\u{202F}234\u{202F}567,89"));
    }

    public function testRefuseLesMontantsInvalides(): void
    {
        foreach (['', 'abc', '12.50', '-5,00', '1,234', '12,', ',50'] as $text) {
            $this->assertThrows(InvalidArgumentException::class, static fn () => Amount::parse($text));
        }
    }

    public function testEcritToujoursDeuxDecimales(): void
    {
        $this->assertSame('69,90', Amount::format(6990));
        $this->assertSame('25,00', Amount::format(2500));
        $this->assertSame('0,07', Amount::format(7));
        $this->assertSame('2645,05', Amount::format(264505));
        $this->assertSame('-12,30', Amount::format(-1230));
    }
}
