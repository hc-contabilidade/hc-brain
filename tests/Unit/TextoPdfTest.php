<?php

namespace Tests\Unit;

use App\Support\TextoPdf;
use PHPUnit\Framework\TestCase;

class TextoPdfTest extends TestCase
{
    public function test_gera_pdf_valido_com_acentos(): void
    {
        $pdf = TextoPdf::de('Lista de Usuários', "1. Ana\n2. José\n3. Açúcar");

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
        $this->assertStringContainsString('/BaseFont /Helvetica', $pdf);
        $this->assertStringContainsString('Lista de Usu', $pdf);
    }
}
