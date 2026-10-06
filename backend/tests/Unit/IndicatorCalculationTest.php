<?php

namespace Tests\Unit;

use App\Domains\Monitoring\Services\IndicatorCalculationService;
use PHPUnit\Framework\TestCase;

class IndicatorCalculationTest extends TestCase
{
    private IndicatorCalculationService $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new IndicatorCalculationService;
    }

    public function test_indicateur_croissant(): void
    {
        $this->assertSame(80.0, $this->calculator->attainment('croissant', 100, 80));
    }

    public function test_indicateur_decroissant(): void
    {
        $this->assertSame(200.0, $this->calculator->attainment('decroissant', 10, 5));
        $this->assertSame(50.0, $this->calculator->attainment('decroissant', 10, 20));
    }

    public function test_indicateur_binaire_et_qualitatif(): void
    {
        $this->assertSame(100.0, $this->calculator->attainment('binaire', 1, 1));
        $this->assertSame(0.0, $this->calculator->attainment('binaire', 1, 0));
        $this->assertNull($this->calculator->attainment('qualitatif', 1, 1));
    }

    public function test_ponderation_des_taches(): void
    {
        $this->assertSame(50.0, $this->calculator->weighted([
            ['progress' => 0, 'weight' => 1],
            ['progress' => 50, 'weight' => 2],
            ['progress' => 100, 'weight' => 1],
        ]));
    }
}
